/*
 * ladybug — module entry, handle objects and the ladybug_* function set.
 *
 * Handles are opaque final classes with no properties; the C resource lives in the object
 * struct. Each child handle holds a zval reference to its parent (connection -> database,
 * result -> connection) for two reasons: liblbug's destructors touch the parent, and a
 * result read lazily must not outlive the connection that produced it. The parent's `open`
 * flag is checked before every call, so a use-after-close is an exception rather than a
 * segfault.
 */

#include "php_ladybug.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "ext/date/php_date.h"
#include "ext/spl/spl_exceptions.h"
#include "ladybug_arginfo.h"

ZEND_DECLARE_MODULE_GLOBALS(ladybug)

zend_class_entry *ladybug_database_ce;
zend_class_entry *ladybug_connection_ce;
zend_class_entry *ladybug_statement_ce;
zend_class_entry *ladybug_result_ce;

zend_class_entry *ladybug_exception_ce;
zend_class_entry *ladybug_database_error_ce;
zend_class_entry *ladybug_query_error_ce;

static zend_object_handlers ladybug_database_handlers;
static zend_object_handlers ladybug_connection_handlers;
static zend_object_handlers ladybug_statement_handlers;
static zend_object_handlers ladybug_result_handlers;

/* -- object lifecycle ---------------------------------------------------------------- */

static zend_object *ladybug_database_create(zend_class_entry *ce)
{
    ladybug_database_object *object = zend_object_alloc(sizeof(ladybug_database_object), ce);

    zend_object_std_init(&object->std, ce);
    object_properties_init(&object->std, ce);
    object->std.handlers = &ladybug_database_handlers;
    object->open = false;

    return &object->std;
}

static void ladybug_database_free(zend_object *std)
{
    ladybug_database_object *object = LADYBUG_OBJ(ladybug_database_object, std);

    if (object->open) {
        object->open = false;
        lbug_database_destroy(&object->db);
    }
    zend_object_std_dtor(std);
}

static zend_object *ladybug_connection_create(zend_class_entry *ce)
{
    ladybug_connection_object *object = zend_object_alloc(sizeof(ladybug_connection_object), ce);

    zend_object_std_init(&object->std, ce);
    object_properties_init(&object->std, ce);
    object->std.handlers = &ladybug_connection_handlers;
    object->open = false;
    ZVAL_UNDEF(&object->database);

    return &object->std;
}

static void ladybug_connection_free(zend_object *std)
{
    ladybug_connection_object *object = LADYBUG_OBJ(ladybug_connection_object, std);

    if (object->open) {
        object->open = false;
        lbug_connection_destroy(&object->conn);
    }
    /* Only now may the database go: its destructor and ours are ordered. */
    if (!Z_ISUNDEF(object->database)) {
        zval_ptr_dtor(&object->database);
        ZVAL_UNDEF(&object->database);
    }
    zend_object_std_dtor(std);
}

static HashTable *ladybug_connection_gc(zend_object *std, zval **table, int *count)
{
    ladybug_connection_object *object = LADYBUG_OBJ(ladybug_connection_object, std);

    *table = &object->database;
    *count = Z_ISUNDEF(object->database) ? 0 : 1;

    return zend_std_get_properties(std);
}

static zend_object *ladybug_statement_create(zend_class_entry *ce)
{
    ladybug_statement_object *object = zend_object_alloc(sizeof(ladybug_statement_object), ce);

    zend_object_std_init(&object->std, ce);
    object_properties_init(&object->std, ce);
    object->std.handlers = &ladybug_statement_handlers;
    object->open = false;
    ZVAL_UNDEF(&object->connection);

    return &object->std;
}

static void ladybug_statement_free(zend_object *std)
{
    ladybug_statement_object *object = LADYBUG_OBJ(ladybug_statement_object, std);

    if (object->open) {
        object->open = false;
        lbug_prepared_statement_destroy(&object->stmt);
    }
    if (!Z_ISUNDEF(object->connection)) {
        zval_ptr_dtor(&object->connection);
        ZVAL_UNDEF(&object->connection);
    }
    zend_object_std_dtor(std);
}

static HashTable *ladybug_statement_gc(zend_object *std, zval **table, int *count)
{
    ladybug_statement_object *object = LADYBUG_OBJ(ladybug_statement_object, std);

    *table = &object->connection;
    *count = Z_ISUNDEF(object->connection) ? 0 : 1;

    return zend_std_get_properties(std);
}

static zend_object *ladybug_result_create(zend_class_entry *ce)
{
    ladybug_result_object *object = zend_object_alloc(sizeof(ladybug_result_object), ce);

    zend_object_std_init(&object->std, ce);
    object_properties_init(&object->std, ce);
    object->std.handlers = &ladybug_result_handlers;
    object->open = false;
    object->columns = 0;
    ZVAL_UNDEF(&object->connection);

    return &object->std;
}

