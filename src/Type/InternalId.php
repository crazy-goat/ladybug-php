<?php

declare(strict_types=1);

namespace Ladybug\Type;

/**
 * A node or relationship identity: the table it lives in plus its offset within that table.
 */
final readonly class InternalId implements \Stringable, \JsonSerializable
{
    public function __construct(
        public int $tableId,
        public int $offset,
    ) {}

    public function equals(self $other): bool
    {
        return $this->tableId === $other->tableId && $this->offset === $other->offset;
    }

    public function __toString(): string
    {
        return $this->tableId . ':' . $this->offset;
    }

    /** @return array{tableId: int, offset: int} */
    public function jsonSerialize(): array
    {
        return ['tableId' => $this->tableId, 'offset' => $this->offset];
    }
}
