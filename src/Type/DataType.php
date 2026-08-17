<?php

declare(strict_types=1);

namespace Ladybug\Type;

/**
 * Mirrors the `lbug_data_type_id` enum from lbug.h. Values are part of the C ABI —
 * do not renumber.
 */
enum DataType: int
{
    case Any = 0;
    case Node = 10;
    case Rel = 11;
    case RecursiveRel = 12;
    case Serial = 13;
    case Bool = 22;
    case Int64 = 23;
    case Int32 = 24;
    case Int16 = 25;
    case Int8 = 26;
    case Uint64 = 27;
    case Uint32 = 28;
    case Uint16 = 29;
    case Uint8 = 30;
    case Int128 = 31;
    case Double = 32;
    case Float = 33;
    case Date = 34;
    case Timestamp = 35;
    case TimestampSec = 36;
    case TimestampMs = 37;
    case TimestampNs = 38;
    case TimestampTz = 39;
    case Interval = 40;
    case Decimal = 41;
    case InternalId = 42;
    case String = 50;
    case Blob = 51;
    case List = 52;
    case Array = 53;
    case Struct = 54;
    case Map = 55;
    case Union = 56;
    case Pointer = 58;
    case Uuid = 59;

    /**
     * Contributed by the `json` extension (`INSTALL json`), so it is absent from lbug.h —
     * the core header stops at UUID = 59. Loaded extensions can introduce types at any time,
     * which is why an unrecognised id degrades to Unknown rather than failing the query.
     */
    case Json = 60;

    /**
     * Not a liblbug id: reported for a type this client has no case for. The value is still
     * readable — it arrives as liblbug's own rendering, the same fallback ARRAY, UNION and
     * anything unmapped use.
     */
    case Unknown = -1;

    /** The Cypher type name, as it appears in DDL and in `CAST` expressions. */
    public function cypherName(): string
    {
        return match ($this) {
            self::Any => 'ANY',
            self::Node => 'NODE',
            self::Rel => 'REL',
            self::RecursiveRel => 'RECURSIVE_REL',
            self::Serial => 'SERIAL',
            self::Bool => 'BOOL',
            self::Int64 => 'INT64',
            self::Int32 => 'INT32',
            self::Int16 => 'INT16',
            self::Int8 => 'INT8',
            self::Uint64 => 'UINT64',
            self::Uint32 => 'UINT32',
            self::Uint16 => 'UINT16',
            self::Uint8 => 'UINT8',
            self::Int128 => 'INT128',
            self::Double => 'DOUBLE',
            self::Float => 'FLOAT',
            self::Date => 'DATE',
            self::Timestamp => 'TIMESTAMP',
            self::TimestampSec => 'TIMESTAMP_SEC',
            self::TimestampMs => 'TIMESTAMP_MS',
            self::TimestampNs => 'TIMESTAMP_NS',
            self::TimestampTz => 'TIMESTAMP_TZ',
            self::Interval => 'INTERVAL',
            self::Decimal => 'DECIMAL',
            self::InternalId => 'INTERNAL_ID',
            self::String => 'STRING',
            self::Blob => 'BLOB',
            self::List => 'LIST',
            self::Array => 'ARRAY',
            self::Struct => 'STRUCT',
            self::Map => 'MAP',
            self::Union => 'UNION',
            self::Pointer => 'POINTER',
            self::Uuid => 'UUID',
            self::Json => 'JSON',
            self::Unknown => 'UNKNOWN',
        };
    }

    /** True for types this library maps to a PHP int or float. */
    public function isNumeric(): bool
    {
        return match ($this) {
            self::Int8, self::Int16, self::Int32, self::Int64, self::Serial,
            self::Uint8, self::Uint16, self::Uint32, self::Uint64,
            self::Float, self::Double => true,
            default => false,
        };
    }
}