static void ladybug_result_free(zend_object *std)
{
    ladybug_result_object *object = LADYBUG_OBJ(ladybug_result_object, std);

    if (object->open) {
        object->open = false;
        lbug_query_result_destroy(&object->result);
    }
    if (!Z_ISUNDEF(object->connection)) {
        zval_ptr_dtor(&object->connection);
        ZVAL_UNDEF(&object->connection);
    }
    zend_object_std_dtor(std);
}

static HashTable *ladybug_result_gc(zend_object *std, zval **table, int *count)
{
    ladybug_result_object *object = LADYBUG_OBJ(ladybug_result_object, std);

    *table = &object->connection;
    *count = Z_ISUNDEF(object->connection) ? 0 : 1;

    return zend_std_get_properties(std);
}

/* -- guards -------------------------------------------------------------------------- */

static ladybug_database_object *ladybug_database_of(zval *zv)
{
    ladybug_database_object *object = LADYBUG_DATABASE_P(zv);

    if (!object->open) {
        ladybug_throw(ladybug_exception_ce, "This database handle is already closed.");
        return NULL;
    }

    return object;
}

static ladybug_connection_object *ladybug_connection_of(zval *zv)
{
    ladybug_connection_object *object = LADYBUG_CONNECTION_P(zv);

    if (!object->open) {
        ladybug_throw(ladybug_exception_ce, "This connection handle is already closed.");
        return NULL;
    }
    if (!Z_ISUNDEF(object->database) && !LADYBUG_DATABASE_P(&object->database)->open) {
        ladybug_throw(
            ladybug_exception_ce,
            "This connection handle is unusable: the database it belongs to was closed."
        );
        return NULL;
    }

    return object;
}

static ladybug_statement_object *ladybug_statement_of(zval *zv)
{
    ladybug_statement_object *object = LADYBUG_STATEMENT_P(zv);

    if (!object->open) {
        ladybug_throw(ladybug_exception_ce, "This statement handle is already closed.");
        return NULL;
    }
    if (!Z_ISUNDEF(object->connection) && !LADYBUG_CONNECTION_P(&object->connection)->open) {
        ladybug_throw(
            ladybug_exception_ce,
            "This statement handle is unusable: its connection was closed."
        );
        return NULL;
    }

    return object;
}

static ladybug_result_object *ladybug_result_of(zval *zv)
{
    ladybug_result_object *object = LADYBUG_RESULT_P(zv);

    if (!object->open) {
        ladybug_throw(ladybug_exception_ce, "This result handle is already closed.");
        return NULL;
    }
    /* Rows are read lazily, so the connection must still be alive. Without this check the
     * read would reach freed memory instead of raising. */
    if (!Z_ISUNDEF(object->connection) && !LADYBUG_CONNECTION_P(&object->connection)->open) {
        ladybug_throw(
            ladybug_exception_ce,
            "This result handle is unusable: its connection was closed."
        );
        return NULL;
    }

    return object;
}

/* -- helpers ------------------------------------------------------------------------- */

/*
 * Wraps a freshly produced lbug_query_result in a handle object, turning a failed result
 * into Ladybug\Ext\QueryError and freeing it before the throw.
 */
static int ladybug_wrap_result(lbug_query_result result, zval *connection, zval *out)
{
    ladybug_result_object *object;

    if (!lbug_query_result_is_success(&result)) {
        zend_string *message = ladybug_take_string(lbug_query_result_get_error_message(&result));

        lbug_query_result_destroy(&result);
        if (message != NULL) {
            zend_throw_exception(ladybug_query_error_ce, ZSTR_VAL(message), 0);
            zend_string_release(message);
        } else {
            zend_throw_exception(ladybug_query_error_ce, "Query failed.", 0);
        }

        return FAILURE;
    }

    object_init_ex(out, ladybug_result_ce);
    object = LADYBUG_RESULT_P(out);
    object->result = result;
    object->open = true;
    object->columns = lbug_query_result_get_num_columns(&object->result);
    ZVAL_COPY(&object->connection, connection);

    return SUCCESS;
}

/* Mirrors get_debug_type(), which is what the FFI connector's message uses. */
static const char *ladybug_debug_type(zval *value)
{
    switch (Z_TYPE_P(value)) {
        case IS_NULL:     return "null";
        case IS_TRUE:
        case IS_FALSE:    return "bool";
        case IS_LONG:     return "int";
        case IS_DOUBLE:   return "float";
        case IS_STRING:   return "string";
        case IS_ARRAY:    return "array";
        case IS_RESOURCE: return "resource";
        case IS_OBJECT:   return ZSTR_VAL(Z_OBJCE_P(value)->name);
        default:          return "mixed";
    }
}

