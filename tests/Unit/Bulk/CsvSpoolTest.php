<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit\Bulk;

use Ladybug\Bulk\CsvSpool;
use Ladybug\Exception\TypeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The CSV dialect is a contract with liblbug, and getting it wrong does not raise an error —
 * it stores a different value than the caller passed. So the encoding is pinned here, field by
 * field, and BulkCopyTest checks the same values survive a real round trip.
 */
#[CoversClass(CsvSpool::class)]
final class CsvSpoolTest extends TestCase
{
    private ?CsvSpool $spool = null;

    protected function tearDown(): void
    {
        $this->spool?->discard();
    }

    /** @return iterable<string, array{list<mixed>, string}> */
    public static function rows(): iterable
    {
        yield 'null is an empty field' => [[null], "\n"];
        yield 'booleans are words' => [[true, false], "true,false\n"];
        yield 'integers' => [[0, -42, PHP_INT_MAX], '0,-42,' . PHP_INT_MAX . "\n"];
        yield 'plain string is not quoted' => [['Ada'], "Ada\n"];
        yield 'a comma forces quoting' => [['Smith, John'], "\"Smith, John\"\n"];
        yield 'quotes are doubled' => [['say "hi"'], "\"say \"\"hi\"\"\"\n"];
        yield 'a newline is quoted' => [["two\nlines"], "\"two\nlines\"\n"];
        yield 'a date drops a zero time' => [[new \DateTimeImmutable('1815-12-10 00:00:00')], "1815-12-10\n"];
        yield 'a datetime keeps its time' => [
            [new \DateTimeImmutable('2024-05-06 07:08:09.123456')],
            "2024-05-06 07:08:09.123456\n",
        ];
        yield 'mixed row' => [[1, 'a', null, true], "1,a,,true\n"];
    }

    /** @param list<mixed> $values */
    #[DataProvider('rows')]
    public function testEncoding(array $values, string $expected): void
    {
        self::assertSame($expected, $this->write($values));
    }

    public function testAnEmptyStringIsRefused(): void
    {
        // liblbug reads both an empty field and "" as NULL, with no option to separate them,
        // so writing '' would silently change the value. Declining is the honest option.
        $this->expectException(TypeException::class);
        $this->expectExceptionMessageMatches('/empty string/');

        $this->write(['']);
    }

    /** @return iterable<string, array{float}> */
    public static function floats(): iterable
    {
        yield 'simple' => [1.5];
        yield 'negative' => [-0.5];
        yield 'whole' => [2.0];
        yield 'tiny' => [1.0E-300];
        yield 'huge' => [1.0E+300];
        yield 'many digits' => [0.1 + 0.2];
        yield 'pi-ish' => [3.141592653589793];
    }

    #[DataProvider('floats')]
    public function testFloatsSurviveTheTextRoundTrip(float $value): void
    {
        // A float written with too few digits comes back as a different number, silently.
        $written = trim($this->write([$value]));

        self::assertSame($value, (float) $written, "wrote {$written}");
    }

    public function testAWholeFloatKeepsADecimalPoint(): void
    {
        // "2" in a DOUBLE column is fine, but keeping the point makes the intent obvious in a
        // file someone may end up reading during an incident.
        self::assertSame("2.0\n", $this->write([2.0]));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function unsupported(): iterable
    {
        yield 'array' => [[1, 2], 'array'];
        yield 'object' => [new \stdClass(), 'stdClass'];
        yield 'nan' => [NAN, 'NAN'];
        yield 'infinity' => [INF, 'INF'];
    }

    #[DataProvider('unsupported')]
    public function testUnsupportedValuesAreRejectedWithTheTypeNamed(mixed $value, string $needle): void
    {
        $this->expectException(TypeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/');

        $this->write([$value]);
    }

    public function testStringableIsAccepted(): void
    {
        $value = new class implements \Stringable {
            public function __toString(): string
            {
                return 'from an object';
            }
        };

        self::assertSame("from an object\n", $this->write([$value]));
    }

    public function testSerialReadIsOnlyRequestedWhenAValueCarriesANewline(): void
    {
        // Asking for a serial read costs throughput on every copy, so it has to be conditional.
        $spool = CsvSpool::create();
        $this->spool = $spool;
        $spool->writeRow(['no newlines here', 1]);
        self::assertFalse($spool->needsSerialRead());

        $spool->writeRow(["now\nthere is", 2]);
        self::assertTrue($spool->needsSerialRead());
    }

    public function testCarriageReturnAlsoRequiresASerialRead(): void
    {
        $spool = CsvSpool::create();
        $this->spool = $spool;
        $spool->writeRow(["old\rmac"]);

        self::assertTrue($spool->needsSerialRead());
    }

    public function testRowsAreCountedAndTheFileIsRemovedOnDiscard(): void
    {
        $spool = CsvSpool::create();
        $spool->writeRow([1]);
        $spool->writeRow([2]);

        self::assertSame(2, $spool->rows());
        self::assertFileExists($spool->path);

        $spool->discard();

        self::assertFileDoesNotExist($spool->path, 'a copy file left behind leaks data into /tmp');
    }

    public function testTheFileIsNotReadableByOtherUsers(): void
    {
        // It holds whatever the caller is loading, which may be personal data, in a shared
        // directory until the copy finishes.
        $spool = CsvSpool::create();
        $this->spool = $spool;
        self::assertSame('0600', substr(\sprintf('%o', fileperms($spool->path)), -4));
    }

    /** @param list<mixed> $values */
    private function write(array $values): string
    {
        $spool = CsvSpool::create();
        $this->spool = $spool;
        $spool->writeRow($values);
        $spool->close();

        return (string) file_get_contents($spool->path);
    }
}
