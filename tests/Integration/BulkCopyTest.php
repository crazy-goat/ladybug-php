<?php

declare(strict_types=1);

namespace Ladybug\Tests\Integration;

use Ladybug\Exception\InvalidArgumentException;
use Ladybug\Exception\TypeException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `Connection::copyInto()` against a real database, on whichever backend is under test.
 *
 * The CSV dialect is pinned by CsvSpoolTest; what these check is that the same values come
 * back out of liblbug unchanged. A bulk loader that quietly writes NULL where the caller
 * passed an empty string, or rounds a float, is worse than no bulk loader.
 */
final class BulkCopyTest extends IntegrationTestCase
{
    public function testAssociativeRowsAreCopiedByColumnName(): void
    {
        $this->createPeople();

        $copied = $this->connection->copyInto('Bulk', [
            ['name' => 'Ada', 'age' => 36],
            ['name' => 'Alan', 'age' => 41],
        ]);

        self::assertSame(2, $copied);
        self::assertSame(
            [['b.name' => 'Ada', 'b.age' => 36], ['b.name' => 'Alan', 'b.age' => 41]],
            $this->connection->query('MATCH (b:Bulk) RETURN b.name, b.age ORDER BY b.name')->fetchAll(),
        );
    }

    public function testColumnOrderFollowsTheKeysNotTheTable(): void
    {
        // The column list is built from the first row's keys, so a caller whose array happens
        // to be in a different order than the DDL still gets their values in the right places.
        $this->createPeople();

        $this->connection->copyInto('Bulk', [['age' => 36, 'name' => 'Ada']]);

        self::assertSame(
            [['b.name' => 'Ada', 'b.age' => 36]],
            $this->connection->query('MATCH (b:Bulk) RETURN b.name, b.age')->fetchAll(),
        );
    }

    public function testPositionalRowsUseTheTablesOwnOrder(): void
    {
        $this->createPeople();

        $this->connection->copyInto('Bulk', [['Ada', 36], ['Alan', 41]]);

        self::assertSame(
            ['Ada', 'Alan'],
            $this->connection->query('MATCH (b:Bulk) RETURN b.name ORDER BY b.age')->fetchColumn('b.name'),
        );
    }

    public function testAnExplicitColumnListCopiesASubset(): void
    {
        $this->connection->run('CREATE NODE TABLE Partial(id INT64, name STRING, note STRING, PRIMARY KEY(id))');

        $this->connection->copyInto('Partial', [[1, 'Ada'], [2, 'Alan']], columns: ['id', 'name']);

        $rows = $this->connection->query('MATCH (p:Partial) RETURN p.name, p.note ORDER BY p.id')->fetchAll();

        self::assertSame([['p.name' => 'Ada', 'p.note' => null], ['p.name' => 'Alan', 'p.note' => null]], $rows);
    }

    public function testEveryValueTypeSurvivesTheRoundTrip(): void
    {
        $this->connection->run(
            'CREATE NODE TABLE Wide(id INT64, s STRING, f DOUBLE, b BOOLEAN, d DATE, ts TIMESTAMP, PRIMARY KEY(id))',
        );

        $rows = [
            [1, 'plain', 1.5, true, new \DateTimeImmutable('1815-12-10'), new \DateTimeImmutable('2024-05-06 07:08:09.123456')],
            [2, 'Smith, John', -0.5, false, null, null],
            [3, "two\nlines", 0.1 + 0.2, true, null, null],
            [4, 'say "hi"', 2.0, false, null, null],
            [5, 'tiny', 1.0E-300, true, null, null],
            [6, null, 1.0E+300, false, null, null],
        ];

        self::assertSame(6, $this->connection->copyInto('Wide', $rows));

        $back = $this->connection
            ->query('MATCH (w:Wide) RETURN w.id, w.s, w.f, w.b, w.d, w.ts ORDER BY w.id')
            ->fetchAll();

        self::assertSame('plain', $back[0]['w.s']);
        self::assertSame('Smith, John', $back[1]['w.s']);
        self::assertSame("two\nlines", $back[2]['w.s'], 'a quoted newline needs a serial read');
        self::assertSame('say "hi"', $back[3]['w.s']);
        self::assertSame('tiny', $back[4]['w.s']);
        self::assertNull($back[5]['w.s'], 'NULL must not arrive as an empty string');

        self::assertSame(0.1 + 0.2, $back[2]['w.f'], 'floats must round-trip exactly');
        self::assertSame(1.0E-300, $back[4]['w.f']);
        self::assertSame(1.0E+300, $back[5]['w.f']);

        self::assertTrue($back[0]['w.b']);
        self::assertFalse($back[1]['w.b']);

        self::assertSame('1815-12-10', $back[0]['w.d']?->format('Y-m-d'));
        self::assertSame('2024-05-06 07:08:09.123456', $back[0]['w.ts']?->format('Y-m-d H:i:s.u'));
        self::assertNull($back[1]['w.d']);
    }

