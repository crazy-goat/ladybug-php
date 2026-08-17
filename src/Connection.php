<?php

declare(strict_types=1);

namespace Ladybug;

use Ladybug\Connector\Connector;
use Ladybug\Connector\Handle;
use Ladybug\Exception\ConnectionException;

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
