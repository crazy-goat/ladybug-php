/*
 * Minimal reproducer for a liblbug 0.19.1 crash on Linux. No PHP involved.
 *
 * liblbug's prebuilt Linux .so statically links libstdc++ and exports its symbols, 130 of
 * them with STB_GNU_UNIQUE binding — locale facet ids such as std::moneypunct<char>::id and
 * their initialisation guards:
 *
 *   nm -D --defined-only liblbug.so | grep -c '^[0-9a-f]* u '     # 130
 *
 * glibc binds STB_GNU_UNIQUE symbols process-wide and ignores RTLD_DEEPBIND for them, while
 * ordinary global symbols honour it. So a host that dlopens liblbug with RTLD_DEEPBIND while
 * a system libstdc++ is already loaded ends up with liblbug's locale registry split across
 * two C++ runtimes, and the first statement that compiles a std::regex — INSTALL does, when
 * building its HTTP client — dies inside std::codecvt.
 *
 * PHP is such a host: Zend's DL_LOAD (zend_portability.h) uses
 * RTLD_LAZY|RTLD_GLOBAL|RTLD_DEEPBIND for every extension and for ext/ffi's dlopen, and any
 * extension linking libstdc++ (intl alone) supplies the other half.
 *
 * Both ingredients are necessary and neither is sufficient. Verified on Debian arm64,
 * liblbug 0.19.1, in this order:
 *
 *   ./repro 1 1   -> 139 (SIGSEGV)   DEEPBIND on liblbug, libstdc++ loaded first
 *   ./repro 0 1   -> 0               no DEEPBIND
 *   ./repro 1 0   -> 0               no second libstdc++
 *
 * Suggested fix: link the Linux release with -Wl,--exclude-libs,ALL (or a version script
 * exporting only lbug_*) and compile with -fno-gnu-unique. The macOS dylib already exports
 * none of these symbols, so this is a Linux packaging difference rather than a design choice.
 *
 * Build (from the repo root, with liblbug unpacked in lib/):
 *   cc -Wall -Wextra -O2 -g -o /tmp/repro-dlopen tools/repro-install-crash-dlopen.c \
 *       -I lib -ldl
 *   LD_LIBRARY_PATH=lib /tmp/repro-dlopen 1 1; echo "exit=$?"
 *
 * Arguments: [deepbind=1] [load_libstdc++=1] [statement="INSTALL json"]
 */

#define _GNU_SOURCE
#include <dlfcn.h>
#include <stdio.h>
#include <stdlib.h>

#include "lbug.h"

int main(int argc, char** argv)
{
    int deepbind = argc > 1 ? atoi(argv[1]) : 1;
    int load_cxx = argc > 2 ? atoi(argv[2]) : 1;
    const char* statement = argc > 3 ? argv[3] : "INSTALL json";

    /* Stands in for PHP having loaded an extension that links the system libstdc++. */
    if (load_cxx && dlopen("libstdc++.so.6", RTLD_LAZY | RTLD_GLOBAL) == NULL) {
        fprintf(stderr, "dlopen(libstdc++.so.6) failed: %s\n", dlerror());
        return 1;
    }

    /* Exactly what Zend's DL_LOAD does, minus DEEPBIND when asked to compare. */
    void* lib = dlopen("liblbug.so", RTLD_LAZY | RTLD_GLOBAL | (deepbind ? RTLD_DEEPBIND : 0));
    if (lib == NULL) {
        fprintf(stderr, "dlopen(liblbug.so) failed: %s\n", dlerror());
        return 1;
    }

    printf("deepbind=%d libstdc++_first=%d statement=%s\n", deepbind, load_cxx, statement);
    fflush(stdout);

    /* Types come from lbug.h so the ABI is the real one — lbug_system_config is passed by
     * value, and a hand-rolled layout here would put the crash in doubt. */
    lbug_system_config (*default_config)(void) = dlsym(lib, "lbug_default_system_config");
    lbug_state (*database_init)(const char*, lbug_system_config, lbug_database*) = dlsym(lib, "lbug_database_init");
    lbug_state (*connection_init)(lbug_database*, lbug_connection*) = dlsym(lib, "lbug_connection_init");
    lbug_state (*query)(lbug_connection*, const char*, lbug_query_result*) = dlsym(lib, "lbug_connection_query");
    bool (*is_success)(lbug_query_result*) = dlsym(lib, "lbug_query_result_is_success");

    if (default_config == NULL || database_init == NULL || connection_init == NULL
        || query == NULL || is_success == NULL) {
        fprintf(stderr, "dlsym failed: %s\n", dlerror());
        return 1;
    }

    lbug_database database;
    lbug_connection connection;
    lbug_query_result result;

    if (database_init("", default_config(), &database) != LbugSuccess) {
        fprintf(stderr, "lbug_database_init failed\n");
        return 1;
    }
    if (connection_init(&database, &connection) != LbugSuccess) {
        fprintf(stderr, "lbug_connection_init failed\n");
        return 1;
    }

    printf("running the statement (this is where it dies)\n");
    fflush(stdout);

    if (query(&connection, statement, &result) != LbugSuccess) {
        fprintf(stderr, "lbug_connection_query failed\n");
        return 1;
    }

    printf("no crash, success=%d\n", (int) is_success(&result));

    /* Deliberately not destroying anything: this is a crash reproducer, the process is about
     * to exit, and every teardown call is one more thing for a reader to discount. */
    return 0;
}
