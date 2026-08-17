<?php

declare(strict_types=1);

namespace Ladybug\Connector\Ffi;

use FFI;
use FFI\CData;
use FFI\Exception;
use Ladybug\Config;
use Ladybug\Connector\Connector;
use Ladybug\Connector\Handle;
use Ladybug\Connector\LibraryVersion;
use Ladybug\Exception\ConnectorException;
use Ladybug\Exception\DatabaseException;
use Ladybug\Exception\QueryException;
use Ladybug\Type\DataType;

/**
 * Talks to liblbug through PHP's FFI. Needs no compilation — just the shared library —
 * at the cost of doing value conversion in PHP, which makes row fetching several times
 * slower than the native extension.
 */
final readonly class FfiConnector implements Connector
{
    private const OK = 0;

    private \FFI $ffi;

    private ValueReader $reader;

    public function __construct(?string $libraryPath = null)
    {
        if (!\extension_loaded('FFI')) {
            throw new ConnectorException('The FFI connector requires ext-ffi (php.ini: ffi.enable=1).');
        }

        $path = LibraryLocator::findOrFail($libraryPath);
        try {
            $ffi = \FFI::cdef(Cdef::source(), $path);
        } catch (Exception $e) {
            throw new ConnectorException("Failed to load liblbug from {$path}: {$e->getMessage()}", $e->getCode(), previous: $e);
        }

        $this->ffi = $ffi;
        $this->reader = new ValueReader($ffi);

        // Before anything that passes a struct across the boundary. lbug_get_version()
        // only returns a string, so it is safe to call even against a mismatched library.
        LibraryVersion::assertSupported($this->libraryVersion(), 'FFI');
    }

    public static function isAvailable(): bool
    {
        return self::ffiIsUsable() && LibraryLocator::find() !== null;
    }

    /**
     * ffi.enable=preload restricts FFI::cdef() to preloaded scripts, so the extension
     * being loaded is not on its own enough.
     */
    public static function ffiIsUsable(): bool
    {
        if (!\extension_loaded('FFI') || !class_exists(\FFI::class)) {
            return false;
        }

        $enable = strtolower((string) \ini_get('ffi.enable'));

        return match ($enable) {
            '0', 'off', 'false', '' => false,
            'preload' => PHP_SAPI === 'cli' || (bool) \ini_get('opcache.preload'),
            default => true,
        };
    }

    public function id(): string
    {
        return 'ffi';
    }

    public static function priority(): int
    {
        return 10;
    }

    public function libraryVersion(): string
    {
        // Deliberately not falling back to the version we were built against: reporting a
        // version we did not actually read would defeat the compatibility check below.
        return $this->takeString($this->ffi->lbug_get_version()) ?? '';
    }

    // -- database ---------------------------------------------------------------------

    public function openDatabase(string $path, Config $config): Handle
    {
        $systemConfig = $this->systemConfig($config);

        $database = $this->alloc('lbug_database');
        $state = $this->ffi->lbug_database_init($path, $systemConfig, \FFI::addr($database));
        if ($state !== self::OK) {
            throw new DatabaseException(
                \sprintf('Could not open the database at "%s". Check the path is writable and not held by another process in read-write mode.', $path),
            );
        }

        return new FfiHandle('database', $database);
    }

    public function closeDatabase(Handle $database): void
    {
        $handle = $this->handle($database, 'database');
        $data = $handle->data();
        if ($handle->markClosed()) {
            $this->ffi->lbug_database_destroy(\FFI::addr($data));
        }
    }

    // -- connection -------------------------------------------------------------------

    public function connect(Handle $database): Handle
    {
        $handle = $this->handle($database, 'database');
        $connection = $this->alloc('lbug_connection');
        $state = $this->ffi->lbug_connection_init(\FFI::addr($handle->data()), \FFI::addr($connection));
        if ($state !== self::OK) {
            throw new DatabaseException('Could not open a connection to the database.');
        }

        return new FfiHandle('connection', $connection, $handle);
    }

    public function closeConnection(Handle $connection): void
    {
        $handle = $this->handle($connection, 'connection');
        if (!$handle->isOpen()) {
            $handle->markClosed();

            return;
        }

        $data = $handle->data();
        if ($handle->markClosed()) {
            $this->ffi->lbug_connection_destroy(\FFI::addr($data));
        }
    }

    public function setMaxThreads(Handle $connection, int $threads): void
    {
        $handle = $this->handle($connection, 'connection');
        $state = $this->ffi->lbug_connection_set_max_num_thread_for_exec(\FFI::addr($handle->data()), $threads);
        if ($state !== self::OK) {
            throw new ConnectorException("Could not set the thread count to {$threads}.");
        }
    }

    public function setQueryTimeout(Handle $connection, int $timeoutMs): void
    {
        $handle = $this->handle($connection, 'connection');
        $state = $this->ffi->lbug_connection_set_query_timeout(\FFI::addr($handle->data()), $timeoutMs);
        if ($state !== self::OK) {
            throw new ConnectorException("Could not set the query timeout to {$timeoutMs} ms.");
        }
    }

    public function interrupt(Handle $connection): void
    {
        $handle = $this->handle($connection, 'connection');
        $this->ffi->lbug_connection_interrupt(\FFI::addr($handle->data()));
    }

    // -- query ------------------------------------------------------------------------

    public function query(Handle $connection, string $cypher): Handle
    {
        $handle = $this->handle($connection, 'connection');
        $result = $this->alloc('lbug_query_result');
        $this->ffi->lbug_connection_query(\FFI::addr($handle->data()), $cypher, \FFI::addr($result));

        return $this->checkedResult($result, $handle, $cypher);
    }

    public function prepare(Handle $connection, string $cypher): Handle
    {
        $handle = $this->handle($connection, 'connection');
        $statement = $this->alloc('lbug_prepared_statement');
        $this->ffi->lbug_connection_prepare(\FFI::addr($handle->data()), $cypher, \FFI::addr($statement));

        if (!$this->ffi->lbug_prepared_statement_is_success(\FFI::addr($statement))) {
            $message = $this->takeString(
                $this->ffi->lbug_prepared_statement_get_error_message(\FFI::addr($statement)),
            );
            $this->ffi->lbug_prepared_statement_destroy(\FFI::addr($statement));
            throw new QueryException($message ?? 'Failed to prepare the statement.', $cypher);
        }

        return new FfiHandle('statement', $statement, $handle);
    }

    /** @param array<string, mixed> $parameters */
    public function execute(Handle $connection, Handle $statement, array $parameters = []): Handle
    {
        $connectionHandle = $this->handle($connection, 'connection');
        $statementHandle = $this->handle($statement, 'statement');

        foreach ($parameters as $name => $value) {
            $this->bind($statementHandle, (string) $name, $value);
        }

        $result = $this->alloc('lbug_query_result');
        $this->ffi->lbug_connection_execute(
            \FFI::addr($connectionHandle->data()),
            \FFI::addr($statementHandle->data()),
            \FFI::addr($result),
        );

        return $this->checkedResult($result, $connectionHandle, null, $parameters);
    }

    public function closeStatement(Handle $statement): void
    {
        $handle = $this->handle($statement, 'statement');
        if (!$handle->isOpen()) {
            $handle->markClosed();

            return;
        }

        $data = $handle->data();
        if ($handle->markClosed()) {
            $this->ffi->lbug_prepared_statement_destroy(\FFI::addr($data));
        }
    }

    private function bind(FfiHandle $statement, string $name, mixed $value): void
    {
        $address = \FFI::addr($statement->data());

        $state = match (true) {
            $value === null => $this->bindNull($address, $name),
            \is_bool($value) => $this->ffi->lbug_prepared_statement_bind_bool($address, $name, $value),
            \is_int($value) => $this->ffi->lbug_prepared_statement_bind_int64($address, $name, $value),
            \is_float($value) => $this->ffi->lbug_prepared_statement_bind_double($address, $name, $value),
            \is_string($value) => $this->ffi->lbug_prepared_statement_bind_string($address, $name, $value),
            $value instanceof \DateTimeInterface => $this->ffi->lbug_prepared_statement_bind_string(
                $address,
                $name,
                $value->format('Y-m-d H:i:s.u'),
            ),
            $value instanceof \Stringable => $this->ffi->lbug_prepared_statement_bind_string($address, $name, (string) $value),
            default => throw new QueryException(\sprintf(
                'Cannot bind $%s: %s is not supported. Use scalars, null, DateTimeInterface, or pass a Cypher literal.',
                $name,
                get_debug_type($value),
            )),
        };

        if ($state !== self::OK) {
            throw new QueryException("Could not bind parameter \$$name. Is it declared in the query?");
        }
    }

    /**
     * There is no bind_null, so we build a NULL value and bind that. Note that
     * lbug_value_create_null() *returns* an owned pointer rather than filling an out
     * parameter, unlike every other constructor in the header.
     */
    private function bindNull(CData $statement, string $name): int
    {
        $null = $this->ffi->lbug_value_create_null();
        try {
            return $this->ffi->lbug_prepared_statement_bind_value($statement, $name, $null);
        } finally {
            $this->ffi->lbug_value_destroy($null);
        }
    }

    // -- result -----------------------------------------------------------------------

    public function columnNames(Handle $result): array
    {
        $handle = $this->handle($result, 'result');
        $address = \FFI::addr($handle->data());
        $count = $this->ffi->lbug_query_result_get_num_columns($address);

        $names = [];
        for ($i = 0; $i < $count; ++$i) {
            $out = $this->alloc('char*');
            $this->ffi->lbug_query_result_get_column_name($address, $i, \FFI::addr($out));
            $names[] = $this->takeString($out) ?? "column_{$i}";
        }

        return $names;
    }

    public function columnTypes(Handle $result): array
    {
        $handle = $this->handle($result, 'result');
        $address = \FFI::addr($handle->data());
        $count = $this->ffi->lbug_query_result_get_num_columns($address);

        $types = [];
        for ($i = 0; $i < $count; ++$i) {
            $type = $this->alloc('lbug_logical_type');
            $this->ffi->lbug_query_result_get_column_data_type($address, $i, \FFI::addr($type));
            try {
                $types[] = DataType::from($this->ffi->lbug_data_type_get_id(\FFI::addr($type)));
            } finally {
                $this->ffi->lbug_data_type_destroy(\FFI::addr($type));
            }
        }

        return $types;
    }

    public function rowCount(Handle $result): int
    {
        $handle = $this->handle($result, 'result');

        return (int) $this->ffi->lbug_query_result_get_num_tuples(\FFI::addr($handle->data()));
    }

    public function fetch(Handle $result): ?array
    {
        $handle = $this->handle($result, 'result');
        $address = \FFI::addr($handle->data());

        if (!$this->ffi->lbug_query_result_has_next($address)) {
            return null;
        }

        $tuple = $this->alloc('lbug_flat_tuple');
        if ($this->ffi->lbug_query_result_get_next($address, \FFI::addr($tuple)) !== self::OK) {
            throw new QueryException('Failed to read the next row from the query result.');
        }

        try {
            $columns = (int) $this->ffi->lbug_query_result_get_num_columns($address);
            $row = [];
            for ($i = 0; $i < $columns; ++$i) {
                $value = $this->alloc('lbug_value');
                $this->ffi->lbug_flat_tuple_get_value(\FFI::addr($tuple), $i, \FFI::addr($value));
                try {
                    $row[] = $this->reader->read($value);
                } finally {
                    $this->ffi->lbug_value_destroy(\FFI::addr($value));
                }
            }

            return $row;
        } finally {
            $this->ffi->lbug_flat_tuple_destroy(\FFI::addr($tuple));
        }
    }

    public function rewind(Handle $result): void
    {
        $handle = $this->handle($result, 'result');
        $this->ffi->lbug_query_result_reset_iterator(\FFI::addr($handle->data()));
    }

    public function nextResultSet(Handle $result): ?Handle
    {
        $handle = $this->handle($result, 'result');
        $address = \FFI::addr($handle->data());

        if (!$this->ffi->lbug_query_result_has_next_query_result($address)) {
            return null;
        }

        $next = $this->alloc('lbug_query_result');
        if ($this->ffi->lbug_query_result_get_next_query_result($address, \FFI::addr($next)) !== self::OK) {
            throw new QueryException('Failed to advance to the next result in the statement chain.');
        }

        return $this->checkedResult($next, $handle->parent(), null);
    }

    public function summary(Handle $result): array
    {
        $handle = $this->handle($result, 'result');
        $summary = $this->alloc('lbug_query_summary');
        if ($this->ffi->lbug_query_result_get_query_summary(\FFI::addr($handle->data()), \FFI::addr($summary)) !== self::OK) {
            return ['compilingTimeMs' => 0.0, 'executionTimeMs' => 0.0];
        }

        try {
            return [
                'compilingTimeMs' => $this->ffi->lbug_query_summary_get_compiling_time(\FFI::addr($summary)),
                'executionTimeMs' => $this->ffi->lbug_query_summary_get_execution_time(\FFI::addr($summary)),
            ];
        } finally {
            $this->ffi->lbug_query_summary_destroy(\FFI::addr($summary));
        }
    }

    public function closeResult(Handle $result): void
    {
        $handle = $this->handle($result, 'result');
        if (!$handle->isOpen()) {
            $handle->markClosed();

            return;
        }

        $data = $handle->data();
        if ($handle->markClosed()) {
            $this->ffi->lbug_query_result_destroy(\FFI::addr($data));
        }
    }

    // -- internals --------------------------------------------------------------------

    /**
     * Turns a freshly produced lbug_query_result into a handle, converting a failed
     * result into a QueryException and freeing it before the throw.
     */
    /** @param array<string, mixed> $parameters */
    private function checkedResult(CData $result, ?Handle $parent, ?string $cypher, array $parameters = []): FfiHandle
    {
        if (!$this->ffi->lbug_query_result_is_success(\FFI::addr($result))) {
            $message = $this->takeString($this->ffi->lbug_query_result_get_error_message(\FFI::addr($result)));
            $this->ffi->lbug_query_result_destroy(\FFI::addr($result));
            throw new QueryException($message ?? 'Query failed.', $cypher, $parameters);
        }

        return new FfiHandle('result', $result, $parent);
    }

    /**
     * Builds an lbug_system_config, starting from liblbug's defaults and overwriting only
     * the fields the caller actually set.
     */
    private function systemConfig(Config $config): CData
    {
        /** @var FFI\CData $systemConfig */
        $systemConfig = $this->ffi->lbug_default_system_config();

        foreach ([
            'buffer_pool_size' => $config->bufferPoolSize,
            'max_num_threads' => $config->maxThreads,
            'enable_compression' => $config->compression,
            'read_only' => $config->readOnly,
            'max_db_size' => $config->maxDbSize,
            'auto_checkpoint' => $config->autoCheckpoint,
            'checkpoint_threshold' => $config->checkpointThreshold,
        ] as $field => $value) {
            if ($value !== null) {
                $systemConfig->{$field} = $value;
            }
        }

        return $systemConfig;
    }

    /**
     * Allocates an owned C struct. FFI::new() is nullable in the type stubs and returns
     * null when the request cannot be satisfied, which must not be passed on to liblbug.
     */
    private function alloc(string $type): CData
    {
        $data = $this->ffi->new($type);
        if (!$data instanceof CData) {
            throw new ConnectorException("Could not allocate a {$type} for liblbug.");
        }

        return $data;
    }

    /** Reads a char* that liblbug allocated, then frees it. */
    private function takeString(?CData $pointer): ?string
    {
        if (!$pointer instanceof CData || \FFI::isNull($pointer)) {
            return null;
        }

        try {
            return \FFI::string($pointer);
        } finally {
            $this->ffi->lbug_destroy_string($pointer);
        }
    }

    private function handle(Handle $handle, string $expectedKind): FfiHandle
    {
        if (!$handle instanceof FfiHandle) {
            throw new ConnectorException(\sprintf(
                'Handle mismatch: the FFI connector received a %s. Handles cannot be shared between connectors.',
                $handle::class,
            ));
        }

        if ($handle->kind() !== $expectedKind) {
            throw new ConnectorException("Expected a {$expectedKind} handle, got a {$handle->kind()} handle.");
        }

        return $handle;
    }
}
