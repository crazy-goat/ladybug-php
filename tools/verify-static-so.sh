#!/usr/bin/env bash
# Acceptance checks for a statically linked ladybug.so — the shape that gets distributed.
#
#   bash tools/verify-static-so.sh [path/to/ladybug.so]
#
# Run this inside the environment that breaks: a Linux process with intl loaded, so the system
# libstdc++ is already mapped when liblbug initialises. `make docker-static` builds exactly
# that image and calls this; the release workflow calls it again on the artefact it uploads,
# in a container that has no repository in it.
#
# Four things are checked, and each one is a bug that shipped or nearly shipped:
#
#   1. no liblbug in the dynamic dependencies    — otherwise the archive was not linked in
#   2. no exported symbol that is not liblbug's  — 281 std:: and 23 unique leaked until v0.4.0
#   3. liblbug's own symbols still exported      — hiding them breaks `LOAD json`
#   4. INSTALL and LOAD actually run             — the crash itself, with intl in the process
#
# Checks 2 and 3 are the two ways to get the export set wrong, and they pull against each
# other: glibc binds STB_GNU_UNIQUE symbols process-wide regardless of RTLD_DEEPBIND, so
# re-exporting libstdc++'s locale facets hands the next library the split runtime that breaks
# liblbug — while LadybugDB's downloaded extensions resolve 91 `lbug::` symbols from us and
# fail to load without them. ext/ladybug.map draws the line; this checks it held.
set -uo pipefail

SO="${1:-ext/modules/ladybug.so}"
failures=0

fail() {
    printf '  FAIL  %s\n' "$1"
    failures=$((failures + 1))
}

pass() {
    printf '  ok    %s\n' "$1"
}

if [ ! -f "$SO" ]; then
    echo "verify-static-so: $SO does not exist" >&2
    exit 1
fi

echo "== $SO ($(du -h "$SO" | cut -f1), $(uname -s) $(uname -m))"

# macOS shares the linkage question and none of the symbol-visibility one: a dylib's symbols are
# not offered to the rest of the process (two-level namespace), so nothing it exports can be
# bound by anyone else by accident. The checks below follow that split.
case "$(uname -s)" in
    Darwin) linked() { otool -L "$1"; } ;;
    *)      linked() { ldd "$1"; } ;;
esac

# -- 1. linkage ---------------------------------------------------------------------------

if linked "$SO" | grep -i lbug; then
    fail "the extension still depends on liblbug at runtime — this is not a static build"
else
    pass "no liblbug dependency"
fi

if linked "$SO" | grep -qi 'libstdc++\|libc++'; then
    # Not fatal: the C++ runtime itself may legitimately be shared. Worth printing, because a
    # binary that links it has a version floor on the target machine.
    printf '  note  links the C++ runtime dynamically: %s\n' \
        "$(linked "$SO" | grep -i 'libstdc++\|libc++' | tr -s ' ' | sed 's/^ //' | cut -d' ' -f1 | paste -sd' ' -)"
fi

# -- 2 and 3. what we export ---------------------------------------------------------------

check_exports() {
    local exports strays lbug_cxx
    exports=$(nm -D --defined-only "$SO" 2>/dev/null)

    # nm -D prints mangled names, so grepping for the literal "std::" finds nothing and reads
    # as a clean result. It is not: _ZNSt/_ZSt are the prefixes, and `u` in the type column is
    # STB_GNU_UNIQUE. Anything matching those without a liblbug type in the name belongs to the
    # C++ standard library and must not be visible.
    strays=$(printf '%s\n' "$exports" | grep ' _ZNSt\| _ZSt\|^[0-9a-f]* u ' | grep -v '4lbug' | wc -l | tr -d ' ')

    if [ "$strays" -eq 0 ]; then
        pass "exports no C++ standard library symbols of its own"
    else
        fail "exports $strays libstdc++ symbols (expected 0 — is ext/ladybug.map applied?)"
        printf '%s\n' "$exports" | grep ' _ZNSt\| _ZSt\|^[0-9a-f]* u ' | grep -v '4lbug' \
            | c++filt 2>/dev/null | cut -c20-110 | head -5 | sed 's/^/          /'
    fi

    # The other direction: LadybugDB's downloaded extensions link against liblbug's C++ API, so
    # hiding it turns `LOAD json` into an undefined-symbol error. 91 symbols are needed; the
    # count here is in the thousands, and only its being non-trivial matters.
    lbug_cxx=$(printf '%s\n' "$exports" | grep -c '4lbug')
    if [ "$lbug_cxx" -gt 500 ]; then
        pass "exports liblbug's own symbols ($lbug_cxx), which its extensions resolve against"
    else
        fail "exports only $lbug_cxx liblbug symbols; LOAD json will fail on undefined symbols"
    fi

    printf '%s\n' "$exports" | grep -q ' get_module' \
        && pass "exports get_module" \
        || fail "does not export get_module; PHP cannot load this at all"
}

