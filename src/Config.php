<?php

declare(strict_types=1);

namespace Ladybug;

use Ladybug\Exception\InvalidArgumentException;

/**
 * Database-level settings, mapped onto `lbug_system_config`. Every field defaults to
 * null, meaning "leave whatever liblbug's own default is" — we only overwrite the
 * fields the caller actually set.
 */
final readonly class Config
{
    public function __construct(
        /** Buffer pool size in bytes. Larger keeps more of the database in memory. */
        public ?int $bufferPoolSize = null,
        /** Worker threads per query. 0 lets liblbug decide. */
        public ?int $maxThreads = null,
        public ?bool $compression = null,
        public ?bool $readOnly = null,
        public ?int $maxDbSize = null,
        public ?bool $autoCheckpoint = null,
        public ?int $checkpointThreshold = null,
        /** Preferred connector: 'ext', 'ffi', or null to auto-detect. */
        public ?string $connector = null,
        /** Explicit path to liblbug (FFI connector only). Falls back to auto-discovery. */
        public ?string $libraryPath = null,
    ) {}

    public static function readOnly(): self
    {
        return new self(readOnly: true);
    }

    /**
     * A copy with some fields replaced, by name: `$config->with(readOnly: true)`.
     *
     * The signature has to be an untyped variadic for named arguments to reach it, which means
     * neither PHPStan nor the engine can check the names against the constructor. Unrecognised
     * ones are therefore rejected here: left to `new self(...)`, a typo raises a bare `\Error`
     * that escapes {@see \Ladybug\Exception\LadybugException}, and a positional argument raises
     * a different `\Error` about overwriting an earlier one. Both are the same mistake and both
     * deserve the same message.
     *
     * @param mixed ...$overrides keyed by constructor parameter name
     *
     * @throws InvalidArgumentException if a name is not a field of this class
     */
    public function with(mixed ...$overrides): self
    {
        $current = [
            'bufferPoolSize' => $this->bufferPoolSize,
            'maxThreads' => $this->maxThreads,
            'compression' => $this->compression,
            'readOnly' => $this->readOnly,
            'maxDbSize' => $this->maxDbSize,
            'autoCheckpoint' => $this->autoCheckpoint,
            'checkpointThreshold' => $this->checkpointThreshold,
            'connector' => $this->connector,
            'libraryPath' => $this->libraryPath,
        ];

        foreach (array_keys($overrides) as $name) {
            // array_key_exists, not isset: every field defaults to null, so isset() would reject
            // each name that has not been set yet — which is most of them.
            if (\is_string($name) && \array_key_exists($name, $current)) {
                continue;
            }

            throw new InvalidArgumentException(\is_int($name)
                ? \sprintf(
                    'Config::with() takes named arguments only; argument %d was positional. '
                    . 'Write with(%s: …).',
                    $name + 1,
                    array_keys($current)[$name] ?? 'name',
                )
                : \sprintf(
                    'Config has no field "%s". Known fields: %s.',
                    $name,
                    implode(', ', array_keys($current)),
                ));
        }

        return new self(...[...$current, ...$overrides]);
    }
}
