<?php

declare(strict_types=1);

namespace Ladybug\Exception;

/**
 * Every exception thrown by this library implements this interface, so callers can
 * catch the whole library with a single catch block.
 */
interface LadybugException extends \Throwable {}