/* Formats a DateTimeInterface the way the FFI connector binds it. */
static zend_string *ladybug_format_datetime(zval *value)
{
    zval format;
    zval formatted;
    zend_string *result = NULL;

    ZVAL_STRING(&format, "Y-m-d H:i:s.u");
    zend_call_method_with_1_params(Z_OBJ_P(value), Z_OBJCE_P(value), NULL, "format", &formatted, &format);
    zval_ptr_dtor(&format);

    if (!EG(exception) && Z_TYPE(formatted) == IS_STRING) {
        result = zend_string_copy(Z_STR(formatted));
    }
    zval_ptr_dtor(&formatted);

    return result;
}

static int ladybug_bind_parameter(lbug_prepared_statement *stmt, zend_string *name, zval *value)
{
    lbug_state state;

    switch (Z_TYPE_P(value)) {
        case IS_NULL: {
            /* There is no bind_null; build a NULL value and bind that. Note that
             * lbug_value_create_null() returns an owned pointer rather than filling an out
             * parameter, unlike every other constructor in the header. */
            lbug_value *null_value = lbug_value_create_null();

            state = lbug_prepared_statement_bind_value(stmt, ZSTR_VAL(name), null_value);
            lbug_value_destroy(null_value);
            break;
        }
        case IS_TRUE:
            state = lbug_prepared_statement_bind_bool(stmt, ZSTR_VAL(name), true);
            break;
        case IS_FALSE:
            state = lbug_prepared_statement_bind_bool(stmt, ZSTR_VAL(name), false);
            break;
        case IS_LONG:
            state = lbug_prepared_statement_bind_int64(stmt, ZSTR_VAL(name), (int64_t) Z_LVAL_P(value));
            break;
        case IS_DOUBLE:
            state = lbug_prepared_statement_bind_double(stmt, ZSTR_VAL(name), Z_DVAL_P(value));
            break;
        case IS_STRING:
            state = lbug_prepared_statement_bind_string(stmt, ZSTR_VAL(name), Z_STRVAL_P(value));
            break;
        case IS_OBJECT: {
            zend_string *text = NULL;

            /* DateTimeInterface first: it is not Stringable, and formatting it the same way
             * the FFI connector does keeps the two backends interchangeable. */
            if (instanceof_function(Z_OBJCE_P(value), php_date_get_interface_ce())) {
                text = ladybug_format_datetime(value);
            } else if (instanceof_function(Z_OBJCE_P(value), zend_ce_stringable)) {
                text = zval_get_string(value);
            }
            if (text == NULL) {
                if (!EG(exception)) {
                    ladybug_throw(
                        ladybug_query_error_ce,
                        "Cannot bind $%s: %s is not supported. Use scalars, null, "
                        "DateTimeInterface, or pass a Cypher literal.",
                        ZSTR_VAL(name),
                        ladybug_debug_type(value)
                    );
                }
                return FAILURE;
            }
            state = lbug_prepared_statement_bind_string(stmt, ZSTR_VAL(name), ZSTR_VAL(text));
            zend_string_release(text);
            break;
        }
        default:
            ladybug_throw(
                ladybug_query_error_ce,
                "Cannot bind $%s: %s is not supported. Use scalars, null, "
                "DateTimeInterface, or pass a Cypher literal.",
                ZSTR_VAL(name),
                ladybug_debug_type(value)
            );
            return FAILURE;
    }

    if (state != LbugSuccess) {
        ladybug_throw(
            ladybug_query_error_ce,
            "Could not bind parameter $%s. Is it declared in the query?",
            ZSTR_VAL(name)
        );
        return FAILURE;
    }

    return SUCCESS;
}

/* -- functions ----------------------------------------------------------------------- */

ZEND_FUNCTION(ladybug_abi_version)
{
    ZEND_PARSE_PARAMETERS_NONE();

    RETURN_LONG(LADYBUG_ABI_VERSION);
}

ZEND_FUNCTION(ladybug_version)
{
    ZEND_PARSE_PARAMETERS_NONE();

    RETURN_STRING(lbug_get_version());
}

