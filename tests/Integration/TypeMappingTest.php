<?php

declare(strict_types=1);

namespace Ladybug\Tests\Integration;

use Ladybug\Connector\Ffi\ValueReader;
use Ladybug\Type\DataType;
use Ladybug\Type\Node;
use Ladybug\Type\Rel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/** Every LadybugDB type this client claims to understand, checked against the real thing. */
#[CoversClass(ValueReader::class)]
final class TypeMappingTest extends IntegrationTestCase
{
    /** @return iterable<string, array{string, mixed}> */
    public static function scalarProvider(): iterable
    {
        yield 'INT64' => ['RETURN 42 AS v', 42];
        yield 'INT64 negative' => ['RETURN -42 AS v', -42];
        yield 'INT32' => ['RETURN CAST(7 AS INT32) AS v', 7];
        yield 'INT16' => ['RETURN CAST(7 AS INT16) AS v', 7];
        yield 'INT8' => ['RETURN CAST(7 AS INT8) AS v', 7];
        yield 'UINT64' => ['RETURN CAST(7 AS UINT64) AS v', 7];
        yield 'UINT32' => ['RETURN CAST(7 AS UINT32) AS v', 7];
        yield 'UINT16' => ['RETURN CAST(7 AS UINT16) AS v', 7];
        yield 'UINT8' => ['RETURN CAST(7 AS UINT8) AS v', 7];
        yield 'DOUBLE' => ['RETURN 1.5 AS v', 1.5];
        yield 'BOOL true' => ['RETURN true AS v', true];
        yield 'BOOL false' => ['RETURN false AS v', false];
        yield 'STRING' => ['RETURN "text" AS v', 'text'];
        yield 'STRING empty' => ['RETURN "" AS v', ''];
        yield 'STRING utf-8' => ['RETURN "zażółć gęślą jaźń" AS v', 'zażółć gęślą jaźń'];
        yield 'NULL' => ['RETURN null AS v', null];
        yield 'LIST' => ['RETURN [1, 2, 3] AS v', [1, 2, 3]];
        yield 'LIST empty' => ['RETURN CAST([] AS INT64[]) AS v', []];
        yield 'LIST nested' => ['RETURN [[1, 2], [3]] AS v', [[1, 2], [3]]];
        yield 'LIST of strings' => ['RETURN ["a", "b"] AS v', ['a', 'b']];
        yield 'LIST with null' => ['RETURN [1, null, 3] AS v', [1, null, 3]];
        yield 'STRUCT' => ['RETURN {a: 1, b: "two"} AS v', ['a' => 1, 'b' => 'two']];
        yield 'STRUCT nested' => ['RETURN {a: {b: 1}} AS v', ['a' => ['b' => 1]]];
        yield 'MAP' => ['RETURN map(["x", "y"], [1, 2]) AS v', ['x' => 1, 'y' => 2]];
        yield 'UUID' => [
            'RETURN UUID("8b4e2f8a-1234-4c1a-9d2b-0f1e2d3c4b5a") AS v',
            '8b4e2f8a-1234-4c1a-9d2b-0f1e2d3c4b5a',
        ];
    }

    #[DataProvider('scalarProvider')]
    public function testScalarMapping(string $cypher, mixed $expected): void
    {
        self::assertSame($expected, $this->connection->query($cypher)->fetchOne());
    }

    public function testFloatIsMappedWithSinglePrecisionTolerance(): void
    {
        $value = $this->connection->query('RETURN CAST(1.5 AS FLOAT) AS v')->fetchOne();

        self::assertIsFloat($value);
        self::assertEqualsWithDelta(1.5, $value, 0.000001);
    }

    public function testDecimalIsMappedToAStringToPreserveScale(): void
    {
        $value = $this->connection->query('RETURN CAST("123.456" AS DECIMAL(10, 3)) AS v')->fetchOne();

        self::assertIsString($value, 'DECIMAL must not lose precision through a float');
        self::assertSame('123.456', $value);
    }

    public function testBlobIsMappedToABinaryString(): void
    {
        $value = $this->connection->query('RETURN BLOB("\\\\xAA\\\\xBB\\\\x00") AS v')->fetchOne();

        self::assertIsString($value);
        self::assertSame('aabb00', bin2hex($value));
    }

    public function testDateIsMappedToDateTimeImmutable(): void
    {
        $value = $this->connection->query('RETURN date("2026-08-17") AS v')->fetchOne();

        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame('2026-08-17', $value->format('Y-m-d'));
        self::assertSame('UTC', $value->getTimezone()->getName());
    }

    public function testADateBeforeTheEpochIsMappedCorrectly(): void
    {
        $value = $this->connection->query('RETURN date("1900-01-31") AS v')->fetchOne();

        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame('1900-01-31', $value->format('Y-m-d'));
    }

    public function testTimestampKeepsMicroseconds(): void
    {
        $value = $this->connection->query('RETURN timestamp("2026-08-17 10:20:30.123456") AS v')->fetchOne();

        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame('2026-08-17 10:20:30.123456', $value->format('Y-m-d H:i:s.u'));
    }