if [ "$(uname -s)" = "Darwin" ]; then
    printf '  skip  export checks: a two-level namespace offers nothing to other libraries\n'
else
    check_exports
fi

# -- 4. the crash itself -------------------------------------------------------------------

php -m | grep -q '^intl$' \
    || printf '  note  intl is not loaded, so the crash conditions are absent from this run\n'

# INSTALL is reached through the library when there is one, and the artefact is only checked
# for loadability when this runs against a bare .so outside a checkout.
if [ -f vendor/autoload.php ]; then
    cat > /tmp/verify-install.php <<'PHP'
<?php
require getcwd() . '/vendor/autoload.php';
$c = Ladybug\Database::inMemory(new Ladybug\Config(connector: 'ext'))->connect();
foreach (['json', 'fts', 'vector'] as $extension) {
    $c->run("INSTALL {$extension}");
    $c->run("LOAD {$extension}");
}
// Not just loaded: a value has to come back through the type this extension adds.
$c->run('CREATE NODE TABLE Doc(id INT64, payload JSON, PRIMARY KEY(id))');
$c->run('CREATE (:Doc {id: 1, payload: to_json({a: 1})})');
exit($c->query('MATCH (d:Doc) RETURN d.payload')->fetchOne() === '{"a":1}' ? 0 : 1);
PHP
    php -d extension="$(cd "$(dirname "$SO")" && pwd)/$(basename "$SO")" /tmp/verify-install.php
    status=$?
    # A signalled process reports 128+n with n at most 64. PHP's own fatal error is 255, and an
    # uncaught exception has to stay distinguishable from a segfault — the two mean opposite
    # things here, and conflating them once turned a failed LOAD into "the crash is not avoided".
    if [ "$status" -eq 0 ]; then
        pass "INSTALL and LOAD run for json, fts and vector, and a JSON value round-trips"
    elif [ "$status" -gt 128 ] && [ "$status" -le 192 ]; then
        fail "died on signal $((status - 128)) — the INSTALL crash is not avoided"
    else
        # Offline containers cannot download an extension at all; that is not a linkage fault.
        printf '  note  exited %d without a signal (offline, or an error above), no verdict\n' "$status"
    fi
else
    php -d extension="$SO" -m | grep -q '^ladybug$' \
        && pass "the extension loads" \
        || fail "the extension does not load"
fi

# -- the suites, when run from a checkout --------------------------------------------------

if [ -f phpunit.xml ] && [ -x vendor/bin/phpunit ]; then
    echo "== integration suite, native extension"
    make test-ext || fail "the integration suite failed against the static extension"
    echo "== .phpt suite"
    make ext-test || fail "the .phpt suite failed against the static extension"

    if [ -f lib/liblbug.so ] || [ -f lib/liblbug.dylib ]; then
        echo "== integration suite, FFI connector (same process conditions)"
        make test-ffi || fail "the integration suite failed against the FFI connector"
    fi
fi

echo
if [ "$failures" -eq 0 ]; then
    echo "verify-static-so: all checks passed"
    exit 0
fi
echo "verify-static-so: $failures check(s) failed"
exit 1
