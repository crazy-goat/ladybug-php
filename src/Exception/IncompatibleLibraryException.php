<?php

declare(strict_types=1);

namespace Ladybug\Exception;

/**
 * The liblbug found at runtime is not a version this package was built against.
 *
 * This is a hard failure on purpose. Both connectors describe liblbug's structs by hand
 * — the FFI connector in Cdef, the extension through the header it was compiled with —
 * and a struct whose layout changed underneath us does not produce a nice error: it
 * produces silently wrong values or a segfault. Refusing to start is the safe outcome.
 */
final class IncompatibleLibraryException extends ConnectorException {}
