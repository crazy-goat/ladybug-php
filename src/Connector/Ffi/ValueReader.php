<?php

declare(strict_types=1);

namespace Ladybug\Connector\Ffi;

use FFI;
use FFI\CData;
use Ladybug\Exception\ConnectorException;
use Ladybug\Exception\TypeException;
use Ladybug\Type\DataType;
use Ladybug\Type\InternalId;
use Ladybug\Type\Node;
use Ladybug\Type\Path;
use Ladybug\Type\Rel;

/**
 * Converts an lbug_value into a PHP value.
 *
 * Every `lbug_value` obtained from a getter is owned by us and must be destroyed, which
 * is why the composite branches are written with try/finally: a throw halfway through a
 * nested list must not leak the elements already read.
 *
 * @internal FfiConnector's value-conversion half; the extension does the same work in C
 */
final class ValueReader
{
    private const OK = 0;

    public function __construct(private readonly \FFI $ffi) {}

    /**
     * Every liblbug getter reports success through lbug_state, and on failure it leaves the
     * out parameter untouched. Ignoring that does not produce an error — it produces a
     * plausible wrong value (a zeroed struct reads as an empty list) or, for the getters that
     * yield an lbug_value, a garbage handle that segfaults when read.
     *
     * ARRAY columns are exactly this case: liblbug's list accessors reject them, and the
     * unchecked version silently returned [].
     *
     * ConnectorException, not TypeException, to match what the extension's errors map to —
     * the same failure has to be catchable the same way on both backends.
     *
     * @throws ConnectorException
     */
    private function checked(int $state, string $message): void
    {
        if ($state !== self::OK) {
            throw new ConnectorException($message);
        }
    }

    /**
     * Allocates an owned C struct; FFI::new() is nullable and null must never reach liblbug.
     */
    private function alloc(string $type): CData
    {
        $data = $this->ffi->new($type);
        if ($data === null) {
            throw new TypeException("Could not allocate a {$type} for liblbug.");
        }

        return $data;
    }

    public function read(CData $value): mixed
    {
        if ($this->ffi->lbug_value_is_null(\FFI::addr($value))) {
            return null;
        }

        return match ($this->typeOf($value)) {
            DataType::Bool => $this->scalar($value, 'lbug_value_get_bool', 'bool'),
            DataType::Int8 => $this->scalar($value, 'lbug_value_get_int8', 'int8_t'),
            DataType::Int16 => $this->scalar($value, 'lbug_value_get_int16', 'int16_t'),
            DataType::Int32 => $this->scalar($value, 'lbug_value_get_int32', 'int32_t'),
            DataType::Int64, DataType::Serial => $this->scalar($value, 'lbug_value_get_int64', 'int64_t'),
            DataType::Uint8 => $this->scalar($value, 'lbug_value_get_uint8', 'uint8_t'),
            DataType::Uint16 => $this->scalar($value, 'lbug_value_get_uint16', 'uint16_t'),
            DataType::Uint32 => $this->scalar($value, 'lbug_value_get_uint32', 'uint32_t'),
            DataType::Uint64 => $this->unsigned64($value),
            DataType::Int128 => $this->int128($value),
            DataType::Float => $this->scalar($value, 'lbug_value_get_float', 'float'),
            DataType::Double => $this->scalar($value, 'lbug_value_get_double', 'double'),
            DataType::String => $this->string($value, 'lbug_value_get_string'),
            DataType::Uuid => $this->string($value, 'lbug_value_get_uuid'),
            DataType::Decimal => $this->string($value, 'lbug_value_get_decimal_as_string'),
            DataType::Blob => $this->blob($value),
            DataType::Date => $this->date($value),
            DataType::Timestamp => $this->timestamp($value, 'lbug_value_get_timestamp', 'lbug_timestamp_t', 1_000_000),
            DataType::TimestampTz => $this->timestamp($value, 'lbug_value_get_timestamp_tz', 'lbug_timestamp_tz_t', 1_000_000),
            DataType::TimestampMs => $this->timestamp($value, 'lbug_value_get_timestamp_ms', 'lbug_timestamp_ms_t', 1_000),
            DataType::TimestampSec => $this->timestamp($value, 'lbug_value_get_timestamp_sec', 'lbug_timestamp_sec_t', 1),
            DataType::TimestampNs => $this->timestamp($value, 'lbug_value_get_timestamp_ns', 'lbug_timestamp_ns_t', 1_000_000_000),
            DataType::Interval => $this->interval($value),
            DataType::InternalId => $this->internalId($value),
            DataType::List => $this->list($value),
            DataType::Struct => $this->struct($value),
            // liblbug 0.19.1's list and struct accessors reject ARRAY and UNION values, so
            // its own rendering is the only way to reach the contents through the C API. The
            // value itself is intact — cast(col AS STRING) in Cypher gives the same text.
            DataType::Array, DataType::Union => $this->toString($value),
            DataType::Map => $this->map($value),
            // JSON arrives as its textual form, which is what a caller decodes anyway.
            DataType::Json => $this->toString($value),
            DataType::RecursiveRel => $this->path($value),
            DataType::Node => $this->node($value),
            DataType::Rel => $this->rel($value),
            // RECURSIVE_REL and anything the header gains later: keep the data reachable
            // as a string rather than silently dropping the column.
            default => $this->toString($value),
        };
    }

