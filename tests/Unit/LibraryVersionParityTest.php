<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit;

use Ladybug\Connector\LibraryVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The supported liblbug version is stated in four places that cannot share a constant: the
 * PHP class, the extension's C header, the download script and the CI matrix. If they drift
 * apart, one connector rejects a library the other accepts — or worse, CI installs a version
 * nothing was verified against and the whole check becomes decorative.
 *
 * @see LibraryVersion
 */
#[CoversClass(LibraryVersion::class)]
final class LibraryVersionParityTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testTheExtensionHeaderAgreesOnTheVerifiedVersion(): void
    {
        self::assertSame(
            LibraryVersion::VERIFIED,
            $this->define('LADYBUG_LIBLBUG_VERIFIED'),
            'ext/php_ladybug.h was built against a different liblbug than LibraryVersion::VERIFIED names.',
        );
    }

    public function testTheExtensionHeaderAgreesOnTheSupportedSeries(): void
    {
        self::assertSame(
            implode(',', LibraryVersion::SUPPORTED_SERIES),
            $this->define('LADYBUG_LIBLBUG_SERIES'),
            'The extension and the FFI connector would accept different liblbug releases.',
        );
    }

    public function testTheExtensionHeaderAgreesOnTheOverrideVariable(): void
    {
        // One escape hatch for both connectors, or the documented one only works for one.
        self::assertSame(LibraryVersion::OVERRIDE_ENV, $this->define('LADYBUG_ALLOW_ANY_LIBRARY_ENV'));
    }

    public function testTheDownloadScriptDefaultsToTheVerifiedVersion(): void
    {
        $script = $this->read('tools/fetch-liblbug.sh');

        if (preg_match('/^VERSION="\$\{1:-([^}"]+)\}"/m', $script, $m) !== 1) {
            self::fail('Could not find the default version in tools/fetch-liblbug.sh.');
        }

        self::assertSame(LibraryVersion::VERIFIED, $m[1]);
    }

    public function testCiInstallsTheVerifiedVersion(): void
    {
        $workflow = $this->read('.github/workflows/ci.yml');

        if (preg_match("/LIBLBUG_VERSION:\s*'([^']+)'/", $workflow, $m) !== 1) {
            self::fail('Could not find LIBLBUG_VERSION in the CI workflow.');
        }

        self::assertSame(LibraryVersion::VERIFIED, $m[1]);
    }

    public function testTheSupportedSeriesContainsTheVerifiedVersion(): void
    {
        self::assertContains(LibraryVersion::series(LibraryVersion::VERIFIED), LibraryVersion::SUPPORTED_SERIES);
    }

    private function define(string $name): string
    {
        $header = $this->read('ext/php_ladybug.h');

        if (preg_match('/^#define\s+' . preg_quote($name, '/') . '\s+"([^"]*)"/m', $header, $m) !== 1) {
            self::fail("ext/php_ladybug.h no longer defines {$name}.");
        }

        return $m[1];
    }

    private function read(string $path): string
    {
        $full = self::ROOT . '/' . $path;
        $contents = is_file($full) ? file_get_contents($full) : false;

        self::assertIsString($contents, "Could not read {$path}.");

        return $contents;
    }
}
