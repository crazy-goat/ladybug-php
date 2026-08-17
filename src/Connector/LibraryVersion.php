<?php

declare(strict_types=1);

namespace Ladybug\Connector;

use Ladybug\Exception\IncompatibleLibraryException;

/**
 * Which liblbug releases this package may talk to.
 *
 * liblbug is pre-1.0 and makes no ABI promise across minor releases, while both of our
 * connectors depend on its exact struct layout — lbug_system_config in particular, whose
 * fields Cdef spells out one by one. A 0.20 library loaded against a 0.19 description
 * would not fail: lbug_database_init() would read a config struct that means something
 * else. So the version is checked once, before the first call that touches a struct.
 *
 * Patch releases within a series are accepted; a new minor has to be verified by hand
 * (regenerate lib/, run CdefMatchesHeaderTest, bump the constants here and in
 * ext/php_ladybug.h) before it is allowed through.
 */
final class LibraryVersion
{
    /**
     * The liblbug release this package is developed and tested against — the one
     * tools/fetch-liblbug.sh downloads by default.
     */
    public const VERIFIED = '0.19.1';

    /**
     * The major.minor series assumed to keep VERIFIED's ABI.
     *
     * @var list<string>
     */
    public const SUPPORTED_SERIES = ['0.19'];

    /**
     * Set to 1 to downgrade the check to a warning. For trying an unreleased or newer
     * liblbug during development — never for production, where a layout mismatch is
     * memory corruption rather than an exception.
     */
    public const OVERRIDE_ENV = 'LADYBUG_ALLOW_ANY_LIBRARY';

    /**
     * The major.minor part of a version string, ignoring any suffix such as
     * "0.20.0-rc.1" or trailing build metadata.
     */
    public static function series(string $version): string
    {
        if (preg_match('/^(\d+)\.(\d+)/', trim($version), $m) !== 1) {
            return '';
        }

        return "{$m[1]}.{$m[2]}";
    }

    public static function isSupported(string $version): bool
    {
        return \in_array(self::series($version), self::SUPPORTED_SERIES, true);
    }

    public static function overridden(): bool
    {
        $value = getenv(self::OVERRIDE_ENV);

        return !\in_array($value, [false, '', '0'], true);
    }

    /**
     * @throws IncompatibleLibraryException unless $runtime is a supported release
     */
    public static function assertSupported(string $runtime, string $connector): void
    {
        if (self::isSupported($runtime)) {
            return;
        }

        $message = self::message($runtime, $connector);

        if (self::overridden()) {
            trigger_error($message, E_USER_WARNING);

            return;
        }

        throw new IncompatibleLibraryException($message);
    }

    public static function message(string $runtime, string $connector): string
    {
        $supported = implode(', ', array_map(static fn(string $s): string => "{$s}.x", self::SUPPORTED_SERIES));
        $found = $runtime === '' ? '(unreadable)' : $runtime;

        return \sprintf(
            'liblbug %s is not supported by the %s connector, which needs %s (verified against %s). '
            . 'Struct layouts differ between liblbug minor releases, so continuing would risk wrong '
            . 'results or a crash rather than an error. Install a supported liblbug (see '
            . 'tools/fetch-liblbug.sh), or set %s=1 to downgrade this to a warning at your own risk.',
            $found,
            $connector,
            $supported,
            self::VERIFIED,
            self::OVERRIDE_ENV,
        );
    }
}