ZEND_FUNCTION(ladybug_database_open)
{
    zend_string *path;
    HashTable *config = NULL;
    lbug_system_config system_config;
    ladybug_database_object *object;
    zend_string *key;
    zval *value;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(path)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT(config)
    ZEND_PARSE_PARAMETERS_END();

    system_config = lbug_default_system_config();

    if (config != NULL) {
        ZEND_HASH_FOREACH_STR_KEY_VAL(config, key, value) {
            if (key == NULL) {
                continue;
            }
            if (zend_string_equals_literal(key, "bufferPoolSize")) {
                system_config.buffer_pool_size = (uint64_t) zval_get_long(value);
            } else if (zend_string_equals_literal(key, "maxThreads")) {
                system_config.max_num_threads = (uint64_t) zval_get_long(value);
            } else if (zend_string_equals_literal(key, "compression")) {
                system_config.enable_compression = zend_is_true(value);
            } else if (zend_string_equals_literal(key, "readOnly")) {
                system_config.read_only = zend_is_true(value);
            } else if (zend_string_equals_literal(key, "maxDbSize")) {
                system_config.max_db_size = (uint64_t) zval_get_long(value);
            } else if (zend_string_equals_literal(key, "autoCheckpoint")) {
                system_config.auto_checkpoint = zend_is_true(value);
            } else if (zend_string_equals_literal(key, "checkpointThreshold")) {
                system_config.checkpoint_threshold = (uint64_t) zval_get_long(value);
            } else {
                ladybug_throw(ladybug_exception_ce, "Unknown configuration key \"%s\".", ZSTR_VAL(key));
                RETURN_THROWS();
            }
        } ZEND_HASH_FOREACH_END();
    }

    object_init_ex(return_value, ladybug_database_ce);
    object = LADYBUG_DATABASE_P(return_value);

    if (lbug_database_init(ZSTR_VAL(path), system_config, &object->db) != LbugSuccess) {
        zval_ptr_dtor(return_value);
        ZVAL_UNDEF(return_value);
        zend_string *reason = ladybug_take_string(lbug_get_last_error());

        if (reason != NULL) {
            ladybug_throw(
                ladybug_database_error_ce,
                "liblbug could not open the database at \"%s\": %s",
                ZSTR_VAL(path),
                ZSTR_VAL(reason)
            );
            zend_string_release(reason);
        } else {
            ladybug_throw(
                ladybug_database_error_ce,
                "liblbug could not open the database at \"%s\".",
                ZSTR_VAL(path)
            );
        }
        RETURN_THROWS();
    }
    object->open = true;
}

ZEND_FUNCTION(ladybug_database_close)
{
    zval *database;
    ladybug_database_object *object;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(database, ladybug_database_ce)
    ZEND_PARSE_PARAMETERS_END();

    object = LADYBUG_DATABASE_P(database);
    if (object->open) {
        object->open = false;
        lbug_database_destroy(&object->db);
    }
}

ZEND_FUNCTION(ladybug_connect)
{
    zval *database;
    ladybug_database_object *db;
    ladybug_connection_object *object;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(database, ladybug_database_ce)
    ZEND_PARSE_PARAMETERS_END();

    db = ladybug_database_of(database);
    if (db == NULL) {
        RETURN_THROWS();
    }

    object_init_ex(return_value, ladybug_connection_ce);
    object = LADYBUG_CONNECTION_P(return_value);

    if (lbug_connection_init(&db->db, &object->conn) != LbugSuccess) {
        zval_ptr_dtor(return_value);
        ZVAL_UNDEF(return_value);
        ladybug_throw(ladybug_database_error_ce, "liblbug could not open a connection.");
        RETURN_THROWS();
    }
    object->open = true;
    ZVAL_COPY(&object->database, database);
}

ZEND_FUNCTION(ladybug_connection_close)
{
    zval *connection;
    ladybug_connection_object *object;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(connection, ladybug_connection_ce)
    ZEND_PARSE_PARAMETERS_END();

    object = LADYBUG_CONNECTION_P(connection);
    if (object->open) {
        object->open = false;
        lbug_connection_destroy(&object->conn);
    }
}

ZEND_FUNCTION(ladybug_connection_set_max_threads)
{
    zval *connection;
    zend_long threads;
    ladybug_connection_object *object;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJECT_OF_CLASS(connection, ladybug_connection_ce)
        Z_PARAM_LONG(threads)
    ZEND_PARSE_PARAMETERS_END();

    object = ladybug_connection_of(connection);
    if (object == NULL) {
        RETURN_THROWS();
    }
    if (threads < 0) {
        ladybug_throw(ladybug_exception_ce, "The thread count cannot be negative, got %" PRId64 ".", (int64_t) threads);
        RETURN_THROWS();
    }
    if (lbug_connection_set_max_num_thread_for_exec(&object->conn, (uint64_t) threads) != LbugSuccess) {
        ladybug_throw(ladybug_exception_ce, "Could not set the thread count to %" PRId64 ".", (int64_t) threads);
        RETURN_THROWS();
    }
}

ZEND_FUNCTION(ladybug_connection_set_query_timeout)
{
    zval *connection;
    zend_long timeout;
    ladybug_connection_object *object;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJECT_OF_CLASS(connection, ladybug_connection_ce)
        Z_PARAM_LONG(timeout)
    ZEND_PARSE_PARAMETERS_END();

    object = ladybug_connection_of(connection);
    if (object == NULL) {
        RETURN_THROWS();
    }
    if (timeout < 0) {
        ladybug_throw(ladybug_exception_ce, "The query timeout cannot be negative, got %" PRId64 ".", (int64_t) timeout);
        RETURN_THROWS();
    }
    if (lbug_connection_set_query_timeout(&object->conn, (uint64_t) timeout) != LbugSuccess) {
        ladybug_throw(ladybug_exception_ce, "Could not set the query timeout to %" PRId64 " ms.", (int64_t) timeout);
        RETURN_THROWS();
    }
}

