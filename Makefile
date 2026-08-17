# Convenience targets for the native extension. The PHP side needs nothing but Composer.
#
#   make ext              build ext/modules/ladybug.so against ../lib (shared)
#   make ext-static       same, linking liblbug.a into the .so
#   make ext-asan         same, instrumented with AddressSanitizer
#   make ext-test         run the extension's own .phpt suite
#   make test-asan        integration suite under AddressSanitizer (needs ext-asan)
#   make test             PHP suite on the default connector
#   make test-both        PHP suite on FFI and on the extension

LIBLBUG_DIR ?= $(CURDIR)/lib
EXT_SO      := $(CURDIR)/ext/modules/ladybug.so
PHP         ?= php

ASAN_CFLAGS ?= -fsanitize=address -fno-omit-frame-pointer -g -O1

# The object files do not depend on configure's flags, so switching between the plain, static
# and instrumented builds leaves make convinced everything is up to date — you get the old
# .so with the new configuration, which is how an "uninstrumented" build kept reporting ASAN
# symbols. Recording the mode and cleaning on a change is the cheapest reliable fix.
define with_mode
@if [ "$$(cat ext/.build-mode 2>/dev/null)" != "$(1)" ]; then \
	(cd ext && $(MAKE) -s clean >/dev/null 2>&1 || true); \
	mkdir -p ext && echo "$(1)" > ext/.build-mode; \
fi
endef

.PHONY: ext ext-static ext-asan ext-test ext-clean liblbug test test-ffi test-ext test-both test-asan ci

ext:
	$(call with_mode,shared)
	cd ext && phpize -q && ./configure --enable-ladybug --with-liblbug=$(LIBLBUG_DIR) >/dev/null && $(MAKE) -s
	@otool -L $(EXT_SO) 2>/dev/null | grep lbug || ldd $(EXT_SO) 2>/dev/null | grep lbug || true

# Self-contained .so (~20 MB): needs a liblbug-static-* release unpacked in $(LIBLBUG_DIR).
ext-static:
	$(call with_mode,static)
	cd ext && phpize -q && ./configure --enable-ladybug --enable-ladybug-static --with-liblbug=$(LIBLBUG_DIR) >/dev/null && $(MAKE) -s

# Instrumented build for `make test-asan`. Reconfigures, so it always rebuilds from scratch;
# run `make ext` afterwards to get an uninstrumented .so back (ASAN slows queries down a lot).
ext-asan:
	$(call with_mode,asan)
	cd ext && phpize -q \
		&& CFLAGS="$(ASAN_CFLAGS)" LDFLAGS="-fsanitize=address" \
			./configure --enable-ladybug --with-liblbug=$(LIBLBUG_DIR) >/dev/null \
		&& $(MAKE) -s

# phpize does not always manage to place run-tests.php, so fall back to the copy that
# ships with the PHP build.
ext-test:
	@test -f ext/run-tests.php || cp "$$($(PHP)-config --include-dir)/../../lib/php/build/run-tests.php" ext/run-tests.php
	cd ext && TEST_PHP_EXECUTABLE=$$(which $(PHP)) $(PHP) run-tests.php -d extension=$(EXT_SO) tests/

ext-clean:
	cd ext && ($(MAKE) -s distclean 2>/dev/null || true) && rm -rf modules .libs build configure autom4te.cache

# Downloads the shared liblbug for this platform into lib/.
liblbug:
	@bash tools/fetch-liblbug.sh

test:
	vendor/bin/phpunit

test-ffi:
	LADYBUG_CONNECTOR=ffi vendor/bin/phpunit --testsuite integration

test-ext:
	LADYBUG_CONNECTOR=ext $(RUNNER) $(PHP) -d extension=$(EXT_SO) vendor/bin/phpunit --testsuite integration

# The point of the two-backend design: identical assertions, both implementations.
test-both: test-ffi test-ext

# The integration suite under AddressSanitizer — the same 107 tests, so every C path the
# extension has is exercised with a poisoned heap.
#
# Only that suite: run-tests.php gives its children a clean environment, so neither the
# preloaded runtime nor USE_ZEND_ALLOC reaches them and every .phpt would silently SKIP —
# reporting success with zero tests run. Hence the guard below; a sanitizer job that quietly
# checks nothing is worse than no sanitizer job.
#
# RUNNER wraps the php invocation only, never make: on macOS the sanitizer cannot be inserted
# into Apple-signed binaries ("Sanitizer load violates platform policy").
test-asan:
	@nm $(EXT_SO) 2>/dev/null | grep -q __asan \
		|| { echo "test-asan: $(EXT_SO) is not instrumented — run 'make ext-asan' first." >&2; exit 1; }
	$(MAKE) test-ext RUNNER="bash $(CURDIR)/tools/run-asan.sh"

ci:
	composer ci
