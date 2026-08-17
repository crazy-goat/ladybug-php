# Linux test environment.
#
# Two things cannot be checked on a macOS workstation: the Linux code paths (different
# liblbug build, different dynamic loader) and liblbug's Linux-only failures — `INSTALL`
# segfaults there, which took a throwaway CI branch to diagnose the first time.
#
# The source is COPYed in rather than bind-mounted on purpose. A mount would drop Linux
# object files and a Linux ladybug.so into ext/, silently breaking the host's build: the
# artefacts have the same names and the build-mode marker cannot tell the platforms apart.
#
#   make docker-test          the whole suite, both connectors, on Linux
#   make docker-shell         a shell in the same image
#   make docker-repro-install liblbug's INSTALL crash, on demand
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gdb \
        git \
        libffi-dev \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# ffi for the FFI connector, bcmath for INT128 above the 64-bit range.
#
# EXTRA_PHP_EXTS exists for one purpose: adding `intl` reproduces liblbug's INSTALL crash.
# intl links the system libstdc++, which then shares the process with liblbug's own bundled
# copy — and INSTALL dies inside std::codecvt. Same image, same liblbug, same script: exit 0
# without it, 139 with it.
ARG EXTRA_PHP_EXTS=""
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j"$(nproc)" ffi bcmath ${EXTRA_PHP_EXTS}

# The sanitizer cannot coexist with opcache's RTLD_DEEPBIND dlopen, and this image exists
# partly to run sanitised builds. Nothing here needs the optimiser.
RUN if [ -f /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini ]; then \
        rm /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini; \
    fi

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_ROOT_VERSION=dev-main \
    LADYBUG_LIBRARY=/app/lib/liblbug.so

WORKDIR /app

# The image runs tests; it does not lint. Installing the full dev set pulled some fifty
# packages from codeload, which answers 429 often enough to break the build for no reason —
# so PHPUnit comes as a phar and nothing else is fetched at all. This package has no runtime
# dependencies, so `--no-dev` downloads nothing.
#
# composer.lock is deliberately not copied: it is gitignored, CI resolves per PHP version,
# and a lock resolved on a PHP 8.5 host pulls dev packages that refuse to install on 8.3.
ARG PHPUNIT_MAJOR=11
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts \
    && mkdir -p vendor/bin \
    && curl -fsSL -o vendor/bin/phpunit "https://phar.phpunit.de/phpunit-${PHPUNIT_MAJOR}.phar" \
    && chmod +x vendor/bin/phpunit \
    && vendor/bin/phpunit --version

COPY . .

# autoload-dev carries the test and tool namespaces. --dev is explicit because dump-autoload
# inherits the previous install's no-dev mode, and silently omitting them leaves an image whose
# tests cannot be found.
#
# The check reads the PSR-4 map rather than loading a test class: those extend PHPUnit classes,
# which exist only inside the phar's own runtime.
RUN composer dump-autoload --dev --no-interaction \
    && php -r '$m = require "vendor/composer/autoload_psr4.php"; exit(isset($m["Ladybug\\Tests\\"]) ? 0 : 1);'

# Fetch liblbug for this container's architecture and build the extension against it.
RUN bash tools/fetch-liblbug.sh 0.19.1 && make ext

CMD ["make", "test-both"]
