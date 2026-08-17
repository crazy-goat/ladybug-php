# Convenience targets for the native extension. The PHP side needs nothing but Composer.
#
#   make ext              build ext/modules/ladybug.so against ../lib (shared)
#   make ext-static       same, linking liblbug.a into the .so
#   make ext-asan         same, instrumented with AddressSanitizer
#   make ext-test         run the extension's own .phpt suite
#   make test-asan        integration suite under AddressSanitizer (needs ext-asan)
#   make bench            FFI vs the extension, both in one process
#   make docker-test      the whole suite on Linux (DOCKER_PHP=8.4 to pick a version)
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

.PHONY: ext ext-static ext-asan ext-test ext-clean liblbug test test-ffi test-ext test-both test-asan bench ci docker-build docker-test docker-shell docker-repro-install docker-static

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

# Both backends in one process, so the comparison is not across runs or machines.
bench:
	$(PHP) -d extension=$(EXT_SO) benchmarks/benchmark.php $(BENCH_ARGS)

# -- Linux, from a macOS workstation -------------------------------------------------------
#
# The image COPYs the source instead of mounting it: a mount would leave Linux object files
# and a Linux ladybug.so in ext/, which have the same names as the host's and would break
# the next native build. Rebuild after editing — it is cached down to the COPY.

DOCKER_IMAGE ?= ladybug-php-test
DOCKER_PHP   ?= 8.3

# Everything about the INSTALL crash — and the static linkage that avoids it — was first
# measured on arm64, because that is what an Apple laptop emulates for free. The released
# binaries target x86_64 as well, so both architectures have to be checked before shipping:
#
#   make docker-static DOCKER_PLATFORM=linux/amd64
#
# Emulated x86_64 is slow (minutes, not seconds) but faithful enough for a loader question.
DOCKER_PLATFORM ?=
docker_platform_arg = $(if $(DOCKER_PLATFORM),--platform $(DOCKER_PLATFORM),)
docker_tag_suffix   = $(if $(DOCKER_PLATFORM),-$(subst /,-,$(DOCKER_PLATFORM)),)

docker-build:
	docker build $(docker_platform_arg) --build-arg PHP_VERSION=$(DOCKER_PHP) \
		-t $(DOCKER_IMAGE)$(docker_tag_suffix) .

docker-test: docker-build
	docker run --rm $(docker_platform_arg) $(DOCKER_IMAGE)$(docker_tag_suffix)

docker-shell: docker-build
	docker run --rm -it $(docker_platform_arg) $(DOCKER_IMAGE)$(docker_tag_suffix) bash

# What a released binary has to satisfy, checked in the environment that broke: intl loaded
# (so the system libstdc++ is in the process), liblbug linked statically, and the extension
# exporting nothing of its bundled C++ runtime. The first two are the crash conditions; the
# third is what keeps us from handing the same hazard to the next library loaded.
#
# LADYBUG_TEST_EXTENSIONS=1 removes the skip guard, so INSTALL really runs here.
docker-static:
	docker build $(docker_platform_arg) --build-arg PHP_VERSION=$(DOCKER_PHP) \
		--build-arg EXTRA_PHP_EXTS=intl --build-arg LIBLBUG_VARIANT=static \
		-t $(DOCKER_IMAGE)-static$(docker_tag_suffix) .
	@docker run --rm $(docker_platform_arg) -e LADYBUG_TEST_EXTENSIONS=1 \
		$(DOCKER_IMAGE)-static$(docker_tag_suffix) bash tools/verify-static-so.sh

# liblbug 0.19.1 segfaults on INSTALL when a second C++ runtime shares the process, and since
# v0.3.1 the FFI connector defuses it by loading liblbug before ext/ffi can. Both facts have to
# be visible at once, or this target reads as "there is no bug": the middle row is the crash,
# and the third is the fix holding. LADYBUG_NO_PRELOAD=1 switches the fix off.
#
# Verified on arm64 and, through emulation, on x86_64 — 0 / 139 / 0 on both.
#
#   make docker-repro-install DOCKER_PLATFORM=linux/amd64
#
# gdb is in the image too: `make docker-shell`, then `gdb --args php …`.
docker-repro-install: docker-build
	docker build $(docker_platform_arg) --build-arg PHP_VERSION=$(DOCKER_PHP) \
		--build-arg EXTRA_PHP_EXTS=intl -t $(DOCKER_IMAGE)-intl$(docker_tag_suffix) .
	@printf '%s\n' '<?php' \
		'require "/app/vendor/autoload.php";' \
		'$$c = Ladybug\Database::inMemory(new Ladybug\Config(connector: "ffi"))->connect();' \
		'$$c->run("INSTALL json");' \
		'echo "no crash\n";' > $(CURDIR)/build/repro-install.php
	@echo "--- without intl (one C++ runtime): expect 0"
	@docker run --rm $(docker_platform_arg) -v $(CURDIR)/build/repro-install.php:/tmp/r.php:ro $(DOCKER_IMAGE)$(docker_tag_suffix) \
		php /tmp/r.php; echo "    exit=$$?"
	@echo "--- with intl, connector fix disabled: expect 139 (SIGSEGV)"
	@docker run --rm $(docker_platform_arg) -e LADYBUG_NO_PRELOAD=1 -v $(CURDIR)/build/repro-install.php:/tmp/r.php:ro \
		$(DOCKER_IMAGE)-intl$(docker_tag_suffix) php /tmp/r.php; echo "    exit=$$?"
	@echo "--- with intl, connector fix active: expect 0"
	@docker run --rm $(docker_platform_arg) -v $(CURDIR)/build/repro-install.php:/tmp/r.php:ro $(DOCKER_IMAGE)-intl$(docker_tag_suffix) \
		php /tmp/r.php; echo "    exit=$$?"

ci:
	composer ci
