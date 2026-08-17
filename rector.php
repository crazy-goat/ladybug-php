<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\CodeQuality\Rector\FunctionLike\SimplifyUselessVariableRector;
use Rector\DeadCode\Rector\If_\RemoveDeadInstanceOfRector;
use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\EarlyReturn\Rector\StmtsAwareInterface\ReturnEarlyIfVariableRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPromotedPropertyRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/stubs',
        // Rector cannot see through FFI's magic calls and would rewrite working code.
        __DIR__ . '/src/Connector/Ffi/ValueReader.php',
        // Rector's stubs type FFI::new() as always returning CData, while php-src (and
        // PHPStan's stubs) make it nullable. Left alone, these rules delete the null guard
        // in FfiConnector::alloc() and a failed allocation would reach liblbug as a null
        // pointer. Scoped to the FFI directory rather than disabled globally.
        SimplifyUselessVariableRector::class => [__DIR__ . '/src/Connector/Ffi'],
        ReturnEarlyIfVariableRector::class => [__DIR__ . '/src/Connector/Ffi'],
        RemoveDeadInstanceOfRector::class => [__DIR__ . '/src/Connector/Ffi'],
        // QueryResult::$owner and PreparedStatement::$owner are never read on purpose:
        // they pin the owning object's lifetime so a lazily-read result cannot outlive
        // the connection it came from. Removing them reintroduces a use-after-free.
        RemoveUnusedPromotedPropertyRector::class => [
            __DIR__ . '/src/QueryResult.php',
            __DIR__ . '/src/PreparedStatement.php',
        ],
        // Renames $e to $runtimeException and friends; costs more readability than it buys.
        CatchExceptionNameMatchingTypeRector::class,
    ])
    // 8.2 is the floor this package supports, so no rule may introduce newer syntax.
    ->withSets([
        LevelSetList::UP_TO_PHP_82,
        SetList::CODE_QUALITY,
        SetList::CODING_STYLE,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
    ])
    ->withRules([
        ClassPropertyAssignToConstructorPromotionRector::class,
    ])
    ->withPhpSets(php82: true)
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);
