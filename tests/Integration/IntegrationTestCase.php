<?php

declare(strict_types=1);

namespace Ladybug\Tests\Integration;

use Ladybug\Config;
use Ladybug\Connection;
use Ladybug\Connector\Connector;
use Ladybug\Connector\ConnectorFactory;
use Ladybug\Database;
use Ladybug\Exception\ConnectorException;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that talk to a real database.
 *
 * The backend comes from LADYBUG_CONNECTOR when set, otherwise from the factory, so the
 * same suite can be run twice — once per connector — to prove the two implementations
 * behave identically:
 *
 *     LADYBUG_CONNECTOR=ffi vendor/bin/phpunit --testsuite integration
 *     LADYBUG_CONNECTOR=ext vendor/bin/phpunit --testsuite integration
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Database $database;

    protected Connection $connection;

    private string $workDirectory = '';

    protected function setUp(): void
    {
        $connector = self::connectorUnderTest();

        $this->workDirectory = sys_get_temp_dir() . '/ladybug-php-' . bin2hex(random_bytes(6));
        if (!mkdir($this->workDirectory, 0o777, true) && !is_dir($this->workDirectory)) {
            self::fail("Could not create the temporary directory {$this->workDirectory}");
        }

        $this->database = new Database($this->databasePath(), connector: $connector);
        $this->connection = $this->database->connect();
    }

    protected function tearDown(): void
    {
        $this->database->close();
        $this->removeWorkDirectory();
    }

    protected function databasePath(): string
    {
        return $this->workDirectory . '/graph.lbdb';
    }

    /** Reopens the same directory, e.g. to check that data survived. */
    protected function reopen(Config $config = new Config()): Database
    {
        return new Database($this->databasePath(), $config, self::connectorUnderTest());
    }

    protected static function connectorUnderTest(): Connector
    {
        $requested = getenv('LADYBUG_CONNECTOR');
        $config = \is_string($requested) && $requested !== '' ? new Config(connector: $requested) : null;

        try {
            return ConnectorFactory::create($config);
        } catch (ConnectorException $e) {
            self::markTestSkipped('No usable LadybugDB connector: ' . $e->getMessage());
        }
    }

    /** A Person/Knows schema, used by most of the graph-shaped tests. */
    protected function createPersonSchema(): void
    {
        $this->connection->run(
            'CREATE NODE TABLE Person(name STRING, age INT64, score DOUBLE, active BOOLEAN, PRIMARY KEY(name))',
        );
        $this->connection->run('CREATE REL TABLE Knows(FROM Person TO Person, since INT64)');
    }

    private function removeWorkDirectory(): void
    {
        if ($this->workDirectory === '' || !is_dir($this->workDirectory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->workDirectory);
    }
}
