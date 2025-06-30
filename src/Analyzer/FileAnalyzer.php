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
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->storage = $storage;
        $this->logger = $logger;
        $this->rootPath = rtrim($rootPath, '/');
    }

    public function analyzeFile(string $filePath): void
    {
        $this->logger->info('Analyzing file', ['file' => $filePath]);

        if (!file_exists($filePath)) {
            throw new FileAnalysisException("File not found: $filePath");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new FileAnalysisException("Failed to read file: $filePath");
        }

        $relativePath = $this->getRelativePath($filePath);
        
        // 判断是否需要解析 AST
        $shouldParseAst = $this->shouldParseAst($relativePath);
        
        try {
            $fileType = null;
            $namespace = null;
            $className = null;
            $fullClassName = null;
            $ast = null;

            // 如果需要解析 AST
            if ($shouldParseAst) {
                $ast = $this->parser->parse($content);
                if ($ast === null || empty($ast)) {
                    $this->logger->warning('Empty AST for file', ['file' => $filePath]);
                    return;
                }

                // 先使用 NameResolver 解析所有名称为 FQCN
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new NameResolver());
                $ast = $traverser->traverse($ast);

                $fileType = $this->detectFileType($ast);
                $namespace = $this->extractNamespace($ast);
                $className = $this->extractClassName($ast);
                
                // Build fully qualified class name
                if ($className !== null) {
                    $fullClassName = $namespace !== null ? $namespace . '\\' . $className : $className;
                }
            }
            
            // 添加文件到存储
            $fileId = $this->storage->addFile(
                $relativePath,
                $content,
                $fileType
            );

            // 如果需要解析 AST，存储 AST 并分析依赖
            if ($shouldParseAst && $ast !== null) {
                // 存储 AST
                $this->storage->storeAst($fileId, $ast);

                // 分析符号和依赖（现在所有名称都是 FQCN）
                $visitor = new AnalysisVisitor($this->storage, $fileId, $namespace, $filePath);
                $traverser = new NodeTraverser();
                $traverser->addVisitor($visitor);
                $traverser->traverse($ast);
                
                // 如果有类名，也要添加到符号表
                if ($fullClassName !== null) {
                    $this->storage->addSymbol($fileId, 'class', $className, $fullClassName, $namespace);
                }

                $this->logger->info('File analyzed successfully', [
                    'file' => $filePath,
                    'symbols' => $visitor->getSymbolCount(),
                    'dependencies' => $visitor->getDependencyCount(),
                    'ast_stored' => true,
                ]);
            } else {
                $this->logger->info('File stored without AST parsing', [
                    'file' => $filePath,
                    'is_vendor' => str_contains($relativePath, '/vendor/'),
                ]);
            }
        } catch (Error $e) {
            $this->logger->error('Parse error in file', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw new FileAnalysisException("Parse error in $filePath: " . $e->getMessage(), 0, $e);
        }
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
        
        return true;
    }

    private function detectFileType(array $ast): string
    {
        return $this->detectFileTypeRecursive($ast);
    }

    private function detectFileTypeRecursive(array $nodes): string
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Class_) {
                return 'class';
            } elseif ($node instanceof Node\Stmt\Interface_) {
                return 'interface';
            } elseif ($node instanceof Node\Stmt\Trait_) {
                return 'trait';
            } elseif ($node instanceof Node\Stmt\Namespace_) {
                // 递归检查命名空间内的节点
                if (!empty($node->stmts)) {
                    $type = $this->detectFileTypeRecursive($node->stmts);
                    if ($type !== 'script') {
                        return $type;
                    }
                }
            }
        }
        return 'script';
    }

    private function extractClassName(array $ast): ?string
    {
        return $this->extractClassNameRecursive($ast);
    }
    
    private function extractClassNameRecursive(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            if (($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Interface_ || $node instanceof Node\Stmt\Trait_) && $node->name !== null) {
                return $node->name->toString();
            } elseif ($node instanceof Node\Stmt\Namespace_ && !empty($node->stmts)) {
                $className = $this->extractClassNameRecursive($node->stmts);
                if ($className !== null) {
                    return $className;
                }
            }
        }
        return null;
    }
    
    private function extractNamespace(array $ast): ?string
    {
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                return $node->name !== null ? $node->name->toString() : null;
            }
        }
        return null;
    }

    private function getRelativePath(string $path): string
    {
        $path = realpath($path);
        $rootPath = realpath($this->rootPath);
        
        if (strpos($path, $rootPath) === 0) {
            return substr($path, strlen($rootPath) + 1);
        }
        return $path;
    }
}