ZEND_FUNCTION(ladybug_connection_interrupt)
{
    zval *connection;
    ladybug_connection_object *object;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(connection, ladybug_connection_ce)
    ZEND_PARSE_PARAMETERS_END();

    object = ladybug_connection_of(connection);
    if (object == NULL) {
        RETURN_THROWS();
    }
    lbug_connection_interrupt(&object->conn);
}

ZEND_FUNCTION(ladybug_query)
{
    zval *connection;
    zend_string *cypher;
    ladybug_connection_object *object;
    lbug_query_result result;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJECT_OF_CLASS(connection, ladybug_connection_ce)
        Z_PARAM_STR(cypher)
    ZEND_PARSE_PARAMETERS_END();

    object = ladybug_connection_of(connection);
    if (object == NULL) {
        RETURN_THROWS();
    }

    lbug_connection_query(&object->conn, ZSTR_VAL(cypher), &result);
    if (ladybug_wrap_result(result, connection, return_value) != SUCCESS) {
        RETURN_THROWS();
    }
}

ZEND_FUNCTION(ladybug_prepare)
{
    zval *connection;
    zend_string *cypher;
    ladybug_connection_object *object;
    ladybug_statement_object *statement;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJECT_OF_CLASS(connection, ladybug_connection_ce)
        Z_PARAM_STR(cypher)
    ZEND_PARSE_PARAMETERS_END();

    object = ladybug_connection_of(connection);
    if (object == NULL) {
        RETURN_THROWS();
    }

    object_init_ex(return_value, ladybug_statement_ce);
    statement = LADYBUG_STATEMENT_P(return_value);

    lbug_connection_prepare(&object->conn, ZSTR_VAL(cypher), &statement->stmt);
    if (!lbug_prepared_statement_is_success(&statement->stmt)) {
        zend_string *message = ladybug_take_string(
            lbug_prepared_statement_get_error_message(&statement->stmt)
        );

        lbug_prepared_statement_destroy(&statement->stmt);
        zval_ptr_dtor(return_value);
        ZVAL_UNDEF(return_value);

        if (message != NULL) {
            zend_throw_exception(ladybug_query_error_ce, ZSTR_VAL(message), 0);
            zend_string_release(message);
        } else {
            zend_throw_exception(ladybug_query_error_ce, "Failed to prepare the statement.", 0);
        }
        RETURN_THROWS();
    }

    statement->open = true;
    ZVAL_COPY(&statement->connection, connection);
}

ZEND_FUNCTION(ladybug_execute)
{
    zval *connection;
    zval *statement;
    HashTable *parameters = NULL;
    ladybug_connection_object *connection_object;
    ladybug_statement_object *statement_object;
    lbug_query_result result;
    zend_string *key;
    zval *value;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_OBJECT_OF_CLASS(connection, ladybug_connection_ce)
        Z_PARAM_OBJECT_OF_CLASS(statement, ladybug_statement_ce)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT(parameters)
    ZEND_PARSE_PARAMETERS_END();

    connection_object = ladybug_connection_of(connection);
    if (connection_object == NULL) {
        RETURN_THROWS();
    }
    statement_object = ladybug_statement_of(statement);
    if (statement_object == NULL) {
        RETURN_THROWS();
    }

    if (parameters != NULL) {
        bool bind_failed = false;

        ZEND_HASH_FOREACH_STR_KEY_VAL(parameters, key, value) {
            if (key == NULL) {
                /* An integer key cannot name a Cypher parameter. Report it rather than
                 * silently dropping the binding. */
                ladybug_throw(ladybug_query_error_ce, "Parameter names must be strings; got an integer key.");
                bind_failed = true;
                break;
            }
            if (ladybug_bind_parameter(&statement_object->stmt, key, value) != SUCCESS) {
                bind_failed = true;
                break;
            }
        } ZEND_HASH_FOREACH_END();

        if (bind_failed) {
            RETURN_THROWS();
        }
    }

    lbug_connection_execute(&connection_object->conn, &statement_object->stmt, &result);
    if (ladybug_wrap_result(result, connection, return_value) != SUCCESS) {
        RETURN_THROWS();
    }
}

ZEND_FUNCTION(ladybug_statement_close)
{
    zval *statement;
    ladybug_statement_object *object;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(statement, ladybug_statement_ce)
    ZEND_PARSE_PARAMETERS_END();

    object = LADYBUG_STATEMENT_P(statement);
    if (object->open) {
        object->open = false;
        lbug_prepared_statement_destroy(&object->stmt);
    }
}