    public function typeOf(CData $value): DataType
    {
        $type = $this->alloc('lbug_logical_type');
        $this->ffi->lbug_value_get_data_type(\FFI::addr($value), \FFI::addr($type));
        try {
            $id = $this->ffi->lbug_data_type_get_id(\FFI::addr($type));
        } finally {
            $this->ffi->lbug_data_type_destroy(\FFI::addr($type));
        }

        // An id with no case is a type a loaded extension added (the json extension uses 60,
        // which lbug.h does not declare). Reporting Unknown keeps the value readable as text
        // instead of making one unmapped column fail the whole query.
        return DataType::tryFrom($id) ?? DataType::Unknown;
    }

    // -- leaves ---------------------------------------------------------------------------

    private function scalar(CData $value, string $getter, string $ctype): mixed
    {
        $out = $this->alloc($ctype);
        $this->checked(
            $this->ffi->{$getter}(\FFI::addr($value), \FFI::addr($out)),
            "Could not read a {$ctype} value from the result.",
        );

        return $out->cdata;
    }

    /** UINT64 above PHP_INT_MAX cannot be a PHP int, so it degrades to a numeric string. */
    private function unsigned64(CData $value): int|string
    {
        $out = $this->alloc('uint64_t');
        $this->checked(
            $this->ffi->lbug_value_get_uint64(\FFI::addr($value), \FFI::addr($out)),
            'Could not read a UINT64 value from the result.',
        );
        $raw = $out->cdata;

        return $raw < 0 ? \sprintf('%u', $raw) : $raw;
    }

    /** INT128 always comes back as a numeric string; bcmath does the assembly when present. */
    private function int128(CData $value): string
    {
        $out = $this->alloc('lbug_int128_t');
        $this->checked(
            $this->ffi->lbug_value_get_int128(\FFI::addr($value), \FFI::addr($out)),
            'Could not read an INT128 value from the result.',
        );
        /** @var numeric-string $low */
        $low = $out->low < 0 ? \sprintf('%u', $out->low) : (string) $out->low;
        /** @var numeric-string $high */
        $high = (string) $out->high;

        if ($high === '0') {
            return $low;
        }
        if (!\function_exists('bcadd')) {
            throw new TypeException(
                'Reading INT128 values outside the 64-bit range requires ext-bcmath. Cast the column to STRING in Cypher instead.',
            );
        }

        return bcadd(bcmul($high, '18446744073709551616'), $low);
    }

    private function string(CData $value, string $getter): string
    {
        $out = $this->alloc('char*');
        $this->checked(
            $this->ffi->{$getter}(\FFI::addr($value), \FFI::addr($out)),
            'Could not read a string value from the result.',
        );
        try {
            return \FFI::string($out);
        } finally {
            $this->ffi->lbug_destroy_string($out);
        }
    }

