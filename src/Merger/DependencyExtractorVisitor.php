<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class DependencyExtractorVisitor extends NodeVisitorAbstract
{
    /** @var array<string> */
    private array $currentNamespace = [];

    /** @var array<string, string> */
    private array $useStatements = [];

    /** @var array<string> */
    private array $dependencies = [];

    public function __construct()
    {
    }

    /**
     * @return array<string>
     */
    public function getDependencies(): array
    {
        return array_unique($this->dependencies);
    }

    public function enterNode(Node $node): ?int
    {
        $this->handleNamespace($node);
        $this->handleUseStatements($node);
        $this->handleTypeHints($node);
        $this->handleMethodReturnTypes($node);
        $this->handlePropertyTypes($node);
        $this->handleNewInstances($node);
        $this->handleStaticCalls($node);
        $this->handleInstanceofChecks($node);
        $this->handleFullyQualifiedNames($node);

        return null;
    }

    private function handleNamespace(Node $node): void
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = null !== $node->name ? [$node->name->toString()] : [];
            $this->useStatements = [];
        }
    }

    private function handleUseStatements(Node $node): void
    {
        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $this->useStatements[$use->getAlias()->toString()] = $use->name->toString();
                $this->dependencies[] = $use->name->toString();
            }
        }
    }

    private function handleTypeHints(Node $node): void
    {
        if ($node instanceof Node\Param && $node->type instanceof Node\Name) {
            $this->dependencies[] = $this->resolveClassName($node->type);
        }
    }

    private function handleMethodReturnTypes(Node $node): void
    {
        if ($node instanceof Node\Stmt\ClassMethod && $node->returnType instanceof Node\Name) {
            $this->dependencies[] = $this->resolveClassName($node->returnType);
        }
    }

    private function handlePropertyTypes(Node $node): void
    {
        if ($node instanceof Node\Stmt\Property && $node->type instanceof Node\Name) {
            $this->dependencies[] = $this->resolveClassName($node->type);
        }
    }

    private function handleNewInstances(Node $node): void
    {
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $this->dependencies[] = $this->resolveClassName($node->class);
        }
    }

    private function handleStaticCalls(Node $node): void
    {
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
            $this->dependencies[] = $this->resolveClassName($node->class);
        }
    }

    private function handleInstanceofChecks(Node $node): void
    {
        if ($node instanceof Node\Expr\Instanceof_ && $node->class instanceof Node\Name) {
            $this->dependencies[] = $this->resolveClassName($node->class);
        }
    }

    private function handleFullyQualifiedNames(Node $node): void
    {
        if ($node instanceof Node\Name\FullyQualified) {
            $this->dependencies[] = $node->toString();
        }
    }

    private function resolveClassName(Node\Name $name): string
    {
        $className = $name->toString();

        if ($name instanceof Node\Name\FullyQualified) {
            return $className;
        }

        if (isset($this->useStatements[$className])) {
            return $this->useStatements[$className];
        }

        if (str_starts_with($className, '\\')) {
            return ltrim($className, '\\');
        }

        if (count($this->currentNamespace) > 0) {
            return implode('\\', $this->currentNamespace) . '\\' . $className;
        }

        return $className;
    }
}
