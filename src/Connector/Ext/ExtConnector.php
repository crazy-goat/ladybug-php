<?php

declare(strict_types=1);

namespace Ladybug\Connector\Ext;

use Ladybug\Config;
use Ladybug\Connector\Connector;
use Ladybug\Connector\Handle;
use Ladybug\Exception\ConnectionException;
use Ladybug\Exception\ConnectorException;
use Ladybug\Exception\DatabaseException;
use Ladybug\Exception\QueryException;
use Ladybug\Ext\Connection as ExtConnection;
use Ladybug\Ext\Database as ExtDatabase;
use Ladybug\Ext\DatabaseError;
use Ladybug\Ext\Exception as ExtException;
use Ladybug\Ext\QueryError;
use Ladybug\Ext\Result as ExtResult;
use Ladybug\Ext\Statement as ExtStatement;
use Ladybug\Type\DataType;

/**
 * Adapter over the native ladybug extension.
 *
 * The extension deliberately exposes a flat procedural ABI (`ladybug_*` functions taking
 * and returning opaque `Ladybug\Ext\*` objects) rather than implementing this interface in
 * C. That keeps the C code free of any knowledge of this package — the extension can ship
 * and version independently — and confines the mapping to this one file.
 *
 * Two things are translated here:
 *
 *  - handles: ExtHandle wraps the extension's opaque objects and adds the kind check
 *  - exceptions: the extension throws Ladybug\Ext\{Exception,DatabaseError,QueryError},
 *    rewrapped into this library's own hierarchy so callers catch identical types no
 *    matter which backend is running
 *
 * Contract the extension must satisfy (all errors are thrown, never returned):
 *
 *   ladybug_abi_version(): int
 *   ladybug_version(): string
 *   ladybug_database_open(string $path, array $config): Ladybug\Ext\Database
 *   ladybug_database_close(Ladybug\Ext\Database $db): void
 *   ladybug_connect(Ladybug\Ext\Database $db): Ladybug\Ext\Connection
 *   ladybug_connection_close(Ladybug\Ext\Connection $conn): void
 *   ladybug_connection_set_max_threads(Ladybug\Ext\Connection $conn, int $threads): void
 *   ladybug_connection_set_query_timeout(Ladybug\Ext\Connection $conn, int $timeoutMs): void
 *   ladybug_connection_interrupt(Ladybug\Ext\Connection $conn): void
 *   ladybug_query(Ladybug\Ext\Connection $conn, string $cypher): Ladybug\Ext\Result
 *   ladybug_prepare(Ladybug\Ext\Connection $conn, string $cypher): Ladybug\Ext\Statement
 *   ladybug_execute(Ladybug\Ext\Connection $conn, Ladybug\Ext\Statement $stmt, array $params): Ladybug\Ext\Result
 *   ladybug_statement_close(Ladybug\Ext\Statement $stmt): void
 *   ladybug_result_column_names(Ladybug\Ext\Result $res): list<string>
 *   ladybug_result_column_types(Ladybug\Ext\Result $res): list<int>   // lbug_data_type_id
 *   ladybug_result_row_count(Ladybug\Ext\Result $res): int
 *   ladybug_result_fetch(Ladybug\Ext\Result $res): ?list<mixed>
 *   ladybug_result_rewind(Ladybug\Ext\Result $res): void
 *   ladybug_result_next_set(Ladybug\Ext\Result $res): ?Ladybug\Ext\Result
 *   ladybug_result_summary(Ladybug\Ext\Result $res): array{compilingTimeMs: float, executionTimeMs: float}
 *   ladybug_result_close(Ladybug\Ext\Result $res): void
 */
final class ExtConnector implements Connector
{
    public const EXTENSION = 'ladybug';

    /** Bumped when the ABI above changes incompatibly; checked against the extension. */
    public const ABI_VERSION = 1;

    public function __construct()
    {
        if (!self::isAvailable()) {
            throw new ConnectorException(\sprintf(
                'The native connector requires ext-%s%s.',
                self::EXTENSION,
                \extension_loaded(self::EXTENSION) ? ' with ABI version ' . self::ABI_VERSION : ' (not loaded)',
            ));
        }
    }

    public static function isAvailable(): bool
    {
        return \extension_loaded(self::EXTENSION)
            && \function_exists('ladybug_abi_version')
            && ladybug_abi_version() === self::ABI_VERSION;
    }

    public function id(): string
    {
        return 'ext';
    }

    public static function priority(): int
    {
        return 100;
    }

    public function libraryVersion(): string
    {
        return ladybug_version();
    }

    // -- database ---------------------------------------------------------------------

