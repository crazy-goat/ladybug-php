<?php

declare(strict_types=1);

/**
 * Signatures of the native ladybug extension, for static analysis only. This file is
 * never loaded at runtime — it documents the ABI that ExtConnector adapts and lets
 * PHPStan check that adapter even on a machine where the extension is not installed.
 *
 * @see \Ladybug\Connector\Ext\ExtConnector for the authoritative description of the ABI
 */

namespace Ladybug\Ext {
    /** Opaque handle for an open database. */
    final class Database
    {
    }

    /** Opaque handle for a connection. */
    final class Connection
    {
    }

    /** Opaque handle for a prepared statement. */
    final class Statement
    {
    }

    /** Opaque handle for a query result. */
    final class Result
    {
    }

    /**
     * Base class for everything the extension throws. ExtConnector rewraps these into
     * Ladybug\Exception\* so callers see the same hierarchy from either backend.
     */
    class Exception extends \RuntimeException
    {
    }

    /** Opening a database or a connection failed. */
    class DatabaseError extends Exception
    {
    }

    /** A query, a prepare, a bind or a row read failed. */
    class QueryError extends Exception
    {
    }
}

namespace {
    function ladybug_abi_version(): int
    {
    }

    function ladybug_version(): string
    {
    }

    /** @param array<string, int|bool> $config */
    function ladybug_database_open(string $path, array $config): Ladybug\Ext\Database
    {
    }

    function ladybug_database_close(Ladybug\Ext\Database $database): void
    {
    }

    function ladybug_connect(Ladybug\Ext\Database $database): Ladybug\Ext\Connection
    {
    }

    function ladybug_connection_close(Ladybug\Ext\Connection $connection): void
    {
    }

    function ladybug_connection_set_max_threads(Ladybug\Ext\Connection $connection, int $threads): void
    {
    }

    function ladybug_connection_set_query_timeout(Ladybug\Ext\Connection $connection, int $timeoutMs): void
    {
    }

    function ladybug_connection_interrupt(Ladybug\Ext\Connection $connection): void
    {
    }

    function ladybug_query(Ladybug\Ext\Connection $connection, string $cypher): Ladybug\Ext\Result
    {
    }

    function ladybug_prepare(Ladybug\Ext\Connection $connection, string $cypher): Ladybug\Ext\Statement
    {
    }

    /** @param array<string, mixed> $parameters */
    function ladybug_execute(Ladybug\Ext\Connection $connection, Ladybug\Ext\Statement $statement, array $parameters): Ladybug\Ext\Result
    {
    }

    function ladybug_statement_close(Ladybug\Ext\Statement $statement): void
    {
    }

    /** @return list<string> */
    function ladybug_result_column_names(Ladybug\Ext\Result $result): array
    {
    }

    /** @return list<int> lbug_data_type_id values */
    function ladybug_result_column_types(Ladybug\Ext\Result $result): array
    {
    }

    function ladybug_result_row_count(Ladybug\Ext\Result $result): int
    {
    }

    /** @return list<mixed>|null */
    function ladybug_result_fetch(Ladybug\Ext\Result $result): ?array
    {
    }

    function ladybug_result_rewind(Ladybug\Ext\Result $result): void
    {
    }

    function ladybug_result_next_set(Ladybug\Ext\Result $result): ?Ladybug\Ext\Result
    {
    }

    /** @return array{compilingTimeMs: float, executionTimeMs: float} */
    function ladybug_result_summary(Ladybug\Ext\Result $result): array
    {
    }

    function ladybug_result_close(Ladybug\Ext\Result $result): void
    {
    }
}
