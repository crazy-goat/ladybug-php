<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The version the extension reports has to keep up with the changelog.
 *
 * `PHP_LADYBUG_VERSION` stayed at 0.1.0 from the first commit through 0.3.1: nothing reads it,
 * nothing tested it, and while the only way to get the extension was `make ext` in a checkout
 * you could see, nobody was misled. Distributed binaries change that — `phpversion('ladybug')`
 * is the first thing anyone reporting a bug will quote.
 *
 * The assertion is "not behind", not "equal", because the header is bumped when work on a
 * release starts and the changelog entry is written when it ships. That still catches the case
 * this exists for: releasing without touching the header.
 */
#[CoversNothing]
final class ExtensionVersionTest extends TestCase
{
    public function testTheExtensionVersionIsNotBehindTheChangelog(): void
    {
        $header = $this->headerVersion();
        $released = $this->newestReleasedVersion();

        self::assertTrue(
            version_compare($header, $released, '>='),
            "ext/php_ladybug.h reports {$header} but the changelog is already at {$released}; "
            . 'bump PHP_LADYBUG_VERSION.',
        );
    }

    public function testBothVersionsAreReadable(): void
    {
        // Without this, a rename or a reformat turns the test above into one that asserts
        // nothing — the regexes would stop matching and there would be no version to compare.
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $this->headerVersion());
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $this->newestReleasedVersion());
    }

    private function headerVersion(): string
    {
        $header = (string) file_get_contents(__DIR__ . '/../../ext/php_ladybug.h');

        if (preg_match('/#define PHP_LADYBUG_VERSION "([^"]+)"/', $header, $match) !== 1) {
            self::fail('PHP_LADYBUG_VERSION not found in ext/php_ladybug.h');
        }

        return $match[1];
    }

    private function newestReleasedVersion(): string
    {
        $changelog = (string) file_get_contents(__DIR__ . '/../../CHANGELOG.md');

        // Headings are `## [0.3.1] - 2026-08-17`; `## [Unreleased]` carries no date and is
        // skipped by the same pattern.
        if (preg_match('/^## \[(\d+\.\d+\.\d+)] - /m', $changelog, $match) !== 1) {
            self::fail('no released version heading found in CHANGELOG.md');
        }

        return $match[1];
    }
}
