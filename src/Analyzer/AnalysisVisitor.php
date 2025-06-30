<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use PhpPacker\Storage\SqliteStorage;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class AnalysisVisitor extends NodeVisitorAbstract
{
    private SqliteStorage $storage;
    private int $fileId;
    private ?string $currentNamespace;
    // Removed unused properties: filePath, useStatements
    private int $symbolCount = 0;
    private int $dependencyCount = 0;
    private bool $inConditionalContext = false;

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
        } elseif ($node instanceof Node\Stmt\If_ ||
            $node instanceof Node\Stmt\ElseIf_ ||
            $node instanceof Node\Stmt\Else_ ||
            $node instanceof Node\Stmt\TryCatch) {
            $this->inConditionalContext = true;
            // 不再这里递归分析，让正常的遍历处理
            // $this->analyzeConditionalBranches($node);
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
            $this->storage->addDependency(
                $this->fileId,
                'extends',
                $fqcn,
                $node->getLine(),
                $this->inConditionalContext
            );
            $this->dependencyCount++;
        }

        foreach ($node->implements as $interface) {
            $fqcn = $interface->toString(); // NameResolver 已经处理过了
            $this->storage->addDependency(
                $this->fileId,
                'implements',
                $fqcn,
                $node->getLine(),
                $this->inConditionalContext
            );
            $this->dependencyCount++;
        }

        foreach ($node->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $fqcn = $trait->toString(); // NameResolver 已经处理过了
                $this->storage->addDependency(
                    $this->fileId,
                    'use_trait',
                    $fqcn,
                    $traitUse->getLine(),
                    $this->inConditionalContext
                );
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
            $this->storage->addDependency(
                $this->fileId,
                'extends',
                $fqcn,
                $node->getLine(),
                $this->inConditionalContext
            );
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

        $this->storage->addDependency(
            $this->fileId,
            $type,
            null,
            $node->getLine(),
            $isConditional,
            $context
        );
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
        // 检查是否在条件语句中
        // 注意：这是一个简化的实现，理想情况下应该在 AST 遍历时追踪父节点
        return $this->inConditionalContext;
    }

    private function processNewInstance(Node\Expr\New_ $node): void
    {
        if ($node->class instanceof Node\Name) {
            $fqcn = $node->class->toString(); // NameResolver 已经处理过了
            $this->storage->addDependency(
                $this->fileId,
                'use_class',
                $fqcn,
                $node->getLine(),
                $this->inConditionalContext
            );
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
            $this->storage->addDependency(
                $this->fileId,
                'extends',
                $fqcn,
                $line,
                $this->inConditionalContext
            );
            $this->dependencyCount++;
        }

        // 处理匿名类的接口实现
        foreach ($class->implements as $interface) {
            $fqcn = $interface->toString(); // NameResolver 已经处理过了
            $this->storage->addDependency(
                $this->fileId,
                'implements',
                $fqcn,
                $line,
                $this->inConditionalContext
            );
            $this->dependencyCount++;
        }

        // 处理匿名类的 trait 使用
        foreach ($class->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $fqcn = $trait->toString(); // NameResolver 已经处理过了
                $this->storage->addDependency(
                    $this->fileId,
                    'use_trait',
                    $fqcn,
                    $line
                );
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
            $this->storage->addDependency(
                $this->fileId,
                'use_class',
                $fqcn,
                $node->getLine(),
                $this->inConditionalContext
            );
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
                // 由于 NameResolver 已经处理，name 应该已经是 FQCN
                $fullName = $use->name->toString();
                $alias = $use->alias !== null ? $use->alias->toString() : $use->name->getLast();

                // 只记录类导入的 use 依赖，使用 FQCN
                if ($node->type === Node\Stmt\Use_::TYPE_NORMAL) {
                    $this->storage->addDependency(
                        $this->fileId,
                        'use_class',
                        $fullName,
                        $node->getLine(),
                        $this->inConditionalContext
                    );
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
                    $this->storage->addDependency(
                        $this->fileId,
                        'use_class',
                        $fullName,
                        $node->getLine(),
                        $this->inConditionalContext
                    );
                    $this->dependencyCount++;
                }
            }
        }
    }

    // Removed unused analyzeConditionalBranches and analyzeNodeForIncludes methods
}
