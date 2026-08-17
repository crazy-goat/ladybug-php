<?php

declare(strict_types=1);

namespace Ladybug;

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

    public function with(mixed ...$overrides): self
    {
        return new self(...[...[
            'bufferPoolSize' => $this->bufferPoolSize,
            'maxThreads' => $this->maxThreads,
            'compression' => $this->compression,
            'readOnly' => $this->readOnly,
            'maxDbSize' => $this->maxDbSize,
            'autoCheckpoint' => $this->autoCheckpoint,
            'checkpointThreshold' => $this->checkpointThreshold,
            'connector' => $this->connector,
            'libraryPath' => $this->libraryPath,
        ], ...$overrides]);
    }
}
