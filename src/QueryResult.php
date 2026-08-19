<?php

declare(strict_types=1);

namespace Ladybug;

use Ladybug\Connector\Connector;
use Ladybug\Connector\Handle;
use Ladybug\Exception\QueryException;
use Ladybug\Type\DataType;

/**
 * The rows produced by one statement.
 *
 * Results stream: iterating pulls one row at a time from liblbug, so a query returning
 * millions of rows costs one row of memory. fetchAll() opts into materialising everything.
 *
 *     foreach ($conn->query('MATCH (p:Person) RETURN p.name, p.age') as $row) {
 *         echo $row['p.name'], ' ', $row['p.age'], PHP_EOL;
 *     }
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class QueryResult implements \IteratorAggregate, \Countable, \Stringable
{
    /** @var list<string>|null */
    private ?array $columnNames = null;

    /** @var list<DataType>|null */
    private ?array $columnTypes = null;

    private bool $closed = false;

    private bool $consumed = false;

    /**
     * The successor in a multi-statement chain, if any. Held so that close() on this
     * result can be deferred while the successor still needs its C-side backing store.
     */
    private ?self $successor = null;

    /**
     * The predecessor in a multi-statement chain, if any. When this result is closed,
     * the predecessor's deferred close is triggered as well.
     */
    private ?self $predecessor = null;

    /** @internal produced by Connection and PreparedStatement */
    public function __construct(
        private readonly Connector $connector,
        private readonly Handle $handle,
        public readonly ?string $cypher = null,
        /** @var array<string, mixed> */
        public readonly array $parameters = [],
        /**
         * The Connection (or PreparedStatement) this result came from. Held purely so PHP
         * refcounting keeps it alive: rows are read lazily, and a temporary owner —
         * `$db->connect()->query(...)` — would otherwise be destructed, and its C
         * connection closed, before the first row is fetched.
         */
        private readonly ?object $owner = null,  // @phpstan-ignore property.onlyWritten
    ) {}

    // -- metadata ---------------------------------------------------------------------

    /** @return list<string> */
    public function columnNames(): array
    {
        $this->assertOpen();

        return $this->columnNames ??= $this->connector->columnNames($this->handle);
    }

    /** @return list<DataType> */
    public function columnTypes(): array
    {
        $this->assertOpen();

        return $this->columnTypes ??= $this->connector->columnTypes($this->handle);
    }

    /** The number of rows the statement produced. */
    public function count(): int
    {
        $this->assertOpen();

        return max(0, $this->connector->rowCount($this->handle));
    }

    /** @return array{compilingTimeMs: float, executionTimeMs: float} */
    public function summary(): array
    {
        $this->assertOpen();

        return $this->connector->summary($this->handle);
    }

    // -- fetching ---------------------------------------------------------------------

    /**
     * The next row keyed by column name, or null at the end.
     *
     * Duplicate column names (`RETURN p.name, p.name`) would collide, so repeats get a
     * "#2", "#3" suffix. Use fetchNumeric() when you need the raw positions.
     *
     * @return array<string, mixed>|null
     */
    public function fetchRow(): ?array
    {
        $row = $this->fetchNumeric();
        if ($row === null) {
            return null;
        }

        return array_combine($this->uniqueColumnNames(), $row);
    }

    /**
     * The next row as a positional list, or null at the end.
     *
     * @return list<mixed>|null
     */
    public function fetchNumeric(): ?array
    {
        $this->assertOpen();
        $row = $this->connector->fetch($this->handle);
        if ($row === null) {
            $this->consumed = true;
        }

        return $row;
    }

    /**
     * The first column of the next row — for queries that return a single value.
     *
     *     $count = $conn->query('MATCH (p:Person) RETURN count(*)')->fetchOne();
     */
    public function fetchOne(): mixed
    {
        return $this->fetchNumeric()[0] ?? null;
    }

    /** @return list<array<string, mixed>> every remaining row, keyed by column name */
    public function fetchAll(): array
    {
        $names = $this->uniqueColumnNames();
        $rows = [];
        while (($row = $this->fetchNumeric()) !== null) {
            $rows[] = array_combine($names, $row);
        }

        return $rows;
    }

    /**
     * Every remaining row, positionally.
     *
     * @return list<list<mixed>>
     */
    public function fetchAllNumeric(): array
    {
        $rows = [];
        while (($row = $this->fetchNumeric()) !== null) {
            $rows[] = array_values($row);
        }

        return $rows;
    }

    /**
     * One column of every remaining row, flattened.
     *
     *     $names = $conn->query('MATCH (p:Person) RETURN p.name')->fetchColumn();
     *
     * @return list<mixed>
     */
    public function fetchColumn(int|string $column = 0): array
    {
        $index = \is_int($column) ? $column : $this->columnIndex($column);
        $values = [];
        while (($row = $this->fetchNumeric()) !== null) {
            if (!\array_key_exists($index, $row)) {
                throw new QueryException(\sprintf(
                    'Column %s is out of range; the result has %d column(s): %s.',
                    var_export($column, true),
                    \count($row),
                    implode(', ', $this->columnNames()),
                ), $this->cypher);
            }

            $values[] = $row[$index];
        }

        return $values;
    }

    /**
     * All rows keyed by one column — the shape you want for lookup tables.
     *
     * @return array<array-key, array<string, mixed>>
     */
    public function fetchAllKeyedBy(int|string $column): array
    {
        $names = $this->uniqueColumnNames();
        $index = \is_int($column) ? $column : $this->columnIndex($column);

        $rows = [];
        while (($row = $this->fetchNumeric()) !== null) {
            $key = $row[$index] ?? throw new QueryException(
                \sprintf('Column %s is out of range.', var_export($column, true)),
                $this->cypher,
            );
            if (!\is_int($key) && !\is_string($key)) {
                throw new QueryException(\sprintf(
                    'Cannot key rows by column %s: values of type %s are not usable as array keys.',
                    var_export($column, true),
                    get_debug_type($key),
                ), $this->cypher);
            }

            $rows[$key] = array_combine($names, $row);
        }

        return $rows;
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function getIterator(): \Generator
    {
        $names = $this->uniqueColumnNames();
        $index = 0;
        while (($row = $this->fetchNumeric()) !== null) {
            yield $index++ => array_combine($names, $row);
        }
    }

    /** Rewinds the cursor so the rows can be read again. */
    public function reset(): self
    {
        $this->assertOpen();
        $this->connector->rewind($this->handle);
        $this->consumed = false;

        return $this;
    }

    /** True once fetching has hit the end of the rows. */
    public function isConsumed(): bool
    {
        return $this->consumed;
    }

    /** The next result when several statements were sent in one call, else null. */
    public function nextResultSet(): ?self
    {
        $this->assertOpen();
        $next = $this->connector->nextResultSet($this->handle);

        if (!$next instanceof Handle) {
            return null;
        }

        $successor = new self($this->connector, $next, $this->cypher, owner: $this->owner);
        $successor->predecessor = $this;
        $this->successor = $successor;

        return $successor;
    }

    /** liblbug's own tabular rendering — mostly for debugging. */
    public function __toString(): string
    {
        $lines = [implode(' | ', $this->columnNames())];
        foreach ($this as $row) {
            $lines[] = implode(' | ', array_map(
                static fn(mixed $v): string => match (true) {
                    $v === null => 'NULL',
                    \is_bool($v) => $v ? 'true' : 'false',
                    \is_scalar($v), $v instanceof \Stringable => (string) $v,
                    default => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: get_debug_type($v),
                },
                $row,
            ));
        }

        return implode("\n", $lines);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        /* In a multi-statement chain the C-side result owned by this handle is also the
         * backing store for its successor. Defer the actual close until the successor is
         * closed too, so freeing this result cannot free the successor out from under
         * the caller. */
        if ($this->successor !== null && !$this->successor->closed) {
            return;
        }

        $this->connector->closeResult($this->handle);

        /* If the predecessor deferred its close because of us, it can now be closed. */
        if ($this->predecessor !== null && $this->predecessor->closed) {
            $this->predecessor->close();
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @return list<string> */
    private function uniqueColumnNames(): array
    {
        $seen = [];
        $names = [];
        foreach ($this->columnNames() as $name) {
            $seen[$name] = ($seen[$name] ?? 0) + 1;
            $names[] = $seen[$name] === 1 ? $name : $name . '#' . $seen[$name];
        }

        return $names;
    }

    private function columnIndex(string $name): int
    {
        $index = array_search($name, $this->columnNames(), true);
        if ($index === false) {
            throw new QueryException(\sprintf(
                'No column named "%s". The result has: %s.',
                $name,
                implode(', ', $this->columnNames()),
            ), $this->cypher);
        }

        return $index;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new QueryException('This query result is closed.', $this->cypher);
        }
    }
}
