<?php

declare(strict_types=1);

/*
 * FFI versus the native extension, on the paths where they actually differ.
 *
 * The whole two-connector design rests on the claim that converting values in C is worth the
 * cost of compiling an extension. This measures it instead of assuming it. Both backends run
 * in one process against identical data, so the comparison is not across machines or runs.
 *
 *   make bench                     both backends, every scenario
 *   php benchmarks/benchmark.php --scale=4
 *
 * Numbers are the best of several repetitions: the fastest run is the one least disturbed by
 * the rest of the machine, and averaging noise in helps nobody.
 */

use Ladybug\Config;
use Ladybug\Connector\ConnectorFactory;
use Ladybug\Database;

require __DIR__ . '/../vendor/autoload.php';

$options = getopt('', ['scale::', 'repeat::', 'connector::']) ?: [];

/** getopt() hands back false for a flag with no value and an array when it is repeated. */
$option = static function (string $name) use ($options): ?string {
    $value = $options[$name] ?? null;

    return is_string($value) && $value !== '' ? $value : null;
};

$scale = max(1, (int) ($option('scale') ?? 1));
$repeat = max(1, (int) ($option('repeat') ?? 3));
$only = $option('connector');

$available = ConnectorFactory::availableIds();
if ($only !== null) {
    $available = array_values(array_filter($available, static fn(string $id): bool => $id === $only));
}

if ($available === []) {
    fwrite(STDERR, "No connector is available.\n");
    foreach (ConnectorFactory::diagnostics() as $id => $info) {
        fwrite(STDERR, sprintf("  %-4s %s\n", $id, $info['detail']));
    }
    exit(1);
}

$rows = 5_000 * $scale;
$inserts = 2_000 * $scale;
$tinyQueries = 1_000 * $scale;

/**
 * One measured workload. `prepare` sets up data and is not timed; `run` is.
 *
 * @var array<string, array{unit: string, count: int, prepare: callable, run: callable}> $scenarios
 */
$scenarios = [
    'fetch scalars' => [
        'unit' => 'rows',
        'count' => $rows,
        'prepare' => static function (\Ladybug\Connection $c) use ($rows): void {
            $c->run('CREATE NODE TABLE Person(id INT64, name STRING, score DOUBLE, PRIMARY KEY(id))');
            $statement = $c->prepare('CREATE (:Person {id: $id, name: $name, score: $score})');
            for ($i = 0; $i < $rows; ++$i) {
                $statement->execute(['id' => $i, 'name' => "person-{$i}", 'score' => $i / 3]);
            }
        },
        'run' => static function (\Ladybug\Connection $c): int {
            return count($c->query('MATCH (p:Person) RETURN p.id, p.name, p.score')->fetchAll());
        },
    ],
    'fetch nodes' => [
        'unit' => 'nodes',
        'count' => $rows,
        // Node conversion is the heaviest path: internal id, label, and every property.
        'prepare' => static fn(\Ladybug\Connection $c): null => null,
        'run' => static function (\Ladybug\Connection $c): int {
            return count($c->query('MATCH (p:Person) RETURN p')->fetchAll());
        },
    ],
    'fetch temporal' => [
        'unit' => 'rows',
        'count' => $rows,
        // DateTimeImmutable and DateInterval construction, which the FFI reader does in PHP.
        'prepare' => static fn(\Ladybug\Connection $c): null => null,
        'run' => static function (\Ladybug\Connection $c): int {
            $cypher = 'MATCH (p:Person) RETURN cast(\'2024-05-06 07:08:09\' AS TIMESTAMP) AS ts, '
                . 'interval(\'1 year 2 days\') AS iv';

            return count($c->query($cypher)->fetchAll());
        },
    ],
    'insert prepared' => [
        'unit' => 'inserts',
        'count' => $inserts,
        'prepare' => static function (\Ladybug\Connection $c): void {
            $c->run('CREATE NODE TABLE Bench(id INT64, payload STRING, PRIMARY KEY(id))');
        },
        // One transaction, or every insert pays for its own commit and the scenario measures
        // the disk rather than the cost of binding parameters and crossing the boundary.
        // Offset by the repetition, or the second run collides with the first run's keys.
        'run' => static function (\Ladybug\Connection $c, int $run) use ($inserts): int {
            $statement = $c->prepare('CREATE (:Bench {id: $id, payload: $payload})');
            $base = $run * $inserts;

            return $c->transaction(static function () use ($statement, $inserts, $base): int {
                for ($i = 0; $i < $inserts; ++$i) {
                    $statement->execute(['id' => $base + $i, 'payload' => 'x']);
                }

                return $inserts;
            });
        },
    ],
    'tiny queries' => [
        'unit' => 'queries',
        'count' => $tinyQueries,
        // Per-call overhead rather than conversion: how much the boundary itself costs.
        'prepare' => static fn(\Ladybug\Connection $c): null => null,
        'run' => static function (\Ladybug\Connection $c) use ($tinyQueries): int {
            for ($i = 0; $i < $tinyQueries; ++$i) {
                $c->query('RETURN 1')->fetchOne();
            }

            return $tinyQueries;
        },
    ],
];

