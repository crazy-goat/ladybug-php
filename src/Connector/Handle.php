<?php

declare(strict_types=1);

namespace Ladybug\Connector;

/**
 * An opaque reference to a resource owned by a connector (database, connection,
 * prepared statement or query result).
 *
 * The upper layers pass handles around but never look inside them. Each connector
 * defines its own implementation: the FFI connector wraps an \FFI\CData struct, the
 * native extension wraps a Zend object. A handle is only ever valid with the
 * connector that produced it.
 *
 * Implementing this is outside the version guarantee, for the reason given on
 * {@see Connector} — it is public only because that interface's signatures need a type.
 */
interface Handle
{
    /** One of: database, connection, statement, result. Used for error messages only. */
    public function kind(): string;

    /** False once the handle has been closed; using it afterwards is an error. */
    public function isOpen(): bool;
}
