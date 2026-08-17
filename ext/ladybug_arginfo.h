/*
 * Argument metadata for the ladybug_* ABI.
 *
 * Hand-written rather than generated: php-src's gen_stub.php is not shipped with the
 * distributed headers, and a hand-written table has no build-time dependency. Keep this
 * in sync with stubs/ladybug-ext.stub.php, which is the same signatures for PHPStan.
 */

#ifndef LADYBUG_ARGINFO_H
#define LADYBUG_ARGINFO_H

#define LADYBUG_DATABASE_CLASS   "Ladybug\\Ext\\Database"
#define LADYBUG_CONNECTION_CLASS "Ladybug\\Ext\\Connection"
#define LADYBUG_STATEMENT_CLASS  "Ladybug\\Ext\\Statement"
#define LADYBUG_RESULT_CLASS     "Ladybug\\Ext\\Result"

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_abi_version, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_version, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_ladybug_database_open, 0, 1, Ladybug\\Ext\\Database, 0)
    ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, config, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_database_close, 0, 1, IS_VOID, 0)
    ZEND_ARG_OBJ_INFO(0, database, Ladybug\\Ext\\Database, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_ladybug_connect, 0, 1, Ladybug\\Ext\\Connection, 0)
    ZEND_ARG_OBJ_INFO(0, database, Ladybug\\Ext\\Database, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_connection_close, 0, 1, IS_VOID, 0)
    ZEND_ARG_OBJ_INFO(0, connection, Ladybug\\Ext\\Connection, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_connection_set_max_threads, 0, 2, IS_VOID, 0)
    ZEND_ARG_OBJ_INFO(0, connection, Ladybug\\Ext\\Connection, 0)
    ZEND_ARG_TYPE_INFO(0, threads, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_connection_set_query_timeout, 0, 2, IS_VOID, 0)
    ZEND_ARG_OBJ_INFO(0, connection, Ladybug\\Ext\\Connection, 0)
    ZEND_ARG_TYPE_INFO(0, timeoutMs, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_connection_interrupt, 0, 1, IS_VOID, 0)
    ZEND_ARG_OBJ_INFO(0, connection, Ladybug\\Ext\\Connection, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_ladybug_query, 0, 2, Ladybug\\Ext\\Result, 0)
    ZEND_ARG_OBJ_INFO(0, connection, Ladybug\\Ext\\Connection, 0)
    ZEND_ARG_TYPE_INFO(0, cypher, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_ladybug_prepare, 0, 2, Ladybug\\Ext\\Statement, 0)
    ZEND_ARG_OBJ_INFO(0, connection, Ladybug\\Ext\\Connection, 0)
    ZEND_ARG_TYPE_INFO(0, cypher, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_ladybug_execute, 0, 2, Ladybug\\Ext\\Result, 0)
    ZEND_ARG_OBJ_INFO(0, connection, Ladybug\\Ext\\Connection, 0)
    ZEND_ARG_OBJ_INFO(0, statement, Ladybug\\Ext\\Statement, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, parameters, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_statement_close, 0, 1, IS_VOID, 0)
    ZEND_ARG_OBJ_INFO(0, statement, Ladybug\\Ext\\Statement, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_result_column_names, 0, 1, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO(0, result, Ladybug\\Ext\\Result, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_result_column_types, 0, 1, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO(0, result, Ladybug\\Ext\\Result, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_result_row_count, 0, 1, IS_LONG, 0)
    ZEND_ARG_OBJ_INFO(0, result, Ladybug\\Ext\\Result, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_result_fetch, 0, 1, IS_ARRAY, 1)
    ZEND_ARG_OBJ_INFO(0, result, Ladybug\\Ext\\Result, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_result_rewind, 0, 1, IS_VOID, 0)
    ZEND_ARG_OBJ_INFO(0, result, Ladybug\\Ext\\Result, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_ladybug_result_next_set, 0, 1, Ladybug\\Ext\\Result, 1)
    ZEND_ARG_OBJ_INFO(0, result, Ladybug\\Ext\\Result, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_result_summary, 0, 1, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO(0, result, Ladybug\\Ext\\Result, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ladybug_result_close, 0, 1, IS_VOID, 0)
    ZEND_ARG_OBJ_INFO(0, result, Ladybug\\Ext\\Result, 0)
ZEND_END_ARG_INFO()

ZEND_FUNCTION(ladybug_abi_version);
ZEND_FUNCTION(ladybug_version);
ZEND_FUNCTION(ladybug_database_open);
ZEND_FUNCTION(ladybug_database_close);
ZEND_FUNCTION(ladybug_connect);
ZEND_FUNCTION(ladybug_connection_close);
ZEND_FUNCTION(ladybug_connection_set_max_threads);
ZEND_FUNCTION(ladybug_connection_set_query_timeout);
ZEND_FUNCTION(ladybug_connection_interrupt);
ZEND_FUNCTION(ladybug_query);
ZEND_FUNCTION(ladybug_prepare);
ZEND_FUNCTION(ladybug_execute);
ZEND_FUNCTION(ladybug_statement_close);
ZEND_FUNCTION(ladybug_result_column_names);
ZEND_FUNCTION(ladybug_result_column_types);
ZEND_FUNCTION(ladybug_result_row_count);
ZEND_FUNCTION(ladybug_result_fetch);
ZEND_FUNCTION(ladybug_result_rewind);
ZEND_FUNCTION(ladybug_result_next_set);
ZEND_FUNCTION(ladybug_result_summary);
ZEND_FUNCTION(ladybug_result_close);

static const zend_function_entry ladybug_functions[] = {
    ZEND_FE(ladybug_abi_version, arginfo_ladybug_abi_version)
    ZEND_FE(ladybug_version, arginfo_ladybug_version)
    ZEND_FE(ladybug_database_open, arginfo_ladybug_database_open)
    ZEND_FE(ladybug_database_close, arginfo_ladybug_database_close)
    ZEND_FE(ladybug_connect, arginfo_ladybug_connect)
    ZEND_FE(ladybug_connection_close, arginfo_ladybug_connection_close)
    ZEND_FE(ladybug_connection_set_max_threads, arginfo_ladybug_connection_set_max_threads)
    ZEND_FE(ladybug_connection_set_query_timeout, arginfo_ladybug_connection_set_query_timeout)
    ZEND_FE(ladybug_connection_interrupt, arginfo_ladybug_connection_interrupt)
    ZEND_FE(ladybug_query, arginfo_ladybug_query)
    ZEND_FE(ladybug_prepare, arginfo_ladybug_prepare)
    ZEND_FE(ladybug_execute, arginfo_ladybug_execute)
    ZEND_FE(ladybug_statement_close, arginfo_ladybug_statement_close)
    ZEND_FE(ladybug_result_column_names, arginfo_ladybug_result_column_names)
    ZEND_FE(ladybug_result_column_types, arginfo_ladybug_result_column_types)
    ZEND_FE(ladybug_result_row_count, arginfo_ladybug_result_row_count)
    ZEND_FE(ladybug_result_fetch, arginfo_ladybug_result_fetch)
    ZEND_FE(ladybug_result_rewind, arginfo_ladybug_result_rewind)
    ZEND_FE(ladybug_result_next_set, arginfo_ladybug_result_next_set)
    ZEND_FE(ladybug_result_summary, arginfo_ladybug_result_summary)
    ZEND_FE(ladybug_result_close, arginfo_ladybug_result_close)
    ZEND_FE_END
};

#endif /* LADYBUG_ARGINFO_H */
