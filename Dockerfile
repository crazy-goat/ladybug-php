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
#   make docker-repro-install a backtrace for the INSTALL crash
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
RUN docker-php-ext-install -j"$(nproc)" ffi bcmath

# The sanitizer cannot coexist with opcache's RTLD_DEEPBIND dlopen, and this image exists
# partly to run sanitised builds. Nothing here needs the optimiser.
RUN if [ -f /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini ]; then \
        rm /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini; \
    fi

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    LADYBUG_LIBRARY=/app/lib/liblbug.so

WORKDIR /app

# Only composer.json: composer.lock is gitignored, so CI resolves per PHP version and this
# image has to as well. A lock resolved on the host's PHP 8.5 pulls dev dependencies that
# refuse to install on 8.3.
COPY composer.json ./
RUN composer install --no-interaction --no-progress --no-scripts

COPY . .

# Fetch liblbug for this container's architecture and build the extension against it.
RUN bash tools/fetch-liblbug.sh 0.19.1 && make ext

CMD ["make", "test-both"]
