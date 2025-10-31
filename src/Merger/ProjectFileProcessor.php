<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpPacker\Exception\FileAnalysisException;
use PhpPacker\Visitor\FqcnTransformVisitor;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psr\Log\LoggerInterface;

class ProjectFileProcessor
{
    private Parser $parser;

    private ProjectAstAnalyzer $analyzer;

    private ProjectFileScanner $scanner;

    private LoggerInterface $logger;

    private NodeDeduplicator $deduplicator;

    /** @var array<string, mixed> */
    private array $processingStats = [
        'total_files' => 0,
        'successful' => 0,
        'failed' => 0,
        'processing_time' => 0,
        'memory_usage' => 0,
    ];

    public function __construct(LoggerInterface $logger, NodeDeduplicator $deduplicator)
    {
        $factory = new ParserFactory();
        $this->parser = $factory->createForNewestSupportedVersion();
        $this->analyzer = new ProjectAstAnalyzer();
        $this->scanner = new ProjectFileScanner();
        $this->logger = $logger;
        $this->deduplicator = $deduplicator;
    }

    /**
     * 合并项目文件的 AST
     */
    /**
     * @param array<int, mixed> $projectFiles
     * @return array<int, Node>
     */
    public function mergeProjectFiles(array $projectFiles): array
    {
        $namespaceGroups = $this->groupFilesByNamespace($projectFiles);

        return $this->createMergedNodes($namespaceGroups);
    }

    /**
     * 按命名空间分组文件
     */
    /**
     * @param array<int, mixed> $projectFiles
     * @return array<string, mixed>
     */
    private function groupFilesByNamespace(array $projectFiles): array
    {
        $namespaceGroups = [];

        foreach ($projectFiles as $file) {
            $ast = $this->loadFileAst($file);
            if (null === $ast || 0 === count($ast)) {
                $this->logger->warning('Failed to parse AST for file', ['file' => $file['path']]);
                continue;
            }

            $namespace = $this->analyzer->extractNamespace($ast);
            $namespaceKey = '' === $namespace ? '__global__' : $namespace;

            if (!isset($namespaceGroups[$namespaceKey])) {
                $namespaceGroups[$namespaceKey] = [];
            }

            $filteredNodes = $this->filterToDefinitionsOnly($ast);
            $namespaceGroups[$namespaceKey] = array_merge(
                $namespaceGroups[$namespaceKey],
                $filteredNodes
            );
        }

        return $namespaceGroups;
    }

    /**
     * 创建合并后的节点
     */
    /**
     * @param array<string, mixed> $namespaceGroups
     * @return array<int, Node>
     */
    private function createMergedNodes(array $namespaceGroups): array
    {
        $mergedNodes = [];

        foreach ($namespaceGroups as $namespace => $nodes) {
            if ('__global__' === $namespace) {
                $mergedNodes = array_merge(
                    $mergedNodes,
                    $this->deduplicator->deduplicateNodes($nodes)
                );
            } else {
                $mergedNodes[] = $this->createNamespaceNode($namespace, $nodes);
            }
        }

        return $mergedNodes;
    }

    /**
     * 创建命名空间节点
     */
    /**
     * @param array<int, Node> $nodes
     */
    private function createNamespaceNode(string $namespace, array $nodes): Node\Stmt\Namespace_
    {
        $namespaceParts = explode('\\', $namespace);

        $deduplicatedNodes = $this->deduplicator->deduplicateNodes($nodes);

        // Ensure all nodes are statements
        $stmts = array_filter($deduplicatedNodes, fn ($node) => $node instanceof Node\Stmt);

        return new Node\Stmt\Namespace_(
            new Node\Name($namespaceParts),
            array_values($stmts)
        );
    }

    /**
     * 从文件加载 AST
     */
    /**
     * @param array<string, mixed> $file
     * @return array<int, Node>|null
     */
    private function loadFileAst(array $file): ?array
    {
        try {
            // 直接解析文件内容
            if (isset($file['content']) && '' !== $file['content']) {
                $ast = $this->parser->parse($file['content']);
                if (null === $ast) {
                    return null;
                }

                // 应用 NameResolver 将所有名称解析为 FQCN
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new NameResolver());
                $ast = $traverser->traverse($ast);

                // 应用 FqcnTransformVisitor 移除use语句
                $fqcnTraverser = new NodeTraverser();
                $fqcnTraverser->addVisitor(new FqcnTransformVisitor());

                return $fqcnTraverser->traverse($ast);
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to load AST', [
                'file' => $file['path'],
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 只保留定义语句（类、接口、trait、函数），过滤掉执行语句
     */
    /**
     * @param array<int, Node> $ast
     * @return array<int, Node>
     */
    private function filterToDefinitionsOnly(array $ast): array
    {
        $definitions = [];

        foreach ($ast as $node) {
            if ($this->isNamespaceNode($node) && $node instanceof Node\Stmt\Namespace_) {
                $definitions = array_merge(
                    $definitions,
                    $this->extractDefinitionsFromNamespace($node)
                );
            } elseif ($this->isDefinitionNode($node)) {
                $definitions[] = $node;
            }
        }

        return $definitions;
    }

    /**
     * 检查是否为命名空间节点
     */
    private function isNamespaceNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Namespace_;
    }

    /**
     * 从命名空间提取定义
     */
    /**
     * @return array<int, Node>
     */
    private function extractDefinitionsFromNamespace(Node\Stmt\Namespace_ $namespace): array
    {
        if (null === $namespace->stmts || 0 === count($namespace->stmts)) {
            return [];
        }

        return $this->filterToDefinitionsOnly($namespace->stmts);
    }

    /**
     * 检查是否为定义节点
     */
    private function isDefinitionNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\Const_;
    }