ZEND_FUNCTION(ladybug_result_column_names)
{
    zval *result;
    ladybug_result_object *object;
    uint64_t index;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(result, ladybug_result_ce)
    ZEND_PARSE_PARAMETERS_END();

    object = ladybug_result_of(result);
    if (object == NULL) {
        RETURN_THROWS();
    }

    array_init_size(return_value, (uint32_t) object->columns);
    for (index = 0; index < object->columns; ++index) {
        char *owned = NULL;
        zend_string *name;

        if (lbug_query_result_get_column_name(&object->result, index, &owned) != LbugSuccess) {
            add_next_index_str(return_value, strpprintf(0, "column_%" PRIu64, index));
            continue;
        }
        name = ladybug_take_string(owned);
        if (name == NULL) {
            add_next_index_str(return_value, strpprintf(0, "column_%" PRIu64, index));
            continue;
        }
        add_next_index_str(return_value, name);
    }
}

ZEND_FUNCTION(ladybug_result_column_types)
{
    zval *result;
    ladybug_result_object *object;
    uint64_t index;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(result, ladybug_result_ce)
    ZEND_PARSE_PARAMETERS_END();

    object = ladybug_result_of(result);
    if (object == NULL) {
        RETURN_THROWS();
    }

    array_init_size(return_value, (uint32_t) object->columns);
    for (index = 0; index < object->columns; ++index) {
        lbug_logical_type type;

        if (lbug_query_result_get_column_data_type(&object->result, index, &type) != LbugSuccess) {
            zval_ptr_dtor(return_value);
            ZVAL_UNDEF(return_value);
            ladybug_throw(ladybug_exception_ce, "Could not read the type of column %" PRIu64 ".", index);
            RETURN_THROWS();
        }
        add_next_index_long(return_value, (zend_long) lbug_data_type_get_id(&type));
        lbug_data_type_destroy(&type);
    }
}

ZEND_FUNCTION(ladybug_result_row_count)
{
    zval *result;
    ladybug_result_object *object;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(result, ladybug_result_ce)
    ZEND_PARSE_PARAMETERS_END();

    object = ladybug_result_of(result);
    if (object == NULL) {
        RETURN_THROWS();
    }

    RETURN_LONG((zend_long) lbug_query_result_get_num_tuples(&object->result));
}

ZEND_FUNCTION(ladybug_result_fetch)
{
    zval *result;
    ladybug_result_object *object;
    lbug_flat_tuple tuple;
    uint64_t index;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(result, ladybug_result_ce)
    ZEND_PARSE_PARAMETERS_END();

    object = ladybug_result_of(result);
    if (object == NULL) {
        RETURN_THROWS();
    }

    if (!lbug_query_result_has_next(&object->result)) {
        RETURN_NULL();
    }
    if (lbug_query_result_get_next(&object->result, &tuple) != LbugSuccess) {
        ladybug_throw(ladybug_query_error_ce, "Failed to read the next row from the query result.");
        RETURN_THROWS();
    }

    array_init_size(return_value, (uint32_t) object->columns);
    for (index = 0; index < object->columns; ++index) {
        lbug_value value;
        zval converted;

        if (lbug_flat_tuple_get_value(&tuple, index, &value) != LbugSuccess) {
            lbug_flat_tuple_destroy(&tuple);
            zval_ptr_dtor(return_value);
            ZVAL_UNDEF(return_value);
            ladybug_throw(ladybug_query_error_ce, "Could not read column %" PRIu64 " of the current row.", index);
            RETURN_THROWS();
        }
        if (ladybug_value_to_zval(&value, &converted) != SUCCESS) {
            lbug_value_destroy(&value);
            lbug_flat_tuple_destroy(&tuple);
            /* Without the reset, return_value still points at the array just freed and the
             * engine frees it again — reported as "zend_mm_heap corrupted", far from here. */
            zval_ptr_dtor(return_value);
            ZVAL_UNDEF(return_value);
            RETURN_THROWS();
        }
        lbug_value_destroy(&value);
        add_next_index_zval(return_value, &converted);
    }

    lbug_flat_tuple_destroy(&tuple);
}

ZEND_FUNCTION(ladybug_result_rewind)
{
    zval *result;
    ladybug_result_object *object;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(result, ladybug_result_ce)
    ZEND_PARSE_PARAMETERS_END();

    object = ladybug_result_of(result);
    if (object == NULL) {
        RETURN_THROWS();
    }
    lbug_query_result_reset_iterator(&object->result);
}