    private function blob(CData $value): string
    {
        $out = $this->alloc('uint8_t*');
        $len = $this->alloc('uint64_t');
        $this->checked(
            $this->ffi->lbug_value_get_blob(\FFI::addr($value), \FFI::addr($out), \FFI::addr($len)),
            'Could not read a BLOB value from the result.',
        );
        try {
            return \FFI::string($out, $len->cdata);
        } finally {
            $this->ffi->lbug_destroy_blob($out);
        }
    }

    private function toString(CData $value): string
    {
        $str = $this->ffi->lbug_value_to_string(\FFI::addr($value));
        try {
            return \FFI::string($str);
        } finally {
            $this->ffi->lbug_destroy_string($str);
        }
    }

    // -- temporal -------------------------------------------------------------------------

    private function date(CData $value): \DateTimeImmutable
    {
        $out = $this->alloc('lbug_date_t');
        $this->checked(
            $this->ffi->lbug_value_get_date(\FFI::addr($value), \FFI::addr($out)),
            'Could not read a DATE value from the result.',
        );

        return (new \DateTimeImmutable('1970-01-01 00:00:00', new \DateTimeZone('UTC')))
            ->modify(\sprintf('%+d days', $out->days));
    }

    /**
     * All timestamp flavours land on DateTimeImmutable in UTC. Microsecond is the finest
     * resolution PHP has, so TIMESTAMP_NS is truncated — read it as a string via
     * `CAST(col AS STRING)` when the extra digits matter.
     */
    private function timestamp(CData $value, string $getter, string $ctype, int $perSecond): \DateTimeImmutable
    {
        $out = $this->alloc($ctype);
        $this->checked(
            $this->ffi->{$getter}(\FFI::addr($value), \FFI::addr($out)),
            'Could not read a timestamp value from the result.',
        );
        $raw = $out->value;

        $seconds = intdiv($raw, $perSecond);
        $fraction = $raw - $seconds * $perSecond;
        if ($fraction < 0) {   // intdiv truncates toward zero; keep the remainder positive
            --$seconds;
            $fraction += $perSecond;
        }
        $micros = $perSecond >= 1_000_000
            ? intdiv($fraction, intdiv($perSecond, 1_000_000))
            : $fraction * intdiv(1_000_000, $perSecond);

        $utc = new \DateTimeZone('UTC');
        $timestamp = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%d.%06d', $seconds, $micros), $utc);
        if ($timestamp === false) {
            throw new TypeException(\sprintf('Could not convert the timestamp value %d to a DateTimeImmutable.', $raw));
        }

        // createFromFormat('U.u') yields a +00:00 offset zone; name it UTC instead so
        // callers see a stable getTimezone()->getName().
        return $timestamp->setTimezone($utc);
    }

    private function interval(CData $value): \DateInterval
    {
        $out = $this->alloc('lbug_interval_t');
        $this->checked(
            $this->ffi->lbug_value_get_interval(\FFI::addr($value), \FFI::addr($out)),
            'Could not read an INTERVAL value from the result.',
        );

        $interval = new \DateInterval('PT0S');
        $interval->y = intdiv($out->months, 12);
        $interval->m = $out->months % 12;
        $interval->d = $out->days;
        $micros = $out->micros;
        $interval->h = intdiv($micros, 3_600_000_000);
        $micros %= 3_600_000_000;
        $interval->i = intdiv($micros, 60_000_000);
        $micros %= 60_000_000;
        $interval->s = intdiv($micros, 1_000_000);
        $interval->f = ($micros % 1_000_000) / 1_000_000;

        return $interval;
    }

    private function internalId(CData $value): InternalId
    {
        return new InternalId(...$this->readInternalId($value));
    }

    /** @return array{tableId: int, offset: int} */
    private function readInternalId(CData $value): array
    {
        $out = $this->alloc('lbug_internal_id_t');
        $this->checked(
            $this->ffi->lbug_value_get_internal_id(\FFI::addr($value), \FFI::addr($out)),
            'Could not read an INTERNAL_ID value from the result.',
        );

        return ['tableId' => $out->table_id, 'offset' => $out->offset];
    }

    // -- composites -----------------------------------------------------------------------

