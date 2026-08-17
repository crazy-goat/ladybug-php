<?php

declare(strict_types=1);

namespace Ladybug\Exception;

class QueryException extends \RuntimeException implements LadybugException
{
    public function __construct(
        string $message,
        public readonly ?string $cypher = null,
        /** @var array<string, mixed> */
        public readonly array $parameters = [],
    ) {
        parent::__construct($message);
    }

    public function __toString(): string
    {
        return $this->cypher === null
            ? parent::__toString()
            : parent::__toString() . "\nCypher: " . $this->cypher;
    }
}
