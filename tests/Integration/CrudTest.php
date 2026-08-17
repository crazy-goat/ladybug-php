<?php

declare(strict_types=1);

namespace Ladybug\Tests\Integration;

use Ladybug\Connection;
use Ladybug\Database;
use PHPUnit\Framework\Attributes\CoversClass;

/** The minimum a client must get right: create a database, write, read back. */
#[CoversClass(Database::class)]
#[CoversClass(Connection::class)]
final class CrudTest extends IntegrationTestCase
{
    public function testTheDatabaseIsCreatedOnDisk(): void
    {
        self::assertFileExists($this->databasePath());
        self::assertFalse($this->database->isClosed());
    }

    public function testDdlCreatesATable(): void
    {
        $this->createPersonSchema();

        $tables = $this->connection->query('CALL show_tables() RETURN name')->fetchColumn();

        self::assertContains('Person', $tables);
        self::assertContains('Knows', $tables);
    }

    public function testAWrittenRowCanBeReadBack(): void
    {
        $this->createPersonSchema();
        $this->connection->run("CREATE (:Person {name: 'Piotr', age: 40, score: 9.5, active: true})");

        $row = $this->connection
            ->query("MATCH (p:Person) WHERE p.name = 'Piotr' RETURN p.name, p.age, p.score, p.active")
            ->fetchRow();

        self::assertSame(
            ['p.name' => 'Piotr', 'p.age' => 40, 'p.score' => 9.5, 'p.active' => true],
            $row,
        );
    }

    public function testAWriteThroughParametersCanBeReadBack(): void
    {
        $this->createPersonSchema();
        $this->connection->run(
            'CREATE (:Person {name: $name, age: $age, score: $score, active: $active})',
            ['name' => 'Ada', 'age' => 36, 'score' => 10.0, 'active' => false],
        );

        $row = $this->connection
            ->query('MATCH (p:Person) WHERE p.name = $name RETURN p.age, p.active', ['name' => 'Ada'])
            ->fetchRow();

        self::assertSame(['p.age' => 36, 'p.active' => false], $row);
    }

    public function testAPropertyCanBeUpdated(): void
    {
        $this->createPersonSchema();
        $this->connection->run("CREATE (:Person {name: 'Ada', age: 36})");

        $this->connection->run('MATCH (p:Person) WHERE p.name = $name SET p.age = $age', ['name' => 'Ada', 'age' => 37]);

        self::assertSame(37, $this->connection->query("MATCH (p:Person) WHERE p.name = 'Ada' RETURN p.age")->fetchOne());
    }

    public function testANodeCanBeDeleted(): void
    {
        $this->createPersonSchema();
        $this->connection->run("CREATE (:Person {name: 'Temp', age: 1})");
        self::assertSame(1, $this->countPeople());

        $this->connection->run("MATCH (p:Person) WHERE p.name = 'Temp' DELETE p");

        self::assertSame(0, $this->countPeople());
    }

    public function testAnUnsetPropertyReadsAsNull(): void
    {
        $this->createPersonSchema();
        $this->connection->run("CREATE (:Person {name: 'NoAge'})");

        self::assertNull($this->connection->query("MATCH (p:Person) WHERE p.name = 'NoAge' RETURN p.age")->fetchOne());
    }

    public function testManyRowsRoundTrip(): void
    {
        $this->createPersonSchema();
        $statement = $this->connection->prepare('CREATE (:Person {name: $name, age: $age})');
        for ($i = 0; $i < 500; ++$i) {
            $statement->execute(['name' => "person-{$i}", 'age' => $i])->close();
        }

        self::assertSame(500, $this->countPeople());
        // sum() over INT64 widens to INT128, which this client surfaces as a numeric
        // string so nothing is lost on 64-bit PHP.
        self::assertSame(
            (string) array_sum(range(0, 499)),
            $this->connection->query('MATCH (p:Person) RETURN sum(p.age)')->fetchOne(),
        );
    }

    public function testDataSurvivesReopening(): void
    {
        $this->createPersonSchema();
        $this->connection->run("CREATE (:Person {name: 'Persisted', age: 1})");

        $this->connection->close();

        $this->database->close();

        $reopened = $this->reopen();
        try {
            $connection = $reopened->connect();
            self::assertSame(
                'Persisted',
                $connection->query("MATCH (p:Person) WHERE p.name = 'Persisted' RETURN p.name")->fetchOne(),
            );
        } finally {
            $reopened->close();
        }
    }

    private function countPeople(): int
    {
        $count = $this->connection->query('MATCH (p:Person) RETURN count(*)')->fetchOne();
        self::assertIsInt($count);

        return $count;
    }
}
