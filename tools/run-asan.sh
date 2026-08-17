#!/usr/bin/env bash
#
# Runs a command with AddressSanitizer's runtime loaded into PHP.
#
# The extension is instrumented (make ext-asan), but the PHP binary is not, so the runtime
# has to be preloaded — otherwise malloc is never intercepted, the heap is never poisoned,
# and the instrumentation reports nothing at all. (Verified the other way round: with this
# script a deliberate one-byte overflow in MINIT is reported with a symbolised stack.)
#
#   bash tools/run-asan.sh php -d extension=… vendor/bin/phpunit --testsuite integration
#
# Wrap php itself, not make: on macOS the runtime cannot be inserted into Apple-signed
# binaries. The environment set here does not survive into grandchildren that build their own
# env — run-tests.php does exactly that, which is why .phpt files are not run this way.
#
set -euo pipefail

if [ "$#" -eq 0 ]; then
    echo "usage: $0 <command> [args...]" >&2
    exit 2
fi

find_runtime() {
    case "$(uname -s)" in
        Darwin)
            local developer
            developer="$(xcode-select -p 2>/dev/null || echo /Library/Developer/CommandLineTools)"
            find "${developer}/usr/lib/clang" -name 'libclang_rt.asan_osx_dynamic.dylib' 2>/dev/null | head -1
            ;;
        Linux)
            local candidate
            for compiler in "${CC:-cc}" gcc clang; do
                command -v "$compiler" >/dev/null 2>&1 || continue
                for name in libasan.so libclang_rt.asan-x86_64.so libclang_rt.asan-aarch64.so; do
                    candidate="$("$compiler" -print-file-name="$name" 2>/dev/null || true)"
                    # -print-file-name echoes the name back unchanged when it finds nothing.
                    if [ -n "$candidate" ] && [ "$candidate" != "$name" ] && [ -e "$candidate" ]; then
                        echo "$candidate"
                        return
                    fi
                done
            done
            ;;
    esac
}

RUNTIME="$(find_runtime)"
if [ -z "$RUNTIME" ]; then
    echo "run-asan: could not find the AddressSanitizer runtime for $(uname -s)." >&2
    echo "  Linux: install libasan (gcc) or compiler-rt (clang); macOS: install the Xcode command line tools." >&2
    exit 1
fi

case "$(uname -s)" in
    Darwin) export DYLD_INSERT_LIBRARIES="$RUNTIME" ;;
    Linux) export LD_PRELOAD="$RUNTIME" ;;
esac

# Zend's allocator serves our emalloc()s out of its own pools, which ASAN sees as one big
# valid region — an overflow inside it would go unnoticed. This routes them to malloc.
export USE_ZEND_ALLOC=0

# Leak detection is deliberately off: the host PHP is not instrumented, so its own startup
# allocations are reported as leaks and drown out ours. Leaks are covered separately, by
# the RSS growth test in the integration suite.
export ASAN_OPTIONS="${ASAN_OPTIONS:-detect_leaks=0:verify_asan_link_order=0:exitcode=1:print_summary=1}"

echo "run-asan: $RUNTIME"
echo "run-asan: ASAN_OPTIONS=$ASAN_OPTIONS"

exec "$@"
