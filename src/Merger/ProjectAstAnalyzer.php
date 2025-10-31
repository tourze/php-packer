<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

class ProjectAstAnalyzer
{
    /**
     * @param array<Node> $ast
     * @return array<string>
     */
    public function extractDependencies(array $ast): array
    {
        $visitor = new DependencyExtractorVisitor();

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return array_unique($visitor->getDependencies());
    }

    /**
     * @param array<Node> $ast
     * @return array{classes: array<string>, functions: array<string>, constants: array<string>}
     */
    public function extractSymbols(array $ast): array
    {
        $visitor = $this->createSymbolExtractorVisitor();

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->getSymbols();
    }

    /**
     * @param array<Node> $ast
     * @return array{namespace: string, file_doc: string|null, classes: array<string, array{name: string, doc: string|null}>}
     */
    public function extractMetadata(array $ast, string $content): array
    {
        return [
            'namespace' => $this->extractNamespace($ast),
            'file_doc' => $this->extractFileDocComment($ast),
            'classes' => $this->extractClassInfo($ast),
        ];
    }

    /**
     * @param array<Node> $ast
     */
    public function extractNamespace(array $ast): string
    {
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                return null !== $node->name ? $node->name->toString() : '';
            }
        }

        return '';
    }

    private function createSymbolExtractorVisitor(): SymbolExtractorVisitor
    {
        return new SymbolExtractorVisitor();
    }

    /**
     * @param array<Node> $ast
     */
    private function extractFileDocComment(array $ast): ?string
    {
        if (count($ast) > 0) {
            $firstNode = $ast[0];
            $docComment = $firstNode->getDocComment();
            if (null !== $docComment) {
                return $docComment->getText();
            }
        }

        return null;
    }

    /**
     * @param array<Node> $ast
     * @return array<string, array{name: string, doc: string|null}>
     */
    private function extractClassInfo(array $ast): array
    {
        $classes = [];

        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $classes = array_merge($classes, $this->extractClassesFromNamespace($node));
            } elseif ($node instanceof Node\Stmt\Class_) {
                $classes = array_merge($classes, $this->extractClassData($node));
            }
        }

        return $classes;
    }

    /**
     * @return array<string, array{name: string, doc: string|null}>
     */
    private function extractClassesFromNamespace(Node\Stmt\Namespace_ $namespace): array
    {
        $classes = [];

        foreach ($namespace->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Class_) {
                $classes = array_merge($classes, $this->extractClassData($stmt));
            }
        }

        return $classes;
    }

    /**
     * @return array<string, array{name: string, doc: string|null}>
     */
    private function extractClassData(Node\Stmt\Class_ $class): array
    {
        if (null === $class->name) {
            return [];
        }

        $className = $class->name->toString();

        return [
            $className => [
                'name' => $className,
                'doc' => null !== $class->getDocComment() ? $class->getDocComment()->getText() : null,
            ],
        ];
    }
}
