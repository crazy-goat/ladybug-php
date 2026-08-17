<?php

declare(strict_types=1);

namespace Ladybug;

use Ladybug\Bulk\CsvSpool;
use Ladybug\Connector\Connector;
use Ladybug\Connector\Handle;
use Ladybug\Exception\ConnectionException;
use Ladybug\Exception\InvalidArgumentException;
use Ladybug\Exception\TypeException;

/**
 * A connection to a database. Thread-safe on the C side, but a PHP process is
 * single-threaded, so the usual reason to open several is concurrency across workers.
 *
 *     $conn->run('CREATE NODE TABLE Person(name STRING, PRIMARY KEY(name))');
 *     $rows = $conn->query('MATCH (p:Person) WHERE p.age > $min RETURN p.name', ['min' => 30])->fetchAll();
 */
final class Connection
{
    /** Prepared statements are cached by Cypher text, the way most database clients do it. */
    private const STATEMENT_CACHE_SIZE = 64;

    /** @var array<string, PreparedStatement> */
    private array $statementCache = [];

    private bool $closed = false;

    /** @internal use Database::connect() */
    public function __construct(
        private readonly Connector $connector,
        private readonly Handle $handle,
        private readonly Database $database,
    ) {}

    /**
     * Runs a query and returns its result. With parameters it prepares (and caches) the
     * statement; without them it goes straight to liblbug's direct query path.
     *
     * @param array<string, mixed> $parameters keyed without the leading '$'
     */
    public function query(string $cypher, array $parameters = []): QueryResult
    {
        $this->assertOpen();

        if ($parameters === []) {
            return new QueryResult($this->connector, $this->connector->query($this->handle, $cypher), $cypher, owner: $this);
        }

        return $this->prepare($cypher)->execute($parameters);
    }

    /**
     * Runs a query whose rows you do not care about (DDL, writes) and returns the number
     * of rows the statement reported. Frees the result immediately.
     */
    /** @param array<string, mixed> $parameters */
    public function run(string $cypher, array $parameters = []): int
    {
        $result = $this->query($cypher, $parameters);
        try {
            return $result->count();
        } finally {
            $result->close();
        }
    }

    /**
     * Runs one or more statements separated by ';' and returns every result. Useful for
     * schema migrations, where a whole file is executed in one call.
     *
     * @return list<QueryResult>
     */
    public function queryMultiple(string $cypher): array
    {
        $this->assertOpen();

        $first = new QueryResult($this->connector, $this->connector->query($this->handle, $cypher), $cypher, owner: $this);
        $results = [$first];
        $current = $first;
        while (($next = $current->nextResultSet()) instanceof QueryResult) {
            $results[] = $next;
            $current = $next;
        }

        return $results;
    }

    public function prepare(string $cypher): PreparedStatement
    {
        $this->assertOpen();

        if (isset($this->statementCache[$cypher])) {
            // Refresh LRU position.
            $statement = $this->statementCache[$cypher];
            unset($this->statementCache[$cypher]);
            $this->statementCache[$cypher] = $statement;

            return $statement;
        }

        if (\count($this->statementCache) >= self::STATEMENT_CACHE_SIZE) {
            $oldest = array_key_first($this->statementCache);
            $this->statementCache[$oldest]->close();
            unset($this->statementCache[$oldest]);
        }

        $statement = new PreparedStatement(
            $this->connector,
            $this->connector->prepare($this->handle, $cypher),
            $this->handle,
            $cypher,
            $this,
        );

        return $this->statementCache[$cypher] = $statement;
    }

    /**
     * Runs $work inside an explicit transaction, committing on return and rolling back if
     * anything is thrown.
     *
     * @template T
     * @param callable(self): T $work
     * @return T
     */
    public function transaction(callable $work, bool $readOnly = false): mixed
    {
        $this->assertOpen();
        $this->run($readOnly ? 'BEGIN TRANSACTION READ ONLY' : 'BEGIN TRANSACTION');

        try {
            $outcome = $work($this);
        } catch (\Throwable $e) {
            try {
                $this->run('ROLLBACK');
            } catch (\Throwable) {
                // The original failure is what the caller needs to see.
            }

            throw $e;
        }

        $this->run('COMMIT');

        return $outcome;
    }

