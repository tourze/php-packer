<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use PhpPacker\Storage\SqliteStorage;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
            throw new RuntimeException("File not found: $filePath");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: $filePath");
        }

        try {
            $ast = $this->parser->parse($content);
            if ($ast === null || empty($ast)) {
                $this->logger->warning('Empty AST for file', ['file' => $filePath]);
                return;
            }

            $fileType = $this->detectFileType($ast);
            $namespace = $this->extractNamespace($ast);
            $className = $this->extractClassName($ast);
            
            // Build fully qualified class name
            $fullClassName = null;
            if ($className !== null) {
                $fullClassName = $namespace !== null ? $namespace . '\\' . $className : $className;
            }
            
            $fileId = $this->storage->addFile(
                $this->getRelativePath($filePath),
                $content,
                $fileType,
                $fullClassName
            );

            $visitor = new AnalysisVisitor($this->storage, $fileId, $namespace, $filePath);
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver()); // 自动解析名称为 FQCN
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            $this->logger->info('File analyzed successfully', [
                'file' => $filePath,
                'symbols' => $visitor->getSymbolCount(),
                'dependencies' => $visitor->getDependencyCount(),
            ]);
        } catch (Error $e) {
            $this->logger->error('Parse error in file', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Parse error in $filePath: " . $e->getMessage(), 0, $e);
        }
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

class AnalysisVisitor extends NodeVisitorAbstract
{
    private SqliteStorage $storage;
    private int $fileId;
    private ?string $currentNamespace;
    // Removed unused properties: filePath, useStatements
    private int $symbolCount = 0;
    private int $dependencyCount = 0;

    /**
     * @phpstan-ignore-next-line constructor.unusedParameter
     */
    public function __construct(SqliteStorage $storage, int $fileId, ?string $namespace, string $filePath)
    {
        $this->storage = $storage;
        $this->fileId = $fileId;
        $this->currentNamespace = $namespace;
        // Removed: filePath assignment
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name !== null ? $node->name->toString() : null;
            // Reset use statements tracking
        } elseif ($node instanceof Node\Stmt\Use_) {
            $this->processUseStatement($node);
        } elseif ($node instanceof Node\Stmt\GroupUse) {
            $this->processGroupUseStatement($node);
        } elseif ($node instanceof Node\Stmt\Class_) {
            $this->processClass($node);
        } elseif ($node instanceof Node\Stmt\Interface_) {
            $this->processInterface($node);
        } elseif ($node instanceof Node\Stmt\Trait_) {
            $this->processTrait($node);
        } elseif ($node instanceof Node\Stmt\Function_) {
            $this->processFunction($node);
        } elseif ($node instanceof Node\Expr\Include_) {
            $this->processInclude($node);
        } elseif ($node instanceof Node\Expr\New_) {
            $this->processNewInstance($node);
        } elseif ($node instanceof Node\Expr\StaticCall ||
                  $node instanceof Node\Expr\ClassConstFetch) {
            $this->processStaticReference($node);
        }

        return null;
    }

    private function processClass(Node\Stmt\Class_ $node): void
    {
        if ($node->name === null) {
            return;
        }

        $className = $node->name->toString();
        $fqn = $this->buildFqn($className);
        
        $this->storage->addSymbol(
            $this->fileId,
            'class',
            $className,
            $fqn,
            $this->currentNamespace,
            $this->getVisibility($node)
        );
        $this->symbolCount++;

        if ($node->extends instanceof Node\Name) {
            $fqcn = $node->extends->toString(); // NameResolver 已经处理过了
            $this->storage->addDependency([
                'source_file_id' => $this->fileId,
                'dependency_type' => 'extends',
                'target_symbol' => $fqcn,
                'line_number' => $node->getLine(),
            ]);
            $this->dependencyCount++;
        }

        foreach ($node->implements as $interface) {
            $fqcn = $interface->toString(); // NameResolver 已经处理过了
            $this->storage->addDependency([
                'source_file_id' => $this->fileId,
                'dependency_type' => 'implements',
                'target_symbol' => $fqcn,
                'line_number' => $node->getLine(),
            ]);
            $this->dependencyCount++;
        }

        foreach ($node->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $fqcn = $trait->toString(); // NameResolver 已经处理过了
                $this->storage->addDependency([
                    'source_file_id' => $this->fileId,
                    'dependency_type' => 'use_trait',
                    'target_symbol' => $fqcn,
                    'line_number' => $traitUse->getLine(),
                ]);
                $this->dependencyCount++;
            }
        }
    }

    private function buildFqn(string $name): string
    {
        return $this->currentNamespace !== null ? $this->currentNamespace . '\\' . $name : $name;
    }


    private function getVisibility(Node\Stmt\Class_ $node): string
    {
        if ($node->isAbstract()) {
            return 'abstract';
        } elseif ($node->isFinal()) {
            return 'final';
        }
        return 'public';
    }


    private function processInterface(Node\Stmt\Interface_ $node): void
    {
        $interfaceName = $node->name->toString();
        $fqn = $this->buildFqn($interfaceName);

        $this->storage->addSymbol(
            $this->fileId,
            'interface',
            $interfaceName,
            $fqn,
            $this->currentNamespace
        );
        $this->symbolCount++;

        foreach ($node->extends as $extend) {
            $fqcn = $extend->toString(); // NameResolver 已经处理过了
            $this->storage->addDependency([
                'source_file_id' => $this->fileId,
                'dependency_type' => 'extends',
                'target_symbol' => $fqcn,
                'line_number' => $node->getLine(),
            ]);
            $this->dependencyCount++;
        }
    }

    private function processTrait(Node\Stmt\Trait_ $node): void
    {
        $traitName = $node->name->toString();
        $fqn = $this->buildFqn($traitName);

        $this->storage->addSymbol(
            $this->fileId,
            'trait',
            $traitName,
            $fqn,
            $this->currentNamespace
        );
        $this->symbolCount++;
    }

    private function processFunction(Node\Stmt\Function_ $node): void
    {
        $functionName = $node->name->toString();
        $fqn = $this->currentNamespace !== null ? $this->currentNamespace . '\\' . $functionName : $functionName;

        $this->storage->addSymbol(
            $this->fileId,
            'function',
            $functionName,
            $fqn,
            $this->currentNamespace
        );
        $this->symbolCount++;
    }

    private function processInclude(Node\Expr\Include_ $node): void
    {
        $type = match ($node->type) {
            Node\Expr\Include_::TYPE_INCLUDE => 'include',
            Node\Expr\Include_::TYPE_INCLUDE_ONCE => 'include_once',
            Node\Expr\Include_::TYPE_REQUIRE => 'require',
            Node\Expr\Include_::TYPE_REQUIRE_ONCE => 'require_once',
            default => 'include',
        };

        $context = $this->extractIncludeContext($node);
        $isConditional = $this->isConditionalInclude($node);

        $this->storage->addDependency([
            'source_file_id' => $this->fileId,
            'dependency_type' => $type,
            'line_number' => $node->getLine(),
            'is_conditional' => $isConditional,
            'context' => $context,
        ]);
        $this->dependencyCount++;
    }

    private function extractIncludeContext(Node\Expr\Include_ $node): string
    {
        if ($node->expr instanceof Node\Scalar\String_) {
            return $node->expr->value;
        } elseif ($node->expr instanceof Node\Expr\BinaryOp\Concat) {
            // 尝试解析简单的 __DIR__ 连接
            $resolved = $this->resolveConcatExpression($node->expr);
            return $resolved !== null ? $resolved : 'dynamic';
        }
        return 'complex';
    }

    private function resolveConcatExpression(Node\Expr\BinaryOp\Concat $node): ?string
    {
        $left = $this->resolveExpressionValue($node->left);
        $right = $this->resolveExpressionValue($node->right);
        
        if ($left !== null && $right !== null) {
            return $left . $right;
        }
        
        return null;
    }

    private function resolveExpressionValue(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        } elseif ($expr instanceof Node\Scalar\MagicConst\Dir) {
            // 对于 __DIR__，我们返回相对目录标记
            return '__DIR__';
        } elseif ($expr instanceof Node\Expr\BinaryOp\Concat) {
            return $this->resolveConcatExpression($expr);
        }
        
        return null;
    }

    private function isConditionalInclude(Node $node): bool
    {
        // php-parser 的 parent 属性需要手动设置，我们改用其他方法检测
        // 简化的条件检测：如果在代码中间位置且不是文件开始，可能是条件的
        $line = $node->getLine();
        
        // 获取文件的总行数来判断位置（这是一个简化的启发式方法）
        // 在实际应用中，可以通过遍历整个AST来设置parent属性
        
        // 暂时使用行号作为简单的条件判断
        // 如果不是在文件开始的几行，可能是条件的
        return $line > 5; // 简化的条件检测
    }

    private function processNewInstance(Node\Expr\New_ $node): void
    {
        if ($node->class instanceof Node\Name) {
            $fqcn = $node->class->toString(); // NameResolver 已经处理过了
            $this->storage->addDependency([
                'source_file_id' => $this->fileId,
                'dependency_type' => 'use_class',
                'target_symbol' => $fqcn,
                'line_number' => $node->getLine(),
            ]);
            $this->dependencyCount++;
        } elseif ($node->class instanceof Node\Stmt\Class_) {
            // 处理匿名类
            $this->processAnonymousClass($node->class, $node->getLine());
        }
    }

    private function processAnonymousClass(Node\Stmt\Class_ $class, int $line): void
    {
        // 处理匿名类的继承
        if ($class->extends !== null) {
            $fqcn = $class->extends->toString(); // NameResolver 已经处理过了
            $this->storage->addDependency([
                'source_file_id' => $this->fileId,
                'dependency_type' => 'extends',
                'target_symbol' => $fqcn,
                'line_number' => $line,
            ]);
            $this->dependencyCount++;
        }

        // 处理匿名类的接口实现
        foreach ($class->implements as $interface) {
            $fqcn = $interface->toString(); // NameResolver 已经处理过了
            $this->storage->addDependency([
                'source_file_id' => $this->fileId,
                'dependency_type' => 'implements',
                'target_symbol' => $fqcn,
                'line_number' => $line,
            ]);
            $this->dependencyCount++;
        }

        // 处理匿名类的 trait 使用
        foreach ($class->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $fqcn = $trait->toString(); // NameResolver 已经处理过了
                $this->storage->addDependency([
                    'source_file_id' => $this->fileId,
                    'dependency_type' => 'use_trait',
                    'target_symbol' => $fqcn,
                    'line_number' => $line,
                ]);
                $this->dependencyCount++;
            }
        }
    }

    private function processStaticReference(Node $node): void
    {
        $className = null;
        $classNode = null;

        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
            $className = $node->class->toString();
            $classNode = $node->class;
        } elseif ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
            $className = $node->class->toString();
            $classNode = $node->class;
        }

        if ($className !== null && !in_array($className, ['self', 'static', 'parent']) && $classNode !== null) {
            $fqcn = $classNode->toString(); // NameResolver 已经处理过了
            $this->storage->addDependency([
                'source_file_id' => $this->fileId,
                'dependency_type' => 'use_class',
                'target_symbol' => $fqcn,
                'line_number' => $node->getLine(),
            ]);
            $this->dependencyCount++;
        }
    }

    public function getSymbolCount(): int
    {
        return $this->symbolCount;
    }

    public function getDependencyCount(): int
    {
        return $this->dependencyCount;
    }

    private function processUseStatement(Node\Stmt\Use_ $node): void
    {
        foreach ($node->uses as $use) {
            if ($use instanceof Node\Stmt\UseUse) {
                $fullName = $use->name->toString();
                $alias = $use->alias !== null ? $use->alias->toString() : $use->name->getLast();
                // Track use statement: $alias => $fullName
                
                // 只记录类导入的 use 依赖
                if ($node->type === Node\Stmt\Use_::TYPE_NORMAL) {
                    $this->storage->addDependency([
                        'source_file_id' => $this->fileId,
                        'dependency_type' => 'use_class',
                        'target_symbol' => $fullName,
                        'line_number' => $node->getLine(),
                    ]);
                    $this->dependencyCount++;
                }
            }
        }
    }

    private function processGroupUseStatement(Node\Stmt\GroupUse $node): void
    {
        $prefix = $node->prefix->toString();
        
        foreach ($node->uses as $use) {
            if ($use instanceof Node\Stmt\UseUse) {
                $fullName = $prefix . '\\' . $use->name->toString();
                $alias = $use->alias !== null ? $use->alias->toString() : $use->name->getLast();
                // Track use statement: $alias => $fullName
                
                // 只记录类导入的 use 依赖
                if ($node->type === Node\Stmt\Use_::TYPE_NORMAL) {
                    $this->storage->addDependency([
                        'source_file_id' => $this->fileId,
                        'dependency_type' => 'use_class',
                        'target_symbol' => $fullName,
                        'line_number' => $node->getLine(),
                    ]);
                    $this->dependencyCount++;
                }
            }
        }
    }
}