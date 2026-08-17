<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit;

use Ladybug\Tools\CoverageGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The gate decides whether CI passes, so its merging has to be right: the suite runs once
 * per backend and each run reports the other connector as dead code.
 */
#[CoversClass(CoverageGate::class)]
final class CoverageGateTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }
    }

    public function testCoveredLinesAreUnionedAcrossReports(): void
    {
        // The shape of the real thing: each backend covers its own connector and neither
        // covers the other, so only the union describes what the suite actually exercises.
        $ffi = $this->clover(['Ffi.php' => [1 => 3, 2 => 1], 'Ext.php' => [1 => 0, 2 => 0]]);
        $ext = $this->clover(['Ffi.php' => [1 => 0, 2 => 0], 'Ext.php' => [1 => 7, 2 => 2]]);

        $merged = CoverageGate::merge([$ffi, $ext]);

        self::assertSame(2, $merged['Ffi.php']['covered']);
        self::assertSame(2, $merged['Ext.php']['covered']);
        self::assertSame(100.0, CoverageGate::percentage($merged));
    }

    public function testALaterReportCannotUncoverALine(): void
    {
        $first = $this->clover(['A.php' => [1 => 5]]);
        $second = $this->clover(['A.php' => [1 => 0]]);

        self::assertSame(1, CoverageGate::merge([$first, $second])['A.php']['covered']);
        // Order must not matter, or the percentage depends on which backend ran last.
        self::assertSame(1, CoverageGate::merge([$second, $first])['A.php']['covered']);
    }

    public function testUncoveredLinesLowerThePercentage(): void
    {
        $report = $this->clover(['A.php' => [1 => 1, 2 => 1, 3 => 0, 4 => 0]]);

        self::assertSame(50.0, CoverageGate::percentage(CoverageGate::merge([$report])));
    }

    public function testNonStatementLinesAreIgnored(): void
    {
        // Clover emits a row per method as well; counting those would double-count signatures.
        $report = $this->clover(['A.php' => [10 => 1]], methods: [10 => 1]);

        self::assertSame(1, CoverageGate::merge([$report])['A.php']['total']);
    }

    public function testLeastCoveredSkipsFullyCoveredFilesAndOrdersByRatio(): void
    {
        $report = $this->clover([
            'Full.php' => [1 => 1, 2 => 1],
            'Half.php' => [1 => 1, 2 => 0],
            'Empty.php' => [1 => 0, 2 => 0, 3 => 0],
        ]);

        $worst = CoverageGate::leastCovered(CoverageGate::merge([$report]), 10);

        self::assertSame(['Empty.php', 'Half.php'], array_keys($worst));
    }

    public function testLeastCoveredBreaksTiesByNumberOfUncoveredLines(): void
    {
        $report = $this->clover([
            'Small.php' => [1 => 1, 2 => 0],
            'Big.php' => [1 => 1, 2 => 1, 3 => 0, 4 => 0],
        ]);

        // Both sit at 50%, but Big.php has more untested code behind it.
        self::assertSame(['Big.php', 'Small.php'], array_keys(CoverageGate::leastCovered(CoverageGate::merge([$report]), 10)));
    }

    public function testAMissingReportIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no such report');

        CoverageGate::merge([sys_get_temp_dir() . '/ladybug-missing-' . bin2hex(random_bytes(4)) . '.xml']);
    }

    public function testAReportWithoutFilesIsAnError(): void
    {
        // A run that produced no coverage at all must fail loudly rather than report 0%
        // and let the threshold decide — the usual cause is a missing coverage driver.
        $path = $this->write('<?xml version="1.0"?><coverage><project/></coverage>');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contains no <file> elements');

        CoverageGate::merge([$path]);
    }

    public function testUnparsableXmlIsAnError(): void
    {
        $path = $this->write('this is not xml');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not parse');

        CoverageGate::merge([$path]);
    }

    /**
     * @param array<string, array<int, int>> $files       file => line => hits
     * @param array<int, int>                $methods     line => hits, emitted as type="method"
     */
    private function clover(array $files, array $methods = []): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<coverage clover="3.0"><project>';
        foreach ($files as $name => $lines) {
            $xml .= \sprintf('<file name="%s">', $name);
            foreach ($methods as $line => $hits) {
                $xml .= \sprintf('<line num="%d" type="method" count="%d"/>', $line, $hits);
            }

            foreach ($lines as $line => $hits) {
                $xml .= \sprintf('<line num="%d" type="stmt" count="%d"/>', $line, $hits);
            }

            $xml .= '</file>';
        }

        return $this->write($xml . '</project></coverage>');
    }

    private function write(string $contents): string
    {
        $path = sys_get_temp_dir() . '/ladybug-clover-' . bin2hex(random_bytes(6)) . '.xml';
        file_put_contents($path, $contents);
        $this->written[] = $path;

        return $path;
    }
}
