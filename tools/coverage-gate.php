<?php

declare(strict_types=1);

/*
 * Merges Clover reports and enforces a floor on line coverage.
 *
 *   php tools/coverage-gate.php 85 build/clover-ffi.xml build/clover-ext.xml
 *
 * Merging matters here: the suite runs once per backend, and each run leaves the other
 * connector almost entirely uncovered. Taking the union of covered lines is what makes the
 * number describe the library rather than whichever run happened to produce the report.
 */

use Ladybug\Tools\CoverageGate;

require __DIR__ . '/../vendor/autoload.php';

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
$arguments = array_slice($argv, 1);
$minimum = (float) (array_shift($arguments) ?? 0);
$reports = $arguments;

if ($reports === []) {
    fwrite(STDERR, "usage: php tools/coverage-gate.php <min-percent> <clover.xml>...\n");
    exit(2);
}

try {
    $coverage = CoverageGate::merge($reports);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'coverage-gate: ' . $e->getMessage() . "\n");
    exit(2);
}

$percent = CoverageGate::percentage($coverage);
$root = dirname(__DIR__) . '/';

echo "Least covered files:\n";
foreach (CoverageGate::leastCovered($coverage, 10) as $file => $stats) {
    echo sprintf(
        "  %5.1f%%  %4d/%-4d  %s\n",
        CoverageGate::percentage([$file => $stats]),
        $stats['covered'],
        $stats['total'],
        str_replace($root, '', $file),
    );
}

echo sprintf(
    "\nLine coverage: %.2f%% (%d/%d) across %d file(s), minimum %.2f%%\n",
    $percent,
    array_sum(array_column($coverage, 'covered')),
    array_sum(array_column($coverage, 'total')),
    count($coverage),
    $minimum,
);

if ($percent + 0.005 < $minimum) {
    fwrite(STDERR, sprintf("coverage-gate: below the minimum of %.2f%%\n", $minimum));
    exit(1);
}
