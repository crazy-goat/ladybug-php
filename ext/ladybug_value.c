/*
 * lbug_value -> zval conversion.
 *
 * This file must stay observably identical to Ladybug\Connector\Ffi\ValueReader: the
 * integration suite runs the same assertions against both connectors, so any divergence
 * here shows up as a test failure rather than as a subtle production surprise.
 *
 * Ownership rule: every lbug_value obtained from a getter is ours and must be destroyed,
 * including on the error paths — hence the goto cleanup style in the composite branches.
 */

#include "php_ladybug.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "ext/standard/php_string.h"

#include <time.h>
#include <inttypes.h>

void ladybug_throw(zend_class_entry *ce, const char *format, ...)
{
    va_list args;
    char *message = NULL;

    va_start(args, format);
    vspprintf(&message, 0, format, args);
    va_end(args);

    zend_throw_exception(ce, message, 0);
    efree(message);
}

zend_string *ladybug_take_string(char *owned)
{
    zend_string *result;

    if (owned == NULL) {
        return NULL;
    }
    result = zend_string_init(owned, strlen(owned), 0);
    lbug_destroy_string(owned);

    return result;
}

/* -- userland class lookups ---------------------------------------------------------- */

static zend_class_entry *ladybug_lookup_class(zend_class_entry **cache, const char *name)
{
    zend_string *class_name;

    if (*cache != NULL) {
        return *cache;
    }

    class_name = zend_string_init(name, strlen(name), 0);
    *cache = zend_lookup_class(class_name);
    zend_string_release(class_name);

    if (*cache == NULL && !EG(exception)) {
        ladybug_throw(
            ladybug_exception_ce,
            "Class %s is not available. Values of this type need the crazy-goat/ladybug-php "
            "package (or its autoloader) to be loaded.",
            name
        );
    }

    return *cache;
}

static int ladybug_new_instance(zend_class_entry *ce, zval *out, uint32_t argc, zval *args)
{
    if (object_init_ex(out, ce) != SUCCESS) {
        ZVAL_UNDEF(out);
        return FAILURE;
    }
    if (ce->constructor != NULL) {
        zend_call_known_instance_method(ce->constructor, Z_OBJ_P(out), NULL, argc, args);
        if (EG(exception)) {
            zval_ptr_dtor(out);
            ZVAL_UNDEF(out);
            return FAILURE;
        }
    }

    return SUCCESS;
}

/* -- leaves -------------------------------------------------------------------------- */

/* UINT64 above ZEND_LONG_MAX cannot be a PHP int, so it degrades to a numeric string. */
static void ladybug_uint64_to_zval(uint64_t value, zval *out)
{
    if (value <= (uint64_t) ZEND_LONG_MAX) {
        ZVAL_LONG(out, (zend_long) value);
        return;
    }
    ZVAL_STR(out, strpprintf(0, "%" PRIu64, value));
}

/*
 * INT128 always comes back as a numeric string. The low/high halves are assembled by long
 * division on the decimal representation, so no bcmath dependency and no precision loss.
 */
static void ladybug_int128_to_zval(lbug_int128_t value, zval *out)
{
    /* 128-bit two's complement, little-endian halves. */
    unsigned __int128 magnitude;
    bool negative = value.high < 0;
    char buffer[41];
    int position = (int) sizeof(buffer) - 1;

    if (negative) {
        /* Negate across the pair without overflowing either half. */
        unsigned __int128 combined = ((unsigned __int128) (uint64_t) value.high << 64) | value.low;
        magnitude = (unsigned __int128) (-(__int128) combined);
    } else {
        magnitude = ((unsigned __int128) (uint64_t) value.high << 64) | value.low;
    }

    buffer[position] = '\0';
    if (magnitude == 0) {
        buffer[--position] = '0';
    } else {
        while (magnitude > 0) {
            buffer[--position] = (char) ('0' + (int) (magnitude % 10));
            magnitude /= 10;
        }
    }
    if (negative) {
        buffer[--position] = '-';
    }

    ZVAL_STRING(out, buffer + position);
}