    /**
     * Bulk-loads rows into an existing table and returns how many landed.
     *
     * `COPY FROM` is liblbug's own bulk path and it reads a file, so the rows are spooled to
     * a temporary CSV and handed over. That is worth it: a loop of `CREATE` pays query
     * planning per row, while this hands liblbug the whole batch at once.
     *
     * ```php
     * $connection->copyInto('Person', [
     *     ['name' => 'Ada', 'age' => 36],
     *     ['name' => 'Alan', 'age' => 41],
     * ]);
     * ```
     *
     * Rows may be associative (the keys name the columns, taken from the first row) or lists
     * (positional, in the table's own column order). For a REL table the first two positions
     * are the FROM and TO primary keys.
     *
     * Values may be scalars, null or `DateTimeInterface`. Lists, structs and maps have no CSV
     * spelling and are rejected — use `CREATE` with parameters for those.
     *
     * @param iterable<array<string, mixed>|list<mixed>> $rows
     * @param list<string>|null                          $columns overrides the columns to copy
     *
     * @throws InvalidArgumentException on an unusable table or column name, or a row whose
     *                                  shape does not match the columns
     * @throws TypeException            on a value CSV cannot carry — a list, a map, NAN, or an
     *                                  empty string, which liblbug would read back as NULL
     */
    public function copyInto(string $table, iterable $rows, ?array $columns = null): int
    {
        $this->assertOpen();
        $this->assertIdentifier($table, 'table');
        foreach ($columns ?? [] as $column) {
            $this->assertIdentifier($column, 'column');
        }

        $spool = CsvSpool::create();

        try {
            foreach ($rows as $row) {
                // The first row settles the column list, so every later row is checked
                // against it rather than silently writing fields in a different order.
                $columns ??= $this->columnsOf($row);
                $spool->writeRow($this->orderRow($row, $columns, $spool->rows() + 1));
            }

            $spool->close();

            if ($spool->rows() === 0) {
                return 0;
            }

            return $this->copyFromFile($table, $columns ?? [], $spool);
        } finally {
            $spool->discard();
        }
    }

    /**
     * @param list<string> $columns
     */
    private function copyFromFile(string $table, array $columns, CsvSpool $spool): int
    {
        $target = $columns === [] ? $table : \sprintf('%s (%s)', $table, implode(', ', $columns));

        // file_format is explicit because liblbug otherwise infers it from the extension, and
        // a temporary file has none. Serial reads only when a value carried a newline: the
        // parallel CSV reader rejects those, and disabling it always would cost throughput.
        $options = ["file_format='csv'"];
        if ($spool->needsSerialRead()) {
            $options[] = 'parallel=false';
        }

        $result = $this->query(\sprintf(
            "COPY %s FROM '%s' (%s)",
            $target,
            // The path is ours and contains no quote, but a copy statement is still a
            // statement — do not hand liblbug an unescaped literal.
            str_replace("'", "''", $spool->path),
            implode(', ', $options),
        ));

        // liblbug answers with "N tuples have been copied to the T table."; its count is
        // authoritative, and the spooled count is the fallback if the wording ever changes.
        $reported = $result->fetchOne();
        if (\is_string($reported) && preg_match('/^(\d+) tuple/', $reported, $match) === 1) {
            return (int) $match[1];
        }

        return $spool->rows();
    }

    /**
     * @param array<string, mixed>|list<mixed> $row
     *
     * @return list<string>
     */
    private function columnsOf(array $row): array
    {
        $names = [];
        foreach (array_keys($row) as $key) {
            if (!\is_string($key)) {
                // A positional row copies into the table's own column order, so there is no
                // column list to build.
                return [];
            }

            $this->assertIdentifier($key, 'column');
            $names[] = $key;
        }

        return $names;
    }

    /**
     * @param array<string, mixed>|list<mixed> $row
     * @param list<string>                     $columns
     *
     * @return list<mixed>
     */
    private function orderRow(array $row, array $columns, int $number): array
    {
        // A positional row is copied in its own order — the column list only tells liblbug
        // which columns those positions refer to, so there is nothing to reorder.
        if ($columns === [] || array_is_list($row)) {
            return array_values($row);
        }

        $ordered = [];
        foreach ($columns as $column) {
            if (!\array_key_exists($column, $row)) {
                throw new InvalidArgumentException(\sprintf(
                    'Row %d has no "%s" column. Every row must carry the same columns as the first (%s).',
                    $number,
                    $column,
                    implode(', ', $columns),
                ));
            }

            $ordered[] = $row[$column];
        }

        if (\count($row) !== \count($columns)) {
            throw new InvalidArgumentException(\sprintf(
                'Row %d has %d columns but %d were expected (%s). Extra columns would be copied into the wrong place.',
                $number,
                \count($row),
                \count($columns),
                implode(', ', $columns),
            ));
        }

        return $ordered;
    }

    /**
     * Table and column names go into the statement as text, so they are checked rather than
     * escaped: a Cypher identifier has no quoting form that would make arbitrary input safe.
     */
    private function assertIdentifier(string $name, string $kind): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(\sprintf(
                'Not a usable %s name for a copy: "%s". Expected letters, digits and underscores, not starting with a digit.',
                $kind,
                $name,
            ));
        }
    }

    public function setMaxThreads(int $threads): self
    {
        $this->assertOpen();
        $this->connector->setMaxThreads($this->handle, $threads);

        return $this;
    }

    public function setQueryTimeout(int $timeoutMs): self
    {
        $this->assertOpen();
        $this->connector->setQueryTimeout($this->handle, $timeoutMs);

        return $this;
    }

    /** Aborts whatever query is running on this connection. */
    public function interrupt(): void
    {
        $this->assertOpen();
        $this->connector->interrupt($this->handle);
    }

    public function database(): Database
    {
        return $this->database;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ($this->statementCache as $statement) {
            $statement->close();
        }

        $this->statementCache = [];

        $this->connector->closeConnection($this->handle);
    }

    public function __destruct()
    {
        $this->close();
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new ConnectionException('This connection is closed.');
        }
    }
}
