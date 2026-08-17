<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit;

use Ladybug\Config;
use Ladybug\Exception\InvalidArgumentException;
use Ladybug\Exception\LadybugException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    public function testWithReplacesOnlyTheNamedFields(): void
    {
        $config = new Config(bufferPoolSize: 1024, connector: 'ffi');

        $narrowed = $config->with(readOnly: true);

        self::assertTrue($narrowed->readOnly);
        self::assertSame(1024, $narrowed->bufferPoolSize);
        self::assertSame('ffi', $narrowed->connector);
        // readonly class: the original must be untouched.
        self::assertNull($config->readOnly);
    }

    public function testWithCanSetAFieldBackToNull(): void
    {
        // The naive implementation of with() is a null-coalesce per field, which cannot
        // express "unset this again" — worth pinning, since every field defaults to null.
        $config = (new Config(maxThreads: 4))->with(maxThreads: null);

        self::assertNull($config->maxThreads);
    }

    public function testAnUnknownFieldNameIsRejectedAsALibraryException(): void
    {
        // Left to `new self(...)` this is an "Unknown named parameter" \Error, which no catch
        // block written against this library would ever catch. Note that PHPStan reports nothing
        // here — a `mixed ...$overrides` signature is opaque to it, which is why the check is
        // written by hand rather than left to static analysis.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Config has no field "bufferPollSize"');

        (new Config())->with(bufferPollSize: 1024);
    }

    public function testTheRejectionNamesTheFieldsThatDoExist(): void
    {
        try {
            (new Config())->with(threads: 4);
            self::fail('an unknown field should not be accepted');
        } catch (InvalidArgumentException $e) {
            self::assertInstanceOf(LadybugException::class, $e);
            self::assertStringContainsString('maxThreads', $e->getMessage());
        }
    }

    public function testAPositionalArgumentIsRejectedRatherThanSilentlyBound(): void
    {
        // with(1024) spreads to key 0, which PHP then binds positionally to the first
        // constructor parameter *and* by name from the defaults — a confusing \Error about
        // overwriting an argument. It is the same mistake as a typo and gets the same message.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('named arguments only');

        (new Config())->with(1024);
    }

    public function testReadOnlyIsAShorthandForTheConstructor(): void
    {
        self::assertTrue(Config::readOnly()->readOnly);
    }
}