static int ladybug_string_to_zval(lbug_value *value, zval *out, lbug_state (*getter)(lbug_value *, char **))
{
    char *owned = NULL;
    zend_string *result;

    if (getter(value, &owned) != LbugSuccess) {
        ladybug_throw(ladybug_exception_ce, "Could not read a string value from the result.");
        return FAILURE;
    }
    result = ladybug_take_string(owned);
    if (result == NULL) {
        ZVAL_EMPTY_STRING(out);
        return SUCCESS;
    }
    ZVAL_STR(out, result);

    return SUCCESS;
}

static int ladybug_blob_to_zval(lbug_value *value, zval *out)
{
    uint8_t *blob = NULL;
    uint64_t length = 0;

    if (lbug_value_get_blob(value, &blob, &length) != LbugSuccess) {
        ladybug_throw(ladybug_exception_ce, "Could not read a BLOB value from the result.");
        return FAILURE;
    }
    ZVAL_STRINGL(out, (const char *) blob, (size_t) length);
    lbug_destroy_blob(blob);

    return SUCCESS;
}

/* Last resort for types with no dedicated PHP shape: keep the data reachable as text
 * rather than dropping the column. RECURSIVE_REL takes this path, as does anything a
 * future liblbug adds. */
static int ladybug_to_string_zval(lbug_value *value, zval *out)
{
    zend_string *rendered = ladybug_take_string(lbug_value_to_string(value));

    if (rendered == NULL) {
        ZVAL_NULL(out);
        return SUCCESS;
    }
    ZVAL_STR(out, rendered);

    return SUCCESS;
}

/* -- temporal ------------------------------------------------------------------------ */

/*
 * All temporal values land on DateTimeImmutable in UTC. The instance is built from a
 * formatted string with an explicit "UTC" suffix rather than from a Unix timestamp,
 * because "@0"-style construction yields a +00:00 offset zone whose getName() is "+00:00"
 * — and the FFI connector (which calls setTimezone) reports "UTC".
 */
static int ladybug_datetime_to_zval(int64_t seconds, int32_t micros, zval *out)
{
    zend_class_entry *ce = ladybug_lookup_class(&LADYBUG_G(datetime_ce), "DateTimeImmutable");
    time_t timestamp = (time_t) seconds;
    struct tm parts;
    zval argument;
    int status;

    if (ce == NULL) {
        return FAILURE;
    }
    if (gmtime_r(&timestamp, &parts) == NULL) {
        ladybug_throw(
            ladybug_exception_ce,
            "Timestamp %" PRId64 " is outside the range this platform can represent.",
            seconds
        );
        return FAILURE;
    }

    ZVAL_STR(&argument, strpprintf(0, "%04d-%02d-%02d %02d:%02d:%02d.%06d UTC",
        parts.tm_year + 1900,
        parts.tm_mon + 1,
        parts.tm_mday,
        parts.tm_hour,
        parts.tm_min,
        parts.tm_sec,
        micros));

    status = ladybug_new_instance(ce, out, 1, &argument);
    zval_ptr_dtor(&argument);

    return status;
}

/* Splits a raw count into whole seconds plus a positive microsecond remainder. */
static int ladybug_split_timestamp(int64_t raw, int64_t per_second, int64_t *seconds, int32_t *micros)
{
    int64_t fraction;

    *seconds = raw / per_second;
    fraction = raw - *seconds * per_second;
    if (fraction < 0) {  /* C division truncates toward zero; keep the remainder positive */
        --*seconds;
        fraction += per_second;
    }

    *micros = per_second >= 1000000
        ? (int32_t) (fraction / (per_second / 1000000))
        : (int32_t) (fraction * (1000000 / per_second));

    return SUCCESS;
}

static int ladybug_date_to_zval(lbug_value *value, zval *out)
{
    lbug_date_t date;

    if (lbug_value_get_date(value, &date) != LbugSuccess) {
        ladybug_throw(ladybug_exception_ce, "Could not read a DATE value from the result.");
        return FAILURE;
    }

    return ladybug_datetime_to_zval((int64_t) date.days * 86400, 0, out);
}

