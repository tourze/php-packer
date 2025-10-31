<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use PhpPacker\Exception\FileAnalysisException;
use PhpPacker\Storage\SqliteStorage;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psr\Log\LoggerInterface;

class FileAnalyzer
{
    private Parser $parser;

    private SqliteStorage $storage;

    private LoggerInterface $logger;

    private string $rootPath;

    public function __construct(SqliteStorage $storage, LoggerInterface $logger, string $rootPath)
    {
        $parserFactory = new ParserFactory();
        $this->parser = $parserFactory->createForNewestSupportedVersion();
        $this->storage = $storage;
        $this->logger = $logger;
        $this->rootPath = rtrim($rootPath, '/');
    }

    public function analyzeFile(string $filePath): void
    {
        $this->logger->info('Analyzing file', ['file' => $filePath]);

        $content = $this->validateAndReadFile($filePath);
        $relativePath = $this->getRelativePath($filePath);
        $shouldParseAst = $this->shouldParseAst($relativePath);

        try {
            if ($shouldParseAst) {
                $this->analyzeFileWithAst($filePath, $content, $relativePath);
            } else {
                $this->storeFileWithoutAst($content, $relativePath);
            }
        } catch (Error $e) {
            $this->handleParseError($filePath, $e);
        }
    }

    private function validateAndReadFile(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new FileAnalysisException("File not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if (false === $content) {
            throw new FileAnalysisException("Failed to read file: {$filePath}");
        }

        return $content;
    }

    private function analyzeFileWithAst(string $filePath, string $content, string $relativePath): void
    {
        $ast = $this->parseAndProcessAst($content, $filePath);
        if (null === $ast) {
            return;
        }

        [$fileType, $namespace, $className, $fullClassName] = $this->extractFileMetadata($ast);

        $fileId = $this->storage->addFile(
            $relativePath,
            $content,
            $fileType,
            null,
            false,
            $fullClassName
        );

        $this->processAstAndDependencies($fileId, $ast, $namespace, $filePath, $fullClassName, $className);
    }

    /** @return array<Node>|null */
    private function parseAndProcessAst(string $content, string $filePath): ?array
    {
        $ast = $this->parser->parse($content);
        if (null === $ast || [] === $ast) {
            $this->logger->warning('Empty AST for file', ['file' => $filePath]);

            return null;
        }

        // 使用 NameResolver 解析所有名称为 FQCN
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        return $traverser->traverse($ast);
    }

    /**
     * @param array<Node> $ast
     * @return array{?string, ?string, ?string, ?string}
     */
    private function extractFileMetadata(array $ast): array
    {
        $fileType = $this->detectFileType($ast);
        $namespace = $this->extractNamespace($ast);
        $className = $this->extractClassName($ast);

        $fullClassName = null;
        if (null !== $className) {
            $fullClassName = null !== $namespace ? $namespace . '\\' . $className : $className;
        }

        return [$fileType, $namespace, $className, $fullClassName];
    }

    /** @param array<Node> $ast */
    private function processAstAndDependencies(
        int $fileId,
        array $ast,
        ?string $namespace,
        string $filePath,
        ?string $fullClassName,
        ?string $className,
    ): void {
        $this->storage->storeAst($fileId, $ast);

        $visitor = new AnalysisVisitor($this->storage, $fileId, $namespace, $filePath);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        if (null !== $fullClassName && null !== $className) {
            $this->storage->addSymbol($fileId, 'class', $className, $fullClassName, $namespace);
        }

        $this->logger->info('File analyzed successfully', [
            'file' => $filePath,
            'symbols' => $visitor->getSymbolCount(),
            'dependencies' => $visitor->getDependencyCount(),
            'ast_stored' => true,
        ]);
    }

