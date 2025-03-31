<?php

namespace PhpPacker\Parser;

use PhpPacker\Analyzer\DependencyAnalyzer;
use PhpPacker\Config\Configuration;
use PhpPacker\Exception\ResourceException;
use PhpPacker\Visitor\RemoveDeclareStatementVisitor;
use PhpPacker\Visitor\RemoveIncludeAutoloadVisitor;
use PhpPacker\Visitor\RemoveIncludeVisitor;
use PhpPacker\Visitor\RemoveUseStatementVisitor;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use Psr\Log\LoggerInterface;
use Rector\Application\FileProcessor;
use Rector\Application\Provider\CurrentFileProvider;
use Rector\CodeQuality\Rector\Assign\CombinedAssignRector;
use Rector\CodeQuality\Rector\BooleanNot\ReplaceMultipleBooleanNotRector;
use Rector\CodeQuality\Rector\BooleanNot\SimplifyDeMorganBinaryRector;
use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\CodeQuality\Rector\ClassMethod\InlineArrayReturnAssignRector;
use Rector\CodeQuality\Rector\Concat\JoinStringConcatRector;
use Rector\CodeQuality\Rector\Expression\InlineIfToExplicitIfRector;
use Rector\CodeQuality\Rector\Expression\TernaryFalseExpressionToIfRector;
use Rector\CodeQuality\Rector\FuncCall\ArrayMergeOfNonArraysToSimpleArrayRector;
use Rector\CodeQuality\Rector\FuncCall\CallUserFuncWithArrowFunctionToInlineRector;
use Rector\CodeQuality\Rector\FuncCall\CompactToVariablesRector;
use Rector\CodeQuality\Rector\FuncCall\InlineIsAInstanceOfRector;
use Rector\CodeQuality\Rector\FuncCall\RemoveSoleValueSprintfRector;
use Rector\CodeQuality\Rector\FuncCall\SetTypeToCastRector;
use Rector\CodeQuality\Rector\FuncCall\SimplifyInArrayValuesRector;
use Rector\CodeQuality\Rector\FuncCall\UnwrapSprintfOneArgumentRector;
use Rector\CodeQuality\Rector\If_\CompleteMissingIfElseBracketRector;
use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodeQuality\Rector\If_\SimplifyIfElseToTernaryRector;
use Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector;
use Rector\CodeQuality\Rector\LogicalAnd\AndAssignsToSeparateLinesRector;
use Rector\CodeQuality\Rector\LogicalAnd\LogicalToBooleanRector;
use Rector\CodeQuality\Rector\Switch_\SingularSwitchToIfRector;
use Rector\CodingStyle\Rector\Assign\SplitDoubleAssignRector;
use Rector\CodingStyle\Rector\ClassConst\RemoveFinalFromConstRector;
use Rector\CodingStyle\Rector\ClassConst\SplitGroupedClassConstantsRector;
use Rector\CodingStyle\Rector\FuncCall\CallUserFuncToMethodCallRector;
use Rector\CodingStyle\Rector\FuncCall\ConsistentImplodeRector;
use Rector\CodingStyle\Rector\FuncCall\VersionCompareFuncCallToConstantRector;
use Rector\CodingStyle\Rector\Property\SplitGroupedPropertiesRector;
use Rector\CodingStyle\Rector\Stmt\RemoveUselessAliasInUseStatementRector;
use Rector\CodingStyle\Rector\String_\SymplifyQuoteEscapeRector;
use Rector\CodingStyle\Rector\String_\UseClassKeywordForClassNameResolutionRector;
use Rector\CodingStyle\Rector\Ternary\TernaryConditionVariableAssignmentRector;
use Rector\CodingStyle\Rector\Use_\SeparateMultiUseImportsRector;
use Rector\DeadCode\Rector\Assign\RemoveDoubleAssignRector;
use Rector\DeadCode\Rector\BooleanAnd\RemoveAndTrueRector;
use Rector\DeadCode\Rector\Cast\RecastingRemovalRector;
use Rector\DeadCode\Rector\ClassLike\RemoveTypedPropertyNonMockDocblockRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnExprInConstructRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\Concat\RemoveConcatAutocastRector;
use Rector\DeadCode\Rector\Expression\SimplifyMirrorAssignRector;
use Rector\DeadCode\Rector\For_\RemoveDeadContinueRector;
use Rector\DeadCode\Rector\For_\RemoveDeadIfForeachForRector;
use Rector\DeadCode\Rector\For_\RemoveDeadLoopRector;
use Rector\DeadCode\Rector\FunctionLike\RemoveDeadReturnRector;
use Rector\DeadCode\Rector\If_\ReduceAlwaysFalseIfOrRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\DeadCode\Rector\If_\SimplifyIfElseWithSameContentRector;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\DeadCode\Rector\Plus\RemoveDeadZeroAndOneOperationRector;
use Rector\DeadCode\Rector\Property\RemoveUselessReadOnlyTagRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\DeadCode\Rector\Return_\RemoveDeadConditionAboveReturnRector;
use Rector\DeadCode\Rector\StaticCall\RemoveParentCallWithoutParentRector;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use Rector\DeadCode\Rector\Switch_\RemoveDuplicatedCaseInSwitchRector;
use Rector\DeadCode\Rector\Ternary\TernaryToBooleanOrFalseToBooleanAndRector;
use Rector\DependencyInjection\LazyContainerFactory;
use Rector\DowngradePhp74\Rector\ArrowFunction\ArrowFunctionToAnonymousFunctionRector;
use Rector\DowngradePhp74\Rector\Coalesce\DowngradeNullCoalescingOperatorRector;
use Rector\DowngradePhp80\Rector\Catch_\DowngradeNonCapturingCatchesRector;
use Rector\DowngradePhp80\Rector\Class_\DowngradePropertyPromotionRector;
use Rector\DowngradePhp80\Rector\ClassConstFetch\DowngradeClassOnObjectToGetClassRector;
use Rector\DowngradePhp80\Rector\Expression\DowngradeMatchToSwitchRector;
use Rector\DowngradePhp80\Rector\Expression\DowngradeThrowExprRector;
use Rector\DowngradePhp80\Rector\FuncCall\DowngradeStrContainsRector;
use Rector\DowngradePhp80\Rector\FuncCall\DowngradeStrEndsWithRector;
use Rector\DowngradePhp80\Rector\FuncCall\DowngradeStrStartsWithRector;
use Rector\DowngradePhp80\Rector\FunctionLike\DowngradeUnionTypeDeclarationRector;
use Rector\DowngradePhp80\Rector\NullsafeMethodCall\DowngradeNullsafeToTernaryOperatorRector;
use Rector\DowngradePhp80\Rector\Property\DowngradeMixedTypeTypedPropertyRector;
use Rector\DowngradePhp80\Rector\Property\DowngradeUnionTypeTypedPropertyRector;
use Rector\DowngradePhp81\Rector\Array_\DowngradeArraySpreadStringKeyRector;
use Rector\DowngradePhp81\Rector\ClassConst\DowngradeFinalizePublicClassConstantRector;
use Rector\DowngradePhp81\Rector\FuncCall\DowngradeArrayIsListRector;
use Rector\DowngradePhp81\Rector\FuncCall\DowngradeFirstClassCallableSyntaxRector;
use Rector\DowngradePhp81\Rector\FunctionLike\DowngradeNeverTypeDeclarationRector;
use Rector\DowngradePhp81\Rector\FunctionLike\DowngradeNewInInitializerRector;
use Rector\DowngradePhp81\Rector\LNumber\DowngradeOctalNumberRector;
use Rector\DowngradePhp81\Rector\Property\DowngradeReadonlyPropertyRector;
use Rector\DowngradePhp82\Rector\Class_\DowngradeReadonlyClassRector;
use Rector\DowngradePhp84\Rector\MethodCall\DowngradeNewMethodCallWithoutParenthesesRector;
use Rector\EarlyReturn\Rector\Foreach_\ChangeNestedForeachIfsToEarlyContinueRector;
use Rector\EarlyReturn\Rector\If_\ChangeIfElseValueAssignToEarlyReturnRector;
use Rector\EarlyReturn\Rector\If_\ChangeNestedIfsToEarlyReturnRector;
use Rector\EarlyReturn\Rector\If_\RemoveAlwaysElseRector;
use Rector\EarlyReturn\Rector\Return_\PreparedValueToEarlyReturnRector;
use Rector\EarlyReturn\Rector\Return_\ReturnBinaryOrToEarlyReturnRector;
use Rector\EarlyReturn\Rector\StmtsAwareInterface\ReturnEarlyIfVariableRector;
use Rector\Instanceof_\Rector\Ternary\FlipNegatedTernaryInstanceofRector;
use Rector\Php52\Rector\Property\VarToPublicPropertyRector;
use Rector\Php53\Rector\Variable\ReplaceHttpServerVarsByServerRector;
use Rector\Php54\Rector\Break_\RemoveZeroBreakContinueRector;
use Rector\Php54\Rector\FuncCall\RemoveReferenceFromCallRector;
use Rector\Php55\Rector\FuncCall\PregReplaceEModifierRector;
use Rector\Php70\Rector\Switch_\ReduceMultipleDefaultSwitchRector;
use Rector\Php74\Rector\ArrayDimFetch\CurlyToSquareBracketArrayStringRector;
use Rector\Php74\Rector\Ternary\ParenthesizeNestedTernaryRector;
use Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector;
use Rector\PhpParser\Printer\BetterStandardPrinter;
use Rector\TypeDeclaration\Rector\ClassMethod\AddParamArrayDocblockBasedOnCallableNativeFuncCallRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddReturnArrayDocblockBasedOnArrayMapRector;
use Rector\TypeDeclaration\Rector\Closure\AddClosureNeverReturnTypeRector;
use Rector\TypeDeclaration\Rector\Closure\AddClosureVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\FunctionLike\AddClosureParamTypeFromIterableMethodCallRector;
use Rector\TypeDeclaration\Rector\FunctionLike\AddReturnTypeDeclarationFromYieldsRector;
use Rector\ValueObject\Application\File;
use Rector\Visibility\Rector\ClassMethod\ExplicitPublicClassMethodRector;
use Symfony\Component\Stopwatch\Stopwatch;