static int ladybug_timestamp_to_zval(lbug_value *value, lbug_data_type_id type, zval *out)
{
    int64_t raw;
    int64_t per_second;
    int64_t seconds;
    int32_t micros;

    switch (type) {
        case LBUG_TIMESTAMP: {
            lbug_timestamp_t ts;
            if (lbug_value_get_timestamp(value, &ts) != LbugSuccess) { goto failed; }
            raw = ts.value;
            per_second = 1000000;
            break;
        }
        case LBUG_TIMESTAMP_TZ: {
            lbug_timestamp_tz_t ts;
            if (lbug_value_get_timestamp_tz(value, &ts) != LbugSuccess) { goto failed; }
            raw = ts.value;
            per_second = 1000000;
            break;
        }
        case LBUG_TIMESTAMP_MS: {
            lbug_timestamp_ms_t ts;
            if (lbug_value_get_timestamp_ms(value, &ts) != LbugSuccess) { goto failed; }
            raw = ts.value;
            per_second = 1000;
            break;
        }
        case LBUG_TIMESTAMP_SEC: {
            lbug_timestamp_sec_t ts;
            if (lbug_value_get_timestamp_sec(value, &ts) != LbugSuccess) { goto failed; }
            raw = ts.value;
            per_second = 1;
            break;
        }
        case LBUG_TIMESTAMP_NS: {
            lbug_timestamp_ns_t ts;
            if (lbug_value_get_timestamp_ns(value, &ts) != LbugSuccess) { goto failed; }
            raw = ts.value;
            per_second = 1000000000;
            break;
        }
        default:
            ladybug_throw(ladybug_exception_ce, "Unhandled timestamp type %d.", (int) type);
            return FAILURE;
    }

    ladybug_split_timestamp(raw, per_second, &seconds, &micros);

    return ladybug_datetime_to_zval(seconds, micros, out);

failed:
    ladybug_throw(ladybug_exception_ce, "Could not read a timestamp value from the result.");
    return FAILURE;
}

/*
 * INTERVAL becomes a DateInterval with its fields set directly. Building an ISO 8601 spec
 * string instead would lose negative components, which liblbug does produce.
 */
static int ladybug_interval_to_zval(lbug_value *value, zval *out)
{
    zend_class_entry *ce = ladybug_lookup_class(&LADYBUG_G(dateinterval_ce), "DateInterval");
    lbug_interval_t interval;
    zval argument;
    int64_t micros;

    if (ce == NULL) {
        return FAILURE;
    }
    if (lbug_value_get_interval(value, &interval) != LbugSuccess) {
        ladybug_throw(ladybug_exception_ce, "Could not read an INTERVAL value from the result.");
        return FAILURE;
    }

    ZVAL_STRING(&argument, "PT0S");
    if (ladybug_new_instance(ce, out, 1, &argument) != SUCCESS) {
        zval_ptr_dtor(&argument);
        return FAILURE;
    }
    zval_ptr_dtor(&argument);

    micros = interval.micros;
    zend_update_property_long(ce, Z_OBJ_P(out), "y", 1, (zend_long) (interval.months / 12));
    zend_update_property_long(ce, Z_OBJ_P(out), "m", 1, (zend_long) (interval.months % 12));
    zend_update_property_long(ce, Z_OBJ_P(out), "d", 1, (zend_long) interval.days);
    zend_update_property_long(ce, Z_OBJ_P(out), "h", 1, (zend_long) (micros / 3600000000LL));
    micros %= 3600000000LL;
    zend_update_property_long(ce, Z_OBJ_P(out), "i", 1, (zend_long) (micros / 60000000LL));
    micros %= 60000000LL;
    zend_update_property_long(ce, Z_OBJ_P(out), "s", 1, (zend_long) (micros / 1000000LL));
    zend_update_property_double(ce, Z_OBJ_P(out), "f", 1, (double) (micros % 1000000LL) / 1000000.0);

    if (EG(exception)) {
        zval_ptr_dtor(out);
        ZVAL_UNDEF(out);
        return FAILURE;
    }

    return SUCCESS;
}

