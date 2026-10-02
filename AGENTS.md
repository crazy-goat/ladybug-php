# AGENTS.md

Project commands and specifics for ladybug-php, a PHP client for LadybugDB (an embedded graph
database) with two interchangeable connectors: FFI (PHP) and a native C extension (`ext/`).
The development process (issue, worktree, review, PR, merge) is in
[docs/workflow.md](docs/workflow.md), the release process in
[docs/release-workflow.md](docs/release-workflow.md). The default branch is `main`.

Everything is written in English (code, comments, docs, commits, issues). The UTF-8 test data
in `tests/Integration/ParameterTest.php` and `TypeMappingTest.php` is deliberate.

## Layout

| Path | Content |
|---|---|
| `src/` | Library, namespace `Ladybug\` (`Database`, `Connection`, `PreparedStatement`, `QueryResult`, `Connector/`, `Bulk/`, `Type/`, `Exception/`) |
| `ext/` | The native extension in C (`ladybug.c`, `ladybug_value.c`, `config.m4`, `.phpt` tests in `ext/tests/`) |
| `stubs/` | PHPStan stubs for the extension ABI |
| `tests/Unit/` | Unit suite, no database needed |
| `tests/Integration/` | Integration suite, backend-agnostic (`LADYBUG_CONNECTOR=ffi` or `ext`) |
| `tools/` | `fetch-liblbug.sh`, `verify-static-so.sh`, `run-asan.sh`, `build-ext-mirror.sh`, coverage gate |
| `bin/` | `lint.sh`, `pick-issue.sh`, `worktree*.sh` |
| `docs/` | Process docs |

`ladybug-ext` (the PIE package) is generated from `ext/` by `make mirror-ext`; it is a separate
mirror repository and is never edited by hand.

## Commands

PHP 8.2+ (CI runs 8.2 to 8.5) with `ext-ffi` and `ext-bcmath`.

```bash
composer install
make liblbug               # downloads liblbug into lib/ (needed by every test run)

# Lint: php-cs-fixer + PHPStan + Rector (dry run) + clang-format (ext/*.c, ext/*.h)
#       + shellcheck (all tracked shell scripts) + hadolint (needs the last three installed)
bin/lint.sh                # check only; runs every step; `composer lint` / `make lint` call it
bin/lint.sh --fix          # fixers first, then the checks; `composer lint:fix`
composer cs | stan | rector     # single tools

# Tests
composer test:unit         # no database
composer test:ffi          # integration suite on the FFI connector
make ext                   # build ext/modules/ladybug.so
make test-ext              # integration suite on the extension
make ext-test              # the extension's own .phpt tests
make test-both             # both backends
make ext-asan && make test-asan   # AddressSanitizer build (see CONTRIBUTING.md)
make docker-test           # the whole suite on Linux, from a macOS workstation
```

Required tools for `bin/lint.sh`: `clang-format` 23 (CI pins `clang-format==23.1.2`),
`shellcheck`, `hadolint`; a missing tool is a failure. The C style is `.clang-format`.

## Conventions

- Anything changed in one connector is changed in the other, and `composer test:both` passes.
  See [CONTRIBUTING.md](CONTRIBUTING.md).
- `declare(strict_types=1)` in every PHP file; PHP-CS-Fixer (`@PER-CS2.0`), PHPStan level 8
  without a baseline, Rector for PHP 8.2.
- The liblbug version is stated in four places that `LibraryVersionParityTest` keeps in sync,
  including `LIBLBUG_VERSION` in `.github/workflows/ci.yml` (keep that line in the `env:` block).
- Commit style: Conventional Commits (`feat`, `fix`, `perf`, `test`, `docs`, `chore`, `ci`).
  Branches and worktrees follow `docs/workflow.md`.
- Update `CHANGELOG.md` under `## [Unreleased]` for every user-visible change.
  `tests/Unit/ExtensionVersionTest` reads it, so it counts as code in CI.
- No Docker Compose in this repository: `bin/worktree-setup.sh` installs Composer dependencies
  and fetches liblbug; `bin/worktree-teardown.sh` has nothing to stop.

## CI

`.github/workflows/ci.yml` runs on pull requests to `main` and on pushes to `main`. The
`changes` job detects documentation-only changes and the `docs` job checks them fast. `lint`,
`test` (PHP 8.2 to 8.5 on Linux and macOS), `coverage`, `sanitizer` (AddressSanitizer on macOS)
and `static-link` run only for code changes. `ci-ok` aggregates the results and is the check to
require. The `lint` job installs pinned tools and runs only `bin/lint.sh`. Tag pushes (`v*`) run
`release.yaml`: it builds the extension binaries and creates the GitHub Release from the
`CHANGELOG.md` section.
