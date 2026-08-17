<?php

declare(strict_types=1);

namespace Ladybug\Tests\Fake;

use Ladybug\Config;
use Ladybug\Connector\Connector;
use Ladybug\Connector\Handle;
use Ladybug\Type\DataType;

/**
 * A connector that talks to nothing. It exists to prove the abstraction holds — the upper
 * layers work against any implementation of Connector — and to let the factory rules be
 * tested without a database.
 */
final class FakeConnector implements Connector
{
    public static bool $available = true;

    public static int $priorityValue = 50;

    public static int $constructed = 0;

    /** @var list<string> every low-level call, in order */
    public array $calls = [];

    /** @var list<list<mixed>> rows handed out by fetch() */
    public array $rows = [];

    /** @var list<string> */
    public array $columns = ['a'];

    private int $cursor = 0;

    public function __construct()
    {
        ++self::$constructed;
    }

    /**
     * Loads the rows a subsequent fetch() should hand out, rewinding the cursor.
     *
     * @param list<list<mixed>> $rows
     * @param list<string> $columns
     */
    public function willReturn(array $rows, array $columns): void
    {
        $this->rows = $rows;
        $this->columns = $columns;
        $this->cursor = 0;
    }

    public static function reset(): void
    {
        self::$available = true;
        self::$priorityValue = 50;
        self::$constructed = 0;
    }

    public static function isAvailable(): bool
    {
        return self::$available;
    }

    public function id(): string
    {
        return 'fake';
    }

    public static function priority(): int
    {
        return self::$priorityValue;
    }

    public function libraryVersion(): string
    {
        return '0.0.0-fake';
    }

    public function openDatabase(string $path, Config $config): Handle
    {
        $this->calls[] = "openDatabase({$path})";

        return new FakeHandle('database');
    }

    public function closeDatabase(Handle $database): void
    {
        $this->calls[] = 'closeDatabase';
    }

    public function connect(Handle $database): Handle
    {
        $this->calls[] = 'connect';

        return new FakeHandle('connection');
    }

    public function closeConnection(Handle $connection): void
    {
        $this->calls[] = 'closeConnection';
    }

    public function setMaxThreads(Handle $connection, int $threads): void
    {
        $this->calls[] = "setMaxThreads({$threads})";
    }

    public function setQueryTimeout(Handle $connection, int $timeoutMs): void
    {
        $this->calls[] = "setQueryTimeout({$timeoutMs})";
    }

    public function interrupt(Handle $connection): void
    {
        $this->calls[] = 'interrupt';
    }

    public function query(Handle $connection, string $cypher): Handle
    {
        $this->calls[] = "query({$cypher})";
        $this->cursor = 0;

        return new FakeHandle('result');
    }

    public function prepare(Handle $connection, string $cypher): Handle
    {
        $this->calls[] = "prepare({$cypher})";

        return new FakeHandle('statement');
    }

    /** @param array<string, mixed> $parameters */
    public function execute(Handle $connection, Handle $statement, array $parameters = []): Handle
    {
        $this->calls[] = 'execute(' . json_encode($parameters, JSON_THROW_ON_ERROR) . ')';
        $this->cursor = 0;

        return new FakeHandle('result');
    }

    public function closeStatement(Handle $statement): void
    {
        $this->calls[] = 'closeStatement';
    }

    public function columnNames(Handle $result): array
    {
        return $this->columns;
    }

    public function columnTypes(Handle $result): array
    {
        return array_fill(0, \count($this->columns), DataType::Int64);
    }

    public function rowCount(Handle $result): int
    {
        return \count($this->rows);
    }

    public function fetch(Handle $result): ?array
    {
        return $this->rows[$this->cursor++] ?? null;
    }

    public function rewind(Handle $result): void
    {
        $this->calls[] = 'rewind';
        $this->cursor = 0;
    }

    public function nextResultSet(Handle $result): ?Handle
    {
        return null;
    }

    public function summary(Handle $result): array
    {
        return ['compilingTimeMs' => 0.1, 'executionTimeMs' => 0.2];
    }

    public function closeResult(Handle $result): void
    {
        $this->calls[] = 'closeResult';
    }
}