/** @var array<string, array<string, float>> $results  scenario => connector => ops/sec */
$results = [];

foreach ($available as $connectorId) {
    $directory = sys_get_temp_dir() . '/ladybug-bench-' . $connectorId . '-' . bin2hex(random_bytes(4));
    mkdir($directory, 0o777, true);

    $database = new Database($directory . '/graph.lbdb', new Config(connector: $connectorId));
    $connection = $database->connect();

    fwrite(STDERR, sprintf(
        "%s: liblbug %s, %d rows, %d inserts, %d queries, best of %d\n",
        $connectorId,
        $database->libraryVersion(),
        $rows,
        $inserts,
        $tinyQueries,
        $repeat,
    ));

    foreach ($scenarios as $name => $scenario) {
        ($scenario['prepare'])($connection);

        $best = INF;
        for ($run = 0; $run < $repeat; ++$run) {
            $started = hrtime(true);
            $produced = ($scenario['run'])($connection, $run);
            $elapsed = (hrtime(true) - $started) / 1e9;

            if ($produced !== $scenario['count']) {
                fwrite(STDERR, sprintf(
                    "  %s: expected %d %s, got %d — scenario is not measuring what it claims\n",
                    $name,
                    $scenario['count'],
                    $scenario['unit'],
                    $produced,
                ));
                exit(1);
            }

            $best = min($best, $elapsed);
        }

        $results[$name][$connectorId] = $scenario['count'] / $best;
        fwrite(STDERR, sprintf("  %-16s %10.0f %s/s\n", $name, $results[$name][$connectorId], $scenario['unit']));
    }

    $database->close();
    array_map('unlink', glob($directory . '/*') ?: []);
    @rmdir($directory);
}

// -- report -----------------------------------------------------------------------------

$header = sprintf('| %-16s | %-9s |', 'scenario', 'unit');
$divider = sprintf('|%s|%s|', str_repeat('-', 18), str_repeat('-', 11));
foreach ($available as $connectorId) {
    $header .= sprintf(' %12s |', $connectorId);
    $divider .= str_repeat('-', 14) . '|';
}

$comparable = count($available) === 2;
if ($comparable) {
    $header .= sprintf(' %8s |', 'ratio');
    $divider .= str_repeat('-', 10) . '|';
}

echo "\n", $header, "\n", $divider, "\n";

foreach ($scenarios as $name => $scenario) {
    $line = sprintf('| %-16s | %-9s |', $name, $scenario['unit']);
    foreach ($available as $connectorId) {
        $line .= sprintf(' %12s |', number_format($results[$name][$connectorId], 0));
    }

    if ($comparable) {
        [$first, $second] = $available;
        $line .= sprintf(' %7.2fx |', $results[$name][$first] / $results[$name][$second]);
    }

    echo $line, "\n";
}

if ($comparable) {
    [$first, $second] = $available;
    echo "\nratio = ", $first, ' / ', $second, " throughput; above 1.00 means ", $first, " is faster.\n";
}
