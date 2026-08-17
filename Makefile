# Convenience targets for the native extension. The PHP side needs nothing but Composer.
#
#   make ext              build ext/modules/ladybug.so against ../lib (shared)
#   make ext-static       same, linking liblbug.a into the .so
#   make ext-test         run the extension's own .phpt suite
#   make test             PHP suite on the default connector
#   make test-both        PHP suite on FFI and on the extension

LIBLBUG_DIR ?= $(CURDIR)/lib
EXT_SO      := $(CURDIR)/ext/modules/ladybug.so
PHP         ?= php

.PHONY: ext ext-static ext-test ext-clean liblbug test test-ffi test-ext test-both ci

ext:
	cd ext && phpize -q && ./configure --enable-ladybug --with-liblbug=$(LIBLBUG_DIR) >/dev/null && $(MAKE) -s
	@otool -L $(EXT_SO) 2>/dev/null | grep lbug || ldd $(EXT_SO) 2>/dev/null | grep lbug || true

# Self-contained .so (~20 MB): needs a liblbug-static-* release unpacked in $(LIBLBUG_DIR).
ext-static:
	cd ext && phpize -q && ./configure --enable-ladybug --enable-ladybug-static --with-liblbug=$(LIBLBUG_DIR) >/dev/null && $(MAKE) -s

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
	LADYBUG_CONNECTOR=ext $(PHP) -d extension=$(EXT_SO) vendor/bin/phpunit --testsuite integration

# The point of the two-backend design: identical assertions, both implementations.
test-both: test-ffi test-ext

ci:
	composer ci