/* -- identities ---------------------------------------------------------------------- */

static int ladybug_internal_id_to_zval(lbug_internal_id_t id, zval *out)
{
    zend_class_entry *ce = ladybug_lookup_class(&LADYBUG_G(internal_id_ce), "Ladybug\\Type\\InternalId");
    zval arguments[2];
    int status;

    if (ce == NULL) {
        return FAILURE;
    }

    ZVAL_LONG(&arguments[0], (zend_long) id.table_id);
    ZVAL_LONG(&arguments[1], (zend_long) id.offset);
    status = ladybug_new_instance(ce, out, 2, arguments);

    return status;
}

static int ladybug_read_internal_id(lbug_value *value, lbug_internal_id_t *out)
{
    if (lbug_value_get_internal_id(value, out) != LbugSuccess) {
        ladybug_throw(ladybug_exception_ce, "Could not read an INTERNAL_ID value from the result.");
        return FAILURE;
    }

    return SUCCESS;
}

/* Reads an INTERNAL_ID sub-value, freeing the intermediate lbug_value either way. */
static int ladybug_id_of(
    lbug_value *value,
    lbug_state (*getter)(lbug_value *, lbug_value *),
    zval *out
) {
    lbug_value child;
    int status;

    if (getter(value, &child) != LbugSuccess) {
        ZVAL_NULL(out);
        return SUCCESS;
    }
    if (lbug_value_is_null(&child)) {
        ZVAL_NULL(out);
        lbug_value_destroy(&child);
        return SUCCESS;
    }

    lbug_internal_id_t id;
    status = ladybug_read_internal_id(&child, &id);
    lbug_value_destroy(&child);
    if (status != SUCCESS) {
        return FAILURE;
    }

    return ladybug_internal_id_to_zval(id, out);
}

static int ladybug_label_of(
    lbug_value *value,
    lbug_state (*getter)(lbug_value *, lbug_value *),
    zval *out
) {
    lbug_value child;
    int status;

    if (getter(value, &child) != LbugSuccess) {
        ZVAL_EMPTY_STRING(out);
        return SUCCESS;
    }
    status = ladybug_value_to_zval(&child, out);
    lbug_value_destroy(&child);
    if (status != SUCCESS) {
        return FAILURE;
    }
    if (Z_TYPE_P(out) != IS_STRING) {
        convert_to_string(out);
    }

    return SUCCESS;
}

/* -- composites ---------------------------------------------------------------------- */

static int ladybug_list_to_zval(lbug_value *value, zval *out)
{
    uint64_t size = 0;
    uint64_t index;

    if (lbug_value_get_list_size(value, &size) != LbugSuccess) {
        ladybug_throw(ladybug_exception_ce, "Could not read the size of a LIST value.");
        return FAILURE;
    }

    array_init_size(out, (uint32_t) size);
    for (index = 0; index < size; ++index) {
        lbug_value element;
        zval converted;

        if (lbug_value_get_list_element(value, index, &element) != LbugSuccess) {
            ladybug_throw(ladybug_exception_ce, "Could not read element %" PRIu64 " of a LIST value.", index);
            zval_ptr_dtor(out);
            ZVAL_UNDEF(out);
            return FAILURE;
        }
        if (ladybug_value_to_zval(&element, &converted) != SUCCESS) {
            lbug_value_destroy(&element);
            zval_ptr_dtor(out);
            ZVAL_UNDEF(out);
            return FAILURE;
        }
        lbug_value_destroy(&element);
        add_next_index_zval(out, &converted);
    }

    return SUCCESS;
}

