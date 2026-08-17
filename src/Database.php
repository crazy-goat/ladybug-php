<?php

declare(strict_types=1);

namespace Ladybug;

use Ladybug\Connector\Connector;
use Ladybug\Connector\ConnectorFactory;
use Ladybug\Connector\Handle;
use Ladybug\Exception\DatabaseException;

/**
 * An open LadybugDB database. Create one per data directory and share it; connections are
 * cheap, databases are not.
 *
 *     $db = new Database('/var/data/graph.lbdb');
 *     $conn = $db->connect();
 *
 * Closing is optional — the destructor releases the C resources — but explicit close()
 * makes the moment of release predictable, which matters for read-write databases since
 * they hold a lock on the directory.
 */
final class Database
{
    private readonly Connector $connector;

    private readonly Handle $handle;

    private bool $closed = false;

    /** @var list<\WeakReference<Connection>> */
    private array $connections = [];

    public function __construct(
        public readonly string $path,
        public readonly Config $config = new Config(),
        ?Connector $connector = null,
    ) {
        $this->connector = $connector ?? ConnectorFactory::create($config);
        $this->handle = $this->connector->openDatabase($path, $config);
    }

    /**
     * An in-memory database, discarded when the process ends. Handy for tests and for
     * one-off graph computations.
     */
    public static function inMemory(Config $config = new Config(), ?Connector $connector = null): self
    {
        return new self('', $config, $connector);
    }

    public function connect(): Connection
    {
        $this->assertOpen();
        $connection = new Connection($this->connector, $this->connector->connect($this->handle), $this);
        $this->connections[] = \WeakReference::create($connection);

        return $connection;
    }

    /** Which backend this database is running on: 'ext' or 'ffi'. */
    public function connectorId(): string
    {
        return $this->connector->id();
    }

    public function libraryVersion(): string
    {
        return $this->connector->libraryVersion();
    }

    /** @internal */
    public function connector(): Connector
    {
        return $this->connector;
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

        // Connections must go first: liblbug's connection destructor touches the database.
        foreach ($this->connections as $reference) {
            $reference->get()?->close();
        }

        $this->connections = [];

        $this->connector->closeDatabase($this->handle);
    }

    public function __destruct()
    {
        $this->close();
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new DatabaseException("The database at \"{$this->path}\" is closed.");
        }
    }
}
