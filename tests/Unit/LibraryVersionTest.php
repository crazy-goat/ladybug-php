<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit;

use Ladybug\Connector\LibraryVersion;
use Ladybug\Exception\IncompatibleLibraryException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LibraryVersion::class)]
final class LibraryVersionTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(LibraryVersion::OVERRIDE_ENV);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function versions(): iterable
    {
        yield 'release' => ['0.19.1', '0.19'];
        yield 'no patch' => ['0.19', '0.19'];
        yield 'pre-release' => ['0.20.0-rc.1', '0.20'];
        yield 'build metadata' => ['1.0.0+build.5', '1.0'];
        yield 'padded' => ["  0.19.1\n", '0.19'];
        yield 'garbage' => ['not-a-version', ''];
        yield 'major only' => ['1', ''];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('versions')]
    public function testSeriesTakesTheMajorMinorPrefix(string $version, string $expected): void
    {
        self::assertSame($expected, LibraryVersion::series($version));
    }

    public function testTheVerifiedVersionIsSupported(): void
    {
        self::assertTrue(LibraryVersion::isSupported(LibraryVersion::VERIFIED));
    }

    public function testAPatchReleaseInTheSameSeriesIsSupported(): void
    {
        // Same series means the same struct layouts, which is all the connectors depend on.
        self::assertTrue(LibraryVersion::isSupported('0.19.999'));
    }

    public function testANewMinorIsNotSupportedUntilItIsVerified(): void
    {
        self::assertFalse(LibraryVersion::isSupported('0.20.0'));
        self::assertFalse(LibraryVersion::isSupported('0.18.2'));
        self::assertFalse(LibraryVersion::isSupported('1.0.0'));
    }

    public function testAnUnreadableVersionIsNotSupported(): void
    {
        // A NULL from lbug_get_version() must not be read as "probably fine".
        self::assertFalse(LibraryVersion::isSupported(''));
    }

    public function testAssertSupportedPassesForTheVerifiedVersion(): void
    {
        LibraryVersion::assertSupported(LibraryVersion::VERIFIED, 'FFI');

        $this->expectNotToPerformAssertions();
    }

    public function testAssertSupportedThrowsWithAnActionableMessage(): void
    {
        try {
            LibraryVersion::assertSupported('0.20.0', 'FFI');
            self::fail('Expected an IncompatibleLibraryException.');
        } catch (IncompatibleLibraryException $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('0.20.0', $message);
        self::assertStringContainsString('FFI', $message);
        self::assertStringContainsString('0.19.x', $message);
        self::assertStringContainsString(LibraryVersion::OVERRIDE_ENV, $message);
    }

    public function testAnUnreadableVersionIsNamedInTheMessage(): void
    {
        self::assertStringContainsString('(unreadable)', LibraryVersion::message('', 'FFI'));
    }

    public function testTheOverrideDowngradesTheFailureToAWarning(): void
    {
        putenv(LibraryVersion::OVERRIDE_ENV . '=1');

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        }, E_USER_WARNING);

        try {
            LibraryVersion::assertSupported('0.20.0', 'FFI');
        } finally {
            restore_error_handler();
        }

        self::assertIsString($warning);
        self::assertStringContainsString('0.20.0', $warning);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function overrideValues(): iterable
    {
        yield 'one' => ['1', true];
        yield 'yes' => ['yes', true];
        yield 'zero' => ['0', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('overrideValues')]
    public function testOverrideIsOffUnlessSetToSomethingTruthy(string $value, bool $expected): void
    {
        putenv(LibraryVersion::OVERRIDE_ENV . '=' . $value);

        self::assertSame($expected, LibraryVersion::overridden());
    }

    public function testOverrideIsOffWhenUnset(): void
    {
        putenv(LibraryVersion::OVERRIDE_ENV);

        self::assertFalse(LibraryVersion::overridden());
    }
}