static int ladybug_struct_to_zval(lbug_value *value, zval *out)
{
    uint64_t count = 0;
    uint64_t index;

    if (lbug_value_get_struct_num_fields(value, &count) != LbugSuccess) {
        ladybug_throw(ladybug_exception_ce, "Could not read the field count of a STRUCT value.");
        return FAILURE;
    }

    array_init_size(out, (uint32_t) count);
    for (index = 0; index < count; ++index) {
        char *owned_name = NULL;
        zend_string *name;
        lbug_value field;
        zval converted;

        if (lbug_value_get_struct_field_name(value, index, &owned_name) != LbugSuccess) {
            ladybug_throw(ladybug_exception_ce, "Could not read field name %" PRIu64 " of a STRUCT value.", index);
            goto failed;
        }
        name = ladybug_take_string(owned_name);
        if (name == NULL) {
            ladybug_throw(ladybug_exception_ce, "STRUCT field %" PRIu64 " has no name.", index);
            goto failed;
        }
        if (lbug_value_get_struct_field_value(value, index, &field) != LbugSuccess) {
            zend_string_release(name);
            ladybug_throw(ladybug_exception_ce, "Could not read field %" PRIu64 " of a STRUCT value.", index);
            goto failed;
        }
        if (ladybug_value_to_zval(&field, &converted) != SUCCESS) {
            lbug_value_destroy(&field);
            zend_string_release(name);
            goto failed;
        }
        lbug_value_destroy(&field);
        zend_symtable_update(Z_ARRVAL_P(out), name, &converted);
        zend_string_release(name);
    }

    return SUCCESS;

failed:
    zval_ptr_dtor(out);
    ZVAL_UNDEF(out);
    return FAILURE;
}

/*
 * Cypher MAP keys are arbitrary values. When every key fits a PHP array key an associative
 * array is returned; otherwise a list of ['key' => ..., 'value' => ...] pairs, so no entry
 * is lost to key coercion. Same rule as ValueReader::map().
 */
static int ladybug_map_to_zval(lbug_value *value, zval *out)
{
    uint64_t size = 0;
    uint64_t index;
    bool usable_keys = true;
    zval pairs;

    if (lbug_value_get_map_size(value, &size) != LbugSuccess) {
        ladybug_throw(ladybug_exception_ce, "Could not read the size of a MAP value.");
        return FAILURE;
    }

    array_init_size(&pairs, (uint32_t) size);
    for (index = 0; index < size; ++index) {
        lbug_value key_value;
        lbug_value entry_value;
        zval key;
        zval entry;
        zval pair;

        if (lbug_value_get_map_key(value, index, &key_value) != LbugSuccess) {
            ladybug_throw(ladybug_exception_ce, "Could not read key %" PRIu64 " of a MAP value.", index);
            goto failed;
        }
        if (ladybug_value_to_zval(&key_value, &key) != SUCCESS) {
            lbug_value_destroy(&key_value);
            goto failed;
        }
        lbug_value_destroy(&key_value);

        if (lbug_value_get_map_value(value, index, &entry_value) != LbugSuccess) {
            zval_ptr_dtor(&key);
            ladybug_throw(ladybug_exception_ce, "Could not read value %" PRIu64 " of a MAP value.", index);
            goto failed;
        }
        if (ladybug_value_to_zval(&entry_value, &entry) != SUCCESS) {
            lbug_value_destroy(&entry_value);
            zval_ptr_dtor(&key);
            goto failed;
        }
        lbug_value_destroy(&entry_value);

        if (Z_TYPE(key) != IS_LONG && Z_TYPE(key) != IS_STRING) {
            usable_keys = false;
        }

        array_init_size(&pair, 2);
        add_assoc_zval(&pair, "key", &key);
        add_assoc_zval(&pair, "value", &entry);
        add_next_index_zval(&pairs, &pair);
    }

    if (!usable_keys) {
        ZVAL_COPY_VALUE(out, &pairs);
        return SUCCESS;
    }

    /* Flatten the pairs into a plain associative array. */
    array_init_size(out, (uint32_t) size);
    {
        zval *pair;
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL(pairs), pair) {
            zval *key = zend_hash_str_find(Z_ARRVAL_P(pair), "key", 3);
            zval *entry = zend_hash_str_find(Z_ARRVAL_P(pair), "value", 5);

            if (key == NULL || entry == NULL) {
                continue;
            }
            Z_TRY_ADDREF_P(entry);
            if (Z_TYPE_P(key) == IS_LONG) {
                zend_hash_index_update(Z_ARRVAL_P(out), Z_LVAL_P(key), entry);
            } else {
                zend_symtable_update(Z_ARRVAL_P(out), Z_STR_P(key), entry);
            }
        } ZEND_HASH_FOREACH_END();
    }
    zval_ptr_dtor(&pairs);

    return SUCCESS;

