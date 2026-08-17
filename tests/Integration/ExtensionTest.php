<?php

declare(strict_types=1);

namespace Ladybug\Tests\Integration;

use Ladybug\Type\DataType;

/**
 * LadybugDB's own extensions — `json`, `fts`, `vector` — reached through plain `query()`.
 *
 * There is no dedicated API for these and there does not need to be: `INSTALL` and `LOAD` are
 * Cypher statements. What does need testing is that their results survive value conversion,
 * because they introduce shapes the core does not: `json` adds a data type id that lbug.h does
 * not declare, and `vector` stores embeddings in fixed-size `ARRAY` columns, which liblbug's
 * own accessors cannot decompose.
 *
 * `INSTALL` downloads from the internet into ~/.lbdb, so these tests skip when that is
 * unavailable rather than failing an offline checkout — and on Linux CI, where it crashes
 * outright. See load() for the details of that.
 */
final class ExtensionTest extends IntegrationTestCase
{
    public function testJsonValuesArriveAsTextAndReportTheJsonType(): void
    {
        $this->load('json');

        $this->connection->run('CREATE NODE TABLE Doc(id INT64, payload JSON, PRIMARY KEY(id))');
        $this->connection->run("CREATE (:Doc {id: 1, payload: to_json({a: 1, b: ['x', 'y']})})");

        $result = $this->connection->query('MATCH (d:Doc) RETURN d.payload');

        // The json extension uses data type id 60, which the core header stops short of (UUID
        // = 59). Before this was mapped, reading the column threw "liblbug is newer than this
        // client" and columnTypes() threw a raw ValueError.
        self::assertSame([DataType::Json], $result->columnTypes());
        self::assertSame('{"a":1,"b":["x","y"]}', $result->fetchOne());
    }

    public function testAnUnmappedTypeDoesNotFailTheQuery(): void
    {
        // Any extension may introduce a type this client has no case for. That must degrade to
        // Unknown plus liblbug's own rendering, not take the whole query down with it — the
        // version check already rules out "liblbug is newer than we think".
        self::assertSame('UNKNOWN', DataType::Unknown->cypherName());
        self::assertNull(DataType::tryFrom(9_999));
    }

    public function testFullTextSearchReturnsScoredMatches(): void
    {
        $this->load('fts');

        $this->connection->run('CREATE NODE TABLE Article(id INT64, body STRING, PRIMARY KEY(id))');
        $this->connection->run("CREATE (:Article {id: 1, body: 'the quick brown fox'})");
        $this->connection->run("CREATE (:Article {id: 2, body: 'lazy dogs sleep'})");
        $this->connection->run("CALL CREATE_FTS_INDEX('Article', 'body_idx', ['body'])");

        $matches = $this->connection
            ->query("CALL QUERY_FTS_INDEX('Article', 'body_idx', 'quick fox') RETURN node.id AS id, score")
            ->fetchAll();

        self::assertCount(1, $matches);
        self::assertSame(1, $matches[0]['id']);
        self::assertIsFloat($matches[0]['score']);
    }

    public function testVectorSearchReturnsNearestNeighboursInOrder(): void
    {
        $this->load('vector');
        $this->createDocumentsWithEmbeddings();

        $this->connection->run("CALL CREATE_VECTOR_INDEX('Doc', 'emb_idx', 'emb')");

        $hits = $this->connection
            ->query(
                "CALL QUERY_VECTOR_INDEX('Doc', 'emb_idx', cast([1.0, 0.1, 0.0] AS FLOAT[3]), 2) "
                . 'RETURN node.id AS id, distance',
            )
            ->fetchAll();

        self::assertCount(2, $hits);
        self::assertSame(1, $hits[0]['id'], 'the nearest embedding must come first');
        self::assertLessThan($hits[1]['distance'], $hits[0]['distance']);
    }

    public function testAnEmbeddingColumnArrivesAsTextAndCastsToAList(): void
    {
        $this->load('vector');
        $this->createDocumentsWithEmbeddings();

        // FLOAT[3] is an ARRAY, and liblbug 0.19.1's list accessors reject those, so the
        // column arrives as liblbug's rendering. This is the limitation anyone storing
        // embeddings meets first, hence a test rather than only a README note.
        self::assertSame(
            '[1.000000,0.000000,0.000000]',
            $this->connection->query('MATCH (d:Doc) WHERE d.id = 1 RETURN d.emb')->fetchOne(),
        );

        // Casting to a variable-size LIST in Cypher is the way to get numbers.
        self::assertSame(
            [1.0, 0.0, 0.0],
            $this->connection->query('MATCH (d:Doc) WHERE d.id = 1 RETURN cast(d.emb AS FLOAT[])')->fetchOne(),
        );
    }

    private function createDocumentsWithEmbeddings(): void
    {
        $this->connection->run('CREATE NODE TABLE Doc(id INT64, emb FLOAT[3], PRIMARY KEY(id))');
        $this->connection->run("CREATE (:Doc {id: 1, emb: [1.0, 0.0, 0.0]})");
        $this->connection->run("CREATE (:Doc {id: 2, emb: [0.0, 1.0, 0.0]})");
    }

    /**
     * Installs and loads an official extension, or skips.
     *
     * `INSTALL` segfaults on GitHub Actions' Linux runners with liblbug 0.19.1 — all three
     * extensions, both connectors, while ordinary queries on the same connection keep working.
     * A segfault cannot be caught, so those runs have to not attempt it.
     *
     * It is specific to that environment rather than to Linux: in a Debian container it
     * installs cleanly on x86_64 and arm64 alike, and every network failure mode tried there
     * (no network, refused proxy, dead DNS) produces a normal exception instead of a crash. The
     * cause is still unidentified, so the guard is by CI rather than by platform, and
     * LADYBUG_TEST_EXTENSIONS=1 overrides it.
     */
    private function load(string $name): void
    {
        if (PHP_OS_FAMILY === 'Linux' && getenv('CI') !== false && getenv('LADYBUG_TEST_EXTENSIONS') === false) {
            self::markTestSkipped(
                "INSTALL crashes the process on Linux CI runners with liblbug 0.19.1, so the {$name} "
                . 'extension cannot be tested there. It works in a Linux container; set '
                . 'LADYBUG_TEST_EXTENSIONS=1 to try anyway.',
            );
        }

        try {
            $this->connection->run("INSTALL {$name}");
            $this->connection->run("LOAD {$name}");
        } catch (\Throwable $e) {
            // Offline, or the extension host is unreachable. Not a failing build.
            self::markTestSkipped("Could not install the {$name} extension: {$e->getMessage()}");
        }
    }
}
