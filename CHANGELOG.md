# Changelog

All notable changes to this project are documented here, following
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). This package uses
[Semantic Versioning](https://semver.org/) from 1.0.0 onwards; until then, minor versions
may change the API.

Each release also states the `liblbug` versions it supports, because that is a runtime
requirement the package refuses to run without — see
[`LibraryVersion`](src/Connector/LibraryVersion.php).

## [Unreleased]

Supports liblbug 0.19.x.

### Added

- `DataType::Json`, for the type id the `json` extension introduces. It is 60, which lbug.h
  does not declare — the core header stops at `UUID = 59` — so reading a `JSON` column used to
  throw "liblbug is newer than this client" and `columnTypes()` threw a raw `ValueError`.
- `DataType::Unknown`, reported for any type id this client has no case for. Loaded extensions
  can introduce types at any time, and one unmapped column should not fail the whole query —
  the value still arrives as liblbug's own rendering.
- Integration coverage for the `json`, `fts` and `vector` extensions. `INSTALL` segfaults on
  Linux with liblbug 0.19.1 — verified for all three extensions and both connectors, while the
  same code installs cleanly on macOS — so these tests skip there rather than crashing the
  suite; `LADYBUG_TEST_EXTENSIONS=1` overrides. Documented, because it affects anyone deploying
  vector search on Linux. Coverage includes vector search returning neighbours in distance order, and embedding columns
  (`FLOAT[n]`, an `ARRAY`) arriving as text and casting to a list.
- `RECURSIVE_REL` values are returned as `Ladybug\Type\Path` instead of liblbug's text
  rendering. A path is a STRUCT of two lists and liblbug's struct accessors do read it — the
  members are the same `Node` and `Rel` objects every other query produces. `Path` exposes
  `$nodes`, `$rels`, `length()`, `start()` and `end()`, and is deliberately neither iterable
  nor countable: both would have to pick between nodes and relationships.

## [0.2.1] - 2026-08-17

Supports liblbug 0.19.x. Memory-safety fixes — recommended over 0.2.0, where reading an
`ARRAY` column corrupts the heap.

### Fixed

- **Heap corruption when a value failed to convert.** Three error paths in the extension freed
  `return_value` without resetting it, so the engine freed the array a second time. It
  surfaced as `zend_mm_heap corrupted`, with no indication of where it came from. Reading an
  `ARRAY` column triggered it.
- **`ValueReader` ignored every `lbug_state`.** liblbug leaves the out parameter untouched on
  failure, so an unchecked getter returned a plausible wrong value — a zeroed struct reads as
  an empty list — or, for getters that yield an `lbug_value`, a garbage handle that segfaulted
  when read. All 21 calls are now checked, with the same messages the extension uses. The
  extension had always checked them; this was a straight divergence between the two backends.

### Changed

- `ARRAY` and `UNION` values now arrive as liblbug's own rendering (`'[1,2,3]'`) instead of an
  empty array or a crash. liblbug 0.19.1's list and struct accessors reject both types; the
  values themselves are intact, so falling back to text keeps them reachable. Cast to a `LIST`
  in Cypher for structure.

## [0.2.0] - 2026-08-17

Supports liblbug 0.19.x. No API changes: this release is about knowing the existing code is
correct rather than adding to it.

### Added

- The runtime `liblbug` version is verified before the first call that passes a struct
  across the boundary. Both connectors depend on liblbug's exact struct layout and liblbug
  is pre-1.0, so a minor release may rearrange `lbug_system_config` — silently. The FFI
  connector throws `IncompatibleLibraryException`; the extension returns `FAILURE` from
  `MINIT` and does not load. `LADYBUG_ALLOW_ANY_LIBRARY=1` downgrades both to a warning for
  development.
- `phpinfo()` reports the supported liblbug series, the version the extension was built
  against, and liblbug's storage version.
- `make ext-asan` and `make test-asan` run the integration suite under AddressSanitizer.
- `MemoryTest` catches C resources that are never released by watching resident memory over
  a few hundred iterations of four workloads.
- `make bench` compares the two backends in one process. The gap is entirely in value
  conversion: 6.4x on scalar rows, 3.0x on temporal values, 1.0x on writes.
- Coverage is measured on both backends and merged, gated in CI by
  `tools/coverage-gate.php`.
- `CONTRIBUTING.md`, issue templates, and this changelog.

### Changed

- `FfiConnector::libraryVersion()` returns an empty string when the version cannot be read,
  instead of falling back to the version the package was built against — reporting a
  version that was never read would have defeated the check above.
- The install instructions no longer promise Packagist, which is not set up yet.

### Fixed

- `IntegrationTestCase::tearDown()` no longer touches `$this->database` when `setUp()` never
  created it, which turned a subclass's skip into an error.
- Switching between the plain, static and instrumented extension builds now forces a
  rebuild. Object files do not depend on `configure`'s flags, so `make ext` after
  `make ext-asan` kept the instrumented `.so`.

## [0.1.0] - 2026-08-17

Supports liblbug 0.19.x.

First release. Two backends behind one API, held to the same integration suite.

### Added

- `Ladybug\Connector\Connector`: a low-level, handle-based interface, one method per
  meaningful liblbug C call, with value conversion inside the connector and errors as
  exceptions.
- `Connector\Ffi\FfiConnector`: talks to `liblbug` through PHP's FFI, no compilation
  required. `Cdef` transcribes the header by hand; `CdefMatchesHeaderTest` compares all 92
  declarations against the shipped `lbug.h`, because a wrong declaration corrupts memory
  rather than failing.
- `ext/`: a native extension exposing the same ABI, converting values in C. Shared linkage
  by default, `--enable-ladybug-static` for a self-contained `.so`.
- `ConnectorFactory`: picks the highest-priority usable backend, honours
  `LADYBUG_CONNECTOR` and explicit configuration, and reports why each backend was skipped.
- Ergonomic layer: `Database`, `Connection`, `PreparedStatement`, `QueryResult`, with
  streaming results, prepared-statement caching and transactions.
- Type mapping for the full `lbug_data_type_id` set except `RECURSIVE_REL`, including
  `DateTimeImmutable` in UTC, `DateInterval`, and numeric strings for `DECIMAL`/`INT128`.
- PHP 8.2-8.5 on Linux and macOS in CI, integration suite once per backend, plus a
  static-linkage job.

[Unreleased]: https://github.com/crazy-goat/ladybug-php/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/crazy-goat/ladybug-php/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/crazy-goat/ladybug-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/crazy-goat/ladybug-php/releases/tag/v0.1.0