    public function testATimestampBeforeTheEpochKeepsItsFraction(): void
    {
        $value = $this->connection->query('RETURN timestamp("1969-07-20 20:17:40.5") AS v')->fetchOne();

        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame('1969-07-20 20:17:40.500000', $value->format('Y-m-d H:i:s.u'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function timestampFlavourProvider(): iterable
    {
        yield 'TIMESTAMP_SEC' => ['CAST(timestamp("2026-08-17 10:20:30") AS TIMESTAMP_SEC)', '2026-08-17 10:20:30.000000'];
        yield 'TIMESTAMP_MS' => ['CAST(timestamp("2026-08-17 10:20:30.123") AS TIMESTAMP_MS)', '2026-08-17 10:20:30.123000'];
        yield 'TIMESTAMP_NS' => ['CAST(timestamp("2026-08-17 10:20:30.123456") AS TIMESTAMP_NS)', '2026-08-17 10:20:30.123456'];
        yield 'TIMESTAMP_TZ' => ['CAST(timestamp("2026-08-17 10:20:30") AS TIMESTAMP_TZ)', '2026-08-17 10:20:30.000000'];
    }

    #[DataProvider('timestampFlavourProvider')]
    public function testEveryTimestampFlavourIsMapped(string $expression, string $expected): void
    {
        $value = $this->connection->query("RETURN {$expression} AS v")->fetchOne();

        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame($expected, $value->format('Y-m-d H:i:s.u'));
    }

    public function testIntervalIsMappedToDateInterval(): void
    {
        $value = $this->connection->query('RETURN interval("1 year 2 days 3 hours 4 minutes") AS v')->fetchOne();

        self::assertInstanceOf(\DateInterval::class, $value);
        self::assertSame(1, $value->y);
        self::assertSame(2, $value->d);
        self::assertSame(3, $value->h);
        self::assertSame(4, $value->i);
    }

    public function testInt128IsMappedToANumericString(): void
    {
        $value = $this->connection->query('RETURN CAST("170141183460469231731687303715884105727" AS INT128) AS v')->fetchOne();

        self::assertSame('170141183460469231731687303715884105727', $value, 'INT128 must survive as a string');
    }

    public function testNodeIsMappedToANodeObject(): void
    {
        $this->createPersonSchema();
        $this->connection->run("CREATE (:Person {name: 'Piotr', age: 40})");

        $node = $this->connection->query('MATCH (p:Person) RETURN p')->fetchOne();

        self::assertInstanceOf(Node::class, $node);
        self::assertSame('Person', $node->label);
        self::assertSame('Piotr', $node->get('name'));
        self::assertSame(40, $node->get('age'));
        self::assertNull($node->get('score'), 'unset properties are present and null');
    }

    public function testRelIsMappedToARelObject(): void
    {
        $this->createPersonSchema();
        $this->connection->run("CREATE (:Person {name: 'Piotr', age: 40})");
        $this->connection->run("CREATE (:Person {name: 'Ada', age: 36})");
        $this->connection->run(
            "MATCH (a:Person), (b:Person) WHERE a.name = 'Piotr' AND b.name = 'Ada' CREATE (a)-[:Knows {since: 2019}]->(b)",
        );

        $rel = $this->connection->query('MATCH ()-[k:Knows]->() RETURN k')->fetchOne();

        self::assertInstanceOf(Rel::class, $rel);
        self::assertSame('Knows', $rel->label);
        self::assertSame(2019, $rel->get('since'));
        self::assertFalse($rel->src->equals($rel->dst));
    }

    public function testAPatternCanBeTraversed(): void
    {
        $this->createPersonSchema();
        $this->connection->run("CREATE (:Person {name: 'Piotr', age: 40})");
        $this->connection->run("CREATE (:Person {name: 'Ada', age: 36})");
        $this->connection->run(
            "MATCH (a:Person), (b:Person) WHERE a.name = 'Piotr' AND b.name = 'Ada' CREATE (a)-[:Knows {since: 2019}]->(b)",
        );

        $row = $this->connection
            ->query('MATCH (a:Person)-[k:Knows]->(b:Person) RETURN a.name, b.name, k.since')
            ->fetchRow();

        self::assertSame(['a.name' => 'Piotr', 'b.name' => 'Ada', 'k.since' => 2019], $row);
    }

    public function testARecursivePathIsReachable(): void
    {
        $this->createPersonSchema();
        foreach (['a', 'b', 'c'] as $name) {
            $this->connection->run('CREATE (:Person {name: $name, age: 1})', ['name' => $name]);
        }

        $this->connection->run("MATCH (x:Person), (y:Person) WHERE x.name = 'a' AND y.name = 'b' CREATE (x)-[:Knows]->(y)");
        $this->connection->run("MATCH (x:Person), (y:Person) WHERE y.name = 'c' AND x.name = 'b' CREATE (x)-[:Knows]->(y)");

        $path = $this->connection
            ->query("MATCH p = (x:Person)-[:Knows*1..3]->(y:Person) WHERE x.name = 'a' AND y.name = 'c' RETURN p")
            ->fetchOne();

        // RECURSIVE_REL has no dedicated PHP shape yet, so it arrives as liblbug's own
        // rendering rather than being dropped.
        self::assertNotNull($path);
    }

    public function testColumnTypesAreReported(): void
    {
        $result = $this->connection->query('RETURN 1 AS i, 1.5 AS f, "s" AS s, true AS b, date("2026-01-01") AS d');

        self::assertSame(
            [DataType::Int64, DataType::Double, DataType::String, DataType::Bool, DataType::Date],
            $result->columnTypes(),
        );
    }
}
