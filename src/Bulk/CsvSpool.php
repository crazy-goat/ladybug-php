<?php

declare(strict_types=1);

namespace Ladybug\Bulk;

use Ladybug\Exception\InvalidArgumentException;
use Ladybug\Exception\TypeException;

/**
 * Writes rows to a temporary CSV file for `COPY FROM`, in the dialect liblbug reads.
 *
 * `COPY FROM` is the only bulk path liblbug offers, and it takes a file — so the fast route
 * from PHP is to spool one. What matters is that the dialect matches exactly, because a
 * mismatch here does not fail: it stores the wrong value.
 *
 * Verified against liblbug 0.19.1:
 * - an empty unquoted field is NULL, so `null` writes as nothing at all
 * - quotes are doubled, RFC 4180 style
 * - a newline inside a quoted field needs `PARALLEL=FALSE` on the copy, which is why
 *   {@see needsSerialRead()} exists — paying for it unconditionally would halve throughput
 *
 * @internal
 */
final class CsvSpool
{
    private const DELIMITER = ',';

    private const QUOTE = '"';

    /** @var resource */
    private $handle;

    private int $rows = 0;

    private bool $sawNewline = false;

    private function __construct(public readonly string $path)
    {
        $handle = fopen($this->path, 'wb');
        if ($handle === false) {
            throw new InvalidArgumentException("Could not open the temporary copy file {$this->path} for writing.");
        }

        $this->handle = $handle;
    }

    public static function create(): self
    {
        $path = tempnam(sys_get_temp_dir(), 'ladybug-copy-');
        if ($path === false) {
            throw new InvalidArgumentException('Could not create a temporary file for COPY FROM.');
        }

        // The database reads this file back; nothing else has any business in it.
        @chmod($path, 0o600);

        return new self($path);
    }

    /**
     * @param list<mixed> $values already ordered to match the copy's column list
     */
    public function writeRow(array $values): void
    {
        $fields = [];
        foreach ($values as $value) {
            $fields[] = $this->encode($value);
        }

        if (fwrite($this->handle, implode(self::DELIMITER, $fields) . "\n") === false) {
            throw new InvalidArgumentException("Could not write to the temporary copy file {$this->path}.");
        }

        ++$this->rows;
    }

    public function rows(): int
    {
        return $this->rows;
    }

    /**
     * True when a value contained a line break. liblbug's parallel CSV reader rejects those
     * outright ("Quoted newlines are not supported in parallel CSV reader"), so the copy has
     * to ask for a serial read — but only then.
     */
    public function needsSerialRead(): bool
    {
        return $this->sawNewline;
    }

    public function close(): void
    {
        if (\is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function discard(): void
    {
        $this->close();
        @unlink($this->path);
    }

    private function encode(mixed $value): string
    {
        return match (true) {
            // An empty unquoted field is how liblbug spells NULL. A quoted empty string is
            // an empty string, so the two stay distinguishable.
            $value === null => '',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value) => (string) $value,
            \is_float($value) => $this->float($value),
            // A date is checked first: it has a dialect of its own and must never be
            // flattened into text, however it would stringify.
            $value instanceof \DateTimeInterface => $this->temporal($value),
            // Text has exactly one way into the file, whether the caller passed a string or
            // an object that returns one — so whatever string() refuses, an object cannot get
            // past. As two arms these had two sets of rules, and the second one skipped the
            // empty-string guard: an empty Stringable was stored as NULL.
            \is_string($value) || $value instanceof \Stringable => $this->string((string) $value),
            default => throw new TypeException(\sprintf(
                'Cannot copy a value of type %s. COPY FROM takes scalars, null and DateTimeInterface; '
                . 'for lists, structs or maps use CREATE with parameters instead.',
                get_debug_type($value),
            )),
        };
    }

    /**
     * The only route from a PHP string to a field in the file, which is why it does the
     * escaping too: nothing else here writes text, so there is nothing that can forget the
     * guard below.
     *
     * liblbug 0.19.1's CSV reader has no NULL sentinel — `""` and an empty field both read
     * back as NULL, and there is no option to separate them (null_str, nullstr and
     * NULL_STRING are all rejected as unrecognised). Rather than silently storing NULL where
     * the caller passed an empty string, this refuses the row: a bulk loader that changes
     * values without saying so is worse than one that declines the job.
     */
    private function string(string $value): string
    {
        if ($value === '') {
            throw new TypeException(
                'Cannot copy an empty string: liblbug reads an empty CSV field as NULL and offers no '
                . 'way to distinguish the two, so the value would silently change. Pass null if that is '
                . 'what you mean, or insert this row with CREATE and parameters.',
            );
        }

        $hasLineBreak = str_contains($value, "\n") || str_contains($value, "\r");
        if ($hasLineBreak) {
            $this->sawNewline = true;
        }

        // Quoting only what needs it keeps the file smaller and the copy faster.
        $mustQuote = $hasLineBreak
            || str_contains($value, self::DELIMITER)
            || str_contains($value, self::QUOTE);

        if (!$mustQuote) {
            return $value;
        }

        return self::QUOTE . str_replace(self::QUOTE, self::QUOTE . self::QUOTE, $value) . self::QUOTE;
    }

    /**
     * CSV carries no type, so the same text has to satisfy whichever column it lands in. A
     * DATE column rejects "1815-12-10 00:00:00.000000" outright, while both DATE and TIMESTAMP
     * accept "1815-12-10" — so a value with no time component is written date-only. That
     * loses nothing: a TIMESTAMP at midnight parses back to the same instant.
     */
    private function temporal(\DateTimeInterface $value): string
    {
        return $value->format('H:i:s.u') === '00:00:00.000000'
            ? $value->format('Y-m-d')
            : $value->format('Y-m-d H:i:s.u');
    }

    /**
     * Shortest representation that reads back as the same double, with a decimal point
     * regardless of locale — (string) honours precision, which can silently round.
     */
    private function float(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new TypeException(\sprintf(
                'Cannot copy the float value %s: CSV has no representation liblbug reads back as this number.',
                var_export($value, true),
            ));
        }

        $shortest = var_export($value, true);

        return str_contains($shortest, 'E') || str_contains($shortest, '.')
            ? $shortest
            : $shortest . '.0';
    }
}
