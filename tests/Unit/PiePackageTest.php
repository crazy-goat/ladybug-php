<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * `ext/composer.json` is the root manifest of the generated PIE package, crazy-goat/ladybug-ext.
 *
 * It is the one file in this repository that is never exercised by building anything here: PIE
 * reads it on someone else's machine, and gets it from a mirror repository this repo pushes. So
 * the things that can silently rot are checked here.
 *
 * PIE refuses any `./configure` option a package has not declared — `pie install … --with-liblbug=/x`
 * fails with "The option does not exist" — and it passes no options at all unless the installer
 * names them. That makes two invariants worth asserting: the declared options have to exist in
 * `config.m4`, and `config.m4` has to build the extension with no options given.
 */
#[CoversNothing]
final class PiePackageTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(__DIR__ . '/../../ext/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);

        return $decoded;
    }

    private function configM4(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../ext/config.m4');
    }

    public function testItDeclaresItselfAnExtensionUnderItsOwnPackageName(): void
    {
        $manifest = $this->manifest();

        self::assertSame('php-ext', $manifest['type']);

        // PIE forbids an extension sharing a Composer name with a regular package "even if they
        // have different type fields", which is the whole reason for the separate mirror.
        self::assertNotSame('crazy-goat/ladybug-php', $manifest['name']);
        self::assertSame('crazy-goat/ladybug-ext', $manifest['name']);
    }

    public function testTheExtensionNameMatchesWhatTheModuleRegisters(): void
    {
        // Left unset, PIE derives it from the package name — which would be `ladybug-ext`, an
        // invalid extension name, and the INI it writes would not match the module.
        $header = (string) file_get_contents(__DIR__ . '/../../ext/php_ladybug.h');

        self::assertMatchesRegularExpression(
            '/#define PHP_LADYBUG_NAME\s+"' . preg_quote($this->manifest()['php-ext']['extension-name'], '/') . '"/',
            $header,
        );
    }

    public function testEveryDeclaredConfigureOptionExistsInConfigM4(): void
    {
        $configM4 = $this->configM4();

        foreach ($this->manifest()['php-ext']['configure-options'] as $option) {
            // --with-liblbug comes from PHP_ARG_WITH([liblbug]), --enable-ladybug-static from
            // PHP_ARG_ENABLE([ladybug-static]); the prefix tells which macro to expect.
            [$macro, $argument] = str_starts_with($option['name'], 'with-')
                ? ['PHP_ARG_WITH', substr($option['name'], 5)]
                : ['PHP_ARG_ENABLE', substr($option['name'], 7)];

            self::assertStringContainsString(
                "{$macro}([{$argument}]",
                $configM4,
                "{$option['name']} is offered to PIE users but no such option exists in config.m4",
            );
        }
    }

    public function testTheBuildIsEnabledWithoutAnyOptions(): void
    {
        // PIE runs a bare `./configure` unless the installer passes something. With the usual
        // default of `no`, that configures a build of nothing and succeeds — `pie install` would
        // report success and install no extension.
        self::assertMatchesRegularExpression(
            '/PHP_ARG_ENABLE\(\[ladybug],.*?\[yes]\)/s',
            $this->configM4(),
            'PHP_ARG_ENABLE([ladybug]) must default to yes or a bare ./configure builds nothing',
        );
    }

    public function testWindowsIsExcludedRatherThanUntested(): void
    {
        // The FFI connector looks for lbug_shared.dll but nothing has ever been run on Windows,
        // and PIE would otherwise offer the extension there.
        self::assertSame(['windows'], $this->manifest()['php-ext']['os-families-exclude']);
    }
}
