/*
 * Reproducer for a suspected liblbug 0.19.1 crash caused by dlopen()
 * ordering, with no PHP involved at all. See:
 * https://github.com/LadybugDB/ladybug
 *
 * Background: PHP's intl extension links libstdc++ and is loaded at
 * startup with dlopen(..., RTLD_LAZY|RTLD_GLOBAL|RTLD_DEEPBIND). liblbug
 * itself only reaches the process later, through PHP's FFI extension,
 * which dlopen()s it with plain RTLD_LAZY|RTLD_GLOBAL (no DEEPBIND).
 * The hypothesis is that liblbug's internal libstdc++ symbol references
 * (e.g. std::codecvt<char16_t, char, mbstate_t>) then resolve against the
 * *system* libstdc++ sitting in the global symbol namespace instead of
 * the copy statically linked into liblbug.so, producing two coexisting
 * incompatible C++ runtimes and a crash inside liblbug's locale/codecvt
 * code the first time INSTALL pulls in ICU/ffmpeg-style facets.
 *
 * This program mimics that load order using only dlopen()/dlsym() -
 * liblbug is never a link-time dependency, so its own symbols cannot
 * win at link time the way they do in tools/repro-install-crash.c.
 *
 * Build (Linux, from the repo root, against the .so inside the Docker
 * image at /app/lib):
 *   cc -Wall -Wextra -o /tmp/repro-dlopen \
 *       tools/repro-install-crash-dlopen.c -I lib -ldl
 *
 * Run (env vars select the load order being tested):
 *   PRELOAD_CXX=0            /tmp/repro-dlopen              # baseline
 *   PRELOAD_CXX=1 DEEPBIND=0 /tmp/repro-dlopen              # FFI-like
 *   PRELOAD_CXX=1 DEEPBIND=1 /tmp/repro-dlopen              # PHP-startup-like
 *
 * A statement to run may be given as argv[1] (default "INSTALL json").
 * Exit code 139 (128 + SIGSEGV) means the crash reproduced without any
 * PHP in the process at all.
 */

#define _GNU_SOURCE
#include <dlfcn.h>
#include <stdio.h>
#include <stdlib.h>

#include "lbug.h"

typedef char* (*lbug_get_version_fn)(void);
typedef lbug_system_config (*lbug_default_system_config_fn)(void);
typedef lbug_state (*lbug_database_init_fn)(const char*, lbug_system_config, lbug_database*);
typedef lbug_state (*lbug_connection_init_fn)(lbug_database*, lbug_connection*);
typedef lbug_state (*lbug_connection_query_fn)(
    lbug_connection*, const char*, lbug_query_result*);
typedef bool (*lbug_query_result_is_success_fn)(lbug_query_result*);
typedef char* (*lbug_query_result_to_string_fn)(lbug_query_result*);
typedef char* (*lbug_query_result_get_error_message_fn)(lbug_query_result*);
typedef void (*lbug_query_result_destroy_fn)(lbug_query_result*);
typedef void (*lbug_connection_destroy_fn)(lbug_connection*);
typedef void (*lbug_database_destroy_fn)(lbug_database*);
typedef void (*lbug_destroy_string_fn)(char*);

static void* must_dlsym(void* handle, const char* name) {
    void* sym = dlsym(handle, name);
    if (!sym) {
        fprintf(stderr, "dlsym(%s) failed: %s\n", name, dlerror());
        exit(1);
    }
    return sym;
}

static int env_flag(const char* name, int default_value) {
    const char* v = getenv(name);
    if (!v || !v[0]) {
        return default_value;
    }
    return v[0] != '0';
}

int main(int argc, char** argv) {
    const char* statement = (argc > 1) ? argv[1] : "INSTALL json";
    int preload_cxx = env_flag("PRELOAD_CXX", 1);
    int deepbind = env_flag("DEEPBIND", 0);

    printf("PRELOAD_CXX=%d DEEPBIND=%d statement=%s\n", preload_cxx, deepbind, statement);
    fflush(stdout);

    if (preload_cxx) {
        int flags = RTLD_NOW | RTLD_GLOBAL | (deepbind ? RTLD_DEEPBIND : 0);
        printf("dlopen libstdc++.so.6 (flags=0x%x) ...\n", flags);
        fflush(stdout);
        void* cxx = dlopen("libstdc++.so.6", flags);
        if (!cxx) {
            fprintf(stderr, "dlopen(libstdc++.so.6) failed: %s\n", dlerror());
            return 1;
        }
        printf("libstdc++.so.6 loaded\n");
        fflush(stdout);
    }

    printf("dlopen liblbug.so (RTLD_LAZY|RTLD_GLOBAL) ...\n");
    fflush(stdout);
    void* lbug = dlopen("liblbug.so", RTLD_LAZY | RTLD_GLOBAL);
    if (!lbug) {
        fprintf(stderr, "dlopen(liblbug.so) failed: %s\n", dlerror());
        return 1;
    }
    printf("liblbug.so loaded\n");
    fflush(stdout);

    lbug_get_version_fn lbug_get_version = must_dlsym(lbug, "lbug_get_version");
    lbug_default_system_config_fn lbug_default_system_config
        = must_dlsym(lbug, "lbug_default_system_config");
    lbug_database_init_fn lbug_database_init = must_dlsym(lbug, "lbug_database_init");
    lbug_connection_init_fn lbug_connection_init = must_dlsym(lbug, "lbug_connection_init");
    lbug_connection_query_fn lbug_connection_query = must_dlsym(lbug, "lbug_connection_query");
    lbug_query_result_is_success_fn lbug_query_result_is_success
        = must_dlsym(lbug, "lbug_query_result_is_success");
    lbug_query_result_to_string_fn lbug_query_result_to_string
        = must_dlsym(lbug, "lbug_query_result_to_string");
    lbug_query_result_get_error_message_fn lbug_query_result_get_error_message
        = must_dlsym(lbug, "lbug_query_result_get_error_message");
    lbug_query_result_destroy_fn lbug_query_result_destroy
        = must_dlsym(lbug, "lbug_query_result_destroy");
    lbug_connection_destroy_fn lbug_connection_destroy
        = must_dlsym(lbug, "lbug_connection_destroy");
    lbug_database_destroy_fn lbug_database_destroy = must_dlsym(lbug, "lbug_database_destroy");
    lbug_destroy_string_fn lbug_destroy_string = must_dlsym(lbug, "lbug_destroy_string");

    char* version = lbug_get_version();
    printf("liblbug version: %s\n", version);
    fflush(stdout);
    lbug_destroy_string(version);

    lbug_system_config config = lbug_default_system_config();

    lbug_database db;
    if (lbug_database_init("", config, &db) != LbugSuccess) {
        printf("lbug_database_init failed\n");
        fflush(stdout);
        return 1;
    }
    printf("database initialized\n");
    fflush(stdout);

    lbug_connection conn;
    if (lbug_connection_init(&db, &conn) != LbugSuccess) {
        printf("lbug_connection_init failed\n");
        fflush(stdout);
        lbug_database_destroy(&db);
        return 1;
    }
    printf("connection initialized\n");
    fflush(stdout);

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

    printf("done\n");
    fflush(stdout);

    return exit_code;
}
