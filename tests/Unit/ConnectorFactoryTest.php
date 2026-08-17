<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit;

use Ladybug\Config;
use Ladybug\Connector\ConnectorFactory;
use Ladybug\Connector\Ext\ExtConnector;
use Ladybug\Connector\Ffi\FfiConnector;
use Ladybug\Exception\ConnectorException;
use Ladybug\Tests\Fake\FakeConnector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectorFactory::class)]
final class ConnectorFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        ConnectorFactory::reset();
        FakeConnector::reset();
    }

    protected function tearDown(): void
    {
        ConnectorFactory::reset();
        FakeConnector::reset();
        putenv('LADYBUG_CONNECTOR');
    }

    public function testDescribesEveryBuiltInBackend(): void
    {
        $diagnostics = ConnectorFactory::diagnostics();

        self::assertArrayHasKey('ext', $diagnostics);
        self::assertArrayHasKey('ffi', $diagnostics);
        self::assertSame(ExtConnector::priority(), $diagnostics['ext']['priority']);
        self::assertSame(FfiConnector::priority(), $diagnostics['ffi']['priority']);
        foreach ($diagnostics as $info) {
            self::assertNotSame('', $info['detail'], 'every backend explains its state');
        }
    }

    public function testRanksTheNativeExtensionAboveFfi(): void
    {
        self::assertGreaterThan(
            FfiConnector::priority(),
            ExtConnector::priority(),
            'the native extension must win when both are available',
        );
    }

    public function testPicksTheHighestPriorityAvailableBackend(): void
    {
        FakeConnector::$priorityValue = 9000;
        ConnectorFactory::register('fake', FakeConnector::class);

        self::assertSame('fake', ConnectorFactory::availableIds()[0]);
        self::assertSame('fake', ConnectorFactory::create()->id());
    }

    public function testSkipsUnavailableBackends(): void
    {
        FakeConnector::$available = false;
        ConnectorFactory::register('fake', FakeConnector::class);

        self::assertNotContains('fake', ConnectorFactory::availableIds());
    }

    public function testHonoursAnExplicitChoiceFromConfig(): void
    {
        FakeConnector::$priorityValue = 1; // lowest, so only an explicit request selects it
        ConnectorFactory::register('fake', FakeConnector::class);

        self::assertSame('fake', ConnectorFactory::create(new Config(connector: 'fake'))->id());
    }

    public function testHonoursTheEnvironmentOverride(): void
    {
        FakeConnector::$priorityValue = 1;
        ConnectorFactory::register('fake', FakeConnector::class);
        putenv('LADYBUG_CONNECTOR=fake');

        self::assertSame('fake', ConnectorFactory::create()->id());
    }

    public function testConfigBeatsTheEnvironmentOverride(): void
    {
        ConnectorFactory::register('fake', FakeConnector::class);
        putenv('LADYBUG_CONNECTOR=ffi');

        self::assertSame('fake', ConnectorFactory::create(new Config(connector: 'fake'))->id());
    }

    public function testRejectsAnUnknownConnectorName(): void
    {
        $this->expectException(ConnectorException::class);
        $this->expectExceptionMessageMatches('/Unknown connector "redis".*Registered: ext, ffi/s');

        ConnectorFactory::create(new Config(connector: 'redis'));
    }

    public function testExplainsWhyAnExplicitlyRequestedBackendIsUnusable(): void
    {
        FakeConnector::$available = false;
        ConnectorFactory::register('fake', FakeConnector::class);

        $this->expectException(ConnectorException::class);
        $this->expectExceptionMessageMatches('/requested explicitly but is not usable/');

        ConnectorFactory::create(new Config(connector: 'fake'));
    }

    public function testRejectsARegistrationThatIsNotAConnector(): void
    {
        $this->expectException(ConnectorException::class);
        $this->expectExceptionMessageMatches('/does not implement/');

        /** @phpstan-ignore argument.type (deliberately wrong, that is the point) */
        ConnectorFactory::register('bogus', \stdClass::class);
    }

    public function testReusesOneInstancePerBackend(): void
    {
        ConnectorFactory::register('fake', FakeConnector::class);

        $first = ConnectorFactory::create(new Config(connector: 'fake'));
        $second = ConnectorFactory::create(new Config(connector: 'fake'));

        self::assertSame($first, $second, 'liblbug should be dlopened once per process');
        self::assertSame(1, FakeConnector::$constructed);
    }

    public function testResetForgetsCustomRegistrations(): void
    {
        ConnectorFactory::register('fake', FakeConnector::class);
        ConnectorFactory::reset();

        $this->expectException(ConnectorException::class);
        ConnectorFactory::create(new Config(connector: 'fake'));
    }
}
