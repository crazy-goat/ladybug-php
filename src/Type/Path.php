<?php

declare(strict_types=1);

namespace Ladybug\Type;

/**
 * A path returned by a recursive relationship pattern (`MATCH p = (a)-[:Knows*1..3]->(b)`).
 *
 * Deliberately not iterable or countable: "iterating a path" and "the size of a path" have
 * two defensible meanings each (nodes or relationships), and a wrong guess in an API heading
 * for 1.0 is worse than making the caller say which one it wants. Read `$path->nodes`,
 * `$path->rels`, or `length()` for the hop count.
 */
final readonly class Path implements \JsonSerializable
{
    /**
     * @param list<Node> $nodes in traversal order, starting at the pattern's left-hand side
     * @param list<Rel>  $rels  one fewer than $nodes for a simple path
     */
    public function __construct(
        public array $nodes = [],
        public array $rels = [],
    ) {}

    /** Hops traversed: a path of one node has length 0. */
    public function length(): int
    {
        return \count($this->rels);
    }

    public function start(): ?Node
    {
        return $this->nodes[0] ?? null;
    }

    public function end(): ?Node
    {
        return $this->nodes === [] ? null : $this->nodes[\count($this->nodes) - 1];
    }

    /**
     * Keys mirror liblbug's own field names, so a path survives a JSON round trip in a shape
     * that still resembles what the database returned.
     *
     * @return array{_nodes: list<Node>, _rels: list<Rel>}
     */
    public function jsonSerialize(): array
    {
        return ['_nodes' => $this->nodes, '_rels' => $this->rels];
    }
}