ZEND_FUNCTION(ladybug_result_next_set)
{
    zval *result;
    ladybug_result_object *object;
    lbug_query_result next;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(result, ladybug_result_ce)
    ZEND_PARSE_PARAMETERS_END();

    object = ladybug_result_of(result);
    if (object == NULL) {
        RETURN_THROWS();
    }

    if (!lbug_query_result_has_next_query_result(&object->result)) {
        RETURN_NULL();
    }
    if (lbug_query_result_get_next_query_result(&object->result, &next) != LbugSuccess) {
        ladybug_throw(ladybug_query_error_ce, "Failed to advance to the next result in the statement chain.");
        RETURN_THROWS();
    }
    if (ladybug_wrap_result(next, &object->connection, return_value) != SUCCESS) {
        RETURN_THROWS();
    }
}

ZEND_FUNCTION(ladybug_result_summary)
{
    zval *result;
    ladybug_result_object *object;
    lbug_query_summary summary;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(result, ladybug_result_ce)
    ZEND_PARSE_PARAMETERS_END();

    object = ladybug_result_of(result);
    if (object == NULL) {
        RETURN_THROWS();
    }

    array_init_size(return_value, 2);
    if (lbug_query_result_get_query_summary(&object->result, &summary) != LbugSuccess) {
        add_assoc_double(return_value, "compilingTimeMs", 0.0);
        add_assoc_double(return_value, "executionTimeMs", 0.0);
        return;
    }
    add_assoc_double(return_value, "compilingTimeMs", lbug_query_summary_get_compiling_time(&summary));
    add_assoc_double(return_value, "executionTimeMs", lbug_query_summary_get_execution_time(&summary));
    lbug_query_summary_destroy(&summary);
}

ZEND_FUNCTION(ladybug_result_close)
{
    zval *result;
    ladybug_result_object *object;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(result, ladybug_result_ce)
    ZEND_PARSE_PARAMETERS_END();

    object = LADYBUG_RESULT_P(result);
    if (object->open) {
        object->open = false;
        lbug_query_result_destroy(&object->result);
    }
}

/* -- registration -------------------------------------------------------------------- */

static zend_class_entry *ladybug_register_handle_class(
    const char *name,
    zend_object *(*create)(zend_class_entry *),
    void (*free_obj)(zend_object *),
    HashTable *(*get_gc)(zend_object *, zval **, int *),
    size_t std_offset,
    zend_object_handlers *handlers
) {
    zend_class_entry ce;
    zend_class_entry *registered;

    INIT_CLASS_ENTRY_EX(ce, name, strlen(name), NULL);
    registered = zend_register_internal_class(&ce);
    registered->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;
    registered->create_object = create;

    memcpy(handlers, &std_object_handlers, sizeof(zend_object_handlers));
    handlers->offset = std_offset;
    handlers->free_obj = free_obj;
    handlers->clone_obj = NULL;  /* a handle cannot be duplicated */
    if (get_gc != NULL) {
        handlers->get_gc = get_gc;
    }

    return registered;
}

/* -- liblbug compatibility ----------------------------------------------------------- */

/* Copies the leading major.minor of a version string ("0.19.1" -> "0.19", "0.20.0-rc.1"
 * -> "0.20"). Returns the length written, or 0 if there is no such prefix. */
static size_t ladybug_version_series(const char *version, char *out, size_t out_size)
{
    size_t len = 0;
    int dots = 0;
    const char *p;

    if (version == NULL) {
        return 0;
    }

    for (p = version; *p != '\0' && len + 1 < out_size; ++p) {
        if (*p == '.') {
            if (++dots == 2) {
                break;
            }
        } else if (*p < '0' || *p > '9') {
            break;
        }
        out[len++] = *p;
    }

    out[len] = '\0';

    return dots >= 1 ? len : 0;
}

static bool ladybug_liblbug_supported(const char *runtime)
{
    char series[16];
    const char *list = LADYBUG_LIBLBUG_SERIES;
    size_t len = ladybug_version_series(runtime, series, sizeof(series));

    if (len == 0) {
        return false;
    }

    while (*list != '\0') {
        const char *comma = strchr(list, ',');
        size_t n = comma != NULL ? (size_t) (comma - list) : strlen(list);

        if (n == len && strncmp(list, series, n) == 0) {
            return true;
        }

        if (comma == NULL) {
            break;
        }
        list = comma + 1;
    }

    return false;
}

static bool ladybug_version_check_overridden(void)
{
    const char *value = getenv(LADYBUG_ALLOW_ANY_LIBRARY_ENV);

    return value != NULL && value[0] != '\0' && strcmp(value, "0") != 0;
}

