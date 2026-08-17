<?php

declare(strict_types=1);

namespace Ladybug\Tools;

/**
 * Clover merging, kept separate from the CLI wrapper so it can be tested.
 *
 * @phpstan-type FileCoverage array{covered: int, total: int, lines: array<int, bool>}
 */
final class CoverageGate
{
    /**
     * Union of covered lines across every report: a line counts as covered if any run
     * executed it. Two runs of the same suite on different backends each leave the other
     * connector uncovered, and neither number alone describes the library.
     *
     * @param list<string> $reports paths to Clover XML files
     *
     * @return array<string, FileCoverage> keyed by absolute file name
     *
     * @throws \RuntimeException if a report is missing or unparsable
     */
    public static function merge(array $reports): array
    {
        /** @var array<string, array<int, bool>> $lines */
        $lines = [];

        foreach ($reports as $report) {
            foreach (self::parse($report) as $file => $executed) {
                foreach ($executed as $line => $covered) {
                    // Only ever promote to covered; a later report must not un-cover a line.
                    $lines[$file][$line] = ($lines[$file][$line] ?? false) || $covered;
                }
            }
        }

        $merged = [];
        foreach ($lines as $file => $executed) {
            ksort($executed);
            $merged[$file] = [
                'covered' => \count(array_filter($executed)),
                'total' => \count($executed),
                'lines' => $executed,
            ];
        }

        ksort($merged);

        return $merged;
    }

    /**
     * @param array<string, FileCoverage> $coverage
     */
    public static function percentage(array $coverage): float
    {
        $total = array_sum(array_column($coverage, 'total'));
        if ($total === 0) {
            return 0.0;
        }

        return array_sum(array_column($coverage, 'covered')) / $total * 100;
    }

    /**
     * Files with the worst coverage first, so the output points at what to test next.
     * Fully covered files are left out — they are not the interesting part of the report.
     *
     * @param array<string, FileCoverage> $coverage
     *
     * @return array<string, FileCoverage>
     */
    public static function leastCovered(array $coverage, int $limit): array
    {
        $incomplete = array_filter($coverage, static fn(array $s): bool => $s['covered'] < $s['total']);

        uasort($incomplete, static function (array $a, array $b): int {
            $left = $a['total'] === 0 ? 1.0 : $a['covered'] / $a['total'];
            $right = $b['total'] === 0 ? 1.0 : $b['covered'] / $b['total'];

            // Ties broken by size: 40 uncovered lines matter more than 2 at the same ratio.
            return $left <=> $right ?: ($b['total'] - $b['covered']) <=> ($a['total'] - $a['covered']);
        });

        return \array_slice($incomplete, 0, $limit, preserve_keys: true);
    }

    /**
     * @return array<string, array<int, bool>> file => line => was executed
     *
     * @throws \RuntimeException
     */
    private static function parse(string $report): array
    {
        if (!is_file($report)) {
            throw new \RuntimeException("no such report: {$report}");
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) file_get_contents($report));
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new \RuntimeException("could not parse {$report} as Clover XML");
        }

        $files = $xml->xpath('//file') ?: [];
        if ($files === []) {
            throw new \RuntimeException("{$report} contains no <file> elements — did the run produce coverage?");
        }

        $result = [];
        foreach ($files as $file) {
            $name = (string) ($file['name'] ?? '');
            if ($name === '') {
                continue;
            }

            foreach ($file->xpath('line') ?: [] as $line) {
                // Statement lines only: method and class rows duplicate their signature line.
                if ((string) ($line['type'] ?? '') !== 'stmt') {
                    continue;
                }

                $number = (int) ($line['num'] ?? 0);
                $hits = (int) ($line['count'] ?? 0);
                $result[$name][$number] = ($result[$name][$number] ?? false) || $hits > 0;
            }
        }

        return $result;
    }
}
