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

### A `ladybug` extension loaded on the analysing machine

Installing the extension puts it into every `php` on that machine — either in `php.ini` or,
with the release install, in a `99-ladybug.ini` in the ini scan directory (`README.md`,
"Prebuilt extension binary"). `make ext` only builds `ext/modules/ladybug.so`; it is the
install step that makes it load. PHPStan and Rector run *inside* a PHP process, so they see
whatever that process has loaded — which in a repository whose extension registers the
library's own class names means the analysis runs against the installed extension instead of
the working tree (php-zvec #188).

The class names do not collide here: the extension registers seven classes, all under
`Ladybug\Ext\` (`Database`, `Connection`, `Statement`, `Result` in `ext/ladybug_arginfo.h`,
`Exception`, `DatabaseError`, `QueryError` in `ext/ladybug.c`), while the library is
`Ladybug\`. **The lint result is machine-dependent all the same, through the stub:**
`phpstan.neon` analyses `src/` against `stubs/ladybug-ext.stub.php`, which declares those
classes *and* the extension's 21 global `ladybug_*` functions.

What a loaded extension changes, precisely:

- **It hides what the stub is missing.** Remove the `Result` class or the `ladybug_version()`
  function from the stub: without the extension PHPStan reports *"class not found"* /
  *"Function ladybug_version not found"*, with the extension loaded it reports nothing. So a
  stub that has fallen behind the extension passes locally and fails in CI, where no extension
  is loaded.
- **It does not override the stub.** Change a signature the stub already declares — make
  `ladybug_version()` return `int` in the stub — and the result is the same with and without
  the extension: the stub wins. Only missing entries are hidden.

`bin/lint.sh` runs the tools with the normal `php.ini`, and that is the state to keep while
the stub drift is the only thing that can go wrong: #139 (open) adds the test that keeps the
stub and the extension in agreement, and once it lands the lint gives the same answer
whatever is loaded.

The day the lint has to run without the extension — which is when a build of `ext/` registers
a class under `Ladybug\` instead of `Ladybug\Ext\`, since then the loaded class shadows the
library's own and no stub can express that — the recipe is environment variables, because
PHPStan restarts itself and spawns workers without inheriting `-n`:

- `PHP_INI_SCAN_DIR` pointed at a copy of the scan directory that leaves out
  `99-ladybug.ini`, for an extension enabled there. Everything else in the directory stays
  available, and unlike `-n` it reaches PHPStan's own processes.
- `PHPRC` for an extension enabled in `php.ini`. It replaces the main `php.ini`, so carry
  over what that ini held: `extension_dir` (scan-directory entries name their extension
  without a path), `memory_limit`, and FFI if the analysis would otherwise have none.
- **Not `php -n`.** It drops the whole scan directory along with the ladybug ini, which on
  the CI image leaves PHPStan and Rector without `Phar` and `PhpToken`: both fail to *start*
  (`Class "Phar" not found`, `Class "PhpToken" not found`), which is what happened in
  php-zvec #259.

When a lint run disagrees with CI, `php -m` is the first thing to check — with the same ini
settings the lint used, or it answers a different question.

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
