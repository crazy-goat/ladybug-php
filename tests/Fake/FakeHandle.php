<?php

declare(strict_types=1);

namespace Ladybug\Tests\Fake;

use Ladybug\Connector\Handle;

final class FakeHandle implements Handle
{
    private bool $open = true;

    public function __construct(private readonly string $kind) {}

    public function kind(): string
    {
        return $this->kind;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function close(): void
    {
        $this->open = false;
    }
}
