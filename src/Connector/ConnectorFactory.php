<?php

declare(strict_types=1);

namespace Ladybug\Connector;

use Ladybug\Config;
use Ladybug\Connector\Ext\ExtConnector;
use Ladybug\Connector\Ffi\FfiConnector;
use Ladybug\Connector\Ffi\LibraryLocator;
use Ladybug\Exception\ConnectorException;

/**
 * Picks a connector. In order of precedence:
 *
 *   1. Config::$connector — an explicit choice, which fails loudly if unavailable
 *   2. LADYBUG_CONNECTOR — same, for deployment-time overrides without touching code
 *   3. highest priority available backend: native extension, then FFI
 *
 * A registry rather than a hardcoded match, so an application can register its own
 * backend (an in-memory fake for tests, or a future remote/HTTP connector) and have the
 * same selection rules apply to it.
 */
final class ConnectorFactory
{
    /** @var array<string, class-string<Connector>> */
    private const BUILT_IN = [
        'ext' => ExtConnector::class,
        'ffi' => FfiConnector::class,
    ];

    /** @var array<string, class-string<Connector>> */
    private static array $registry = self::BUILT_IN;

    /** @var array<string, Connector> */
    private static array $instances = [];

    /** @param class-string<Connector> $class */
    public static function register(string $id, string $class): void
    {
        if (!is_a($class, Connector::class, true)) {
            throw new ConnectorException("{$class} does not implement " . Connector::class . '.');
        }

        self::$registry[$id] = $class;
        unset(self::$instances[$id]);
    }

    /**
     * Connectors are stateless with respect to databases, so one instance per backend is
     * reused for the lifetime of the process — this also means liblbug is dlopen'ed once.
     */
    public static function create(?Config $config = null): Connector
    {
        $fromEnvironment = getenv('LADYBUG_CONNECTOR');
        $requested = $config instanceof Config && $config->connector !== null
            ? $config->connector
            : (\is_string($fromEnvironment) && $fromEnvironment !== '' ? $fromEnvironment : null);

        if ($requested !== null) {
            return self::createSpecific($requested, $config);
        }

        foreach (self::availableIds() as $id) {
            try {
                return self::instantiate($id, $config);
            } catch (ConnectorException) {
                // isAvailable() said yes but construction failed (e.g. a library that is
                // present but unloadable). Fall through to the next candidate.
                continue;
            }
        }

        throw new ConnectorException(
            "No LadybugDB connector is available.\n" . self::diagnosticsText(),
        );
    }

    private static function createSpecific(string $id, ?Config $config): Connector
    {
        if (!isset(self::$registry[$id])) {
            throw new ConnectorException(\sprintf(
                'Unknown connector "%s". Registered: %s.',
                $id,
                implode(', ', array_keys(self::$registry)),
            ));
        }

        try {
            $class = self::$registry[$id];
            if (!$class::isAvailable()) {
                throw new ConnectorException(self::diagnostics()[$id]['detail']);
            }

            return self::instantiate($id, $config);
        } catch (ConnectorException $e) {
            throw new ConnectorException("Connector \"{$id}\" was requested explicitly but is not usable: {$e->getMessage()}\n"
            . self::diagnosticsText(), $e->getCode(), previous: $e);
        }
    }

    private static function instantiate(string $id, ?Config $config): Connector
    {
        $libraryPath = $config?->libraryPath;

        // A caller-supplied library path is specific to one FFI binding, so such an
        // instance is not shared through the cache.
        if ($id === 'ffi' && $libraryPath !== null) {
            return new FfiConnector($libraryPath);
        }

        return self::$instances[$id] ??= new (self::$registry[$id])();
    }

    /** @return list<string> available backends, best first */
    public static function availableIds(): array
    {
        $available = [];
        foreach (self::$registry as $id => $class) {
            if ($class::isAvailable()) {
                $available[$id] = $class::priority();
            }
        }

        arsort($available);

        return array_keys($available);
    }

    /**
     * Why each backend is or is not usable. Worth surfacing in a health check — most
     * deployment problems here are "the .so is not where you think it is".
     *
     * @return array<string, array{available: bool, priority: int, detail: string}>
     */
    public static function diagnostics(): array
    {
        $report = [];
        foreach (self::$registry as $id => $class) {
            $available = $class::isAvailable();
            $report[$id] = [
                'available' => $available,
                'priority' => $class::priority(),
                'detail' => match (true) {
                    $available => 'ready',
                    $id === 'ext' => \extension_loaded(ExtConnector::EXTENSION)
                        ? 'ext-ladybug is loaded but reports a different ABI version than ' . ExtConnector::ABI_VERSION
                        : 'ext-ladybug is not loaded',
                    $id === 'ffi' => !FfiConnector::ffiIsUsable()
                        ? \sprintf('ext-ffi is unusable here (loaded=%s, ffi.enable=%s)', var_export(\extension_loaded('FFI'), true), var_export(\ini_get('ffi.enable'), true))
                        : 'liblbug not found; tried: ' . implode(', ', LibraryLocator::candidates()),
                    default => 'unavailable',
                },
            ];
        }

        return $report;
    }

    private static function diagnosticsText(): string
    {
        $lines = [];
        foreach (self::diagnostics() as $id => $info) {
            $lines[] = \sprintf('  %-4s %s — %s', $id, $info['available'] ? '[ok]  ' : '[skip]', $info['detail']);
        }

        return implode("\n", $lines);
    }

    /** Drops cached instances and any custom registration, leaving only the built-ins. */
    public static function reset(): void
    {
        self::$instances = [];
        self::$registry = self::BUILT_IN;
    }
}