PHP_MINIT_FUNCTION(ladybug)
{
    zend_class_entry ce;
    const char *liblbug_version = lbug_get_version();

    /* Before any class is registered: a layout mismatch is silent data corruption, so the
     * only safe outcome is for the module not to load at all. */
    if (!ladybug_liblbug_supported(liblbug_version)) {
        bool overridden = ladybug_version_check_overridden();

        zend_error(E_CORE_WARNING,
            "ladybug: liblbug %s is not supported by this extension, which needs %s.x "
            "(built against %s). liblbug changes struct layouts between minor releases, so "
            "continuing would risk wrong results or a crash rather than an error. Rebuild "
            "the extension against a supported liblbug, or set %s=1 to load anyway at your "
            "own risk.",
            liblbug_version != NULL ? liblbug_version : "(unreadable)",
            LADYBUG_LIBLBUG_SERIES, LADYBUG_LIBLBUG_VERIFIED, LADYBUG_ALLOW_ANY_LIBRARY_ENV);

        if (!overridden) {
            return FAILURE;
        }
    }

    ladybug_database_ce = ladybug_register_handle_class(
        LADYBUG_DATABASE_CLASS, ladybug_database_create, ladybug_database_free, NULL,
        XtOffsetOf(ladybug_database_object, std), &ladybug_database_handlers
    );
    ladybug_connection_ce = ladybug_register_handle_class(
        LADYBUG_CONNECTION_CLASS, ladybug_connection_create, ladybug_connection_free,
        ladybug_connection_gc, XtOffsetOf(ladybug_connection_object, std), &ladybug_connection_handlers
    );
    ladybug_statement_ce = ladybug_register_handle_class(
        LADYBUG_STATEMENT_CLASS, ladybug_statement_create, ladybug_statement_free,
        ladybug_statement_gc, XtOffsetOf(ladybug_statement_object, std), &ladybug_statement_handlers
    );
    ladybug_result_ce = ladybug_register_handle_class(
        LADYBUG_RESULT_CLASS, ladybug_result_create, ladybug_result_free,
        ladybug_result_gc, XtOffsetOf(ladybug_result_object, std), &ladybug_result_handlers
    );

    /* Exceptions live in the extension's own namespace: the C code stays independent of
     * the Composer package, and ExtConnector rewraps these into Ladybug\Exception\*. */
    /* RuntimeException, matching the library's own exceptions, so a caller who ignores
     * the adapter still catches something conventional. */
    INIT_CLASS_ENTRY(ce, "Ladybug\\Ext\\Exception", NULL);
    ladybug_exception_ce = zend_register_internal_class_ex(&ce, spl_ce_RuntimeException);

    INIT_CLASS_ENTRY(ce, "Ladybug\\Ext\\DatabaseError", NULL);
    ladybug_database_error_ce = zend_register_internal_class_ex(&ce, ladybug_exception_ce);

    INIT_CLASS_ENTRY(ce, "Ladybug\\Ext\\QueryError", NULL);
    ladybug_query_error_ce = zend_register_internal_class_ex(&ce, ladybug_exception_ce);

    return SUCCESS;
}

static void ladybug_globals_ctor(zend_ladybug_globals *globals)
{
    memset(globals, 0, sizeof(*globals));
}

PHP_RINIT_FUNCTION(ladybug)
{
    /* Userland class entries come from the autoloader and are only valid for one request. */
    LADYBUG_G(internal_id_ce) = NULL;
    LADYBUG_G(node_ce) = NULL;
    LADYBUG_G(rel_ce) = NULL;
    LADYBUG_G(path_ce) = NULL;
    LADYBUG_G(datetime_ce) = NULL;
    LADYBUG_G(dateinterval_ce) = NULL;

    return SUCCESS;
}

PHP_MINFO_FUNCTION(ladybug)
{
    php_info_print_table_start();
    php_info_print_table_row(2, "ladybug support", "enabled");
    php_info_print_table_row(2, "extension version", PHP_LADYBUG_VERSION);
    php_info_print_table_row(2, "ABI version", "1");
    php_info_print_table_row(2, "liblbug version", lbug_get_version());
    php_info_print_table_row(2, "liblbug built against", LADYBUG_LIBLBUG_VERIFIED);
    php_info_print_table_row(2, "liblbug supported series", LADYBUG_LIBLBUG_SERIES ".x");
    {
        char storage[32];
        snprintf(storage, sizeof(storage), "%llu", (unsigned long long) lbug_get_storage_version());
        php_info_print_table_row(2, "liblbug storage version", storage);
    }
#ifdef LADYBUG_STATIC_LIBLBUG
    php_info_print_table_row(2, "liblbug linkage", "static");
#else
    php_info_print_table_row(2, "liblbug linkage", "shared");
#endif
    php_info_print_table_end();
}

zend_module_entry ladybug_module_entry = {
    STANDARD_MODULE_HEADER,
    PHP_LADYBUG_NAME,
    ladybug_functions,
    PHP_MINIT(ladybug),
    NULL,
    PHP_RINIT(ladybug),
    NULL,
    PHP_MINFO(ladybug),
    PHP_LADYBUG_VERSION,
    PHP_MODULE_GLOBALS(ladybug),
    (void (*)(void *)) ladybug_globals_ctor,
    NULL,
    NULL,
    STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_LADYBUG
ZEND_GET_MODULE(ladybug)
#endif
