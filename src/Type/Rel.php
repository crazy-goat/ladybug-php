<?php

declare(strict_types=1);

namespace Ladybug\Type;

use Ladybug\Exception\InvalidArgumentException;

/**
 * A graph relationship, with the identities of the nodes it connects.
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
final readonly class Rel implements \ArrayAccess, \IteratorAggregate, \JsonSerializable
{
    public function __construct(
        public ?InternalId $id,
        public string $label,
        public InternalId $src,
        public InternalId $dst,
        /** @var array<string, mixed> */
        public array $properties = [],
    ) {}

    public function __get(string $name): mixed
    {
        if (!\array_key_exists($name, $this->properties)) {
            throw new InvalidArgumentException(
                \sprintf('Rel (%s) has no property "%s". Available: %s', $this->label, $name, implode(', ', array_keys($this->properties))),
            );
        }

        return $this->properties[$name];
    }

    public function __isset(string $name): bool
    {
        return isset($this->properties[$name]);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->properties[$name] ?? $default;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->properties[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new InvalidArgumentException('Query results are immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new InvalidArgumentException('Query results are immutable.');
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->properties);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            '_id' => $this->id,
            '_label' => $this->label,
            '_src' => $this->src,
            '_dst' => $this->dst,
        ] + $this->properties;
    }
}
