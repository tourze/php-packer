<?php

declare(strict_types=1);

namespace PhpPacker\Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * 移除已合并文件的require/include语句
 */
class RequireRemovalVisitor extends NodeVisitorAbstract
{
    /** @var array<string> */
    private array $mergedFiles;

    private bool $removeAll;

    /** @param array<string> $mergedFiles */
    public function __construct(array $mergedFiles, bool $removeAll = false)
    {
        $this->mergedFiles = $mergedFiles;
        $this->removeAll = $removeAll;
    }

    public function leaveNode(Node $node)
    {
        if (!$this->isIncludeExpression($node)) {
            return null;
        }

        return $this->processIncludeNode($node);
    }

    private function processIncludeNode(Node $node): ?int
    {
        if ($this->removeAll) {
            return NodeVisitorAbstract::REMOVE_NODE;
        }

        return $this->shouldRemoveInclude($node) ? NodeVisitorAbstract::REMOVE_NODE : null;
    }

    private function isIncludeExpression(Node $node): bool
    {
        return $node instanceof Node\Stmt\Expression
            && $node->expr instanceof Node\Expr\Include_;
    }

    private function shouldRemoveInclude(Node $node): bool
    {
        $requiredFile = $this->extractRequiredFile($node);
        if (null === $requiredFile) {
            return false;
        }

        return $this->isFileMerged($requiredFile);
    }

    private function extractRequiredFile(Node $node): ?string
    {
        if (!$node instanceof Node\Stmt\Expression) {
            return null;
        }

        $includeExpr = $node->expr;
        assert($includeExpr instanceof Node\Expr\Include_);

        if (!$includeExpr->expr instanceof Node\Scalar\String_) {
            return null;
        }

        return $includeExpr->expr->value;
    }

    private function isFileMerged(string $requiredFile): bool
    {
        foreach ($this->mergedFiles as $mergedFile) {
            if ($this->filesMatch($requiredFile, $mergedFile)) {
                return true;
            }
        }

        return false;
    }

    private function filesMatch(string $requiredFile, string $mergedFile): bool
    {
        return $this->isExactMatch($requiredFile, $mergedFile)
            || $this->isBasenameMatch($requiredFile, $mergedFile)
            || $this->isPathEndMatch($requiredFile, $mergedFile);
    }

    private function isExactMatch(string $requiredFile, string $mergedFile): bool
    {
        return $requiredFile === $mergedFile;
    }

    private function isBasenameMatch(string $requiredFile, string $mergedFile): bool
    {
        return basename($requiredFile) === basename($mergedFile);
    }

    private function isPathEndMatch(string $requiredFile, string $mergedFile): bool
    {
        return str_ends_with($mergedFile, $requiredFile)
            || str_ends_with($requiredFile, $mergedFile);
    }
}
