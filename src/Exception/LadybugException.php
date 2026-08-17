<?php

declare(strict_types=1);

namespace Ladybug\Exception;

/**
 * Every exception thrown by this library implements this interface, so callers can
 * catch the whole library with a single catch block.
 *
 * The classes implementing it are deliberately not `final`, unlike everything else here:
 * {@see IncompatibleLibraryException} is itself a subclass of {@see ConnectorException}, and an
 * application that wants to narrow one further should be able to do the same. Adding a subclass
 * of an existing exception is not a breaking change; changing what an existing class extends is,
 * and we will not do it in a minor release.
 */
interface LadybugException extends \Throwable {}
