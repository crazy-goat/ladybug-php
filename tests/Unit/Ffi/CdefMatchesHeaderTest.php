<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit\Ffi;

use Ladybug\Connector\Ffi\Cdef;
use Ladybug\Type\DataType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The FFI connector re-declares part of lbug.h by hand, and a declaration that disagrees
 * with the real library does not fail loudly — it corrupts memory. (During development
 * exactly one wrong return type, `lbug_value_create_null`, segfaulted the process.)
 *
 * So this test parses the shipped header and compares it, function by function, with what
 * Cdef declares. It is the guard rail that makes hand-transcription safe.
 */
#[CoversClass(Cdef::class)]
final class CdefMatchesHeaderTest extends TestCase
{
    private const HEADER = __DIR__ . '/../../../lib/lbug.h';

    public function testEveryDeclarationMatchesTheShippedHeader(): void
    {
        $header = $this->headerFunctions();
        $mismatches = [];
        $checked = 0;

        foreach ($this->cdefFunctions() as $name => $ours) {
            if (!isset($header[$name])) {
                $mismatches[] = "{$name}: declared here but absent from lbug.h";
                continue;
            }

            ++$checked;
            if ($ours !== $header[$name]) {
                $mismatches[] = "{$name}:\n    cdef:   {$ours}\n    header: {$header[$name]}";
            }
        }

        self::assertSame([], $mismatches, "Cdef disagrees with lbug.h:\n" . implode("\n", $mismatches));
        self::assertGreaterThan(80, $checked, 'the parser found suspiciously few declarations');
    }

    public function testDeclaresTheDataTypeEnumValuesUsedByTheReader(): void
    {
        $header = file_get_contents(self::HEADER);
        self::assertIsString($header);

        preg_match_all('/\bLBUG_([A-Z0-9_]+)\s*=\s*(\d+)/', $header, $matches, PREG_SET_ORDER);
        self::assertNotEmpty($matches, 'no lbug_data_type_id values found in the header');

        foreach ($matches as [, $constant, $value]) {
            self::assertNotNull(
                DataType::tryFrom((int) $value),
                "DataType has no case for LBUG_{$constant} = {$value}; liblbug gained a type",
            );
        }
    }

    /** @return array<string, string> */
    private function cdefFunctions(): array
    {
        $source = (string) preg_replace('/\s+/', ' ', Cdef::source());
        preg_match_all('/([A-Za-z_][A-Za-z0-9_ *]*\s\*?lbug_[a-z0-9_]+\s*\([^)]*\))\s*;/', $source, $matches);

        $functions = [];
        foreach ($matches[1] as $declaration) {
            if (preg_match('/\b(lbug_[a-z0-9_]+)\s*\(/', $declaration, $name) !== 1) {
                continue;
            }

            $functions[$name[1]] = $this->normalise($declaration);
        }

        return $functions;
    }

    /** @return array<string, string> */
    private function headerFunctions(): array
    {
        $header = file_get_contents(self::HEADER);
        self::assertIsString($header, 'lib/lbug.h is missing — download the liblbug release first');

        $header = (string) preg_replace('#/\*.*?\*/#s', '', $header);
        $header = (string) preg_replace('#//[^\n]*#', '', $header);

        preg_match_all('/LBUG_C_API\s+([^;]+?)\s*;/s', $header, $matches);

        $functions = [];
        foreach ($matches[1] as $declaration) {
            if (preg_match('/\b(lbug_[a-z0-9_]+)\s*\(/', $declaration, $name) !== 1) {
                continue;
            }

            $functions[$name[1]] = $this->normalise($declaration);
        }

        return $functions;
    }

    /** Reduces a C declaration to return type, name and parameter types. */
    private function normalise(string $declaration): string
    {
        $text = (string) preg_replace('/\s+/', ' ', $declaration);
        $text = str_replace(['LBUG_C_API ', 'const ', 'struct '], '', $text);
        $text = (string) preg_replace('/\s*\*\s*/', '* ', $text);
        $text = (string) preg_replace('/\s*,\s*/', ', ', $text);
        $text = (string) preg_replace('/\s*\(\s*/', '(', $text);
        $text = (string) preg_replace('/\s*\)\s*/', ')', $text);

        // Drop parameter names, keeping only their types.
        $text = (string) preg_replace_callback('/\(([^)]*)\)/', static function (array $match): string {
            $parameters = array_map(static function (string $parameter): string {
                $parameter = trim($parameter);
                if ($parameter === '' || $parameter === 'void') {
                    return $parameter;
                }

                return trim((string) preg_replace('/\s*\b[A-Za-z_][A-Za-z0-9_]*$/', '', $parameter));
            }, explode(', ', $match[1]));

            return '(' . implode(', ', $parameters) . ')';
        }, $text);

        return trim($text, '; ');
    }
}
