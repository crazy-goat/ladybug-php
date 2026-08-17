<?php

declare(strict_types=1);

namespace Ladybug\Exception;

/**
 * A statement that liblbug rejected or that failed while running, carrying the Cypher and the
 * parameters it was given.
 *
 * `$parameters` holds whatever the application bound, which in a real application is real data.
 * That is a deliberate trade: a query failure is nearly impossible to diagnose from the message
 * alone, and the alternative — redacting here — would hide the values in the one place they are
 * needed. `__toString()` therefore appends the Cypher but never the parameters, so the usual
 * paths (an uncaught exception, a framework error page, a log line) do not print them. Anything
 * that serialises the object itself, such as `var_dump()` or a debug payload sent to an error
 * tracker, will. Treat this exception as containing user data.
 */
class QueryException extends \RuntimeException implements LadybugException
{
    public function __construct(
        string $message,
        public readonly ?string $cypher = null,
        /** @var array<string, mixed> parameters as bound; may contain personal data */
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
