<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer\Processor;

use PhpPacker\Storage\SqliteStorage;
use PhpParser\Node;

class DependencyProcessor
{
    private SqliteStorage $storage;

    private int $fileId;

    private int $dependencyCount = 0;

    private bool $inConditionalContext = false;

    private string $currentNamespace = '';

    /** @var array<string, string> */
    private array $useStatements = [];

    /** @var array<array{type: string, fqcn: string, line: int, conditional: bool}> */
    private array $allDependencies = [];

    public function __construct(SqliteStorage $storage, int $fileId)
    {
        $this->storage = $storage;
        $this->fileId = $fileId;
    }

    public function processClassDependencies(Node\Stmt\Class_ $node): void
    {
        $this->processClassExtends($node);
        $this->processClassImplements($node);
        $this->processClassTraits($node);
    }

    private function processClassExtends(Node\Stmt\Class_ $node): void
    {
        if ($node->extends instanceof Node\Name) {
            $fqcn = $node->extends->toString();
            $this->addDependency('extends', $fqcn, $node->getStartLine());
        }
    }

    private function processClassImplements(Node\Stmt\Class_ $node): void
    {
        foreach ($node->implements as $interface) {
            $fqcn = $interface->toString();
            $this->addDependency('implements', $fqcn, $node->getStartLine());
        }
    }

