<?php

declare(strict_types=1);

namespace Ladybug\Tests\Integration;

use Ladybug\Database;
use Ladybug\Tests\Support\ResidentMemory;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every handle in this library owns a C resource that has to be released exactly once, and a
 * missed release is invisible to the functional tests: they pass, and the process grows.
 * Development produced exactly that — close ordering that never freed the C resources, which
 * surfaced as "Mmap for size 8796093022208 failed" in an unrelated test file once the address
 * space ran out.
 *
 * So these tests do the crude thing that catches it: run a workload thousands of times and
 * insist the resident set does not keep climbing. Thresholds are generous because a leaked
 * liblbug handle is not subtle — the failure mode is megabytes per iteration, not bytes.
 */
#[Group('memory')]
final class MemoryTest extends IntegrationTestCase
{
    /**
     * Enough iterations that a per-iteration leak dominates allocator noise, few enough that
     * the test stays inside a few seconds on the slower FFI backend.
     */
    private const ITERATIONS = 400;

    /** Iterations run before the baseline, so pool growth is not counted as a leak. */
    private const WARMUP = 40;

    private const ALLOWED_GROWTH_KB = 16 * 1024;

    protected function setUp(): void
    {
        if (!ResidentMemory::isAvailable()) {
            self::markTestSkipped('Cannot read the resident set size on ' . PHP_OS_FAMILY . '.');
        }

        parent::setUp();
    }

    public function testOpeningAndClosingDatabasesDoesNotLeak(): void
    {
        // The whole lifecycle: database, connection, statement, result. If any destructor is
        // skipped, each iteration strands a memory-mapped database.
        $growth = $this->growthOver(static function (): void {
            $database = Database::inMemory(connector: self::connectorUnderTest());
            $connection = $database->connect();
            $connection->run('CREATE NODE TABLE T(id INT64, PRIMARY KEY(id))');
            $connection->run('CREATE (:T {id: $id})', ['id' => 1]);
            $connection->query('MATCH (t:T) RETURN t.id')->fetchAll();
            $database->close();
        }, iterations: 60, warmup: 10);

        self::assertLessThan(self::ALLOWED_GROWTH_KB, $growth, $this->explain($growth, 60));
    }

    public function testQueryingRepeatedlyDoesNotLeak(): void
    {
        $this->connection->run('CREATE NODE TABLE Leak(id INT64, name STRING, PRIMARY KEY(id))');
        for ($i = 0; $i < 50; ++$i) {
            $this->connection->run('CREATE (:Leak {id: $id, name: $name})', ['id' => $i, 'name' => "row-{$i}"]);
        }

        // Results hold a connection reference and their own C handle; fetching rows allocates
        // an lbug_value per column that has to be destroyed after conversion.
        $growth = $this->growthOver(function (): void {
            $rows = $this->connection->query('MATCH (l:Leak) RETURN l.id, l.name')->fetchAll();
            self::assertCount(50, $rows);
        });

        self::assertLessThan(self::ALLOWED_GROWTH_KB, $growth, $this->explain($growth, self::ITERATIONS));
    }

    public function testPreparedStatementsDoNotLeakPerExecution(): void
    {
        $this->connection->run('CREATE NODE TABLE Bound(id INT64, PRIMARY KEY(id))');
        $statement = $this->connection->prepare('CREATE (:Bound {id: $id})');

        // One statement, many executions: each bind allocates an lbug_value inside liblbug,
        // and each execution produces a result that must be released.
        $counter = 0;
        $growth = $this->growthOver(static function () use ($statement, &$counter): void {
            $statement->execute(['id' => ++$counter]);
        });

        self::assertLessThan(self::ALLOWED_GROWTH_KB, $growth, $this->explain($growth, self::ITERATIONS));
    }

    public function testConvertingEveryValueTypeDoesNotLeak(): void
    {
        // Composite values are the risky ones: the reader descends into lists, structs and
        // maps, and every level allocates a value that the branch has to free on both paths.
        $cypher = <<<'CYPHER'
            RETURN [1, 2, 3] AS list,
                   {a: 'x', b: [true, false]} AS struct,
                   map([1, 2], ['a', 'b']) AS m,
                   cast('2024-05-06 07:08:09' AS TIMESTAMP) AS ts,
                   interval('1 year 2 days') AS iv,
                   cast('9223372036854775807' AS INT128) AS big
            CYPHER;

        $growth = $this->growthOver(function () use ($cypher): void {
            $row = $this->connection->query($cypher)->fetchRow();
            self::assertIsArray($row);
        });

        self::assertLessThan(self::ALLOWED_GROWTH_KB, $growth, $this->explain($growth, self::ITERATIONS));
    }

    /**
     * Runs $workload, discarding the warm-up, and returns how much the resident set grew in
     * kilobytes over the measured iterations.
     *
     * @param callable(): void $workload
     */
    private function growthOver(callable $workload, int $iterations = self::ITERATIONS, int $warmup = self::WARMUP): int
    {
        for ($i = 0; $i < $warmup; ++$i) {
            $workload();
        }

        gc_collect_cycles();
        $baseline = ResidentMemory::kilobytes();

        for ($i = 0; $i < $iterations; ++$i) {
            $workload();
        }

        gc_collect_cycles();

        return ResidentMemory::kilobytes() - $baseline;
    }

    private function explain(int $growth, int $iterations): string
    {
        return \sprintf(
            'Resident memory grew by %d KB over %d iterations (%.1f KB per iteration), which looks '
            . 'like a C resource that is never released. Run the same suite under `make test-asan` '
            . 'with detect_leaks=1 to find it.',
            $growth,
            $iterations,
            $growth / $iterations,
        );
    }
}
