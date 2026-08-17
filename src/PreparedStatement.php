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

    /** @internal use Connection::prepare() */
    public function __construct(
        private readonly Connector $connector,
        private readonly Handle $handle,
        private readonly Handle $connection,
        public readonly string $cypher,
        /** Kept so the connection outlives this statement; see QueryResult::$owner. */
        private readonly ?object $owner = null,  // @phpstan-ignore property.onlyWritten
    ) {}

    /** @param array<string, mixed> $parameters keyed without the leading '$' */
    public function execute(array $parameters = []): QueryResult
    {
        if ($this->closed) {
            throw new QueryException('This prepared statement is closed.', $this->cypher);
        }

        return new QueryResult(
            $this->connector,
            $this->connector->execute($this->connection, $this->handle, $parameters),
            $this->cypher,
            $parameters,
            $this,
        );
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
