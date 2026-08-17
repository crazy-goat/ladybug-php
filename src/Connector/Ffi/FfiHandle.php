<?php

declare(strict_types=1);

namespace Ladybug\Connector\Ffi;

use FFI\CData;
use Ladybug\Connector\Handle;
use Ladybug\Exception\ConnectorException;

/**
 * Wraps an owned FFI struct. Holding the CData here is what keeps the memory alive:
 * once this object is collected, PHP frees the struct, so a handle must outlive every
 * C-side use of the pointer inside it.
 *
 * @internal produced and consumed by FfiConnector only
 */
final class FfiHandle implements Handle
{
    private bool $open = true;

    public function __construct(
        private readonly string $kind,
        private readonly CData $data,
        /** Retained solely to pin parent memory (e.g. a result keeps its connection alive). */
        private readonly ?Handle $parent = null,
    ) {}

    public function kind(): string
    {
        return $this->kind;
    }

    public function isOpen(): bool
    {
        return $this->open && (!$this->parent instanceof Handle || $this->parent->isOpen());
    }

    public function parent(): ?Handle
    {
        return $this->parent;
    }

    /** @internal for FfiConnector only */
    public function data(): CData
    {
        if (!$this->open) {
            throw new ConnectorException("This {$this->kind} handle is already closed.");
        }

        if ($this->parent instanceof Handle && !$this->parent->isOpen()) {
            throw new ConnectorException(
                "This {$this->kind} handle is unusable: its parent {$this->parent->kind()} was closed.",
            );
        }

        return $this->data;
    }

    /** @internal returns false if it was already closed, so callers can skip the C call */
    public function markClosed(): bool
    {
        if (!$this->open) {
            return false;
        }

        $this->open = false;

        return true;
    }
}
