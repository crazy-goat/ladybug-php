<?php

declare(strict_types=1);

namespace Ladybug\Connector\Ffi;

use Ladybug\Exception\ConnectorException;

/**
 * Finds liblbug on disk. Search order, first hit wins:
 *
 *   1. explicit path passed in Config
 *   2. LADYBUG_LIBRARY environment variable
 *   3. lib/ next to this package (where the release tarball unpacks)
 *   4. platform library directories
 *   5. the bare soname, letting the dynamic loader resolve it
 */
final class LibraryLocator
{
    private const FILENAMES = [
        'Darwin' => ['liblbug.dylib', 'liblbug.0.dylib'],
        'Windows' => ['lbug_shared.dll', 'lbug.dll'],
        'Linux' => ['liblbug.so', 'liblbug.so.0'],
        'BSD' => ['liblbug.so'],
        'Solaris' => ['liblbug.so'],
        'Unknown' => ['liblbug.so'],
    ];

    private const DIRECTORIES = [
        'Darwin' => ['/opt/homebrew/lib', '/usr/local/lib', '/usr/lib'],
        'Windows' => [],
        'Linux' => ['/usr/local/lib', '/usr/lib', '/usr/lib/x86_64-linux-gnu', '/usr/lib/aarch64-linux-gnu', '/lib'],
        'BSD' => ['/usr/local/lib', '/usr/lib'],
        'Solaris' => ['/usr/local/lib', '/usr/lib'],
        'Unknown' => ['/usr/local/lib', '/usr/lib'],
    ];

    /** @return list<string> every path that will be tried, in order */
    public static function candidates(?string $explicit = null): array
    {
        $family = PHP_OS_FAMILY;
        $filenames = self::FILENAMES[$family];
        $packageLib = \dirname(__DIR__, 3) . '/lib';

        $paths = [];
        if ($explicit !== null && $explicit !== '') {
            $paths[] = $explicit;
        }

        $fromEnv = getenv('LADYBUG_LIBRARY');
        if (\is_string($fromEnv) && $fromEnv !== '') {
            $paths[] = $fromEnv;
        }

        foreach ([$packageLib, ...self::DIRECTORIES[$family]] as $dir) {
            foreach ($filenames as $filename) {
                $paths[] = $dir . '/' . $filename;
            }
        }

        // Last resort: no directory, so the platform loader applies its own search path.
        $paths[] = $filenames[0];

        return array_values(array_unique($paths));
    }

    public static function find(?string $explicit = null): ?string
    {
        foreach (self::candidates($explicit) as $path) {
            if (!str_contains($path, '/') && !str_contains($path, '\\')) {
                return $path; // bare soname — we cannot stat it, hand it to the loader
            }

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function findOrFail(?string $explicit = null): string
    {
        $path = self::find($explicit);
        if ($path === null) {
            throw new ConnectorException(
                "Could not locate liblbug. Set LADYBUG_LIBRARY, or pass libraryPath in Config. Tried:\n  "
                . implode("\n  ", self::candidates($explicit)),
            );
        }

        return $path;
    }
}
