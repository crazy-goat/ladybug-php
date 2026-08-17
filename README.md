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
| Row fetching | conversion in C | conversion in PHP, several times slower |
| Requirements | matching PHP ABI | `ext-ffi`, `ffi.enable` |
| Priority | 100 | 10 |

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
| `LIST`, `ARRAY` | `list<mixed>` |
| `STRUCT`, `UNION` | `array<string, mixed>` |
| `MAP` | associative array, or a list of `{key, value}` pairs when keys are not usable as PHP keys |
| `NODE` | `Ladybug\Type\Node` |
| `REL` | `Ladybug\Type\Rel` |
| `RECURSIVE_REL` | `string` (liblbug's own rendering — no dedicated shape yet) |
| `NULL` | `null` |

`TIMESTAMP_NS` is truncated to microseconds, PHP's finest resolution — read it as
`CAST(col AS STRING)` when the extra digits matter.

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
| `composer test` | full suite (173 tests) |
| `composer test:unit` | no database needed |
| `composer test:ffi` | integration suite against FFI |
| `composer test:ext` | the same suite against the native extension |
| `composer test:both` | both backends, back to back |
| `composer stan` | PHPStan level 8 |
| `composer rector` | Rector dry-run |
| `composer cs:fix` | PHP-CS-Fixer |
| `make ext-test` | the extension's own `.phpt` tests |

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

## Status

Both connectors are complete and pass the same 107 integration tests: the FFI connector, the
native extension, the factory, the ergonomic layer, the type mapping above, transactions,
prepared statements and multi-statement results.

CI covers **PHP 8.2, 8.3, 8.4 and 8.5 on Linux x86_64 and macOS arm64**, running the
integration suite once per connector on each — plus a job that builds against the static
archive and asserts the resulting `.so` carries no liblbug dependency. Against liblbug 0.19.1.

Not done yet:

- `RECURSIVE_REL` as a typed path object — it currently arrives as liblbug's own rendering
  rather than being dropped, in both backends
- Arrow and bulk-copy ingestion (`lbug_connection_create_arrow_table` and friends)
- a PIE / PECL package for the extension, so `pie install` works without cloning
- user-defined functions (`create_function` in the Python client) and `AsyncConnection`
- Windows: the FFI connector looks for `lbug_shared.dll` but nothing has been run there

## Installing

```bash
composer require ladybug/ladybug-php
```

The FFI connector then needs `liblbug` on the machine (see above); the native extension is
optional and takes over automatically once loaded.

## Licence

MIT, matching LadybugDB.
