/*
 * ladybug — native PHP extension for LadybugDB.
 *
 * The extension exposes a flat procedural ABI (ladybug_*) over opaque handle objects and
 * knows nothing about the crazy-goat/ladybug-php Composer package: the PHP-side adapter
 * (Ladybug\Connector\Ext\ExtConnector) maps this ABI onto the library's own interfaces.
 * That keeps the two versionable apart, with LADYBUG_ABI_VERSION as the contract.
 *
 * Values are converted to PHP inside the extension, in C — that is the whole reason this
 * connector exists next to the FFI one, and the conversion must stay observably identical
 * to Ladybug\Connector\Ffi\ValueReader.
 */

#ifndef PHP_LADYBUG_H
#define PHP_LADYBUG_H

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "lbug.h"

#define PHP_LADYBUG_NAME    "ladybug"
#define PHP_LADYBUG_VERSION "0.1.0"

/* Bumped on any incompatible change to the ladybug_* function set. ExtConnector refuses
 * to run against a mismatch rather than crashing on a changed signature. */
#define LADYBUG_ABI_VERSION 1

/* The liblbug releases the struct layouts compiled into this extension are valid for.
 * Shared linkage means the library can be swapped after the build, and liblbug changes
 * struct layouts between minor releases — so the runtime version is checked in MINIT and
 * the module refuses to load on a mismatch. Comma-separated major.minor series; keep in
 * sync with Ladybug\Connector\LibraryVersion (LibraryVersionParityTest compares them). */
#define LADYBUG_LIBLBUG_VERIFIED      "0.19.1"
#define LADYBUG_LIBLBUG_SERIES        "0.19"
#define LADYBUG_ALLOW_ANY_LIBRARY_ENV "LADYBUG_ALLOW_ANY_LIBRARY"

extern zend_module_entry ladybug_module_entry;
#define phpext_ladybug_ptr &ladybug_module_entry

/* -- handle objects ------------------------------------------------------------------ */

typedef struct _ladybug_database_object {
    lbug_database db;
    bool open;
    zend_object std;
} ladybug_database_object;

typedef struct _ladybug_connection_object {
    lbug_connection conn;
    bool open;
    /* Keeps the database object alive: liblbug's connection destructor touches it. */
    zval database;
    zend_object std;
} ladybug_connection_object;

typedef struct _ladybug_statement_object {
    lbug_prepared_statement stmt;
    bool open;
    zval connection;
    zend_object std;
} ladybug_statement_object;

typedef struct _ladybug_result_object {
    lbug_query_result result;
    bool open;
    uint64_t columns;
    zval connection;
    zend_object std;
} ladybug_result_object;

extern zend_class_entry *ladybug_database_ce;
extern zend_class_entry *ladybug_connection_ce;
extern zend_class_entry *ladybug_statement_ce;
extern zend_class_entry *ladybug_result_ce;

extern zend_class_entry *ladybug_exception_ce;
extern zend_class_entry *ladybug_database_error_ce;
extern zend_class_entry *ladybug_query_error_ce;

#define LADYBUG_OBJ(type, object) \
    ((type *) ((char *) (object) - XtOffsetOf(type, std)))

#define LADYBUG_DATABASE_P(zv)   LADYBUG_OBJ(ladybug_database_object, Z_OBJ_P(zv))
#define LADYBUG_CONNECTION_P(zv) LADYBUG_OBJ(ladybug_connection_object, Z_OBJ_P(zv))
#define LADYBUG_STATEMENT_P(zv)  LADYBUG_OBJ(ladybug_statement_object, Z_OBJ_P(zv))
#define LADYBUG_RESULT_P(zv)     LADYBUG_OBJ(ladybug_result_object, Z_OBJ_P(zv))

/* -- request-scoped class lookups ---------------------------------------------------- */

/*
 * NODE, REL and INTERNAL_ID values are returned as instances of userland classes from the
 * Composer package. Those come from the autoloader, so the class entries are only valid
 * within a request and are looked up lazily, then cached here for the rest of it.
 */
ZEND_BEGIN_MODULE_GLOBALS(ladybug)
    zend_class_entry *internal_id_ce;
    zend_class_entry *node_ce;
    zend_class_entry *rel_ce;
    zend_class_entry *path_ce;
    zend_class_entry *datetime_ce;
    zend_class_entry *dateinterval_ce;
ZEND_END_MODULE_GLOBALS(ladybug)

ZEND_EXTERN_MODULE_GLOBALS(ladybug)

#define LADYBUG_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(ladybug, v)

/* -- value conversion (ladybug_value.c) ---------------------------------------------- */

/* Converts one lbug_value into a zval. Returns FAILURE with an exception pending. */
int ladybug_value_to_zval(lbug_value *value, zval *out);

/* Throws Ladybug\Ext\Exception with a printf-style message. */
void ladybug_throw(zend_class_entry *ce, const char *format, ...);

/* Reads a char* that liblbug allocated into a zend_string and frees it. */
zend_string *ladybug_take_string(char *owned);

#endif /* PHP_LADYBUG_H */