failed:
    zval_ptr_dtor(&pairs);
    ZVAL_UNDEF(out);
    return FAILURE;
}

static int ladybug_properties_to_zval(
    lbug_value *value,
    lbug_state (*size_getter)(lbug_value *, uint64_t *),
    lbug_state (*name_getter)(lbug_value *, uint64_t, char **),
    lbug_state (*value_getter)(lbug_value *, uint64_t, lbug_value *),
    zval *out
) {
    uint64_t count = 0;
    uint64_t index;

    if (size_getter(value, &count) != LbugSuccess) {
        ladybug_throw(ladybug_exception_ce, "Could not read the property count of a graph value.");
        return FAILURE;
    }

    array_init_size(out, (uint32_t) count);
    for (index = 0; index < count; ++index) {
        char *owned_name = NULL;
        zend_string *name;
        lbug_value property;
        zval converted;

        if (name_getter(value, index, &owned_name) != LbugSuccess) {
            ladybug_throw(ladybug_exception_ce, "Could not read property name %" PRIu64 ".", index);
            goto failed;
        }
        name = ladybug_take_string(owned_name);
        if (name == NULL) {
            ladybug_throw(ladybug_exception_ce, "Property %" PRIu64 " has no name.", index);
            goto failed;
        }
        if (value_getter(value, index, &property) != LbugSuccess) {
            zend_string_release(name);
            ladybug_throw(ladybug_exception_ce, "Could not read property %" PRIu64 ".", index);
            goto failed;
        }
        if (ladybug_value_to_zval(&property, &converted) != SUCCESS) {
            lbug_value_destroy(&property);
            zend_string_release(name);
            goto failed;
        }
        lbug_value_destroy(&property);
        zend_symtable_update(Z_ARRVAL_P(out), name, &converted);
        zend_string_release(name);
    }

    return SUCCESS;

failed:
    zval_ptr_dtor(out);
    ZVAL_UNDEF(out);
    return FAILURE;
}

static int ladybug_node_to_zval(lbug_value *value, zval *out)
{
    zend_class_entry *ce = ladybug_lookup_class(&LADYBUG_G(node_ce), "Ladybug\\Type\\Node");
    zval arguments[3];
    int status;

    if (ce == NULL) {
        return FAILURE;
    }
    if (ladybug_id_of(value, lbug_node_val_get_id_val, &arguments[0]) != SUCCESS) {
        return FAILURE;
    }
    /* Node::$id is not nullable; a node always has an identity, but be defensive. */
    if (Z_TYPE(arguments[0]) == IS_NULL) {
        lbug_internal_id_t zero = {0, 0};
        zval_ptr_dtor(&arguments[0]);
        if (ladybug_internal_id_to_zval(zero, &arguments[0]) != SUCCESS) {
            return FAILURE;
        }
    }
    if (ladybug_label_of(value, lbug_node_val_get_label_val, &arguments[1]) != SUCCESS) {
        zval_ptr_dtor(&arguments[0]);
        return FAILURE;
    }
    if (ladybug_properties_to_zval(
            value,
            lbug_node_val_get_property_size,
            lbug_node_val_get_property_name_at,
            lbug_node_val_get_property_value_at,
            &arguments[2]
        ) != SUCCESS) {
        zval_ptr_dtor(&arguments[0]);
        zval_ptr_dtor(&arguments[1]);
        return FAILURE;
    }

    status = ladybug_new_instance(ce, out, 3, arguments);
    zval_ptr_dtor(&arguments[0]);
    zval_ptr_dtor(&arguments[1]);
    zval_ptr_dtor(&arguments[2]);

    return status;
}

