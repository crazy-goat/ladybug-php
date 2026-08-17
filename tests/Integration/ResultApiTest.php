<?php

declare(strict_types=1);

namespace Ladybug\Tests\Integration;

use Ladybug\QueryResult;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(QueryResult::class)]
final class ResultApiTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createPersonSchema();
        $this->connection->run("CREATE (:Person {name: 'Ada', age: 36})");
        $this->connection->run("CREATE (:Person {name: 'Piotr', age: 40})");
    }

    public function testColumnNamesComeBackAsWritten(): void
    {
        $result = $this->connection->query('MATCH (p:Person) RETURN p.name, p.age AS years');

        self::assertSame(['p.name', 'years'], $result->columnNames());
    }

    public function testForeachStreamsEveryRow(): void
    {
        $names = [];
        foreach ($this->connection->query('MATCH (p:Person) RETURN p.name ORDER BY p.name') as $index => $row) {
            $names[$index] = $row['p.name'];
        }

        self::assertSame(['Ada', 'Piotr'], $names);
    }

    public function testCountReportsTheNumberOfRows(): void
    {
        self::assertCount(2, $this->connection->query('MATCH (p:Person) RETURN p.name'));
    }

    public function testSummaryReportsTimings(): void
    {
        $summary = $this->connection->query('MATCH (p:Person) RETURN p.name')->summary();

        self::assertGreaterThanOrEqual(0.0, $summary['compilingTimeMs']);
        self::assertGreaterThanOrEqual(0.0, $summary['executionTimeMs']);
    }

    public function testResetRewindsTheCursor(): void
    {
        $result = $this->connection->query('MATCH (p:Person) RETURN p.name ORDER BY p.name');

        self::assertCount(2, $result->fetchAllNumeric());
        self::assertCount(0, $result->fetchAllNumeric(), 'a consumed result yields nothing');
        self::assertCount(2, $result->reset()->fetchAllNumeric());
    }

    public function testFetchAllKeyedByBuildsALookupTable(): void
    {
        $byName = $this->connection->query('MATCH (p:Person) RETURN p.name, p.age')->fetchAllKeyedBy('p.name');

        self::assertSame(['Ada', 'Piotr'], array_keys($byName));
        self::assertSame(40, $byName['Piotr']['p.age']);
    }

    public function testDuplicateColumnNamesAreDisambiguated(): void
    {
        $row = $this->connection->query('MATCH (p:Person) RETURN p.name, p.name LIMIT 1')->fetchRow();

        self::assertSame(['p.name', 'p.name#2'], array_keys((array) $row));
    }

    public function testSeveralStatementsInOneCallProduceSeveralResults(): void
    {
        $results = $this->connection->queryMultiple('RETURN 1 AS a; RETURN 2 AS b; RETURN 3 AS c');

        self::assertCount(3, $results);
        self::assertSame([1, 2, 3], array_map(static fn(QueryResult $r): mixed => $r->fetchOne(), $results));
    }

    public function testAnEmptyResultIsHandled(): void
    {
        $result = $this->connection->query("MATCH (p:Person) WHERE p.name = 'nobody' RETURN p.name");

        self::assertCount(0, $result);
        self::assertNull($result->fetchRow());
        self::assertSame([], $result->fetchAll());
    }

    public function testStringificationRendersATable(): void
    {
        $rendered = (string) $this->connection->query('MATCH (p:Person) RETURN p.name, p.age ORDER BY p.name');

        self::assertSame("p.name | p.age\nAda | 36\nPiotr | 40", $rendered);
    }
}