    /**
     * 处理单个文件
     */
    /**
     * @return array<string, mixed>
     */
    public function processFile(string $filePath): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        try {
            $content = $this->readFileContent($filePath);
            $resolvedAst = $this->parseAndResolveAst($content);
            $result = $this->extractFileAnalysisData($resolvedAst, $content, $filePath);

            ++$this->processingStats['successful'];

            return $result;
        } catch (\Exception $e) {
            ++$this->processingStats['failed'];
            $this->logger->error("Failed to process file: {$filePath}", ['error' => $e->getMessage()]);

            return $this->createErrorResult($filePath, $e);
        } finally {
            $this->updateProcessingStats($startTime, $startMemory);
        }
    }

    /**
     * 读取文件内容
     */
    private function readFileContent(string $filePath): string
    {
        $content = file_get_contents($filePath);
        if (false === $content) {
            throw new FileAnalysisException("Cannot read file: {$filePath}");
        }

        return $content;
    }

    /**
     * 解析并解析AST
     */
    /**
     * @return array<int, Node>
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
     * 提取文件分析数据
     */
    /**
     * @param array<int, Node> $resolvedAst
     * @return array<string, mixed>
     */
    private function extractFileAnalysisData(array $resolvedAst, string $content, string $filePath): array
    {
        return [
            'ast' => $resolvedAst,
            'dependencies' => $this->analyzer->extractDependencies($resolvedAst),
            'symbols' => $this->analyzer->extractSymbols($resolvedAst),
            'metadata' => $this->analyzer->extractMetadata($resolvedAst, $content),
            'path' => $filePath,
        ];
    }

    /**
     * 创建错误结果
     */
    /**
     * @return array<string, mixed>
     */
    private function createErrorResult(string $filePath, \Exception $e): array
    {
        return [
            'error' => 'Syntax error in ' . basename($filePath) . ': ' . $e->getMessage(),
            'ast' => [],
            'dependencies' => [],
            'symbols' => ['classes' => [], 'functions' => [], 'constants' => []],
            'metadata' => [],
            'path' => $filePath,
        ];
    }

    /**
     * 更新处理统计信息
     */
    private function updateProcessingStats(float $startTime, int $startMemory): void
    {
        ++$this->processingStats['total_files'];
        $this->processingStats['processing_time'] += microtime(true) - $startTime;
        $this->processingStats['memory_usage'] = max(
            $this->processingStats['memory_usage'],
            memory_get_usage() - $startMemory
        );
    }

    /**
     * 处理多个文件
     */
    /**
     * @param array<int, string> $filePaths
     * @return array<string, mixed>
     */
    public function processFiles(array $filePaths): array
    {
        $results = [];

        foreach ($filePaths as $filePath) {
            $results[$filePath] = $this->processFile($filePath);
        }

        return $results;
    }

    /**
     * 查找项目文件
     */
    /**
     * @return array<int, mixed>
     */
    public function findProjectFiles(string $directory): array
    {
        return $this->scanner->findProjectFiles($directory);
    }

    /**
     * 过滤项目依赖
     */
    /**
     * @param array<int, mixed> $dependencies
     * @param array<int, string> $projectNamespaces
     * @return array<int, mixed>
     */
    public function filterProjectDependencies(array $dependencies, array $projectNamespaces): array
    {
        $detector = new ProjectDependencyDetector($this);

        return $detector->filterProjectDependencies($dependencies, $projectNamespaces);
    }

    /**
     * 获取处理统计信息
     */
    /**
     * @return array<string, mixed>
     */
    public function getProcessingStats(): array
    {
        return $this->processingStats;
    }

    /**
     * 重置处理统计信息
     */
    public function resetProcessingStats(): void
    {
        $this->processingStats = [
            'total_files' => 0,
            'successful' => 0,
            'failed' => 0,
            'processing_time' => 0,
            'memory_usage' => 0,
        ];
    }

    /**
     * 检测循环依赖
     */
    /**
     * @param array<int, string> $filePaths
     * @return array<int, mixed>
     */
    public function detectCircularDependencies(array $filePaths): array
    {
        $detector = new ProjectDependencyDetector($this);

        return $detector->detectCircularDependencies($filePaths);
    }
}
