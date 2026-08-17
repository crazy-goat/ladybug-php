<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit\Ffi;

use Ladybug\Connector\Ffi\LibraryLocator;
use Ladybug\Exception\ConnectorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LibraryLocator::class)]
final class LibraryLocatorTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('LADYBUG_LIBRARY');
    }

    public function testAnExplicitPathIsTriedFirst(): void
    {
        $candidates = LibraryLocator::candidates('/custom/liblbug.so');

        self::assertSame('/custom/liblbug.so', $candidates[0]);
    }

    public function testTheEnvironmentVariableIsTriedBeforeSystemDirectories(): void
    {
        putenv('LADYBUG_LIBRARY=/from/env/liblbug.so');
        $candidates = LibraryLocator::candidates();

        self::assertSame('/from/env/liblbug.so', $candidates[0]);
    }

    public function testThePackageLibDirectoryIsSearched(): void
    {
        $candidates = LibraryLocator::candidates();
        $packageLib = \dirname(__DIR__, 3) . '/lib';

        self::assertNotEmpty(array_filter(
            $candidates,
            static fn(string $path): bool => str_starts_with($path, $packageLib),
        ), 'the release tarball unpacks into lib/, so it must be searched');
    }

    public function testTheLastCandidateIsABareSonameForTheDynamicLoader(): void
    {
        $candidates = LibraryLocator::candidates();
        self::assertNotEmpty($candidates);
        $last = $candidates[\count($candidates) - 1];

        self::assertStringNotContainsString('/', $last);
        self::assertStringStartsWith('liblbug', $last);
    }

    public function testCandidatesAreDeduplicated(): void
    {
        $candidates = LibraryLocator::candidates();

        self::assertSame(array_values(array_unique($candidates)), $candidates);
    }

    public function testAnExistingFileIsFound(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'liblbug');
        if ($file === false) {
            self::fail('could not create a temporary file');
        }

        try {
            self::assertSame($file, LibraryLocator::find($file));
        } finally {
            unlink($file);
        }
    }

    public function testANonExistentExplicitPathIsSkippedRatherThanReturned(): void
    {
        self::assertNotSame('/definitely/not/here/liblbug.so', LibraryLocator::find('/definitely/not/here/liblbug.so'));
    }

    public function testFailureListsEveryPathTried(): void
    {
        // The message is the whole value of this failure mode: "not found" without the
        // search path is the single most common deployment complaint for FFI bindings.
        $exception = null;
        try {
            LibraryLocator::findOrFail('/definitely/not/here/liblbug.so');
        } catch (ConnectorException $e) {
            $exception = $e;
        }

        if (!$exception instanceof ConnectorException) {
            self::markTestSkipped('liblbug is installed here, so the failure path cannot be reached');
        }

        self::assertStringContainsString('LADYBUG_LIBRARY', $exception->getMessage());
        self::assertStringContainsString('/definitely/not/here/liblbug.so', $exception->getMessage());
    }
}