    /** @return list<mixed> */
    private function list(CData $value): array
    {
        $size = $this->alloc('uint64_t');
        $this->checked(
            $this->ffi->lbug_value_get_list_size(\FFI::addr($value), \FFI::addr($size)),
            'Could not read the size of a LIST value.',
        );

        $items = [];
        for ($i = 0; $i < $size->cdata; ++$i) {
            $element = $this->alloc('lbug_value');
            $this->checked(
                $this->ffi->lbug_value_get_list_element(\FFI::addr($value), $i, \FFI::addr($element)),
                "Could not read element {$i} of a LIST value.",
            );
            try {
                $items[] = $this->read($element);
            } finally {
                $this->ffi->lbug_value_destroy(\FFI::addr($element));
            }
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function struct(CData $value): array
    {
        $count = $this->alloc('uint64_t');
        $this->checked(
            $this->ffi->lbug_value_get_struct_num_fields(\FFI::addr($value), \FFI::addr($count)),
            'Could not read the field count of a STRUCT value.',
        );

        $fields = [];
        for ($i = 0; $i < $count->cdata; ++$i) {
            $name = $this->alloc('char*');
            $this->checked(
                $this->ffi->lbug_value_get_struct_field_name(\FFI::addr($value), $i, \FFI::addr($name)),
                "Could not read field name {$i} of a STRUCT value.",
            );
            try {
                $key = \FFI::string($name);
            } finally {
                $this->ffi->lbug_destroy_string($name);
            }

            $field = $this->alloc('lbug_value');
            $this->checked(
                $this->ffi->lbug_value_get_struct_field_value(\FFI::addr($value), $i, \FFI::addr($field)),
                "Could not read field {$i} of a STRUCT value.",
            );
            try {
                $fields[$key] = $this->read($field);
            } finally {
                $this->ffi->lbug_value_destroy(\FFI::addr($field));
            }
        }

        return $fields;
    }

    /**
     * Cypher MAP keys are arbitrary values. When they all fit PHP array keys we return an
     * associative array; otherwise a list of ['key' => …, 'value' => …] pairs, so no
     * entry is ever lost to key coercion.
     */
    /** @return array<array-key, mixed>|list<array{key: mixed, value: mixed}> */
    private function map(CData $value): array
    {
        $size = $this->alloc('uint64_t');
        $this->checked(
            $this->ffi->lbug_value_get_map_size(\FFI::addr($value), \FFI::addr($size)),
            'Could not read the size of a MAP value.',
        );

        $pairs = [];
        $usableKeys = true;
        for ($i = 0; $i < $size->cdata; ++$i) {
            $keyValue = $this->alloc('lbug_value');
            $this->checked(
                $this->ffi->lbug_value_get_map_key(\FFI::addr($value), $i, \FFI::addr($keyValue)),
                "Could not read key {$i} of a MAP value.",
            );
            try {
                $key = $this->read($keyValue);
            } finally {
                $this->ffi->lbug_value_destroy(\FFI::addr($keyValue));
            }

            $entryValue = $this->alloc('lbug_value');
            $this->checked(
                $this->ffi->lbug_value_get_map_value(\FFI::addr($value), $i, \FFI::addr($entryValue)),
                "Could not read value {$i} of a MAP value.",
            );
            try {
                $entry = $this->read($entryValue);
            } finally {
                $this->ffi->lbug_value_destroy(\FFI::addr($entryValue));
            }

            $usableKeys = $usableKeys && (\is_int($key) || \is_string($key));
            $pairs[] = ['key' => $key, 'value' => $entry];
        }

        if (!$usableKeys) {
            return $pairs;
        }

        $map = [];
        foreach ($pairs as $pair) {
            $map[$pair['key']] = $pair['value'];
        }

        return $map;
    }

    /**
     * A RECURSIVE_REL is a STRUCT of two lists, which the struct accessors do read — unlike
     * ARRAY and UNION above. The field names come from liblbug ("_NODES", "_RELS"); matched
     * case-insensitively so a change in casing is not a silent behaviour change.
     */
    private function path(CData $value): Path
    {
        $fields = [];
        foreach ($this->struct($value) as $name => $field) {
            $fields[strtoupper((string) $name)] = $field;
        }

        return new Path(
            nodes: $this->pathMembers($fields, '_NODES', Node::class),
            rels: $this->pathMembers($fields, '_RELS', Rel::class),
        );
    }

    /**
     * @template T of Node|Rel
     *
     * @param array<string, mixed> $fields
     * @param class-string<T>      $expected
     *
     * @return list<T>
     */
    private function pathMembers(array $fields, string $key, string $expected): array
    {
        $members = $fields[$key] ?? null;
        if (!\is_array($members)) {
            throw new TypeException(\sprintf(
                'A RECURSIVE_REL value has no %s list (fields: %s). liblbug returned a path shape this client does not know.',
                $key,
                implode(', ', array_keys($fields)) ?: 'none',
            ));
        }

        $typed = [];
        foreach ($members as $member) {
            if (!$member instanceof $expected) {
                throw new TypeException(\sprintf(
                    'A RECURSIVE_REL %s entry is %s, expected %s.',
                    $key,
                    get_debug_type($member),
                    $expected,
                ));
            }
            $typed[] = $member;
        }

        return $typed;
    }

    private function node(CData $value): Node
    {
        return new Node(
            id: $this->idOf($value, 'lbug_node_val_get_id_val') ?? new InternalId(0, 0),
            label: $this->labelOf($value, 'lbug_node_val_get_label_val'),
            properties: $this->properties($value, 'lbug_node_val_get_property_size', 'lbug_node_val_get_property_name_at', 'lbug_node_val_get_property_value_at'),
        );
    }

    private function rel(CData $value): Rel
    {
        return new Rel(
            // A rel reached through a recursive path may carry no identity of its own.
            id: $this->idOf($value, 'lbug_rel_val_get_id_val'),
            label: $this->labelOf($value, 'lbug_rel_val_get_label_val'),
            src: $this->idOf($value, 'lbug_rel_val_get_src_id_val') ?? new InternalId(0, 0),
            dst: $this->idOf($value, 'lbug_rel_val_get_dst_id_val') ?? new InternalId(0, 0),
            properties: $this->properties($value, 'lbug_rel_val_get_property_size', 'lbug_rel_val_get_property_name_at', 'lbug_rel_val_get_property_value_at'),
        );
    }

    /** Reads an INTERNAL_ID sub-value, freeing the intermediate lbug_value either way. */
    private function idOf(CData $value, string $getter): ?InternalId
    {
        $out = $this->alloc('lbug_value');
        if ($this->ffi->{$getter}(\FFI::addr($value), \FFI::addr($out)) !== 0) {
            return null;
        }
        try {
            if ($this->ffi->lbug_value_is_null(\FFI::addr($out))) {
                return null;
            }

            return new InternalId(...$this->readInternalId($out));
        } finally {
            $this->ffi->lbug_value_destroy(\FFI::addr($out));
        }
    }

    private function labelOf(CData $value, string $getter): string
    {
        $label = $this->alloc('lbug_value');
        $this->checked(
            $this->ffi->{$getter}(\FFI::addr($value), \FFI::addr($label)),
            'Could not read the label of a graph value.',
        );
        try {
            return (string) $this->read($label);
        } finally {
            $this->ffi->lbug_value_destroy(\FFI::addr($label));
        }
    }

    /** @return array<string, mixed> */
    private function properties(CData $value, string $sizeGetter, string $nameGetter, string $valueGetter): array
    {
        $count = $this->alloc('uint64_t');
        $this->checked(
            $this->ffi->{$sizeGetter}(\FFI::addr($value), \FFI::addr($count)),
            'Could not read the property count of a graph value.',
        );

        $properties = [];
        for ($i = 0; $i < $count->cdata; ++$i) {
            $name = $this->alloc('char*');
            $this->checked(
                $this->ffi->{$nameGetter}(\FFI::addr($value), $i, \FFI::addr($name)),
                "Could not read property name {$i} of a graph value.",
            );
            try {
                $key = \FFI::string($name);
            } finally {
                $this->ffi->lbug_destroy_string($name);
            }

            $property = $this->alloc('lbug_value');
            $this->checked(
                $this->ffi->{$valueGetter}(\FFI::addr($value), $i, \FFI::addr($property)),
                "Could not read property {$i} of a graph value.",
            );
            try {
                $properties[$key] = $this->read($property);
            } finally {
                $this->ffi->lbug_value_destroy(\FFI::addr($property));
            }
        }

        return $properties;
    }
}
