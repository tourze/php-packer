<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpPacker\Exception\ConfigurationException;
use PhpPacker\Exception\FileAnalysisException;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Psr\Log\LoggerInterface;

class VendorFileProcessor
{
    private Parser $parser;

    private VendorAstProcessor $astProcessor;

    private VendorAstOptimizer $optimizer;

    private VendorFileScanner $scanner;

    private LoggerInterface $logger;

    private bool $stripComments = false;

    private bool $optimizeCode = false;

    /** @var array<string, mixed> */
    private array $stats = [
        'total_files' => 0,
        'total_classes' => 0,
        'total_size' => 0,
        'processing_time' => 0,
        'packages' => [],
    ];

    public function __construct(LoggerInterface $logger)
    {
        $factory = new ParserFactory();
        $this->parser = $factory->createForNewestSupportedVersion();
        $this->astProcessor = new VendorAstProcessor();
        $this->optimizer = new VendorAstOptimizer();
        $this->scanner = new VendorFileScanner();
        $this->logger = $logger;
    }

    /**
     * 创建 vendor 文件的包含节点
     *
     * @param array<int, array<string, mixed>> $vendorFiles
     * @return array<mixed>
     */
    public function createVendorNodes(array $vendorFiles): array
    {
        $nodes = [];

        foreach ($vendorFiles as $file) {
            $vendorNodes = $this->processVendorFile($file);
            $nodes = array_merge($nodes, $vendorNodes);
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $file
     * @return array<mixed>
     */
    private function processVendorFile(array $file): array
    {
        try {
            if (!isset($file['content']) || !is_string($file['content'])) {
                return [];
            }

            $ast = $this->astProcessor->parseFile($file['content']);
            if (null === $ast) {
                return [];
            }

            $ast = $this->astProcessor->transformAst($ast);
            $filteredAst = $this->astProcessor->filterNodes($ast);

            if (count($filteredAst) > 0 && isset($file['path']) && is_string($file['path'])) {
                $this->astProcessor->addFileComment($filteredAst, $file['path']);
            }

            return $filteredAst;
        } catch (\Exception $e) {
            $filePath = isset($file['path']) && is_string($file['path']) ? $file['path'] : 'unknown';
            $this->logger->warning('Failed to parse vendor file', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * 处理单个文件
     *
     * @return array<string, mixed>
     */
    public function processFile(string $filePath): array
    {
        $startTime = microtime(true);

        try {
            $content = $this->readFileContent($filePath);
            $resolvedAst = $this->parseAndResolveAst($content);
            $fileInfo = $this->extractFileInfo($resolvedAst);
            $processedAst = $this->applyProcessingOptions($resolvedAst);
            $code = $this->generateCode($processedAst['ast']);

            $this->updateFileStats($content, $fileInfo['classes'], $startTime);

            return $this->createSuccessResult($processedAst['ast'], $fileInfo, $processedAst, $code, $filePath);
        } catch (\Exception $e) {
            $this->logger->error("Failed to process vendor file: {$filePath}", ['error' => $e->getMessage()]);

            return $this->createErrorResult($e, $filePath);
        }
    }

    private function readFileContent(string $filePath): string
    {
        $content = file_get_contents($filePath);
        if (false === $content) {
            throw new FileAnalysisException("Cannot read file: {$filePath}");
        }

        return $content;
    }

    /**
     * @return array<mixed>
     */
    private function parseAndResolveAst(string $content): array
    {
        $ast = $this->parser->parse($content);
        if (null === $ast) {
            throw new FileAnalysisException('Failed to parse AST');
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        return $traverser->traverse($ast);
    }

    /**
     * @param array<mixed> $resolvedAst
     * @return array<string, mixed>
     */
    private function extractFileInfo(array $resolvedAst): array
    {
        return [
            'namespace' => $this->astProcessor->extractNamespace($resolvedAst),
            'classes' => $this->astProcessor->extractClasses($resolvedAst),
        ];
    }

    /**
     * @param array<mixed> $resolvedAst
     * @return array<string, mixed>
     */
    private function applyProcessingOptions(array $resolvedAst): array
    {
        $stripped = false;
        $optimized = false;
        $optimizationStats = [];
        $processedAst = $resolvedAst;

        if ($this->stripComments) {
            $processedAst = $this->astProcessor->stripComments($processedAst);
            $stripped = true;
        }

        if ($this->optimizeCode) {
            $optimizationResult = $this->optimizer->optimize($processedAst);
            if (isset($optimizationResult['ast'], $optimizationResult['stats'])) {
                $processedAst = $optimizationResult['ast'];
                $optimized = true;
                $optimizationStats = is_array($optimizationResult['stats']) ? $optimizationResult['stats'] : [];
            }
        }

        return [
            'ast' => $processedAst,
            'stripped' => $stripped,
            'optimized' => $optimized,
            'optimization_stats' => $optimizationStats,
        ];
    }

    /**
     * @param array<mixed> $ast
     */
    private function generateCode(array $ast): string
    {
        $printer = new Standard();

        return $printer->prettyPrintFile($ast);
    }

    /**
     * @param array<mixed> $classes
     */
    private function updateFileStats(string $content, array $classes, float $startTime): void
    {
        ++$this->stats['total_files'];
        $this->stats['total_classes'] += count($classes);
        $this->stats['total_size'] += strlen($content);
        $this->stats['processing_time'] += microtime(true) - $startTime;
    }

    /**
     * @param array<mixed> $ast
     * @param array<string, mixed> $fileInfo
     * @param array<string, mixed> $processedResult
     * @return array<string, mixed>
     */
    private function createSuccessResult(array $ast, array $fileInfo, array $processedResult, string $code, string $filePath): array
    {
        return [
            'ast' => $ast,
            'namespace' => $fileInfo['namespace'],
            'classes' => $fileInfo['classes'],
            'stripped' => $processedResult['stripped'],
            'optimized' => $processedResult['optimized'],
            'optimization_stats' => $processedResult['optimization_stats'],
            'code' => $code,
            'path' => $filePath,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createErrorResult(\Exception $e, string $filePath): array
    {
        return [
            'error' => 'Parse error in ' . basename($filePath) . ': ' . $e->getMessage(),
            'ast' => [],
            'namespace' => '',
            'classes' => [],
            'stripped' => false,
            'optimized' => false,
            'optimization_stats' => [],
            'code' => '',
            'path' => $filePath,
        ];
    }

    /**
     * 设置是否剥离注释
     */
    public function setStripComments(bool $strip): void
    {
        $this->stripComments = $strip;
    }

    /**
     * 设置是否优化代码
     */
    public function setOptimizeCode(bool $optimize): void
    {
        $this->optimizeCode = $optimize;
    }

    /**
     * 处理 Composer 包
     *
     * @return array<string, mixed>
     */
    public function processComposerPackage(string $packagePath): array
    {
        $composerData = $this->loadComposerData($packagePath);
        $packageInfo = $this->createPackageInfo($composerData, $packagePath);
        $packageInfo = $this->processPackageFiles($packageInfo, $packagePath);
        $this->updatePackageStats($packageInfo);

        return $packageInfo;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadComposerData(string $packagePath): array
    {
        $composerFile = $packagePath . '/composer.json';
        if (!file_exists($composerFile)) {
            throw new ConfigurationException("Composer file not found: {$composerFile}");
        }

        $fileContents = file_get_contents($composerFile);
        if (false === $fileContents) {
            throw new ConfigurationException("Cannot read composer.json: {$composerFile}");
        }

        $composerData = json_decode($fileContents, true);
        if (!is_array($composerData)) {
            throw new ConfigurationException("Invalid composer.json: {$composerFile}");
        }

        return $composerData;
    }

    /**
     * @param array<string, mixed> $composerData
     * @return array<string, mixed>
     */
    private function createPackageInfo(array $composerData, string $packagePath): array
    {
        return [
            'name' => isset($composerData['name']) && is_string($composerData['name']) ? $composerData['name'] : basename($packagePath),
            'autoload' => isset($composerData['autoload']) && is_array($composerData['autoload']) ? $composerData['autoload'] : [],
            'files' => [],
            'classes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $packageInfo
     * @return array<string, mixed>
     */
    private function processPackageFiles(array $packageInfo, string $packagePath): array
    {
        $srcPath = $packagePath . '/src';
        if (!is_dir($srcPath)) {
            return $packageInfo;
        }

        $files = $this->scanner->findPhpFiles($srcPath);
        foreach ($files as $file) {
            $packageInfo = $this->processPackageFile($packageInfo, $file);
        }

        return $packageInfo;
    }

    /**
     * @param array<string, mixed> $packageInfo
     * @return array<string, mixed>
     */
    private function processPackageFile(array $packageInfo, string $file): array
    {
        $processed = $this->processFile($file);
        $packageInfo['files'][] = $file;

        if (isset($processed['classes']) && is_array($processed['classes'])) {
            $packageInfo['classes'] = array_merge(
                is_array($packageInfo['classes']) ? $packageInfo['classes'] : [],
                $processed['classes']
            );
        }

        return $packageInfo;
    }

    /**
     * @param array<string, mixed> $packageInfo
     */
    private function updatePackageStats(array $packageInfo): void
    {
        if (isset($packageInfo['name'])) {
            $this->stats['packages'][] = $packageInfo['name'];
        }
    }

    /**
     * 过滤所需文件
     *
     * @param array<mixed> $allFiles
     * @param array<string> $requiredClasses
     * @return array<mixed>
     */
    public function filterRequiredFiles(array $allFiles, array $requiredClasses): array
    {
        return $this->scanner->filterRequiredFiles($allFiles, $requiredClasses);
    }

    /**
     * 获取统计信息
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * 重置处理统计信息
     */
    public function resetStats(): void
    {
        $this->stats = [
            'total_files' => 0,
            'total_classes' => 0,
            'total_size' => 0,
            'processing_time' => 0,
            'packages' => [],
        ];
    }
}