static int ladybug_rel_to_zval(lbug_value *value, zval *out)
{
    zend_class_entry *ce = ladybug_lookup_class(&LADYBUG_G(rel_ce), "Ladybug\\Type\\Rel");
    zval arguments[5];
    uint32_t initialised = 0;
    int status;

    if (ce == NULL) {
        return FAILURE;
    }

    /* A rel reached through a recursive path may carry no identity of its own, which is
     * why Rel::$id is nullable while $src and $dst are not. */
    if (ladybug_id_of(value, lbug_rel_val_get_id_val, &arguments[0]) != SUCCESS) {
        goto failed;
    }
    ++initialised;
    if (ladybug_label_of(value, lbug_rel_val_get_label_val, &arguments[1]) != SUCCESS) {
        goto failed;
    }
    ++initialised;
    if (ladybug_id_of(value, lbug_rel_val_get_src_id_val, &arguments[2]) != SUCCESS) {
        goto failed;
    }
    ++initialised;
    if (ladybug_id_of(value, lbug_rel_val_get_dst_id_val, &arguments[3]) != SUCCESS) {
        goto failed;
    }
    ++initialised;
    if (ladybug_properties_to_zval(
            value,
            lbug_rel_val_get_property_size,
            lbug_rel_val_get_property_name_at,
            lbug_rel_val_get_property_value_at,
            &arguments[4]
        ) != SUCCESS) {
        goto failed;
    }
    ++initialised;

    for (uint32_t i = 2; i <= 3; ++i) {
        if (Z_TYPE(arguments[i]) == IS_NULL) {
            lbug_internal_id_t zero = {0, 0};
            zval_ptr_dtor(&arguments[i]);
            if (ladybug_internal_id_to_zval(zero, &arguments[i]) != SUCCESS) {
                goto failed;
            }
        }
    }

    status = ladybug_new_instance(ce, out, 5, arguments);
    for (uint32_t i = 0; i < initialised; ++i) {
        zval_ptr_dtor(&arguments[i]);
    }

    return status;

failed:
    for (uint32_t i = 0; i < initialised; ++i) {
        zval_ptr_dtor(&arguments[i]);
    }
    return FAILURE;
}

/* -- entry point --------------------------------------------------------------------- */

