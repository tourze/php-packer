<?php

declare(strict_types=1);

namespace PhpPacker\Generator;

use PhpParser\Node;

class ExecutionNodeExtractor
{
    /** @var array<int, string> */
    private array $mergedFiles;

    /**
     * @param array<int, string> $mergedFiles
     */
    public function __construct(array $mergedFiles)
    {
        $this->mergedFiles = $mergedFiles;
    }

    /**
     * @param array<int, Node> $ast
     * @return array<int, Node>
     */
    public function extract(array $ast): array
    {
        $result = [];
        foreach ($ast as $node) {
            $extracted = $this->extractFromNode($node);
            $result = array_merge($result, $extracted);
        }

        return $result;
    }

    /**
     * @return array<int, Node>
     */
    private function extractFromNode(Node $node): array
    {
        if ($this->shouldSkipNode($node)) {
            return [];
        }

        if ($node instanceof Node\Stmt\Namespace_) {
            return $this->handleNamespaceNode($node);
        }

        if ($this->isIncludeStatement($node)) {
            return $this->handleIncludeNode($node);
        }

        return [$node];
    }

    private function shouldSkipNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\Declare_
            || $node instanceof Node\Stmt\Use_
            || $node instanceof Node\Stmt\GroupUse;
    }

    /**
     * @return array<int, Node>
     */
    private function handleNamespaceNode(Node\Stmt\Namespace_ $node): array
    {
        if (null === $node->stmts || 0 === count($node->stmts)) {
            return [];
        }

        return $this->extract($node->stmts);
    }

    private function isIncludeStatement(Node $node): bool
    {
        return $node instanceof Node\Stmt\Expression
            && $node->expr instanceof Node\Expr\Include_;
    }

    /**
     * @return array<int, Node>
     */
    private function handleIncludeNode(Node $node): array
    {
        if (!$this->shouldKeepInclude($node)) {
            return [];
        }

        return [$node];
    }

    private function shouldKeepInclude(Node $node): bool
    {
        if (!$node instanceof Node\Stmt\Expression) {
            return true;
        }

        if (!$node->expr instanceof Node\Expr\Include_) {
            return true;
        }

        if (!$node->expr->expr instanceof Node\Scalar\String_) {
            return true;
        }

        $requiredFile = $node->expr->expr->value;

        return !in_array($requiredFile, $this->mergedFiles, true);
    }
}
