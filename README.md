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

One caveat, stated up front rather than discovered on an upgrade: **calling `Connector` is
covered by this library's version guarantee, implementing it is not.** It carries one method per
liblbug C call, and liblbug is itself pre-1.0, so methods will be added as the C API grows — in
minor releases. Your own connector may stop satisfying the interface then; pin an exact version
if that matters. The alternative was freezing a pre-1.0 database's C surface at our 1.0. The
same applies to `Handle`, which is public only because those signatures need a type.

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

On Linux this is also the linkage to **distribute**: a dynamically linked extension is subject
to the `INSTALL` crash described under [LadybugDB extensions](#ladybugdb-extensions), and the
static build is not.

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

### Bulk loading

`copyInto()` spools the rows to a temporary CSV and hands them to liblbug's own `COPY FROM`,
which loads the whole batch in one go instead of planning a query per row:

```php
$connection->copyInto('Person', [
    ['name' => 'Ada', 'age' => 36],
    ['name' => 'Alan', 'age' => 41],
]);                                        // → 2

$connection->copyInto('Person', $rows, columns: ['name', 'age']);   // positional rows
$connection->copyInto('Knows', [['Ada', 'Alan', 2001]]);            // REL: from, to, props
```

Rows may be associative (the first row's keys name the columns) or lists (the table's own
order; for a REL table, the FROM and TO primary keys first). An `iterable` is enough — a
generator never has to be materialised.

Two limits come from liblbug's CSV reader rather than from choice:

- **An empty string is refused.** liblbug reads an empty field as `NULL` and offers no
  sentinel to separate the two, so copying `''` would silently store `NULL`. `copyInto()`
  throws instead; pass `null` if that is what you mean, or insert that row with `CREATE`.
- **Only scalars, `null` and `DateTimeInterface`.** Lists, structs and maps have no CSV
  spelling; use `CREATE` with parameters for those.

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
- **`INSTALL` segfaults when PHP loads liblbug with `RTLD_DEEPBIND`** and a system libstdc++
  is already in the process. A segfault, not an exception, so there is nothing to catch.

  liblbug 0.19.1's prebuilt Linux `.so` statically links libstdc++ and exports its symbols —
  130 of them with `STB_GNU_UNIQUE` binding, locale facet ids such as
  `std::moneypunct<char>::id` and their init guards:

  ```bash
  nm -D --defined-only lib/liblbug.so | grep -c '^[0-9a-f]* u '     # 130
  ```

  glibc binds `STB_GNU_UNIQUE` symbols process-wide and **ignores `RTLD_DEEPBIND` for them**,
  while ordinary globals honour it. Zend's `DL_LOAD` uses
  `RTLD_LAZY|RTLD_GLOBAL|RTLD_DEEPBIND` for every extension and for `ext/ffi`'s `dlopen`, so
  liblbug's locale registry ends up split across two C++ runtimes — and the first statement
  that compiles a `std::regex`, which `INSTALL` does when building its HTTP client, dies inside
  `std::codecvt`. Any extension linking libstdc++ supplies the other half; `intl` alone does.

  Both ingredients are necessary and neither is sufficient.
  [`tools/repro-install-crash-dlopen.c`](tools/repro-install-crash-dlopen.c) shows it in ~60
  lines of C with no PHP at all:

  | `deepbind` | libstdc++ loaded first | exit |
  |---|---|---|
  | 1 | 1 | **139** |
  | 0 | 1 | 0 |
  | 1 | 0 | 0 |

  `make docker-repro-install` shows the same through PHP, in three rows: no `intl` exits 0,
  `intl` with the connector's fix disabled exits 139, and `intl` with the fix active exits 0
  again. Reproduced on arm64 and, under emulation, on x86_64 — the numbers are identical, so
  this is not an architecture quirk.

  **The FFI connector handles this itself.** It loads liblbug through `dlopen` before
  `ext/ffi` can, with plain `RTLD_LAZY`; an already-loaded object keeps its original binding, so
  DEEPBIND never applies. Only on Linux, only when a libstdc++ is already mapped, and
  `LADYBUG_NO_PRELOAD=1` opts out.

  **The native extension needs `--enable-ladybug-static`.** There liblbug is a link-time
  dependency, so PHP's flags apply at startup, before any of our code runs — nothing the
  library can do from inside. The static build sidesteps it because `liblbug.a` carries no
  libstdc++ of its own: our `.so` links the system one dynamically, leaving a single runtime.
  Verified in a container with `intl`: shared exits 139, static exits 0.

  Note the consequence: **loading a dynamically linked `ladybug.so` makes the FFI connector
  crash too**, because liblbug is then already bound before FFI's fix can run. Don't mix them.

  Failing both, `LD_PRELOAD=/path/to/liblbug.so php …` works for either connector.
  `maxThreads: 1` does *not* help; the dozen worker threads at the crash site are incidental.

  The real fix belongs upstream, and it is narrower than "export less". `-Wl,--exclude-libs,ALL`
  does stop the leak, and it also breaks `LOAD json`: LadybugDB's own extensions are downloaded
  shared objects that resolve 91 `lbug::` C++ symbols from whoever loaded liblbug, so hiding
  everything leaves them with undefined symbols. The line has to run between owners rather than
  languages — keep `lbug_*` and anything mangled with a `lbug` type visible, hide the rest, and
  compile with `-fno-gnu-unique`. [`ext/ladybug.map`](ext/ladybug.map) is that version script,
  applied to our own static build, measured at 281 → 35 `std::` exports and 23 → 6
  `STB_GNU_UNIQUE` with all three official extensions still loading. The macOS dylib exports
  none of these symbols, so this is a Linux packaging difference rather than a design choice.

  The tests detect the hazardous combination and skip; `LADYBUG_TEST_EXTENSIONS=1` overrides.

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

### Which liblbug works with which release

| ladybug-php | liblbug | notes |
|---|---|---|
| 0.5.x | 0.19.x | verified against 0.19.1 |
| 0.4.x | 0.19.x | verified against 0.19.1 |
| ≤ 0.3.x | 0.19.x | on Linux use the static link; see [LadybugDB extensions](#ladybugdb-extensions) |

A liblbug **patch** release inside a supported series is accepted without a new ladybug-php
release. A **minor** release is not: it needs `lib/` regenerated, `CdefMatchesHeaderTest` re-run
and the constants bumped, which is a ladybug-php release of its own. So `0.20` will be refused
by the version check until we ship support for it — deliberately, because the alternative is a
struct read at the wrong offsets.

This table is the whole compatibility story: liblbug is pre-1.0, we track one series at a time,
and neither connector guesses.

### Platforms

| | ext-ladybug | FFI |
|---|---|---|
| Linux x86_64 | CI, prebuilt binary | CI |
| Linux aarch64 | prebuilt binary | — |
| macOS arm64 | CI, prebuilt binary | CI |
| macOS x86_64 | should work, never run | should work, never run |
| Windows | **not supported** | **not supported** |

Windows is not a "not yet" — nothing has ever been run there. The FFI connector does look for
`lbug_shared.dll`, so it may well work, but no test has ever executed on Windows and the PIE
package excludes the platform outright (`os-families-exclude`). Reports are welcome; a passing
CI job would be more welcome.

## Status

Both connectors are complete and pass the same 144 integration tests: the FFI connector, the
native extension, the factory, the ergonomic layer, the type mapping above, transactions,
prepared statements and multi-statement results.

CI covers **PHP 8.2, 8.3, 8.4 and 8.5 on Linux x86_64 and macOS arm64**, running the
integration suite once per connector on each — plus a job that builds against the static
archive and asserts the resulting `.so` carries no liblbug dependency. Against liblbug 0.19.1.

Releases carry a built extension for each of PHP 8.2–8.5 on `linux-x86_64`, `linux-aarch64` and
`macos-arm64`. Every binary is verified before it is attached: the export set is checked against
[`ext/ladybug.map`](ext/ladybug.map), both suites run with `intl` loaded — the condition that
crashes a dynamically linked liblbug — and a clean-room step queries through the extension with
liblbug moved off the machine entirely.

Not done yet:

- Arrow and bulk-copy ingestion (`lbug_connection_create_arrow_table` and friends)
- user-defined functions (`create_function` in the Python client) and `AsyncConnection`
- Windows — see [Platforms](#platforms) for what that means exactly

## What counts as public API

From 1.0.0 this package follows SemVer, so it is worth being precise about what the promise
covers. The authority is not this list but
[`tests/Unit/ApiSurfaceTest`](tests/Unit/ApiSurfaceTest.php): every class under `src/` has to be
classified there as public or internal, and a new one fails the suite until someone decides
which it is. A class nobody classified is a class that became public by accident.

Public: `Database`, `Connection`, `PreparedStatement`, `QueryResult`, `Config`, everything in
`Ladybug\Type\` and `Ladybug\Exception\`, plus `ConnectorFactory`, `LibraryVersion`,
`LibraryLocator`, `ExtConnector`, `FfiConnector` and the `Connector` / `Handle` interfaces.

Internal, marked `@internal` and free to change in any release: `Cdef`, `ValueReader`,
`ExtHandle`, `FfiHandle`, `CsvSpool`.

Four details that are easy to get wrong:

- **Implementing `Connector` or `Handle` is not covered**, though calling them is. The reasoning
  is under [Layers](#layers).
- **Exception classes are not `final`**, on purpose, so an application can narrow one further.
  Everything else in `src/` is final and the test above enforces it.
- **`QueryException::$parameters` holds the values that were bound**, which in production is real
  data. `__toString()` prints the Cypher but never the parameters, so an uncaught exception or a
  log line does not leak them — but `var_dump()` and anything that serialises the object will.
- **`DataType`'s backing integers come from `lbug_data_type_id`.** They are an ABI, not ours to
  renumber; compare cases, not numbers.

The `ladybug_*` functions the extension registers are not API either. They are the ABI between
the extension and `ExtConnector`, versioned by `ExtConnector::ABI_VERSION`; write against the
PHP classes.

## Installing

The PHP side is one command:

```bash
composer require crazy-goat/ladybug-php
```

That gives you the API and no way to talk to a database yet — both connectors need LadybugDB
itself. There are three ways to supply it, and the library uses whichever it finds.

### 1. FFI — nothing to compile

Fetch the shared library and point the package at it:

```bash
bash vendor/crazy-goat/ladybug-php/tools/fetch-liblbug.sh 0.19.1
```

That unpacks into the package's own `lib/`, which is where the connector looks first (see
[Installing liblbug (FFI)](#installing-liblbug-ffi) for the manual route);
`LADYBUG_LIBRARY=/path/to/liblbug.so` overrides it. Needs `ext-ffi` enabled, and `ffi.enable`
must allow it (`preload` counts as off for CLI scripts). Slower than the extension — see the
[benchmark](#choosing-a-backend) — because every value crosses the FFI boundary.

### 2. Prebuilt extension binary — no toolchain, full speed

Every [release](https://github.com/crazy-goat/ladybug-php/releases) carries `ladybug.so` for
PHP 8.2–8.5 on `linux-x86_64`, `linux-aarch64` and `macos-arm64`:

```bash
V=0.4.0; PHP=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
curl -sLO "https://github.com/crazy-goat/ladybug-php/releases/download/v$V/ladybug-$V-php$PHP-linux-x86_64.tar.gz"
tar xzf "ladybug-$V-php$PHP-linux-x86_64.tar.gz"
cp "ladybug-$V-php$PHP-linux-x86_64/ladybug.so" "$(php-config --extension-dir)/"
echo 'extension=ladybug.so' > "$(php-config --ini-dir)/99-ladybug.ini"
```

The PHP version has to match exactly — module ABIs differ between minors and PHP refuses the
wrong one. `SHA256SUMS` is attached to the same release. liblbug is linked into these binaries,
so nothing else is needed on the machine; they are ~20 MB for that reason, and because [only the
static linkage avoids the `INSTALL` crash](#ladybugdb-extensions). Debug and ZTS builds are not
covered — those need option 3.

### 3. From source

```bash
git clone https://github.com/crazy-goat/ladybug-php && cd ladybug-php
make liblbug && make ext                  # dynamic, ~90 KB
bash tools/fetch-liblbug.sh 0.19.1 --static && make ext-static   # or self-contained
```

See [Building the native extension](#building-the-native-extension) for the configure options.
On Linux prefer `ext-static`: a dynamically linked extension crashes on `INSTALL` whenever
another extension carrying libstdc++ is loaded.

### Which one

|  | needs | speed | `INSTALL` safe on Linux |
|---|---|---|---|
| FFI | `ext-ffi` + liblbug on disk | baseline | yes, handled by the connector |
| prebuilt binary | matching PHP version | fastest | yes, linked statically |
| PIE | toolchain + liblbug on disk | fastest | with `--enable-ladybug-static` |
| built from source | phpize, C toolchain, 80 MB archive | fastest | with `--enable-ladybug-static` |

`ConnectorFactory` picks the extension when it is loaded and falls back to FFI otherwise, so
adding the extension to an existing install changes nothing but the throughput. Do not force
`connector: 'ffi'` in a process that has the extension loaded — that puts two copies of liblbug
in one address space.

### PIE

The extension is also published as its own PIE package, because PIE requires an extension's
Composer name to differ from any regular package's and reads only a repository root — so
[crazy-goat/ladybug-ext](https://github.com/crazy-goat/ladybug-ext) is generated from `ext/` on
every release:

```bash
bash tools/fetch-liblbug.sh 0.19.1 --static
pie install crazy-goat/ladybug-ext --enable-ladybug-static --with-liblbug="$PWD/lib"
```

It still compiles, so it needs the toolchain and liblbug on disk like option 3 — what it saves is
knowing phpize, configure and where the INI goes. `make mirror-ext` builds that package here.

## License

MIT, matching LadybugDB.