    public function openDatabase(string $path, Config $config): Handle
    {
        $settings = array_filter([
            'bufferPoolSize' => $config->bufferPoolSize,
            'maxThreads' => $config->maxThreads,
            'compression' => $config->compression,
            'readOnly' => $config->readOnly,
            'maxDbSize' => $config->maxDbSize,
            'autoCheckpoint' => $config->autoCheckpoint,
            'checkpointThreshold' => $config->checkpointThreshold,
        ], static fn(mixed $value): bool => $value !== null);

        return new ExtHandle('database', $this->guard(
            static fn(): ExtDatabase => ladybug_database_open($path, $settings),
            databaseContext: \sprintf(
                'Could not open the database at "%s". Check the path is writable and not held by another process in read-write mode.',
                $path,
            ),
        ));
    }

    public function closeDatabase(Handle $database): void
    {
        $handle = $this->handle($database, 'database');
        if (!$handle->isOpen()) {
            return;
        }

        // The native object has to be taken before markClosed(), which makes the handle
        // refuse to hand it out — otherwise the C resource would never be released.
        $native = $this->native($handle, 'database', ExtDatabase::class);
        if ($handle->markClosed()) {
            ladybug_database_close($native);
        }
    }

    // -- connection -------------------------------------------------------------------

    public function connect(Handle $database): Handle
    {
        $handle = $this->handle($database, 'database');
        $native = $this->native($handle, 'database', ExtDatabase::class);

        return new ExtHandle('connection', $this->guard(
            static fn(): ExtConnection => ladybug_connect($native),
            databaseContext: 'Could not open a connection to the database.',
        ), $handle);
    }

    public function closeConnection(Handle $connection): void
    {
        $handle = $this->handle($connection, 'connection');
        if (!$handle->isOpen()) {
            return;
        }

        // The native object has to be taken before markClosed(), which makes the handle
        // refuse to hand it out — otherwise the C resource would never be released.
        $native = $this->native($handle, 'connection', ExtConnection::class);
        if ($handle->markClosed()) {
            ladybug_connection_close($native);
        }
    }

    public function setMaxThreads(Handle $connection, int $threads): void
    {
        $native = $this->native($connection, 'connection', ExtConnection::class);

        $this->guard(static function () use ($native, $threads): null {
            ladybug_connection_set_max_threads($native, $threads);

            return null;
        });
    }

    public function setQueryTimeout(Handle $connection, int $timeoutMs): void
    {
        $native = $this->native($connection, 'connection', ExtConnection::class);

        $this->guard(static function () use ($native, $timeoutMs): null {
            ladybug_connection_set_query_timeout($native, $timeoutMs);

            return null;
        });
    }

    public function interrupt(Handle $connection): void
    {
        $native = $this->native($connection, 'connection', ExtConnection::class);

        $this->guard(static function () use ($native): null {
            ladybug_connection_interrupt($native);

            return null;
        });
    }

    // -- query ------------------------------------------------------------------------

    public function query(Handle $connection, string $cypher): Handle
    {
        $handle = $this->handle($connection, 'connection');
        $native = $this->native($handle, 'connection', ExtConnection::class);

        return new ExtHandle('result', $this->guard(
            static fn(): ExtResult => ladybug_query($native, $cypher),
            cypher: $cypher,
        ), $handle);
    }

    public function prepare(Handle $connection, string $cypher): Handle
    {
        $handle = $this->handle($connection, 'connection');
        $native = $this->native($handle, 'connection', ExtConnection::class);

        return new ExtHandle('statement', $this->guard(
            static fn(): ExtStatement => ladybug_prepare($native, $cypher),
            cypher: $cypher,
        ), $handle);
    }

    /** @param array<string, mixed> $parameters */
    public function execute(Handle $connection, Handle $statement, array $parameters = []): Handle
    {
        $connectionHandle = $this->handle($connection, 'connection');
        $nativeConnection = $this->native($connectionHandle, 'connection', ExtConnection::class);
        $nativeStatement = $this->native($statement, 'statement', ExtStatement::class);

        return new ExtHandle('result', $this->guard(
            static fn(): ExtResult => ladybug_execute($nativeConnection, $nativeStatement, $parameters),
            parameters: $parameters,
        ), $connectionHandle);
    }

    public function closeStatement(Handle $statement): void
    {
        $handle = $this->handle($statement, 'statement');
        if (!$handle->isOpen()) {
            return;
        }

        // The native object has to be taken before markClosed(), which makes the handle
        // refuse to hand it out — otherwise the C resource would never be released.
        $native = $this->native($handle, 'statement', ExtStatement::class);
        if ($handle->markClosed()) {
            ladybug_statement_close($native);
        }
    }

    // -- result -----------------------------------------------------------------------