    public function testRelationshipsAreCopiedFromKeysInTheFirstTwoColumns(): void
    {
        $this->createPeople();
        $this->connection->copyInto('Bulk', [['Ada', 36], ['Alan', 41]]);
        $this->connection->run('CREATE REL TABLE BulkKnows(FROM Bulk TO Bulk, since INT64)');

        self::assertSame(1, $this->connection->copyInto('BulkKnows', [['Ada', 'Alan', 2001]]));
        self::assertSame(
            [['a.name' => 'Ada', 'k.since' => 2001, 'b.name' => 'Alan']],
            $this->connection
                ->query('MATCH (a:Bulk)-[k:BulkKnows]->(b:Bulk) RETURN a.name, k.since, b.name')
                ->fetchAll(),
        );
    }

    public function testALargeBatchIsCopiedInOneGo(): void
    {
        $this->createPeople();

        $rows = [];
        for ($i = 0; $i < 5_000; ++$i) {
            $rows[] = ['name' => "person-{$i}", 'age' => $i % 90];
        }

        self::assertSame(5_000, $this->connection->copyInto('Bulk', $rows));
        self::assertSame(5_000, $this->connection->query('MATCH (b:Bulk) RETURN count(*)')->fetchOne());
    }

    public function testAGeneratorIsAcceptedWithoutMaterialising(): void
    {
        // The point of a bulk API is not needing the whole batch in memory at once.
        $this->createPeople();

        $rows = (static function (): \Generator {
            for ($i = 0; $i < 100; ++$i) {
                yield ['name' => "gen-{$i}", 'age' => $i];
            }
        })();

        self::assertSame(100, $this->connection->copyInto('Bulk', $rows));
    }

    public function testAnEmptyBatchDoesNothing(): void
    {
        $this->createPeople();

        self::assertSame(0, $this->connection->copyInto('Bulk', []));
        self::assertSame(0, $this->connection->query('MATCH (b:Bulk) RETURN count(*)')->fetchOne());
    }

    public function testARowMissingAColumnNamesItAndTheRowNumber(): void
    {
        $this->createPeople();

        try {
            $this->connection->copyInto('Bulk', [
                ['name' => 'Ada', 'age' => 36],
                ['name' => 'Alan'],
            ]);
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('Row 2', $message);
        self::assertStringContainsString('age', $message);
    }

    public function testAnEmptyStringIsRefusedRatherThanSilentlyStoredAsNull(): void
    {
        // liblbug's CSV reader reads an empty field as NULL and has no sentinel to separate
        // the two, so copying '' would change the value. Refusing says so; storing NULL would
        // not. Documented in the README next to copyInto().
        $this->createPeople();

        try {
            $this->connection->copyInto('Bulk', [['name' => '', 'age' => 1]]);
            self::fail('Expected a TypeException.');
        } catch (TypeException $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('empty string', $message);
        self::assertStringContainsString('CREATE', $message, 'the message has to name the way out');
    }

    public function testAnUnsupportedValueIsRejectedBeforeTheDatabaseSeesIt(): void
    {
        $this->createPeople();

        $this->expectException(TypeException::class);

        $this->connection->copyInto('Bulk', [['name' => 'Ada', 'age' => [1, 2, 3]]]);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableNames(): iterable
    {
        yield 'injection attempt' => ["Bulk' (name) FROM '/etc/passwd"];
        yield 'quote' => ["Bul'k"];
        yield 'space' => ['Bulk table'];
        yield 'leading digit' => ['1Bulk'];
        yield 'empty' => [''];
        yield 'semicolon' => ['Bulk; MATCH (n) DELETE n'];
    }

    #[DataProvider('unusableNames')]
    public function testAnUnusableTableNameIsRefused(string $table): void
    {
        // The table name goes into the statement as text, so this is the only thing standing
        // between a caller-supplied name and an injected copy statement.
        $this->expectException(InvalidArgumentException::class);

        $this->connection->copyInto($table, [['x']]);
    }

    public function testAnUnusableColumnNameIsRefused(): void
    {
        $this->createPeople();

        $this->expectException(InvalidArgumentException::class);

        $this->connection->copyInto('Bulk', [['name' => 'Ada']], columns: ['name); MATCH (n) DELETE n; //']);
    }

    public function testNoTemporaryFilesAreLeftBehind(): void
    {
        $this->createPeople();
        $before = $this->copyFilesInTemp();

        $this->connection->copyInto('Bulk', [['name' => 'Ada', 'age' => 36]]);
        try {
            $this->connection->copyInto('Bulk', [['name' => 'Bad', 'age' => new \stdClass()]]);
        } catch (TypeException) {
            // The failing path has to clean up too.
        }

        self::assertSame($before, $this->copyFilesInTemp());
    }

    private function copyFilesInTemp(): int
    {
        return \count(glob(sys_get_temp_dir() . '/ladybug-copy-*') ?: []);
    }

    private function createPeople(): void
    {
        $this->connection->run('CREATE NODE TABLE Bulk(name STRING, age INT64, PRIMARY KEY(name))');
    }
}
