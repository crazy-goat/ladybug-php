# Contributing

## Getting a working checkout

```bash
composer install
make liblbug            # downloads liblbug for this platform into lib/
composer test           # unit + integration on whichever backend is available
```

That is enough to work on the PHP side. For the native extension:

```bash
make ext                # -> ext/modules/ladybug.so
make test-ext           # the integration suite against it
make ext-test           # the extension's own .phpt suite
```

## The one rule

**Anything you change in one connector, change in the other.** The FFI connector converts
values in PHP, the extension converts them in C, and the integration suite is deliberately
backend-agnostic so both are held to identical assertions:

```bash
composer test:both      # the same suite twice, once per backend
```

A change that passes on one backend and not the other is not finished. This is where the
design pays for itself, and it is also the only thing keeping `DateTimeImmutable` timezone
names and `INT128` string formatting from quietly diverging.

## Guard rails, and why they exist

These are not ceremony — each one exists because something went wrong without it.

| Check | Catches |
|---|---|
| `tests/Unit/Ffi/CdefMatchesHeaderTest` | a hand-transcribed declaration disagreeing with `lbug.h`. One wrong return type (`lbug_value_create_null`) segfaulted the process during development. |
| `tests/Unit/LibraryVersionParityTest` | the four places that name the supported liblbug version drifting apart. |
| `make ext-asan && make test-asan` | use-after-free and buffer overflows in the extension. A close-ordering bug once surfaced as an 8 TB mmap failure in an unrelated test. |
| `tests/Integration/MemoryTest` | C resources that are never released. `memory_get_usage()` cannot see those. |
| `make bench` | performance claims. The extension is 6x faster at fetching and 1.0x at writing; do not assume either. |

On a macOS workstation, `make docker-test` runs the whole suite on Linux — both connectors,
in a container that COPYs the source rather than mounting it, so it cannot leave Linux object
files in your `ext/`. Worth it before touching anything platform-shaped: the first time
liblbug misbehaved on Linux only, diagnosing it took a throwaway CI branch.

Before opening a pull request:

```bash
composer ci             # style, PHPStan level 8, Rector, tests
```

PHPStan runs at level 8 with no baseline, and there are only two ignores, both scoped to
FFI's magic methods. Please keep it that way: if PHPStan complains, it is usually right.

## Upgrading liblbug

The version is stated in four places on purpose — they cannot share a constant — and
`LibraryVersionParityTest` fails if they disagree:

1. `Ladybug\Connector\LibraryVersion::VERIFIED` and `SUPPORTED_SERIES`
2. `LADYBUG_LIBLBUG_VERIFIED` and `LADYBUG_LIBLBUG_SERIES` in `ext/php_ladybug.h`
3. the default in `tools/fetch-liblbug.sh`
4. `LIBLBUG_VERSION` in `.github/workflows/ci.yml`

For a new patch release inside a supported series, update 1, 3 and 4. For a **new minor**,
also:

- re-download `lib/` and run `CdefMatchesHeaderTest` — it compares every declaration and
  every `lbug_data_type_id` against the header
- check `lbug_system_config` field by field against `Cdef::source()`. This struct is passed
  **by value**; a field added in the middle means every following field is read from the
  wrong offset, and nothing fails loudly
- add the new series to `SUPPORTED_SERIES` and `LADYBUG_LIBLBUG_SERIES`

## Scope

Out of scope, deliberately:

- **Windows.** The FFI connector looks for `lbug_shared.dll`, but nothing has ever been run
  there and there is no `config.w32`. Not "PRs welcome" — it needs someone who can own the
  platform, including CI.
- **`AsyncConnection` and user-defined functions.** liblbug exposes them, but PHP has no
  threading model that gives them a sensible shape, and a bad shape in a 1.0 API is worse
  than an absent feature.

## Reporting a bug

Please include the backend (`ext` or `ffi`), your PHP version, and the liblbug version — a
type-conversion or lifetime bug in one backend usually does not exist in the other, and
knowing which one halves the search. `ConnectorFactory::diagnostics()` and
`php --ri ladybug` cover most of it.
