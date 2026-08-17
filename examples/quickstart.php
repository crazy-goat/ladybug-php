<?php

declare(strict_types=1);

/**
 * The shortest useful program: create a graph, write to it, read it back.
 *
 *     php examples/quickstart.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Ladybug\Connector\ConnectorFactory;
use Ladybug\Database;
use Ladybug\Type\Node;

// Which backend is available, and why not the others.
foreach (ConnectorFactory::diagnostics() as $id => $info) {
    printf("%-4s %-9s %s\n", $id, $info['available'] ? 'available' : 'skipped', $info['detail']);
}

$database = Database::inMemory();
$connection = $database->connect();

printf("\nconnector: %s, liblbug %s\n\n", $database->connectorId(), $database->libraryVersion());

$connection->run('CREATE NODE TABLE Person(name STRING, age INT64, PRIMARY KEY(name))');
$connection->run('CREATE REL TABLE Knows(FROM Person TO Person, since INT64)');

// Writes go through bound parameters; the statement is prepared once and cached.
foreach ([['Piotr', 40], ['Ada', 36], ['Grace', 45]] as [$name, $age]) {
    $connection->run('CREATE (:Person {name: $name, age: $age})', ['name' => $name, 'age' => $age]);
}

$connection->run(<<<'CYPHER'
    MATCH (a:Person), (b:Person)
    WHERE a.name = 'Piotr' AND b.name = 'Ada'
    CREATE (a)-[:Knows {since: 2019}]->(b)
    CYPHER);

// A single scalar.
echo 'people: ', $connection->query('MATCH (p:Person) RETURN count(*)')->fetchOne(), "\n";

// One column, flattened.
$names = $connection->query('MATCH (p:Person) RETURN p.name ORDER BY p.name')->fetchColumn();
echo 'names: ', implode(', ', $names), "\n";

// Streaming rows: one row in memory at a time.
echo "\nolder than 38:\n";
foreach ($connection->query('MATCH (p:Person) WHERE p.age > $min RETURN p.name, p.age ORDER BY p.age DESC', ['min' => 38]) as $row) {
    printf("  %-8s %d\n", $row['p.name'], $row['p.age']);
}

// Whole nodes come back as objects.
$node = $connection->query("MATCH (p:Person) WHERE p.name = 'Ada' RETURN p")->fetchOne();
assert($node instanceof Node);
printf("\n%s node %s: %s\n", $node->label, $node->id, json_encode($node->properties, JSON_THROW_ON_ERROR));

// Traversal.
$row = $connection->query('MATCH (a:Person)-[k:Knows]->(b:Person) RETURN a.name, b.name, k.since')->fetchRow();
printf("%s knows %s since %d\n", $row['a.name'], $row['b.name'], $row['k.since']);

// Transactions roll back on any throw.
try {
    $connection->transaction(static function ($conn): void {
        $conn->run("CREATE (:Person {name: 'Temporary', age: 1})");
        throw new RuntimeException('changed my mind');
    });
} catch (RuntimeException $e) {
    printf("\nrolled back (%s); people still: %d\n", $e->getMessage(), $connection->query('MATCH (p:Person) RETURN count(*)')->fetchOne());
}

$database->close();
