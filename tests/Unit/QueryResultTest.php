<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit;

use Ladybug\Exception\QueryException;
use Ladybug\QueryResult;
use Ladybug\Tests\Fake\FakeConnector;
use Ladybug\Tests\Fake\FakeHandle;
use Ladybug\Type\DataType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the row-shaping logic against a fake connector, so the assertions describe
 * this library's behaviour rather than liblbug's.
 */
#[CoversClass(QueryResult::class)]
final class QueryResultTest extends TestCase
{
    private FakeConnector $connector;

    protected function setUp(): void
    {
        $this->connector = new FakeConnector();
    }

    /**
     * @param list<list<mixed>> $rows
     * @param list<string> $columns
     */
    private function resultOf(array $rows, array $columns = ['name', 'age']): QueryResult
    {
        $this->connector->willReturn($rows, $columns);

        return new QueryResult($this->connector, new FakeHandle('result'), 'MATCH (n) RETURN n');
    }

    public function testFetchRowKeysValuesByColumnName(): void
    {
        $result = $this->resultOf([['Ada', 36]]);

        self::assertSame(['name' => 'Ada', 'age' => 36], $result->fetchRow());
        self::assertNull($result->fetchRow(), 'a second call is past the end');
    }

    public function testFetchNumericKeepsPositions(): void
    {
        self::assertSame(['Ada', 36], $this->resultOf([['Ada', 36]])->fetchNumeric());
    }

    public function testFetchOneReturnsTheFirstColumnOfTheNextRow(): void
    {
        self::assertSame('Ada', $this->resultOf([['Ada', 36]])->fetchOne());
    }

    public function testFetchOneReturnsNullOnAnEmptyResult(): void
    {
        self::assertNull($this->resultOf([])->fetchOne());
    }

    public function testFetchAllReturnsEveryRemainingRow(): void
    {
        $result = $this->resultOf([['Ada', 36], ['Piotr', 40]]);

        self::assertSame([
            ['name' => 'Ada', 'age' => 36],
            ['name' => 'Piotr', 'age' => 40],
        ], $result->fetchAll());
    }

    public function testFetchColumnFlattensByIndex(): void
    {
        self::assertSame(['Ada', 'Piotr'], $this->resultOf([['Ada', 36], ['Piotr', 40]])->fetchColumn());
        self::assertSame([36, 40], $this->resultOf([['Ada', 36], ['Piotr', 40]])->fetchColumn(1));
    }

    public function testFetchColumnFlattensByName(): void
    {
        self::assertSame([36, 40], $this->resultOf([['Ada', 36], ['Piotr', 40]])->fetchColumn('age'));
    }

    public function testFetchColumnRejectsAnUnknownName(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/No column named "nope".*name, age/s');

        $this->resultOf([['Ada', 36]])->fetchColumn('nope');
    }

    public function testFetchColumnRejectsAnOutOfRangeIndex(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/out of range/');

        $this->resultOf([['Ada', 36]])->fetchColumn(5);
    }

    public function testFetchAllKeyedByIndexesRowsByAColumn(): void
    {
        $rows = $this->resultOf([['Ada', 36], ['Piotr', 40]])->fetchAllKeyedBy('name');

        self::assertSame(['Ada', 'Piotr'], array_keys($rows));
        self::assertSame(40, $rows['Piotr']['age']);
    }

    public function testFetchAllKeyedByRejectsAnUnusableKeyType(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/not usable as array keys/');

        $this->resultOf([[[1, 2], 36]])->fetchAllKeyedBy('name');
    }

    public function testIterationStreamsRowsInOrder(): void
    {
        $result = $this->resultOf([['Ada', 36], ['Piotr', 40]]);

        self::assertSame([
            0 => ['name' => 'Ada', 'age' => 36],
            1 => ['name' => 'Piotr', 'age' => 40],
        ], iterator_to_array($result));
    }

    public function testDuplicateColumnNamesAreSuffixed(): void
    {
        $result = $this->resultOf([['Ada', 'Ada']], ['name', 'name']);

        self::assertSame(['name' => 'Ada', 'name#2' => 'Ada'], $result->fetchRow());
    }

    public function testConsumptionIsTrackedAndResetRewinds(): void
    {
        $result = $this->resultOf([['Ada', 36]]);

        self::assertFalse($result->isConsumed());
        $result->fetchAll();
        self::assertTrue($result->isConsumed());

        $result->reset();
        self::assertFalse($result->isConsumed());
        self::assertCount(1, $result->fetchAll(), 'rows are readable again after reset()');
        self::assertContains('rewind', $this->connector->calls);
    }

    public function testCountReportsTheRowCount(): void
    {
        self::assertCount(2, $this->resultOf([['Ada', 36], ['Piotr', 40]]));
    }

    public function testColumnTypesAreMappedToTheEnum(): void
    {
        self::assertSame([DataType::Int64, DataType::Int64], $this->resultOf([])->columnTypes());
    }

    public function testSummaryIsPassedThrough(): void
    {
        self::assertSame(['compilingTimeMs' => 0.1, 'executionTimeMs' => 0.2], $this->resultOf([])->summary());
    }

    public function testStringificationRendersATable(): void
    {
        $rendered = (string) $this->resultOf([['Ada', 36], ['Piotr', null]]);

        self::assertSame("name | age\nAda | 36\nPiotr | NULL", $rendered);
    }

    public function testClosingIsIdempotentAndBlocksFurtherUse(): void
    {
        $result = $this->resultOf([['Ada', 36]]);
        $result->close();
        $result->close();

        self::assertSame(1, array_count_values($this->connector->calls)['closeResult']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/closed/');
        $result->fetchOne();
    }

    public function testTheFailingCypherIsCarriedOnTheException(): void
    {
        $result = $this->resultOf([]);
        $result->close();

        $thrown = null;
        try {
            $result->fetchOne();
        } catch (QueryException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'expected a QueryException');
        self::assertSame('MATCH (n) RETURN n', $thrown->cypher);
        self::assertStringContainsString('MATCH (n) RETURN n', (string) $thrown);
    }
}
