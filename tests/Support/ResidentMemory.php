<?php

declare(strict_types=1);

namespace Ladybug\Tests\Support;

/**
 * Resident set size of the current process, in kilobytes.
 *
 * memory_get_usage() only accounts for the Zend allocator, so it cannot see a leaked
 * lbug_database or an lbug_value that was never destroyed — the leaks worth testing for here
 * all live outside PHP's heap. RSS does see them.
 */
final class ResidentMemory
{
    public static function isAvailable(): bool
    {
        return self::read() !== null;
    }

    /**
     * @throws \RuntimeException if RSS cannot be read on this platform
     */
    public static function kilobytes(): int
    {
        $rss = self::read();
        if ($rss === null) {
            throw new \RuntimeException('Cannot read the resident set size on this platform.');
        }

        return $rss;
    }

    private static function read(): ?int
    {
        return match (PHP_OS_FAMILY) {
            'Linux' => self::fromProc(),
            'Darwin', 'BSD' => self::fromPs(),
            default => null,
        };
    }

    private static function fromProc(): ?int
    {
        $statm = @file_get_contents('/proc/self/statm');
        if (!\is_string($statm)) {
            return null;
        }

        $fields = preg_split('/\s+/', trim($statm)) ?: [];
        // statm: size resident shared text lib data dt — all in pages.
        if (!isset($fields[1]) || !ctype_digit($fields[1])) {
            return null;
        }

        return (int) (((int) $fields[1] * self::pageSize()) / 1024);
    }

    private static function fromPs(): ?int
    {
        $output = @shell_exec(\sprintf('ps -o rss= -p %d 2>/dev/null', getmypid()));
        if (!\is_string($output) || trim($output) === '') {
            return null;
        }

        return (int) trim($output);
    }

    private static function pageSize(): int
    {
        $size = @shell_exec('getconf PAGESIZE 2>/dev/null');

        return \is_string($size) && ctype_digit(trim($size)) ? (int) trim($size) : 4096;
    }
}