    private function processClassTraits(Node\Stmt\Class_ $node): void
    {
        foreach ($node->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $fqcn = $trait->toString();
                $this->addDependency('use_trait', $fqcn, $traitUse->getStartLine());
            }
        }
    }

    public function processInterfaceExtends(Node\Stmt\Interface_ $node): void
    {
        foreach ($node->extends as $extend) {
            $fqcn = $extend->toString();
            $this->addDependency('extends', $fqcn, $node->getStartLine());
        }
    }

    public function processNewInstance(Node\Expr\New_ $node): void
    {
        if ($node->class instanceof Node\Name) {
            $fqcn = $node->class->toString();
            $this->addDependency('use_class', $fqcn, $node->getStartLine());
        } elseif ($node->class instanceof Node\Stmt\Class_) {
            // 对于匿名类，只处理其依赖关系，不添加 use_class 记录
            $this->processAnonymousClass($node->class, $node->getStartLine());
        }
    }

    private function processAnonymousClass(Node\Stmt\Class_ $class, int $line): void
    {
        if (null !== $class->extends) {
            $fqcn = $class->extends->toString();
            $this->addDependency('extends', $fqcn, $line);
        }

        foreach ($class->implements as $interface) {
            $fqcn = $interface->toString();
            $this->addDependency('implements', $fqcn, $line);
        }

        foreach ($class->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $fqcn = $trait->toString();
                $this->addDependency('use_trait', $fqcn, $line);
            }
        }
    }

    public function processStaticReference(Node $node): void
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

        if (null !== $className && !in_array($className, ['self', 'static', 'parent'], true)) {
            $fqcn = $classNode->toString();
            $this->addDependency('use_class', $fqcn, $node->getStartLine());
        }
    }

    public function processUseStatement(Node\Stmt\Use_ $node): void
    {
        foreach ($node->uses as $use) {
            $fullName = $use->name->toString();

            if (Node\Stmt\Use_::TYPE_NORMAL === $node->type) {
                $this->addDependency('use_class', $fullName, $node->getStartLine());
            }
        }
    }

    public function processGroupUseStatement(Node\Stmt\GroupUse $node): void
    {
        $prefix = $node->prefix->toString();

        foreach ($node->uses as $use) {
            $fullName = $prefix . '\\' . $use->name->toString();

            if (Node\Stmt\Use_::TYPE_NORMAL === $node->type) {
                $this->addDependency('use_class', $fullName, $node->getStartLine());
            }
        }
    }

    public function setConditionalContext(bool $conditional): void
    {
        $this->inConditionalContext = $conditional;
    }

    public function getDependencyCount(): int
    {
        return $this->dependencyCount;
    }

    /**
     * 处理节点并分析依赖关系
     */
    public function process(Node $node): void
    {
        if ($this->isNamespaceNode($node)) {
            $this->handleNamespaceNode($node);
        } elseif ($this->isUseStatement($node)) {
            $this->handleUseStatement($node);
        } elseif ($this->isDefinitionNode($node)) {
            $this->handleDefinitionNode($node);
        } elseif ($this->isExpressionNode($node)) {
            $this->handleExpressionNode($node);
        }
    }

    private function isNamespaceNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Namespace_;
    }

    private function isUseStatement(Node $node): bool
    {
        return $node instanceof Node\Stmt\Use_ || $node instanceof Node\Stmt\GroupUse;
    }

    private function isDefinitionNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Interface_;
    }

    private function isExpressionNode(Node $node): bool
    {
        return $node instanceof Node\Expr\New_
            || $node instanceof Node\Expr\StaticCall
            || $node instanceof Node\Expr\ClassConstFetch;
    }

    private function handleNamespaceNode(Node $node): void
    {
        if (!$node instanceof Node\Stmt\Namespace_) {
            return;
        }
        $this->setCurrentNamespace(null !== $node->name ? $node->name->toString() : '');
    }

    private function handleUseStatement(Node $node): void
    {
        if ($node instanceof Node\Stmt\Use_) {
            $this->processUseStatement($node);
        } elseif ($node instanceof Node\Stmt\GroupUse) {
            $this->processGroupUseStatement($node);
        }
    }

    private function handleDefinitionNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->processClassDependencies($node);
        } elseif ($node instanceof Node\Stmt\Interface_) {
            $this->processInterfaceExtends($node);
        }
    }

    private function handleExpressionNode(Node $node): void
    {
        if ($node instanceof Node\Expr\New_) {
            $this->processNewInstance($node);
        } elseif ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\ClassConstFetch) {
            $this->processStaticReference($node);
        }
    }

    /**
     * 设置当前命名空间
     */
    public function setCurrentNamespace(string $namespace): void
    {
        $this->currentNamespace = $namespace;
    }

    /**
     * 获取当前命名空间
     */
    public function getCurrentNamespace(): string
    {
        return $this->currentNamespace;
    }

    /**
     * 添加 use 语句
     */
    public function addUse(string $alias, string $fullName): void
    {
        $this->useStatements[$alias] = $fullName;
    }

    /**
     * 解析类名到完全限定名
     */
    public function resolveClass(string $className): string
    {
        // 如果已经是完全限定名
        if (str_starts_with($className, '\\')) {
            return ltrim($className, '\\');
        }

        // 检查是否是别名
        if (isset($this->useStatements[$className])) {
            return $this->useStatements[$className];
        }

        // 检查是否是别名的一部分
        $parts = explode('\\', $className);
        if (count($parts) > 1 && isset($this->useStatements[$parts[0]])) {
            $parts[0] = $this->useStatements[$parts[0]];

            return implode('\\', $parts);
        }

        // 如果有当前命名空间，加上命名空间前缀
        if ('' !== $this->currentNamespace) {
            return $this->currentNamespace . '\\' . $className;
        }

        return $className;
    }

    /**
     * 获取所有依赖关系
     */
    /** @return array<array{type: string, fqcn: string, line: int, conditional: bool}> */
    public function getAllDependencies(): array
    {
        return $this->allDependencies;
    }

    /**
     * 重置处理器状态
     */
    public function reset(): void
    {
        $this->dependencyCount = 0;
        $this->inConditionalContext = false;
        $this->currentNamespace = '';
        $this->useStatements = [];
        $this->allDependencies = [];
    }

    private function addDependency(string $type, string $fqcn, int $line): void
    {
        $resolvedName = $this->resolveClass($fqcn);

        $dependency = [
            'type' => $type,
            'fqcn' => $resolvedName,
            'line' => $line,
            'conditional' => $this->inConditionalContext,
        ];

        $this->allDependencies[] = $dependency;

        $this->storage->addDependency(
            $this->fileId,
            $type,
            $resolvedName,
            $line,
            $this->inConditionalContext
        );
        ++$this->dependencyCount;
    }
}
