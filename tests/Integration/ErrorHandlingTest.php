<?php

declare(strict_types=1);

namespace Ladybug\Tests\Integration;

use Ladybug\Config;
use Ladybug\Database;
use Ladybug\Exception\DatabaseException;
use Ladybug\Exception\LadybugException;
use Ladybug\Exception\QueryException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(QueryException::class)]
final class ErrorHandlingTest extends IntegrationTestCase
{
    public function testASyntaxErrorBecomesAQueryExceptionCarryingTheCypher(): void
    {
        $cypher = 'MATCH (p:Person RETURN p';

        try {
            $this->connection->query($cypher);
            self::fail('expected a QueryException');
        } catch (QueryException $e) {
            self::assertSame($cypher, $e->cypher);
            self::assertNotSame('', $e->getMessage(), "liblbug's own diagnostics must be preserved");
            self::assertStringContainsString($cypher, (string) $e);
        }
    }

    public function testAnUnknownTableIsReported(): void
    {
        $this->expectException(QueryException::class);

        $this->connection->query('MATCH (x:Nonexistent) RETURN x');
    }

    public function testAnUnknownPropertyIsReported(): void
    {
        $this->createPersonSchema();

        $this->expectException(QueryException::class);

        $this->connection->query('MATCH (p:Person) RETURN p.nonexistent');
    }

    public function testAPrimaryKeyViolationIsReported(): void
    {
        $this->createPersonSchema();
        $this->connection->run("CREATE (:Person {name: 'Ada', age: 36})");

        $this->expectException(QueryException::class);

        $this->connection->run("CREATE (:Person {name: 'Ada', age: 99})");
    }

    public function testTheConnectionStaysUsableAfterAFailedQuery(): void
    {
        try {
            $this->connection->query('THIS IS NOT CYPHER');
        } catch (QueryException) {
            // expected
        }

        self::assertSame(1, $this->connection->query('RETURN 1 AS ok')->fetchOne());
    }

    public function testTheConnectionStaysUsableAfterAFailedWrite(): void
    {
        $this->createPersonSchema();
        $this->connection->run("CREATE (:Person {name: 'Ada', age: 36})");

        try {
            $this->connection->run("CREATE (:Person {name: 'Ada', age: 99})");
        } catch (QueryException) {
            // expected
        }

        $this->connection->run("CREATE (:Person {name: 'Piotr', age: 40})");
        self::assertSame(2, $this->connection->query('MATCH (p:Person) RETURN count(*)')->fetchOne());
    }

    public function testEveryLibraryExceptionSharesOneInterface(): void
    {
        try {
            $this->connection->query('NOT CYPHER');
            self::fail('expected an exception');
        } catch (LadybugException $e) {
            self::assertInstanceOf(QueryException::class, $e);
        }
    }

    public function testOpeningAnImpossiblePathFails(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/Could not open the database/');

        new Database('/proc/definitely-not-writable/graph.lbdb', connector: self::connectorUnderTest());
    }

    public function testAClosedDatabaseRefusesToConnect(): void
    {
        $this->database->close();

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/closed/');

        $this->database->connect();
    }

    public function testAReadOnlyDatabaseRefusesWrites(): void
    {
        $this->createPersonSchema();
        $this->connection->run("CREATE (:Person {name: 'Ada', age: 36})");
        $this->connection->close();

        $this->database->close();

        $readOnly = $this->reopen(Config::readOnly());
        try {
            $connection = $readOnly->connect();
            self::assertSame(1, $connection->query('MATCH (p:Person) RETURN count(*)')->fetchOne());

            $this->expectException(QueryException::class);
            $connection->run("CREATE (:Person {name: 'Nope', age: 1})");
        } finally {
            $readOnly->close();
        }
    }
}
