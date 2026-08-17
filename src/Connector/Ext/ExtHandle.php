<?php

declare(strict_types=1);

namespace Ladybug\Connector\Ext;

use Ladybug\Connector\Handle;
use Ladybug\Exception\ConnectorException;

/**
 * Wraps an opaque object handed out by the native extension. Unlike the FFI variant there
 * is no manual memory to pin: the extension's own object destructor frees the C resource
 * if we never close it explicitly.
 *
 * @internal produced and consumed by ExtConnector only
 */
final class ExtHandle implements Handle
{
    private bool $open = true;

    public function __construct(
        private readonly string $kind,
        private readonly object $object,
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

    /**
     * @internal for ExtConnector only
     *
     * The extension hands out one opaque class per kind; the caller knows which one it
     * asked for, so this is annotated loosely and narrowed at the call site.
     */
    public function object(): object
    {
        if (!$this->open) {
            throw new ConnectorException("This {$this->kind} handle is already closed.");
        }

        return $this->object;
    }

    /** @internal returns false if it was already closed */
    public function markClosed(): bool
    {
        if (!$this->open) {
            return false;
        }

        $this->open = false;

        return true;
    }
}