    private function storeFileWithoutAst(string $content, string $relativePath): void
    {
        $this->storage->addFile(
            $relativePath,
            $content,
            null,
            null,
            true,
            null
        );

        $this->logger->info('File stored without AST parsing', [
            'file' => $relativePath,
            'is_vendor' => str_contains($relativePath, '/vendor/'),
        ]);
    }

    private function handleParseError(string $filePath, Error $e): void
    {
        $this->logger->error('Parse error in file', [
            'file' => $filePath,
            'error' => $e->getMessage(),
        ]);
        throw new FileAnalysisException("Parse error in {$filePath}: " . $e->getMessage(), 0, $e);
    }

    /**
     * 判断文件是否需要解析 AST
     */
    private function shouldParseAst(string $relativePath): bool
    {
        // 只解析 PHP 文件
        if (!str_ends_with($relativePath, '.php')) {
            return false;
        }

        // 跳过所有 vendor 文件
        if (str_contains($relativePath, 'vendor/')) {
            $this->logger->debug('Skipping vendor file', ['file' => $relativePath]);

            return false;
        }

        // 跳过 autoload 文件
        if ('autoload.php' === basename($relativePath)) {
            $this->logger->debug('Skipping autoload file', ['file' => $relativePath]);

            return false;
        }

        return true;
    }

    /** @param array<Node> $ast */
    private function detectFileType(array $ast): string
    {
        return $this->detectFileTypeRecursive($ast);
    }

    /** @param array<Node> $nodes */
    private function detectFileTypeRecursive(array $nodes): string
    {
        foreach ($nodes as $node) {
            $nodeType = $this->getNodeType($node);
            if (null !== $nodeType) {
                return $nodeType;
            }

            if ($node instanceof Node\Stmt\Namespace_ && null !== $node->stmts && [] !== $node->stmts) {
                $type = $this->detectFileTypeRecursive($node->stmts);
                if ('script' !== $type) {
                    return $type;
                }
            }
        }

        return 'script';
    }

    private function getNodeType(Node $node): ?string
    {
        if ($node instanceof Node\Stmt\Class_) {
            return 'class';
        }
        if ($node instanceof Node\Stmt\Interface_) {
            return 'interface';
        }
        if ($node instanceof Node\Stmt\Trait_) {
            return 'trait';
        }

        return null;
    }

    /** @param array<Node> $ast */
    private function extractClassName(array $ast): ?string
    {
        return $this->extractClassNameRecursive($ast);
    }

    /** @param array<Node> $nodes */
    private function extractClassNameRecursive(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            $className = $this->extractClassNameFromNode($node);
            if (null !== $className) {
                return $className;
            }

            $className = $this->extractClassNameFromNamespace($node);
            if (null !== $className) {
                return $className;
            }
        }

        return null;
    }

    private function extractClassNameFromNamespace(Node $node): ?string
    {
        if ($node instanceof Node\Stmt\Namespace_ && null !== $node->stmts && [] !== $node->stmts) {
            return $this->extractClassNameRecursive($node->stmts);
        }

        return null;
    }

    private function extractClassNameFromNode(Node $node): ?string
    {
        if (!$this->isClassLikeNode($node)) {
            return null;
        }

        // Check if node has a name property and it's an Identifier
        if (property_exists($node, 'name') && $node->name instanceof Node\Identifier) {
            return $node->name->toString();
        }

        return null;
    }

    private function isClassLikeNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_;
    }

    /** @param array<Node> $ast */
    private function extractNamespace(array $ast): ?string
    {
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                return null !== $node->name ? $node->name->toString() : null;
            }
        }

        return null;
    }

    private function getRelativePath(string $path): string
    {
        $realPath = realpath($path);
        $realRootPath = realpath($this->rootPath);

        if (false === $realPath || false === $realRootPath) {
            // If realpath fails, fallback to original path
            return $path;
        }

        if (0 === strpos($realPath, $realRootPath)) {
            return substr($realPath, strlen($realRootPath) + 1);
        }

        return $realPath;
    }
}
