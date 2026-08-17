<?php

declare(strict_types=1);

namespace Ladybug\Connector;

use Ladybug\Config;
use Ladybug\Type\DataType;

/**
 * The low-level contract: one PHP method per meaningful LadybugDB C API call, with no
 * ergonomics layered on top. Implemented once per backend (native extension, FFI).
 *
 * Design rules that keep the two implementations honest:
 *
 *  - Handles are opaque. Callers never inspect them, connectors never accept a foreign one.
 *  - Value conversion happens *inside* the connector. The extension does it in C, the FFI
 *    connector does it in PHP; either way the upper layers only ever see PHP values. This
 *    is the single hottest path in the library and the only place the two backends are
 *    allowed to differ in performance.
 *  - Errors are exceptions, never return codes. `lbug_state != LbugSuccess` becomes a
 *    Ladybug\Exception\* throw, so the upper layers have no error handling of their own.
 *  - Every open() has a matching close(); double-close is a no-op, use-after-close throws.
 */
interface Connector
{
    /** Whether this backend can run in the current process (extension loaded, library found). */
    public static function isAvailable(): bool;

    /** Stable identifier used in config and diagnostics: 'ext' or 'ffi'. */
    public function id(): string;

    /** Higher wins when the factory picks a backend automatically. */
    public static function priority(): int;

    /** The liblbug version this connector is bound to, e.g. "0.19.1". */
    public function libraryVersion(): string;

    // -- database ---------------------------------------------------------------------

    public function openDatabase(string $path, Config $config): Handle;

    public function closeDatabase(Handle $database): void;

    // -- connection -------------------------------------------------------------------

    public function connect(Handle $database): Handle;

    public function closeConnection(Handle $connection): void;

    public function setMaxThreads(Handle $connection, int $threads): void;

    public function setQueryTimeout(Handle $connection, int $timeoutMs): void;

    /** Aborts the query currently running on this connection, from another thread/process. */
    public function interrupt(Handle $connection): void;

    // -- query ------------------------------------------------------------------------

    /** Runs Cypher directly. Multiple statements separated by ';' produce a result chain. */
    public function query(Handle $connection, string $cypher): Handle;

    public function prepare(Handle $connection, string $cypher): Handle;

    /** @param array<string, mixed> $parameters keyed by $name, without the '$' */
    public function execute(Handle $connection, Handle $statement, array $parameters = []): Handle;

    public function closeStatement(Handle $statement): void;

    // -- result -----------------------------------------------------------------------

    /** @return list<string> */
    public function columnNames(Handle $result): array;

    /** @return list<DataType> */
    public function columnTypes(Handle $result): array;

    public function rowCount(Handle $result): int;

    /**
     * The next row as a positional list of PHP values, or null when exhausted.
     *
     * @return list<mixed>|null
     */
    public function fetch(Handle $result): ?array;

    public function rewind(Handle $result): void;

    /** The next result in a multi-statement chain, or null if this was the last one. */
    public function nextResultSet(Handle $result): ?Handle;

    /** @return array{compilingTimeMs: float, executionTimeMs: float} */
    public function summary(Handle $result): array;

    public function closeResult(Handle $result): void;
}
