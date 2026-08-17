<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit;

use Ladybug\Config;
use Ladybug\Exception\InvalidArgumentException;
use Ladybug\Type\DataType;
use Ladybug\Type\InternalId;
use Ladybug\Type\Node;
use Ladybug\Type\Rel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DataType::class)]
#[CoversClass(InternalId::class)]
#[CoversClass(Node::class)]
#[CoversClass(Rel::class)]
#[CoversClass(Config::class)]
final class TypeTest extends TestCase
{
    public function testDataTypeValuesMatchTheCEnum(): void
    {
        // A wrong number here silently mislabels every column of that type.
        self::assertSame(0, DataType::Any->value);
        self::assertSame(10, DataType::Node->value);
        self::assertSame(23, DataType::Int64->value);
        self::assertSame(50, DataType::String->value);
        self::assertSame(59, DataType::Uuid->value);
    }

    public function testEveryDataTypeHasACypherName(): void
    {
        foreach (DataType::cases() as $case) {
            self::assertNotSame('', $case->cypherName());
        }
    }

    public function testNumericTypesAreRecognised(): void
    {
        self::assertTrue(DataType::Int64->isNumeric());
        self::assertTrue(DataType::Double->isNumeric());
        self::assertFalse(DataType::String->isNumeric());
        self::assertFalse(DataType::Node->isNumeric());
    }

    public function testInternalIdComparesByValue(): void
    {
        self::assertTrue((new InternalId(1, 2))->equals(new InternalId(1, 2)));
        self::assertFalse((new InternalId(1, 2))->equals(new InternalId(1, 3)));
        self::assertSame('1:2', (string) new InternalId(1, 2));
    }

    public function testNodePropertiesAreReachableThreeWays(): void
    {
        $node = new Node(new InternalId(0, 1), 'Person', ['name' => 'Ada', 'age' => 36]);

        self::assertSame('Ada', $node->properties['name']);
        self::assertSame('Ada', $node->__get('name'));
        self::assertSame('Ada', $node['name']);
        self::assertSame(36, $node->get('age'));
        self::assertNull($node->get('missing'));
        self::assertTrue($node->__isset('name'));
        self::assertFalse($node->__isset('missing'));
    }

    public function testNodeNamesTheAvailablePropertiesWhenOneIsMissing(): void
    {
        $node = new Node(new InternalId(0, 1), 'Person', ['name' => 'Ada']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has no property "nope".*Available: name/s');

        $node->__get('nope');
    }

    public function testNodeIsImmutable(): void
    {
        $node = new Node(new InternalId(0, 1), 'Person', ['name' => 'Ada']);

        $this->expectException(InvalidArgumentException::class);
        $node['name'] = 'changed';
    }

    public function testNodeIsIterableAndJsonSerialisable(): void
    {
        $node = new Node(new InternalId(3, 7), 'Person', ['name' => 'Ada']);

        self::assertSame(['name' => 'Ada'], iterator_to_array($node));
        self::assertSame(
            '{"_id":{"tableId":3,"offset":7},"_label":"Person","name":"Ada"}',
            json_encode($node, JSON_THROW_ON_ERROR),
        );
    }

    public function testRelCarriesItsEndpoints(): void
    {
        $rel = new Rel(new InternalId(9, 0), 'Knows', new InternalId(1, 1), new InternalId(1, 2), ['since' => 2019]);

        self::assertSame('Knows', $rel->label);
        self::assertSame(2019, $rel->__get('since'));
        self::assertSame('1:1', (string) $rel->src);
        self::assertSame('1:2', (string) $rel->dst);
    }

    public function testRelExposesPropertiesTheSameWaysNodeDoes(): void
    {
        // Rel and Node carry the same accessor surface, and a caller who learned it on one
        // will use it on the other. Duplicated behaviour needs duplicated tests, or the copy
        // that nobody exercises is the one that breaks.
        $rel = new Rel(new InternalId(9, 0), 'Knows', new InternalId(1, 1), new InternalId(1, 2), ['since' => 2019]);

        self::assertSame(2019, $rel['since']);
        self::assertSame(2019, $rel->get('since'));
        self::assertNull($rel->get('missing'));
        self::assertTrue($rel->__isset('since'));
        self::assertFalse($rel->__isset('missing'));
        self::assertTrue(isset($rel['since']));
        self::assertFalse(isset($rel['missing']));
    }

    public function testRelNamesTheAvailablePropertiesWhenOneIsMissing(): void
    {
        $rel = new Rel(new InternalId(9, 0), 'Knows', new InternalId(1, 1), new InternalId(1, 2), ['since' => 2019]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Rel \(Knows\) has no property "nope".*Available: since/s');

        $rel->__get('nope');
    }

    public function testRelIsImmutable(): void
    {
        $rel = new Rel(null, 'Knows', new InternalId(1, 1), new InternalId(1, 2));

        $this->expectException(InvalidArgumentException::class);
        $rel['since'] = 2020;
    }

    public function testRelRefusesToUnsetAProperty(): void
    {
        $rel = new Rel(null, 'Knows', new InternalId(1, 1), new InternalId(1, 2), ['since' => 2019]);

        $this->expectException(InvalidArgumentException::class);
        unset($rel['since']);
    }

    public function testRelIsIterableAndJsonSerialisable(): void
    {
        $rel = new Rel(new InternalId(9, 0), 'Knows', new InternalId(1, 1), new InternalId(1, 2), ['since' => 2019]);

        self::assertSame(['since' => 2019], iterator_to_array($rel));
        self::assertSame(
            '{"_id":{"tableId":9,"offset":0},"_label":"Knows","_src":{"tableId":1,"offset":1},'
            . '"_dst":{"tableId":1,"offset":2},"since":2019}',
            json_encode($rel, JSON_THROW_ON_ERROR),
        );
    }

    public function testConfigDefaultsToLeavingLiblbugAlone(): void
    {
        $config = new Config();

        self::assertNull($config->bufferPoolSize);
        self::assertNull($config->maxThreads);
        self::assertNull($config->readOnly);
        self::assertNull($config->connector);
    }

    public function testReadOnlyHelper(): void
    {
        self::assertTrue(Config::readOnly()->readOnly);
    }

    public function testWithReturnsAModifiedCopy(): void
    {
        $config = new Config(maxThreads: 4);
        $derived = $config->with(connector: 'ffi');

        self::assertSame(4, $derived->maxThreads, 'untouched fields are preserved');
        self::assertSame('ffi', $derived->connector);
        self::assertNull($config->connector, 'the original is unchanged');
    }
}
