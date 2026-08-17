<?php

declare(strict_types=1);

namespace Ladybug\Connector\Ffi;

use Ladybug\Connector\LibraryVersion;

/**
 * The subset of lbug.h this connector needs, hand-transcribed so we don't depend on a
 * C preprocessor at runtime. Keep in sync with the header shipped alongside liblbug.
 *
 * Verified against lbug.h from LadybugDB v0.19.1.
 */
final class Cdef
{
    /** @see LibraryVersion for the versions these declarations are valid for. */
    public const LIBRARY_VERSION = LibraryVersion::VERIFIED;

    public static function source(): string
    {
        // lbug_system_config's last field (thread_qos) exists on Apple platforms only.
        $threadQos = PHP_OS_FAMILY === 'Darwin' ? "    uint32_t thread_qos;\n" : '';

        return <<<C
            typedef struct {
                uint64_t buffer_pool_size;
                uint64_t max_num_threads;
                bool enable_compression;
                bool read_only;
                uint64_t max_db_size;
                bool auto_checkpoint;
                uint64_t checkpoint_threshold;
                bool throw_on_wal_replay_failure;
                bool enable_checksums;
                bool enable_multi_writes;
                bool enable_default_hash_index;
            $threadQos} lbug_system_config;

            typedef struct { void* _database; } lbug_database;
            typedef struct { void* _connection; } lbug_connection;
            typedef struct { void* _prepared_statement; void* _bound_values; } lbug_prepared_statement;
            typedef struct { void* _query_result; bool _is_owned_by_cpp; } lbug_query_result;
            typedef struct { void* _flat_tuple; bool _is_owned_by_cpp; } lbug_flat_tuple;
            typedef struct { void* _data_type; } lbug_logical_type;
            typedef struct { void* _value; bool _is_owned_by_cpp; } lbug_value;
            typedef struct { void* _query_summary; } lbug_query_summary;

            typedef struct { uint64_t table_id; uint64_t offset; } lbug_internal_id_t;
            typedef struct { int32_t days; } lbug_date_t;
            typedef struct { int64_t value; } lbug_timestamp_t;
            typedef struct { int64_t value; } lbug_timestamp_ns_t;
            typedef struct { int64_t value; } lbug_timestamp_ms_t;
            typedef struct { int64_t value; } lbug_timestamp_sec_t;
            typedef struct { int64_t value; } lbug_timestamp_tz_t;
            typedef struct { int32_t months; int32_t days; int64_t micros; } lbug_interval_t;
            typedef struct { uint64_t low; int64_t high; } lbug_int128_t;

            typedef int lbug_state;
            typedef int lbug_data_type_id;

            lbug_system_config lbug_default_system_config();
            lbug_state lbug_database_init(const char* database_path, lbug_system_config system_config, lbug_database* out_database);
            void lbug_database_destroy(lbug_database* database);

            lbug_state lbug_connection_init(lbug_database* database, lbug_connection* out_connection);
            void lbug_connection_destroy(lbug_connection* connection);
            lbug_state lbug_connection_set_max_num_thread_for_exec(lbug_connection* connection, uint64_t num_threads);
            lbug_state lbug_connection_get_max_num_thread_for_exec(lbug_connection* connection, uint64_t* out_result);
            void lbug_connection_interrupt(lbug_connection* connection);
            lbug_state lbug_connection_set_query_timeout(lbug_connection* connection, uint64_t timeout_in_ms);
            lbug_state lbug_connection_query(lbug_connection* connection, const char* query, lbug_query_result* out_query_result);
            lbug_state lbug_connection_prepare(lbug_connection* connection, const char* query, lbug_prepared_statement* out_prepared_statement);
            lbug_state lbug_connection_execute(lbug_connection* connection, lbug_prepared_statement* prepared_statement, lbug_query_result* out_query_result);

            void lbug_prepared_statement_destroy(lbug_prepared_statement* prepared_statement);
            bool lbug_prepared_statement_is_success(lbug_prepared_statement* prepared_statement);
            char* lbug_prepared_statement_get_error_message(lbug_prepared_statement* prepared_statement);
            lbug_state lbug_prepared_statement_bind_bool(lbug_prepared_statement* prepared_statement, const char* param_name, bool value);
            lbug_state lbug_prepared_statement_bind_int64(lbug_prepared_statement* prepared_statement, const char* param_name, int64_t value);
            lbug_state lbug_prepared_statement_bind_double(lbug_prepared_statement* prepared_statement, const char* param_name, double value);
            lbug_state lbug_prepared_statement_bind_string(lbug_prepared_statement* prepared_statement, const char* param_name, const char* value);
            lbug_state lbug_prepared_statement_bind_value(lbug_prepared_statement* prepared_statement, const char* param_name, lbug_value* value);

            void lbug_query_result_destroy(lbug_query_result* query_result);
            bool lbug_query_result_is_success(lbug_query_result* query_result);
            char* lbug_query_result_get_error_message(lbug_query_result* query_result);
            uint64_t lbug_query_result_get_num_columns(lbug_query_result* query_result);
            lbug_state lbug_query_result_get_column_name(lbug_query_result* query_result, uint64_t index, char** out_result);
            lbug_state lbug_query_result_get_column_data_type(lbug_query_result* query_result, uint64_t index, lbug_logical_type* out_type);
            uint64_t lbug_query_result_get_num_tuples(lbug_query_result* query_result);
            lbug_state lbug_query_result_get_query_summary(lbug_query_result* query_result, lbug_query_summary* out_result);
            bool lbug_query_result_has_next(lbug_query_result* query_result);
            lbug_state lbug_query_result_get_next(lbug_query_result* query_result, lbug_flat_tuple* out_flat_tuple);
            bool lbug_query_result_has_next_query_result(lbug_query_result* query_result);
            lbug_state lbug_query_result_get_next_query_result(lbug_query_result* query_result, lbug_query_result* out_query_result);
            char* lbug_query_result_to_string(lbug_query_result* query_result);
            void lbug_query_result_reset_iterator(lbug_query_result* query_result);

            void lbug_flat_tuple_destroy(lbug_flat_tuple* flat_tuple);
            lbug_state lbug_flat_tuple_get_value(lbug_flat_tuple* flat_tuple, uint64_t index, lbug_value* out_value);

            void lbug_value_destroy(lbug_value* value);
            bool lbug_value_is_null(lbug_value* value);
            void lbug_value_get_data_type(lbug_value* value, lbug_logical_type* out_type);
            lbug_value* lbug_value_create_null();
            char* lbug_value_to_string(lbug_value* value);
            lbug_state lbug_value_get_bool(lbug_value* value, bool* out_result);
            lbug_state lbug_value_get_int8(lbug_value* value, int8_t* out_result);
            lbug_state lbug_value_get_int16(lbug_value* value, int16_t* out_result);
            lbug_state lbug_value_get_int32(lbug_value* value, int32_t* out_result);
            lbug_state lbug_value_get_int64(lbug_value* value, int64_t* out_result);
            lbug_state lbug_value_get_uint8(lbug_value* value, uint8_t* out_result);
            lbug_state lbug_value_get_uint16(lbug_value* value, uint16_t* out_result);
            lbug_state lbug_value_get_uint32(lbug_value* value, uint32_t* out_result);
            lbug_state lbug_value_get_uint64(lbug_value* value, uint64_t* out_result);
            lbug_state lbug_value_get_int128(lbug_value* value, lbug_int128_t* out_result);
            lbug_state lbug_value_get_float(lbug_value* value, float* out_result);
            lbug_state lbug_value_get_double(lbug_value* value, double* out_result);
            lbug_state lbug_value_get_internal_id(lbug_value* value, lbug_internal_id_t* out_result);
            lbug_state lbug_value_get_date(lbug_value* value, lbug_date_t* out_result);
            lbug_state lbug_value_get_timestamp(lbug_value* value, lbug_timestamp_t* out_result);
            lbug_state lbug_value_get_timestamp_ns(lbug_value* value, lbug_timestamp_ns_t* out_result);
            lbug_state lbug_value_get_timestamp_ms(lbug_value* value, lbug_timestamp_ms_t* out_result);
            lbug_state lbug_value_get_timestamp_sec(lbug_value* value, lbug_timestamp_sec_t* out_result);
            lbug_state lbug_value_get_timestamp_tz(lbug_value* value, lbug_timestamp_tz_t* out_result);
            lbug_state lbug_value_get_interval(lbug_value* value, lbug_interval_t* out_result);
            lbug_state lbug_value_get_decimal_as_string(lbug_value* value, char** out_result);
            lbug_state lbug_value_get_string(lbug_value* value, char** out_result);
            lbug_state lbug_value_get_uuid(lbug_value* value, char** out_result);
            lbug_state lbug_value_get_blob(lbug_value* value, uint8_t** out_result, uint64_t* out_length);
            lbug_state lbug_value_get_list_size(lbug_value* value, uint64_t* out_result);
            lbug_state lbug_value_get_list_element(lbug_value* value, uint64_t index, lbug_value* out_value);
            lbug_state lbug_value_get_struct_num_fields(lbug_value* value, uint64_t* out_result);
            lbug_state lbug_value_get_struct_field_name(lbug_value* value, uint64_t index, char** out_result);
            lbug_state lbug_value_get_struct_field_value(lbug_value* value, uint64_t index, lbug_value* out_value);
            lbug_state lbug_value_get_map_size(lbug_value* value, uint64_t* out_result);
            lbug_state lbug_value_get_map_key(lbug_value* value, uint64_t index, lbug_value* out_key);
            lbug_state lbug_value_get_map_value(lbug_value* value, uint64_t index, lbug_value* out_value);

            lbug_state lbug_node_val_get_id_val(lbug_value* node_val, lbug_value* out_value);
            lbug_state lbug_node_val_get_label_val(lbug_value* node_val, lbug_value* out_value);
            lbug_state lbug_node_val_get_property_size(lbug_value* node_val, uint64_t* out_value);
            lbug_state lbug_node_val_get_property_name_at(lbug_value* node_val, uint64_t index, char** out_result);
            lbug_state lbug_node_val_get_property_value_at(lbug_value* node_val, uint64_t index, lbug_value* out_value);
            lbug_state lbug_rel_val_get_id_val(lbug_value* rel_val, lbug_value* out_value);
            lbug_state lbug_rel_val_get_src_id_val(lbug_value* rel_val, lbug_value* out_value);
            lbug_state lbug_rel_val_get_dst_id_val(lbug_value* rel_val, lbug_value* out_value);
            lbug_state lbug_rel_val_get_label_val(lbug_value* rel_val, lbug_value* out_value);
            lbug_state lbug_rel_val_get_property_size(lbug_value* rel_val, uint64_t* out_value);
            lbug_state lbug_rel_val_get_property_name_at(lbug_value* rel_val, uint64_t index, char** out_result);
            lbug_state lbug_rel_val_get_property_value_at(lbug_value* rel_val, uint64_t index, lbug_value* out_value);

            lbug_data_type_id lbug_data_type_get_id(lbug_logical_type* data_type);
            void lbug_data_type_destroy(lbug_logical_type* data_type);

            double lbug_query_summary_get_compiling_time(lbug_query_summary* query_summary);
            double lbug_query_summary_get_execution_time(lbug_query_summary* query_summary);
            void lbug_query_summary_destroy(lbug_query_summary* query_summary);

            char* lbug_get_version();
            uint64_t lbug_get_storage_version();
            char* lbug_get_last_error();

            void lbug_destroy_string(char* str);
            void lbug_destroy_blob(uint8_t* blob);
            C;
    }
}
