<?php

declare(strict_types=1);

namespace Ladybug;

use Ladybug\Connector\Connector;
use Ladybug\Connector\Handle;
use Ladybug\Exception\QueryException;

/**
 * A parsed and planned query, reusable across executions with different parameters.
 * Obtained from Connection::prepare(), which caches them, so re-preparing the same
 * Cypher text is free.
 */
final class PreparedStatement
{
    private bool $closed = false;

    /**
     * Every name bound by an earlier execution. liblbug keeps a binding until it is
     * overwritten, so these decide whether the handle can be reused as it is.
     *
     * @var array<array-key, true>
     */
    private array $boundNames = [];

    /** @internal use Connection::prepare() */
    public function __construct(
        private readonly Connector $connector,
        private Handle $handle,
        private readonly Handle $connection,
        public readonly string $cypher,
        /** Kept so the connection outlives this statement; see QueryResult::$owner. */
        private readonly ?object $owner = null,  // @phpstan-ignore property.onlyWritten
    ) {}

    /**
     * Every execution sees only the parameters passed to it, exactly like a freshly prepared
     * statement: a parameter bound by an earlier execution and omitted now fails with
     * liblbug's "Parameter ... not found." instead of silently reusing the old value.
     *
     * @param array<string, mixed> $parameters keyed without the leading '$'
     */
    public function execute(array $parameters = []): QueryResult
    {
        if ($this->closed) {
            throw new QueryException('This prepared statement is closed.', $this->cypher);
        }

        if (array_diff_key($this->boundNames, $parameters) !== []) {
            // liblbug cannot unbind a parameter, so start over from a fresh handle. Prepare
            // first: if that fails, the old handle is still intact.
            $fresh = $this->connector->prepare($this->connection, $this->cypher);
            $this->connector->closeStatement($this->handle);
            $this->handle = $fresh;
            $this->boundNames = [];
        }

        // Recorded before execution: a failed bind or query can leave some names bound too.
        $this->boundNames += array_fill_keys(array_keys($parameters), true);

        try {
            $result = $this->connector->execute($this->connection, $this->handle, $parameters);
        } catch (QueryException $e) {
            // The connectors report an execution failure without the statement text, which only
            // this object holds. Done here once so both connectors behave the same.
            throw $e->cypher === null
                ? new QueryException($e->getMessage(), $this->cypher, $parameters)
                : $e;
        }

        return new QueryResult(
            $this->connector,
            $result,
            $this->cypher,
            $parameters,
            $this,
        );
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
        $this->connector->closeStatement($this->handle);
    }

    public function __destruct()
    {
        $this->close();
    }
}
