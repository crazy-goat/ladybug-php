<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit;

use Ladybug\Bulk\CsvSpool;
use Ladybug\Config;
use Ladybug\Connection;
use Ladybug\Connector\Connector;
use Ladybug\Connector\ConnectorFactory;
use Ladybug\Connector\Ext\ExtConnector;
use Ladybug\Connector\Ext\ExtHandle;
use Ladybug\Connector\Ffi\Cdef;
use Ladybug\Connector\Ffi\FfiConnector;
use Ladybug\Connector\Ffi\FfiHandle;
use Ladybug\Connector\Ffi\LibraryLocator;
use Ladybug\Connector\Ffi\ValueReader;
use Ladybug\Connector\Handle;
use Ladybug\Connector\LibraryVersion;
use Ladybug\Database;
use Ladybug\Exception\ConnectionException;
use Ladybug\Exception\ConnectorException;
use Ladybug\Exception\DatabaseException;
use Ladybug\Exception\IncompatibleLibraryException;
use Ladybug\Exception\InvalidArgumentException;
use Ladybug\Exception\LadybugException;
use Ladybug\Exception\QueryException;
use Ladybug\Exception\TypeException;
use Ladybug\PreparedStatement;
use Ladybug\QueryResult;
use Ladybug\Type\DataType;
use Ladybug\Type\InternalId;
use Ladybug\Type\Node;
use Ladybug\Type\Path;
use Ladybug\Type\Rel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Guards the API surface this library promises to keep.
 *
 * The list below is the promise. Adding a class to `src/` fails this test until it is put in
 * one of the two buckets, which is the point: with SemVer in force from 1.0.0, a class that
 * nobody classified is a class that became public by accident and cannot be taken back.
 *
 * `@internal` is not enforced by the engine, so this test is the enforcement — PHPStan and
 * PhpStorm both honour the annotation, and users get a warning instead of a surprise.
 */
#[CoversNothing]
final class ApiSurfaceTest extends TestCase
{
    /**
     * Classes an application may name, catch, or call. Anything here is covered by SemVer,
     * with the one documented exception of *implementing* Connector and Handle.
     *
     * @var list<class-string>
     */
    private const PUBLIC_API = [
        Config::class,
        Connection::class,
        Database::class,
        PreparedStatement::class,
        QueryResult::class,

        Connector::class,
        ConnectorFactory::class,
        Handle::class,
        LibraryVersion::class,
        ExtConnector::class,
        FfiConnector::class,
        LibraryLocator::class,

        DataType::class,
        InternalId::class,
        Node::class,
        Path::class,
        Rel::class,

        ConnectionException::class,
        ConnectorException::class,
        DatabaseException::class,
        IncompatibleLibraryException::class,
        InvalidArgumentException::class,
        LadybugException::class,
        QueryException::class,
        TypeException::class,
    ];

    /**
     * Plumbing: reachable because PHP has no package-private, but not part of the promise.
     * Each one must say so in its docblock.
     *
     * @var list<class-string>
     */
    private const INTERNAL = [
        CsvSpool::class,
        ExtHandle::class,
        Cdef::class,
        FfiHandle::class,
        ValueReader::class,
    ];

    /**
     * Exceptions are the one family left open, so an application can narrow one further; see
     * the LadybugException docblock. Everything else is final, so the only way to depend on
     * this library's internals is through its documented surface.
     *
     * @var list<class-string>
     */
    private const MAY_BE_EXTENDED = [
        ConnectionException::class,
        ConnectorException::class,
        DatabaseException::class,
        InvalidArgumentException::class,
        QueryException::class,
        TypeException::class,
    ];

    public function testEveryClassInSrcIsClassifiedAsPublicOrInternal(): void
    {
        $classified = [...self::PUBLIC_API, ...self::INTERNAL];
        sort($classified);

        $found = $this->classesInSrc();

        self::assertSame(
            $classified,
            $found,
            'a class in src/ is missing from this test: add it to PUBLIC_API only if you are '
            . 'willing to keep it working forever, otherwise to INTERNAL and mark it @internal',
        );
    }

    public function testInternalClassesSaySoInTheirDocblock(): void
    {
        foreach (self::INTERNAL as $class) {
            $doc = (new \ReflectionClass($class))->getDocComment();

            self::assertIsString($doc, "{$class} is internal but has no docblock to say so");
            self::assertStringContainsString(
                '@internal',
                $doc,
                "{$class} is internal but its docblock does not carry @internal, so neither "
                . 'PHPStan nor an IDE will warn anyone away from it',
            );
        }
    }

    public function testPublicClassesAreNotMarkedInternal(): void
    {
        foreach (self::PUBLIC_API as $class) {
            $doc = (new \ReflectionClass($class))->getDocComment();

            if (\is_string($doc)) {
                // A stray @internal on a documented class is worse than none: it tells users
                // the thing they are supposed to call is off limits.
                self::assertStringNotContainsString('@internal', $doc, "{$class} is public API");
            }
        }
    }

    public function testNothingIsExtendableUnlessItIsMeantToBe(): void
    {
        foreach ($this->classesInSrc() as $class) {
            $reflection = new \ReflectionClass($class);

            if ($reflection->isInterface() || $reflection->isEnum() || $reflection->isAbstract()) {
                continue;
            }

            if (\in_array($class, self::MAY_BE_EXTENDED, true)) {
                self::assertFalse($reflection->isFinal(), "{$class} is listed as extendable");

                continue;
            }

            self::assertTrue(
                $reflection->isFinal(),
                "{$class} is not final and not listed in MAY_BE_EXTENDED — subclassing it would "
                . 'become a supported use the moment someone tries it',
            );
        }
    }

    /** @return list<class-string> sorted, so the diff on failure names the offending class */
    private function classesInSrc(): array
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $classes = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr((string) $file->getRealPath(), \strlen($root) + 1, -4);
            /** @var class-string $class */
            $class = 'Ladybug\\' . str_replace(\DIRECTORY_SEPARATOR, '\\', $relative);

            self::assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class),
                "{$class} does not follow PSR-4 from its path, or the file declares something else",
            );

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
