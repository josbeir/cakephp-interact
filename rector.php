<?php
declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\ConvertStaticToSelfRector;
use Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Concat\RemoveConcatAutocastRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictFluentReturnRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        ReturnTypeFromStrictFluentReturnRector::class,
        // Preserve CakePHP assertion style and the explicit types required by Mago.
        ConvertStaticToSelfRector::class,
        RemoveConcatAutocastRector::class,
        RemoveUselessVarTagRector::class,
        // CakePHP validates normalized template paths; /../ changes that contract.
        DirnameDirConcatStringToDirectStringPathRector::class,
    ])
    ->withImportNames(
        importNames: true,
        importDocBlockNames: false,
        importShortClasses: true,
        removeUnusedImports: true,
    )
    ->withParallel()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
    );
