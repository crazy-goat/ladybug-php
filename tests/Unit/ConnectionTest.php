<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit;

use Ladybug\Connection;
use Ladybug\Database;
use Ladybug\Exception\ConnectionException;
use Ladybug\Tests\Fake\FakeConnector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Checks the decisions Connection makes on its own — direct query versus prepare, the
 * statement cache, transaction bracketing — without involving a real database.
 */
#[CoversClass(Connection::class)]
#[CoversClass(Database::class)]
final class ConnectionTest extends TestCase
{
    private FakeConnector $connector;

    private Database $database;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connector = new FakeConnector();
        $this->database = new Database('/tmp/fake.lbdb', connector: $this->connector);
        $this->connection = $this->database->connect();
    }

    public function testAQueryWithoutParametersSkipsPreparation(): void
    {
        $this->connection->query('RETURN 1');

        self::assertContains('query(RETURN 1)', $this->connector->calls);
        self::assertNotContains('prepare(RETURN 1)', $this->connector->calls);
    }

    public function testAQueryWithParametersIsPreparedAndExecuted(): void
    {
        $this->connection->query('RETURN $n', ['n' => 1]);

        self::assertContains('prepare(RETURN $n)', $this->connector->calls);
        self::assertContains('execute({"n":1})', $this->connector->calls);
    }

    public function testPreparedStatementsAreCachedByCypherText(): void
    {
        $first = $this->connection->prepare('RETURN $n');
        $second = $this->connection->prepare('RETURN $n');

        self::assertSame($first, $second);
        self::assertSame(1, array_count_values($this->connector->calls)['prepare(RETURN $n)']);
    }

    public function testTheStatementCacheEvictsTheLeastRecentlyUsed(): void
    {
        // 64 is the cache size; the 65th entry must evict the oldest one.
        for ($i = 0; $i < 65; ++$i) {
            $this->connection->prepare("RETURN {$i}");
        }

        self::assertContains('closeStatement', $this->connector->calls, 'an evicted statement is freed');
    }

    public function testRunReturnsTheRowCountAndReleasesTheResult(): void
    {
        $this->connector->rows = [[1], [2], [3]];

        self::assertSame(3, $this->connection->run('MATCH (n) RETURN n'));
        self::assertContains('closeResult', $this->connector->calls);
    }

    public function testTransactionCommitsOnSuccess(): void
    {
        $outcome = $this->connection->transaction(static fn(Connection $conn): string => 'done');

        self::assertSame('done', $outcome);
        self::assertContains('query(BEGIN TRANSACTION)', $this->connector->calls);
        self::assertContains('query(COMMIT)', $this->connector->calls);
        self::assertNotContains('query(ROLLBACK)', $this->connector->calls);
    }

    public function testTransactionRollsBackAndRethrows(): void
    {
        $propagated = null;

        try {
            $this->connection->transaction(static function (): never {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $propagated = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $propagated, 'the exception should have propagated');
        self::assertSame('boom', $propagated->getMessage());

        self::assertContains('query(ROLLBACK)', $this->connector->calls);
        self::assertNotContains('query(COMMIT)', $this->connector->calls);
    }

    public function testAReadOnlyTransactionUsesTheReadOnlyForm(): void
    {
        $this->connection->transaction(static fn(): null => null, readOnly: true);

        self::assertContains('query(BEGIN TRANSACTION READ ONLY)', $this->connector->calls);
    }

    public function testConnectionSettingsAreForwarded(): void
    {
        $this->connection->setMaxThreads(4)->setQueryTimeout(1500);
        $this->connection->interrupt();

        self::assertContains('setMaxThreads(4)', $this->connector->calls);
        self::assertContains('setQueryTimeout(1500)', $this->connector->calls);
        self::assertContains('interrupt', $this->connector->calls);
    }

    public function testClosingTheDatabaseClosesItsConnections(): void
    {
        $this->database->close();

        self::assertTrue($this->connection->isClosed());
        self::assertTrue($this->database->isClosed());
    }

    public function testAClosedConnectionRefusesQueries(): void
    {
        $this->connection->close();

        $this->expectException(ConnectionException::class);
        $this->connection->query('RETURN 1');
    }

    public function testTheConnectorIdAndVersionAreExposed(): void
    {
        self::assertSame('fake', $this->database->connectorId());
        self::assertSame('0.0.0-fake', $this->database->libraryVersion());
    }
}
