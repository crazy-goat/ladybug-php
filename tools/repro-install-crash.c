/*
 * Minimal reproducer for a suspected liblbug crash when running INSTALL
 * (json/fts/vector). See https://github.com/LadybugDB/ladybug for context.
 *
 * Build + run on macOS (from the repo root):
 *   cc -Wall -Wextra -o /tmp/repro tools/repro-install-crash.c \
 *       -I lib -L lib -llbug -Wl,-rpath,lib
 *   /tmp/repro
 *   /tmp/repro "RETURN 1"
 *
 * Build + run on Linux (from the repo root):
 *   cc -Wall -Wextra -o /tmp/repro tools/repro-install-crash.c \
 *       -I lib -L lib -llbug -Wl,-rpath,lib
 *   /tmp/repro
 *   /tmp/repro "RETURN 1"
 *
 * (On Linux the loader needs the rpath baked in above, or set
 * LD_LIBRARY_PATH=lib instead.)
 */

#include <stdio.h>
#include <stdlib.h>

#include "lbug.h"

int main(int argc, char** argv) {
    const char* statement = (argc > 1) ? argv[1] : "INSTALL json";

    char* version = lbug_get_version();
    printf("liblbug version: %s\n", version);
    fflush(stdout);
    lbug_destroy_string(version);

    printf("statement: %s\n", statement);
    fflush(stdout);

    lbug_system_config config = lbug_default_system_config();

    lbug_database db;
    if (lbug_database_init("", config, &db) != LbugSuccess) {
        printf("lbug_database_init failed\n");
        fflush(stdout);
        return 1;
    }

    lbug_connection conn;
    if (lbug_connection_init(&db, &conn) != LbugSuccess) {
        printf("lbug_connection_init failed\n");
        fflush(stdout);
        lbug_database_destroy(&db);
        return 1;
    }

    lbug_query_result result;
    int exit_code;
    if (lbug_connection_query(&conn, statement, &result) != LbugSuccess) {
        printf("lbug_connection_query call failed\n");
        fflush(stdout);
        exit_code = 1;
    } else if (lbug_query_result_is_success(&result)) {
        char* rendered = lbug_query_result_to_string(&result);
        printf("result: %s\n", rendered);
        fflush(stdout);
        lbug_destroy_string(rendered);
        exit_code = 0;
    } else {
        char* error = lbug_query_result_get_error_message(&result);
        printf("error: %s\n", error);
        fflush(stdout);
        lbug_destroy_string(error);
        exit_code = 1;
    }
    lbug_query_result_destroy(&result);

    lbug_connection_destroy(&conn);
    lbug_database_destroy(&db);

    return exit_code;
}