int ladybug_value_to_zval(lbug_value *value, zval *out)
{
    lbug_logical_type type;
    lbug_data_type_id type_id;

    if (lbug_value_is_null(value)) {
        ZVAL_NULL(out);
        return SUCCESS;
    }

    lbug_value_get_data_type(value, &type);
    type_id = lbug_data_type_get_id(&type);
    lbug_data_type_destroy(&type);

    switch (type_id) {
        case LBUG_BOOL: {
            bool result = false;
            if (lbug_value_get_bool(value, &result) != LbugSuccess) { goto read_failed; }
            ZVAL_BOOL(out, result);
            return SUCCESS;
        }
        case LBUG_INT8: {
            int8_t result = 0;
            if (lbug_value_get_int8(value, &result) != LbugSuccess) { goto read_failed; }
            ZVAL_LONG(out, result);
            return SUCCESS;
        }
        case LBUG_INT16: {
            int16_t result = 0;
            if (lbug_value_get_int16(value, &result) != LbugSuccess) { goto read_failed; }
            ZVAL_LONG(out, result);
            return SUCCESS;
        }
        case LBUG_INT32: {
            int32_t result = 0;
            if (lbug_value_get_int32(value, &result) != LbugSuccess) { goto read_failed; }
            ZVAL_LONG(out, result);
            return SUCCESS;
        }
        case LBUG_INT64:
        case LBUG_SERIAL: {
            int64_t result = 0;
            if (lbug_value_get_int64(value, &result) != LbugSuccess) { goto read_failed; }
            ZVAL_LONG(out, (zend_long) result);
            return SUCCESS;
        }
        case LBUG_UINT8: {
            uint8_t result = 0;
            if (lbug_value_get_uint8(value, &result) != LbugSuccess) { goto read_failed; }
            ZVAL_LONG(out, result);
            return SUCCESS;
        }
        case LBUG_UINT16: {
            uint16_t result = 0;
            if (lbug_value_get_uint16(value, &result) != LbugSuccess) { goto read_failed; }
            ZVAL_LONG(out, result);
            return SUCCESS;
        }
        case LBUG_UINT32: {
            uint32_t result = 0;
            if (lbug_value_get_uint32(value, &result) != LbugSuccess) { goto read_failed; }
            ZVAL_LONG(out, result);
            return SUCCESS;
        }
        case LBUG_UINT64: {
            uint64_t result = 0;
            if (lbug_value_get_uint64(value, &result) != LbugSuccess) { goto read_failed; }
            ladybug_uint64_to_zval(result, out);
            return SUCCESS;
        }
        case LBUG_INT128: {
            lbug_int128_t result = {0, 0};
            if (lbug_value_get_int128(value, &result) != LbugSuccess) { goto read_failed; }
            ladybug_int128_to_zval(result, out);
            return SUCCESS;
        }
        case LBUG_FLOAT: {
            float result = 0;
            if (lbug_value_get_float(value, &result) != LbugSuccess) { goto read_failed; }
            ZVAL_DOUBLE(out, (double) result);
            return SUCCESS;
        }
        case LBUG_DOUBLE: {
            double result = 0;
            if (lbug_value_get_double(value, &result) != LbugSuccess) { goto read_failed; }
            ZVAL_DOUBLE(out, result);
            return SUCCESS;
        }
        case LBUG_STRING:
            return ladybug_string_to_zval(value, out, lbug_value_get_string);
        case LBUG_UUID:
            return ladybug_string_to_zval(value, out, lbug_value_get_uuid);
        case LBUG_DECIMAL:
            /* Never a float: DECIMAL keeps its scale as a numeric string. */
            return ladybug_string_to_zval(value, out, lbug_value_get_decimal_as_string);
        case LBUG_BLOB:
            return ladybug_blob_to_zval(value, out);
        case LBUG_DATE:
            return ladybug_date_to_zval(value, out);
        case LBUG_TIMESTAMP:
        case LBUG_TIMESTAMP_TZ:
        case LBUG_TIMESTAMP_MS:
        case LBUG_TIMESTAMP_SEC:
        case LBUG_TIMESTAMP_NS:
            return ladybug_timestamp_to_zval(value, type_id, out);
        case LBUG_INTERVAL:
            return ladybug_interval_to_zval(value, out);
        case LBUG_INTERNAL_ID: {
            lbug_internal_id_t id;
            if (ladybug_read_internal_id(value, &id) != SUCCESS) { return FAILURE; }
            return ladybug_internal_id_to_zval(id, out);
        }
        case LBUG_LIST:
            return ladybug_list_to_zval(value, out);
        case LBUG_STRUCT:
            return ladybug_struct_to_zval(value, out);
        /* liblbug 0.19.1's list and struct accessors reject ARRAY and UNION values —
         * lbug_value_get_list_size() fails outright on a fixed-size ARRAY — so its own
         * rendering is the only way to reach the contents through the C API. The value is
         * intact: cast(col AS STRING) in Cypher gives the same text. Callers who want
         * structure should cast to LIST or read the union member in Cypher instead. */
        case LBUG_ARRAY:
        case LBUG_UNION:
            return ladybug_to_string_zval(value, out);
        case LBUG_MAP:
            return ladybug_map_to_zval(value, out);
        case LBUG_NODE:
            return ladybug_node_to_zval(value, out);
        case LBUG_REL:
            return ladybug_rel_to_zval(value, out);
        default:
            return ladybug_to_string_zval(value, out);
    }

read_failed:
    ladybug_throw(ladybug_exception_ce, "Could not read a value of type %d from the result.", (int) type_id);
    return FAILURE;
}