class CodeParser
{
    private Configuration $config;
    private LoggerInterface $logger;
    private array $processedFiles = [];
    private array $dependencies = [];
    private array $psr4Map = [];
    private Parser $parser;
    private BetterStandardPrinter $printer;

    public function __construct(
        Configuration $config,
        LoggerInterface $logger,
        private readonly DependencyAnalyzer $dependencyAnalyzer,
        private readonly AstManager $astManager,
    )
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->loadPsr4Map();

        $this->parser = (new ParserFactory)->createForVersion(PhpVersion::fromString('8.1'));
        $this->printer = new BetterStandardPrinter();
    }
    
    private function loadPsr4Map(): void
    {
        $vendorPath = dirname($this->config->getEntryFile()) . '/vendor/';
        $autoloadFile = $vendorPath . 'composer/autoload_psr4.php';
        
        if (file_exists($autoloadFile)) {
            $this->psr4Map = require $autoloadFile;
            $this->logger->debug('Loaded PSR-4 autoload map', [
                'namespaces' => array_keys($this->psr4Map)
            ]);
        } else {
            $this->logger->warning('PSR-4 autoload map not found');
        }
    }
    
    public function parse(string $file): void
    {
        if ($this->isFileProcessed($file)) {
            return;
        }

        $stopwatch = new Stopwatch();
        $stopwatch->start('parse');

        $this->logger->debug('Parsing file', ['file' => $file]);

        $ast = $this->parseCode($file);
        $this->astManager->addAst($file, $ast);
        $this->processedFiles[] = $file;

        // 分析文件中的依赖
        $this->dependencies[$file] = iterator_to_array($this->dependencyAnalyzer->findDepFiles($file, $ast));

        // 递归分析依赖文件
        foreach ($this->dependencies[$file] ?? [] as $dependencyFile) {
            $this->parse($dependencyFile);
        }

        $event = $stopwatch->stop('parse');
        $this->logger->debug('File parsed successfully', [
            'file' => $file,
            'stopwatch' => strval($event),
        ]);
    }

    private function parseCode(string $fileName): array
    {
        $code = file_get_contents($fileName);
        if ($code === false) {
            throw new ResourceException("Failed to read file: $fileName");
        }

        $lazyContainerFactory = new LazyContainerFactory();
        $rectorConfig = $lazyContainerFactory->create();

        $rules = [
            // 降级php版本，以提高兼容性
            DowngradeStrStartsWithRector::class,
            DowngradeStrEndsWithRector::class,
            DowngradeStrContainsRector::class,
            DowngradeArrayIsListRector::class,
            DowngradeArraySpreadStringKeyRector::class,
            DowngradeNewInInitializerRector::class,
            DowngradeOctalNumberRector::class,
            DowngradeReadonlyClassRector::class,
            DowngradeNewMethodCallWithoutParenthesesRector::class,
            DowngradeFinalizePublicClassConstantRector::class,
            DowngradeNullsafeToTernaryOperatorRector::class,
            DowngradeNullCoalescingOperatorRector::class,
            DowngradeThrowExprRector::class,
            DowngradeMatchToSwitchRector::class,

            RemoveAlwaysTrueIfConditionRector::class,
            RemoveSoleValueSprintfRector::class,
            RemoveUselessAliasInUseStatementRector::class,
            RemoveFinalFromConstRector::class,
            RemoveDeadReturnRector::class,
            RemoveNonExistingVarAnnotationRector::class,
            RemoveDeadZeroAndOneOperationRector::class,
            RemoveAndTrueRector::class,
            RemoveParentCallWithoutParentRector::class,
            RemoveDeadConditionAboveReturnRector::class,
            RemoveUselessVarTagRector::class,
            RemoveUselessReadOnlyTagRector::class,
            RemoveDoubleAssignRector::class,
            RemoveUnreachableStatementRector::class,
            RemoveDeadLoopRector::class,
            RemoveDeadContinueRector::class,
            RemoveDeadIfForeachForRector::class,
            RemoveDuplicatedCaseInSwitchRector::class,
            RemoveConcatAutocastRector::class,
            RemoveUselessReturnTagRector::class,
            RemoveUselessReturnExprInConstructRector::class,
            RemoveUselessParamTagRector::class,
            RemoveAlwaysElseRector::class,
            RemoveReferenceFromCallRector::class,
            RemoveZeroBreakContinueRector::class,
            RemoveTypedPropertyNonMockDocblockRector::class,

            RecastingRemovalRector::class,
            ReduceAlwaysFalseIfOrRector::class,

            SplitGroupedPropertiesRector::class,
            SplitDoubleAssignRector::class,
            SplitGroupedClassConstantsRector::class,
            SeparateMultiUseImportsRector::class,

            InlineConstructorDefaultToPropertyRector::class,
            InlineArrayReturnAssignRector::class,
            InlineIsAInstanceOfRector::class,
            //InlineVarDocTagToAssertRector::class, //有些包的注释谢得不规范，会导致生成的代码格式错误

            SimplifyDeMorganBinaryRector::class,
            SimplifyIfReturnBoolRector::class,
            SimplifyIfElseToTernaryRector::class,
            SymplifyQuoteEscapeRector::class,
            SimplifyInArrayValuesRector::class,
            SimplifyMirrorAssignRector::class,
            SimplifyIfElseWithSameContentRector::class,

            TernaryConditionVariableAssignmentRector::class,
            TernaryToBooleanOrFalseToBooleanAndRector::class,
            TernaryFalseExpressionToIfRector::class,

            AddReturnTypeDeclarationFromYieldsRector::class,
            AddClosureVoidReturnTypeWhereNoReturnRector::class,
            AddClosureNeverReturnTypeRector::class,
            AddClosureParamTypeFromIterableMethodCallRector::class,
            AddParamArrayDocblockBasedOnCallableNativeFuncCallRector::class,
            AddReturnArrayDocblockBasedOnArrayMapRector::class,

            ReplaceHttpServerVarsByServerRector::class,

            VarToPublicPropertyRector::class,
            PreparedValueToEarlyReturnRector::class,
            CallUserFuncToMethodCallRector::class,
            ArrayMergeOfNonArraysToSimpleArrayRector::class,
            UnwrapSprintfOneArgumentRector::class,
            SetTypeToCastRector::class,
            CallUserFuncWithArrowFunctionToInlineRector::class,
            CompactToVariablesRector::class,
            CombinedAssignRector::class,
            ReplaceMultipleBooleanNotRector::class,
            SingularSwitchToIfRector::class,
            JoinStringConcatRector::class,
            InlineIfToExplicitIfRector::class,
            ExplicitBoolCompareRector::class,
            CompleteMissingIfElseBracketRector::class,
            LogicalToBooleanRector::class,
            AndAssignsToSeparateLinesRector::class,
            ExplicitPublicClassMethodRector::class,
            ConsistentImplodeRector::class,
            VersionCompareFuncCallToConstantRector::class,
            UseClassKeywordForClassNameResolutionRector::class,
            FlipNegatedTernaryInstanceofRector::class,
            ReturnBinaryOrToEarlyReturnRector::class,
            ReturnEarlyIfVariableRector::class,
            ChangeIfElseValueAssignToEarlyReturnRector::class,
            ExplicitNullableParamTypeRector::class,
            PregReplaceEModifierRector::class,
            CurlyToSquareBracketArrayStringRector::class,
            ReduceMultipleDefaultSwitchRector::class,

            ChangeNestedForeachIfsToEarlyContinueRector::class,
            ChangeNestedIfsToEarlyReturnRector::class,
            ParenthesizeNestedTernaryRector::class,
        ];

        // 枚举的处理
        if ($this->config->forKphp()) {
            $rules = array_merge($rules, [
                DowngradeMixedTypeTypedPropertyRector::class,
                DowngradeUnionTypeTypedPropertyRector::class,
                DowngradeUnionTypeDeclarationRector::class,
                DowngradeReadonlyPropertyRector::class,
                DowngradePropertyPromotionRector::class,
                DowngradeNeverTypeDeclarationRector::class,
                DowngradeFirstClassCallableSyntaxRector::class,
                DowngradeNonCapturingCatchesRector::class,
                DowngradeClassOnObjectToGetClassRector::class,
                ArrowFunctionToAnonymousFunctionRector::class,
            ]);
        }

        $rectorConfig->rules($rules);

        $rectorConfig->boot();

        // see \Rector\Application\ApplicationFileProcessor::processFile
        $fileObject = new File($fileName, $code);
        $rectorConfig->get(CurrentFileProvider::class)->setFile($fileObject);
        $configuration = new \Rector\ValueObject\Configuration(
            isDryRun: true,
            showProgressBar: false,
            paths: [
                $fileName,
            ],
        );
        $rectorConfig->get(FileProcessor::class)->processFile($fileObject, $configuration);

        $newCode = $this->printer->prettyPrintFile($fileObject->getNewStmts());
//        echo PHP_EOL;
//        echo PHP_EOL;
//        echo PHP_EOL . $newCode;
//        echo PHP_EOL;
//        echo PHP_EOL;
        $stmts = $this->parser->parse($newCode);

        // 检查是否在全局命名空间
        $hasNamespace = false;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $hasNamespace = true;
                break;
            }
        }
        // 如果是全局命名空间，添加命名空间包裹
        if (!$hasNamespace) {
            $stmts = [new Node\Stmt\Namespace_(null, $stmts)];
        }

        $nodeTraverser = $this->astManager->createNodeTraverser();
        $nodeTraverser->addVisitor(new RemoveUseStatementVisitor()); // 移除所有 use 语句
        $nodeTraverser->addVisitor(new RemoveIncludeAutoloadVisitor());
        $nodeTraverser->addVisitor(new RemoveDeclareStatementVisitor()); // 移除所有 declare 语句
        $nodeTraverser->addVisitor(new RemoveIncludeVisitor()); // 移除所有 include/require 语句

        return $nodeTraverser->traverse($stmts);
    }
    
    private function isFileProcessed(string $file): bool
    {
        return in_array($file, $this->processedFiles);
    }
    
    public function getAstManager(): AstManager
    {
        return $this->astManager;
    }
    
    public function getProcessedFiles(): array
    {
        return $this->processedFiles;
    }
    
    public function getDependencies(): array
    {
        return $this->dependencies;
    }
}
