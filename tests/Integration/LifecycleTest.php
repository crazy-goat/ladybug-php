<?php

declare(strict_types=1);

namespace Ladybug\Tests\Integration;

use Ladybug\Config;
use Ladybug\Database;
use Ladybug\Exception\ConnectionException;
use Ladybug\Exception\LadybugException;
use PHPUnit\Framework\Attributes\CoversClass;

/** Resource ownership: what happens on close, on double close, and when handles outlive their parent. */
#[CoversClass(Database::class)]
final class LifecycleTest extends IntegrationTestCase
{
    public function testTransactionsCommitAndRollBack(): void
    {
        $this->createPersonSchema();

        $this->connection->transaction(function (): void {
            $this->connection->run("CREATE (:Person {name: 'Committed', age: 1})");
        });

        $propagated = null;

        try {
            $this->connection->transaction(function (): never {
                $this->connection->run("CREATE (:Person {name: 'RolledBack', age: 1})");
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $propagated = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $propagated, 'the exception should have propagated');
        self::assertSame('boom', $propagated->getMessage());

        $names = $this->connection->query('MATCH (p:Person) RETURN p.name')->fetchColumn();
        self::assertSame(['Committed'], $names);
    }

    public function testATransactionReturnsItsCallbackValue(): void
    {
        $this->createPersonSchema();

        $count = $this->connection->transaction(function (): mixed {
            $this->connection->run("CREATE (:Person {name: 'X', age: 1})");

            return $this->connection->query('MATCH (p:Person) RETURN count(*)')->fetchOne();
        });

        self::assertSame(1, $count);
    }

    public function testSeveralConnectionsShareOneDatabase(): void
    {
        $this->createPersonSchema();
        $second = $this->database->connect();

        $this->connection->run("CREATE (:Person {name: 'Written', age: 1})");

        self::assertSame(1, $second->query('MATCH (p:Person) RETURN count(*)')->fetchOne());
    }

    public function testClosingIsIdempotent(): void
    {
        $this->database->close();
        $this->database->close();

        self::assertTrue($this->database->isClosed());
    }

    public function testClosingTheDatabaseClosesItsConnections(): void
    {
        $connection = $this->database->connect();
        $this->database->close();

        self::assertTrue($connection->isClosed());

        $this->expectException(ConnectionException::class);
        $connection->query('RETURN 1');
    }

    public function testAnInMemoryDatabaseWorksWithoutAPath(): void
    {
        $memory = Database::inMemory(connector: self::connectorUnderTest());
        try {
            $connection = $memory->connect();
            $connection->run('CREATE NODE TABLE T(id INT64, PRIMARY KEY(id))');
            $connection->run('CREATE (:T {id: 1})');

            self::assertSame(1, $connection->query('MATCH (t:T) RETURN count(*)')->fetchOne());
        } finally {
            $memory->close();
        }
    }

    public function testTwoInMemoryDatabasesAreIsolated(): void
    {
        $first = Database::inMemory(connector: self::connectorUnderTest());
        $second = Database::inMemory(connector: self::connectorUnderTest());

        try {
            $first->connect()->run('CREATE NODE TABLE T(id INT64, PRIMARY KEY(id))');

            $tables = $second->connect()->query('CALL show_tables() RETURN name')->fetchColumn();
            self::assertNotContains('T', $tables);
        } finally {
            $first->close();
            $second->close();
        }
    }

    public function testConnectionSettingsAreAccepted(): void
    {
        $this->connection->setMaxThreads(2)->setQueryTimeout(30_000);

        self::assertSame(1, $this->connection->query('RETURN 1 AS ok')->fetchOne());
    }

    public function testConfigIsAppliedWhenOpening(): void
    {
        $this->database->close();

        $configured = new Database(
            $this->databasePath(),
            new Config(bufferPoolSize: 64 * 1024 * 1024, maxThreads: 2, compression: true),
            self::connectorUnderTest(),
        );

        try {
            self::assertSame(1, $configured->connect()->query('RETURN 1 AS ok')->fetchOne());
        } finally {
            $configured->close();
        }
    }

    public function testTheConnectorIdentifiesItself(): void
    {
        self::assertContains($this->database->connectorId(), ['ext', 'ffi']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $this->database->libraryVersion());
    }

    /**
     * Results are read lazily, so a result must not outlive the connection that produced
     * it without saying so clearly instead of crashing.
     */
    public function testAResultIsUnusableOnceItsConnectionIsGone(): void
    {
        $result = $this->connection->query('RETURN 1 AS a');
        $this->connection->close();

        $this->expectException(LadybugException::class);
        $result->fetchOne();
    }
}