    /** @return list<string> */
    public function columnNames(Handle $result): array
    {
        $native = $this->native($result, 'result', ExtResult::class);

        return $this->guard(static fn(): array => ladybug_result_column_names($native));
    }

    /** @return list<DataType> */
    public function columnTypes(Handle $result): array
    {
        $native = $this->native($result, 'result', ExtResult::class);
        $ids = $this->guard(static fn(): array => ladybug_result_column_types($native));

        // As in the FFI connector: an id this client has no case for is a type a loaded
        // extension introduced, not a reason to fail the query.
        return array_values(array_map(
            static fn(int $id): DataType => DataType::tryFrom($id) ?? DataType::Unknown,
            $ids,
        ));
    }

    public function rowCount(Handle $result): int
    {
        $native = $this->native($result, 'result', ExtResult::class);

        return $this->guard(static fn(): int => ladybug_result_row_count($native));
    }

    /** @return list<mixed>|null */
    public function fetch(Handle $result): ?array
    {
        $native = $this->native($result, 'result', ExtResult::class);

        return $this->guard(static fn(): ?array => ladybug_result_fetch($native));
    }

    public function rewind(Handle $result): void
    {
        $native = $this->native($result, 'result', ExtResult::class);

        $this->guard(static function () use ($native): null {
            ladybug_result_rewind($native);

            return null;
        });
    }

    public function nextResultSet(Handle $result): ?Handle
    {
        $handle = $this->handle($result, 'result');
        $native = $this->native($handle, 'result', ExtResult::class);
        $next = $this->guard(static fn(): ?ExtResult => ladybug_result_next_set($native));

        return $next === null ? null : new ExtHandle('result', $next, $handle->parent());
    }

    /** @return array{compilingTimeMs: float, executionTimeMs: float} */
    public function summary(Handle $result): array
    {
        $native = $this->native($result, 'result', ExtResult::class);

        return $this->guard(static fn(): array => ladybug_result_summary($native));
    }

    public function closeResult(Handle $result): void
    {
        $handle = $this->handle($result, 'result');
        if (!$handle->isOpen()) {
            return;
        }

        // The native object has to be taken before markClosed(), which makes the handle
        // refuse to hand it out — otherwise the C resource would never be released.
        $native = $this->native($handle, 'result', ExtResult::class);
        if ($handle->markClosed()) {
            ladybug_result_close($native);
        }
    }

    // -- internals --------------------------------------------------------------------

    /**
     * Runs one extension call, translating its exceptions into this library's hierarchy.
     *
     * The extension knows nothing about Ladybug\Exception\*, so the mapping lives here:
     * QueryError becomes a QueryException carrying the Cypher the extension never saw,
     * DatabaseError becomes a DatabaseException, and anything else — closed or foreign
     * handles, allocation failures — becomes a ConnectorException.
     *
     * @template T
     * @param \Closure(): T $operation
     * @param array<string, mixed> $parameters
     * @return T
     */
    private function guard(
        \Closure $operation,
        ?string $cypher = null,
        array $parameters = [],
        ?string $databaseContext = null,
    ): mixed {
        try {
            return $operation();
        } catch (QueryError $e) {
            throw new QueryException($e->getMessage(), $cypher, $parameters);
        } catch (DatabaseError $e) {
            throw new DatabaseException($databaseContext ?? $e->getMessage(), 0, $e);
        } catch (ExtException $e) {
            // Reaching a handle whose connection has gone is a connection-level problem,
            // not a backend defect, and the FFI connector reports it the same way.
            if (str_contains($e->getMessage(), 'connection was closed')) {
                throw new ConnectionException($e->getMessage(), 0, $e);
            }

            throw new ConnectorException($e->getMessage(), 0, $e);
        }
    }

    /**
     * The extension returns one opaque class per handle kind. Nothing in the type system
     * records which one a given ExtHandle wraps, so the narrowing happens here, once,
     * behind the kind check.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function native(Handle $handle, string $expectedKind, string $class): object
    {
        $object = $this->handle($handle, $expectedKind)->object();
        if (!$object instanceof $class) {
            throw new ConnectorException(\sprintf(
                'ext-ladybug returned a %s where a %s was expected; the extension does not match ABI version %d.',
                $object::class,
                $class,
                self::ABI_VERSION,
            ));
        }

        return $object;
    }

    private function handle(Handle $handle, string $expectedKind): ExtHandle
    {
        if (!$handle instanceof ExtHandle) {
            throw new ConnectorException(\sprintf(
                'Handle mismatch: the native connector received a %s. Handles cannot be shared between connectors.',
                $handle::class,
            ));
        }

        if ($handle->kind() !== $expectedKind) {
            throw new ConnectorException("Expected a {$expectedKind} handle, got a {$handle->kind()} handle.");
        }

        return $handle;
    }
}
