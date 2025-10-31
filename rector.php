<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\FuncCall\ConsistentImplodeRector;
use Rector\Config\RectorConfig;
use Rector\Php56\Rector\FuncCall\PowToExpRector;
use Rector\Php70\Rector\ClassMethod\Php4ConstructorRector;
use Rector\Php70\Rector\FuncCall\RandomFunctionRector;
use Rector\Php70\Rector\StmtsAwareInterface\IfIssetToCoalescingRector;
use Rector\Php70\Rector\Switch_\ReduceMultipleDefaultSwitchRector;
use Rector\Php71\Rector\BinaryOp\BinaryOpBetweenNumberAndStringRector;
use Rector\Php72\Rector\Assign\ListEachRector;
use Rector\Php72\Rector\FuncCall\CreateFunctionToAnonymousFunctionRector;
use Rector\Php72\Rector\FuncCall\StringsAssertNakedRector;
use Rector\Php72\Rector\Unset_\UnsetCastRector;
use Rector\Php72\Rector\While_\WhileEachToForeachRector;
use Rector\Php73\Rector\BooleanOr\IsCountableRector;
use Rector\Php73\Rector\ConstFetch\SensitiveConstantNameRector;
use Rector\Php73\Rector\FuncCall\ArrayKeyFirstLastRector;
use Rector\Php73\Rector\FuncCall\StringifyStrNeedlesRector;
use Rector\Php74\Rector\FuncCall\FilterVarToAddSlashesRector;
use Rector\Php81\Rector\Array_\FirstClassCallableRector;
use Rector\Php83\Rector\FuncCall\RemoveGetClassGetParentClassNoArgsRector;
use Rector\Php84\Rector\Class_\DeprecatedAnnotationToDeprecatedAttributeRector;

return RectorConfig::configure()
    // uncomment to reach your current PHP version
    // ->withPhpSets(php81: true)
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0)
    ->withRules([
        DeprecatedAnnotationToDeprecatedAttributeRector::class,
        StringifyStrNeedlesRector::class,
        ArrayKeyFirstLastRector::class,
        SensitiveConstantNameRector::class,
        IsCountableRector::class,
        FirstClassCallableRector::class,
        RemoveGetClassGetParentClassNoArgsRector::class,
        WhileEachToForeachRector::class,
        StringsAssertNakedRector::class,
        CreateFunctionToAnonymousFunctionRector::class,
        ListEachRector::class,
        UnsetCastRector::class,
        PowToExpRector::class,
        BinaryOpBetweenNumberAndStringRector::class,
        RandomFunctionRector::class,
        IfIssetToCoalescingRector::class,
        ReduceMultipleDefaultSwitchRector::class,
        Php4ConstructorRector::class,
        ConsistentImplodeRector::class,
        FilterVarToAddSlashesRector::class,
    ])
;
