# ladybug-php

[![CI](https://github.com/crazy-goat/ladybug-php/actions/workflows/ci.yml/badge.svg)](https://github.com/crazy-goat/ladybug-php/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-8.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-777bb4)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

PHP client for [LadybugDB](https://github.com/LadybugDB/ladybug) — an embedded graph
database (formerly Kuzu) with Cypher, vector indices and columnar storage.

Two backends, one API. The library talks to `liblbug` either through a **native PHP
extension** or through **FFI**, and picks whichever is available at runtime.

```php
use Ladybug\Database;

$database = new Database('/var/data/graph.lbdb');
$connection = $database->connect();

$connection->run('CREATE NODE TABLE Person(name STRING, age INT64, PRIMARY KEY(name))');
$connection->run('CREATE (:Person {name: $name, age: $age})', ['name' => 'Ada', 'age' => 36]);

foreach ($connection->query('MATCH (p:Person) WHERE p.age > $min RETURN p.name, p.age', ['min' => 30]) as $row) {
    echo $row['p.name'], ' ', $row['p.age'], PHP_EOL;
}
```

Requires PHP 8.2+.

## Layers

The package is deliberately split into three, so the ergonomic API and the backends can
evolve independently:

```
Ladybug\Database, Connection, QueryResult      ergonomics — the API you write against
Ladybug\Connector\ConnectorFactory             selection — which backend, and why
Ladybug\Connector\Connector (interface)        1:1 with the liblbug C API, handle-based
   ├── Connector\Ext\ExtConnector              native extension (ext-ladybug)
   └── Connector\Ffi\FfiConnector              FFI over liblbug.so / .dylib / .dll
```

The low-level interface is one PHP method per meaningful C call, passing opaque `Handle`
objects. Two rules keep the backends honest:

- **Value conversion happens inside the connector.** The extension does it in C, the FFI
  connector in PHP. This is the hottest path in the library, and the only place the
  backends are allowed to differ in performance.
- **Errors are exceptions, never return codes.** Every `lbug_state != LbugSuccess` becomes
  a `Ladybug\Exception\*` throw, so the upper layers contain no error handling of their own.

Because `Connector` is a plain interface, an application can register its own backend — an
in-memory fake for tests, or a future remote connector — and the same selection rules apply:

```php
ConnectorFactory::register('fake', MyFakeConnector::class);
```

## Choosing a backend

| | ext-ladybug | FFI |
|---|---|---|
| Setup | `make ext` | drop in `liblbug` |
| Row fetching | conversion in C | conversion in PHP, 3-6x slower |
| Requirements | matching PHP ABI | `ext-ffi`, `ffi.enable` |
| Priority | 100 | 10 |

`make bench` runs both backends in one process, so the numbers are comparable. On an M4 Pro,
liblbug 0.19.1, PHP 8.5:

| scenario | unit | ext | ffi | ratio |
|---|---|---|---|---|
| fetch scalars | rows/s | 1,056,357 | 164,399 | 6.4x |
| fetch nodes | nodes/s | 411,958 | 73,117 | 5.6x |
| fetch temporal | rows/s | 513,081 | 169,262 | 3.0x |
| insert prepared | inserts/s | 10,556 | 10,753 | 1.0x |
| tiny queries | queries/s | 7,469 | 7,551 | 1.0x |

Which says something more useful than "the extension is faster": the gap is entirely in value
conversion. Writes and per-query overhead are bound by liblbug itself, so a write-heavy
workload gains nothing from compiling the extension — and `DateTimeImmutable` construction
narrows the gap to 3x even on a read path, because there PHP is doing real work rather than
just copying scalars.

Selection order:

1. `new Config(connector: 'ext')` — explicit, fails loudly if unusable
2. `LADYBUG_CONNECTOR=ffi` — deployment-time override, no code change
3. highest-priority available backend

When nothing works, the exception lists every backend and the reason it was skipped —
including every path searched for `liblbug`, which is the usual FFI complaint:

```
No LadybugDB connector is available.
  ext  [skip] — ext-ladybug is not loaded
  ffi  [skip] — liblbug not found; tried: …/lib/liblbug.dylib, /opt/homebrew/lib/liblbug.dylib, …
```

`ConnectorFactory::diagnostics()` returns the same information as an array, which is worth
wiring into a health check.

## Building the native extension

```bash
make liblbug        # fetch the shared library for this platform into lib/
make ext            # phpize && configure && make  ->  ext/modules/ladybug.so
make ext-test       # the extension's own .phpt suite
```

Then load it (`php.ini`, or `-d extension=...` as the Makefile targets do):

```ini
extension=/path/to/ladybug-php/ext/modules/ladybug.so
```

`liblbug` is linked **dynamically** by default, with the library directory baked in as an
rpath so the extension resolves it without `LD_LIBRARY_PATH` — the trap that makes symlinked
Homebrew paths fail elsewhere. The `.so` is ~90 KB.

For a self-contained extension, link the static archive instead:

```bash
bash tools/fetch-liblbug.sh 0.19.1 --static
make ext-static
```

That produces a ~20 MB `.so` with no liblbug dependency at all (`otool -L` / `ldd` shows
only the C++ runtime and libc), which is the right trade for containers and for hosts where
you would rather not ship a second shared object. liblbug is C++ behind a C API, so the
static link pulls in `libc++`/`libstdc++` explicitly.

`./configure` options: `--enable-ladybug` (required), `--with-liblbug=DIR` (where `lbug.h`
and the library live; defaults to searching `../lib`, `/usr/local`, `/opt/homebrew`) and
`--enable-ladybug-static`.

## Installing liblbug (FFI)

Download the shared library for your platform from the
[releases page](https://github.com/LadybugDB/ladybug/releases) and unpack it into `lib/`
next to this package, or point `LADYBUG_LIBRARY` at it:

```bash
curl -sL -o liblbug.tar.gz https://github.com/LadybugDB/ladybug/releases/download/v0.19.1/liblbug-osx-arm64.tar.gz
mkdir -p lib && tar xzf liblbug.tar.gz -C lib
```

Search order: `Config::$libraryPath` → `LADYBUG_LIBRARY` → `lib/` in this package →
platform library directories → bare soname (left to the dynamic loader).

## API

### Database

```php
$database = new Database('/path/graph.lbdb', new Config(
    bufferPoolSize: 512 * 1024 * 1024,
    maxThreads: 4,
    readOnly: false,
));

$database = Database::inMemory();          // discarded when the process ends
$database = new Database($path, Config::readOnly());

$database->connectorId();                  // 'ext' | 'ffi'
$database->libraryVersion();               // '0.19.1'
$database->close();                        // optional; the destructor also releases
```

Every `Config` field defaults to `null`, meaning "leave liblbug's own default alone" —
only what you set is overwritten.

### Connection

```php
$connection->query($cypher, $parameters);  // → QueryResult
$connection->run($cypher, $parameters);    // → int rows, result freed immediately
$connection->queryMultiple('…; …; …');     // → list<QueryResult>
$connection->prepare($cypher);             // → PreparedStatement (cached, LRU of 64)
$connection->transaction(fn () => …);      // commit on return, rollback on throw
$connection->setMaxThreads(4)->setQueryTimeout(30_000);
$connection->interrupt();
```

`query()` without parameters uses liblbug's direct path; with parameters it prepares and
caches the statement, so a loop over bound values re-plans nothing.

### QueryResult

Results stream — iterating pulls one row at a time, so a million-row query costs one row
of memory. `fetchAll()` is the explicit opt-in to materialising everything.

```php
foreach ($result as $row) { … }            // array<string, mixed> per row
$result->fetchRow();                       // next row keyed by column name, or null
$result->fetchNumeric();                   // next row positionally
$result->fetchOne();                       // first column of the next row
$result->fetchAll();                       // every remaining row
$result->fetchColumn('p.name');            // one column, flattened
$result->fetchAllKeyedBy('p.name');        // lookup table
$result->columnNames();                    // list<string>
$result->columnTypes();                    // list<DataType>
count($result);                            // rows produced
$result->summary();                        // ['compilingTimeMs' => …, 'executionTimeMs' => …]
$result->reset();                          // rewind and read again
```

Duplicate column names (`RETURN p.name, p.name`) would collide as array keys, so repeats
get a `#2`, `#3` suffix. Use `fetchNumeric()` when positions matter.

## Type mapping

| LadybugDB | PHP |
|---|---|
| `BOOL` | `bool` |
| `INT8/16/32/64`, `SERIAL`, `UINT8/16/32` | `int` |
| `UINT64` above `PHP_INT_MAX` | numeric `string` |
| `INT128` | numeric `string` (needs `ext-bcmath` beyond 64 bits) |
| `FLOAT`, `DOUBLE` | `float` |
| `DECIMAL` | numeric `string` — never a float, so scale survives |
| `STRING`, `UUID` | `string` |
| `BLOB` | binary `string` |
| `DATE`, `TIMESTAMP`, `TIMESTAMP_SEC/MS/NS/TZ` | `DateTimeImmutable` in UTC |
| `INTERVAL` | `DateInterval` |
| `INTERNAL_ID` | `Ladybug\Type\InternalId` |
| `LIST` | `list<mixed>` |
| `STRUCT` | `array<string, mixed>` |
| `ARRAY`, `UNION` | `string` (see below) |
| `MAP` | associative array, or a list of `{key, value}` pairs when keys are not usable as PHP keys |
| `NODE` | `Ladybug\Type\Node` |
| `REL` | `Ladybug\Type\Rel` |
| `RECURSIVE_REL` | `Ladybug\Type\Path` |
| `JSON` (json extension) | `string` — the JSON text |
| `NULL` | `null` |

`TIMESTAMP_NS` is truncated to microseconds, PHP's finest resolution — read it as
`CAST(col AS STRING)` when the extra digits matter.

**`ARRAY` and `UNION` arrive as text.** liblbug 0.19.1's C accessors reject them —
`lbug_value_get_list_size()` fails outright on a fixed-size array — so both connectors fall
back to liblbug's own rendering rather than throwing. The value is intact, just not
decomposed:

```php
$connection->query('RETURN cast([1, 2, 3] AS INT64[3]) AS a')->fetchOne();   // '[1,2,3]'
$connection->query('RETURN cast(cast([1, 2, 3] AS INT64[3]) AS INT64[]) AS l')->fetchOne();
                                                                             // [1, 2, 3]
```

Cast to a `LIST` in Cypher when you want structure. This is a liblbug limitation, not a
design choice — it will become a real mapping when the C API can read those types.

A `Path` (from `MATCH p = (a)-[:Knows*1..3]->(b)`) carries the same fully converted `Node` and
`Rel` objects every other query returns:

```php
$path->nodes;        // list<Node>, in traversal order
$path->rels;         // list<Rel>
$path->length();     // hops — one fewer than the node count on a simple path
$path->start();      // ?Node
$path->end();        // ?Node
```

It is deliberately neither iterable nor countable: both would have to pick between nodes and
relationships, and a wrong guess in an API heading for 1.0 is worse than making the call site
say which one it means.

### LadybugDB extensions

`json`, `fts` and `vector` need no API of their own — `INSTALL` and `LOAD` are Cypher, and
their results go through the same conversion as everything else:

```php
$connection->run('INSTALL vector');
$connection->run('LOAD vector');
$connection->run("CALL CREATE_VECTOR_INDEX('Doc', 'emb_idx', 'emb')");

$hits = $connection->query(
    "CALL QUERY_VECTOR_INDEX('Doc', 'emb_idx', cast([1.0, 0.1, 0.0] AS FLOAT[3]), 5) "
    . 'RETURN node.id AS id, distance',
)->fetchAll();
```

Two things worth knowing:

- **Embedding columns are `ARRAY`**, so `RETURN d.emb` gives text
  (`'[1.000000,0.000000,0.000000]'`). Use `cast(d.emb AS FLOAT[])` for a PHP array of floats.
  Search itself is unaffected — the index reads the column, not your process.
- **`INSTALL` downloads to `~/.lbdb`**, so it needs network access on first use.
- **`INSTALL` crashes the process on Linux** with liblbug 0.19.1 — a segfault, not an
  exception, so there is nothing to catch. Reproduced on ubuntu-latest for all three
  extensions and through both connectors, while ordinary queries on the same connection keep
  working; the same code installs cleanly on macOS. Install extensions out of band there (a
  separate process, or a pre-populated `~/.lbdb`) and use `LOAD` only, which fails with a
  normal exception when the extension is missing. The tests for these extensions skip on Linux
  for this reason — `LADYBUG_TEST_EXTENSIONS=1` overrides.

`Node` and `Rel` expose properties three ways, so the call site can read however suits:

```php
$node->properties['name'];   // the array
$node->name;                 // property access
$node['name'];               // array access
$node->get('name', 'default');
```

Two things worth knowing about parameters: bound values are never parsed as Cypher (that
is the point), and a parameter cannot be named after a Cypher keyword — `$when` fails in
the parser before binding happens.

## Development

```bash
composer install
composer ci            # style, static analysis, refactor check, tests
```

| | |
|---|---|
| `composer test` | full suite (231 tests) |
| `composer test:unit` | no database needed |
| `composer test:ffi` | integration suite against FFI |
| `composer test:ext` | the same suite against the native extension |
| `composer test:both` | both backends, back to back |
| `composer stan` | PHPStan level 8 |
| `composer rector` | Rector dry-run |
| `composer cs:fix` | PHP-CS-Fixer |
| `make ext-test` | the extension's own `.phpt` tests |
| `make ext-asan` + `make test-asan` | the integration suite under AddressSanitizer |
| `make bench` | FFI vs the extension, both in one process |

The integration suite is backend-agnostic: `LADYBUG_CONNECTOR` picks which one it exercises,
so both implementations are held to identical assertions. That is the design paying for
itself — `test:both` is what proves the C conversion code and the PHP conversion code agree,
down to `DateTimeImmutable` timezone names and `INT128` string formatting.

### Inside the extension

`ext/ladybug.c` holds the module, the four handle classes and the `ladybug_*` functions;
`ext/ladybug_value.c` holds `lbug_value` -> `zval` conversion, which must stay observably
identical to `ValueReader`. A few decisions worth knowing:

- **Handles are opaque final classes with no properties.** The C resource lives in the object
  struct, and each child handle holds a refcounted zval to its parent (connection -> database,
  result -> connection). liblbug's destructors touch the parent, and a lazily-read result
  must not outlive its connection, so the parent's open flag is checked on every call —
  a use-after-close raises instead of segfaulting.
- **Userland classes are looked up per request.** `Node`, `Rel` and `InternalId` come from
  the Composer autoloader, so their class entries are only valid within one request; they are
  resolved lazily and cached in module globals that `RINIT` clears.
- **Temporal values are built from a formatted string with a `UTC` suffix**, not from a Unix
  timestamp: `@0`-style construction produces a `+00:00` zone whose `getName()` is `+00:00`,
  and the FFI connector reports `UTC`. Backend parity beats the shorter code.
- **INT128 is assembled by repeated division on a `__int128`**, so the extension needs no
  bcmath while the FFI connector does.
- **The extension throws `Ladybug\Ext\{Exception,DatabaseError,QueryError}`** and knows
  nothing about `Ladybug\Exception\*`. `ExtConnector::guard()` does the rewrapping. That is
  what lets the C code version independently of this package, with `LADYBUG_ABI_VERSION` as
  the contract — `ExtConnector::isAvailable()` returns false on a mismatch rather than
  calling into changed signatures.

### The header guard rail

`FfiConnector` re-declares part of `lbug.h` by hand in `Cdef`. A declaration that disagrees
with the real library does not fail loudly — it corrupts memory. During development exactly
one wrong return type (`lbug_value_create_null`, which returns an owned pointer instead of
filling an out parameter) segfaulted the process.

So `tests/Unit/Ffi/CdefMatchesHeaderTest` parses the shipped `lib/lbug.h` and compares all
92 declarations against `Cdef`, and checks that every `lbug_data_type_id` in the header has
a `DataType` case. Run it after any liblbug upgrade.

That test runs at development time. Its runtime counterpart is the version check below.

## liblbug version check

Both connectors depend on liblbug's exact struct layout — `lbug_system_config` in
particular, whose fields `Cdef` spells out one by one — and liblbug is pre-1.0, so a minor
release may rearrange them. Nothing about that failure is loud: `lbug_database_init()` would
read a config struct that means something else.

So the runtime version is verified before the first call that passes a struct:

| | check | on mismatch |
|---|---|---|
| FFI | `FfiConnector::__construct` | `IncompatibleLibraryException` |
| extension | `MINIT` | module refuses to load |

Supported releases are declared in
[`LibraryVersion`](src/Connector/LibraryVersion.php) and in `ext/php_ladybug.h`;
`tests/Unit/LibraryVersionParityTest` checks that those two, `tools/fetch-liblbug.sh` and
the CI matrix all name the same version. Patch releases within a supported series are
accepted, a new minor has to be verified by hand.

Set `LADYBUG_ALLOW_ANY_LIBRARY=1` to downgrade the failure to a warning. That is for trying
a newer liblbug during development — in production a layout mismatch is memory corruption,
not an error you can catch.

## Status

Both connectors are complete and pass the same 107 integration tests: the FFI connector, the
native extension, the factory, the ergonomic layer, the type mapping above, transactions,
prepared statements and multi-statement results.

CI covers **PHP 8.2, 8.3, 8.4 and 8.5 on Linux x86_64 and macOS arm64**, running the
integration suite once per connector on each — plus a job that builds against the static
archive and asserts the resulting `.so` carries no liblbug dependency. Against liblbug 0.19.1.

Not done yet:

- Arrow and bulk-copy ingestion (`lbug_connection_create_arrow_table` and friends)
- a PIE / PECL package for the extension, so `pie install` works without cloning
- user-defined functions (`create_function` in the Python client) and `AsyncConnection`
- Windows: the FFI connector looks for `lbug_shared.dll` but nothing has been run there

## Installing

Not on Packagist yet, so install from the repository:

```bash
composer config repositories.ladybug vcs https://github.com/crazy-goat/ladybug-php
```

```bash
composer require crazy-goat/ladybug-php:dev-main
```

The FFI connector then needs `liblbug` on the machine (see above); the native extension is
optional and takes over automatically once loaded.

## Licence

MIT, matching LadybugDB.
