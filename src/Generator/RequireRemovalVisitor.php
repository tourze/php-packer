<?php

namespace PhpPacker\Generator;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * 移除已合并文件的 require 语句
 */
class RequireRemovalVisitor extends NodeVisitorAbstract
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

    public function leaveNode(Node $node)
    {
        if (!$this->isRequireExpression($node)) {
            return null;
        }

        $requiredPath = $this->extractRequiredPath($node);
        if (null === $requiredPath) {
            return null;
        }

        if ($this->shouldRemoveRequire($requiredPath)) {
            return [];
        }

        return null;
    }

    private function isRequireExpression(Node $node): bool
    {
        return $node instanceof Node\Stmt\Expression
            && $node->expr instanceof Node\Expr\Include_;
    }

    private function extractRequiredPath(Node $node): ?string
    {
        if (!$node instanceof Node\Stmt\Expression) {
            return null;
        }

        $includeExpr = $node->expr;
        assert($includeExpr instanceof Node\Expr\Include_);
        $expr = $includeExpr->expr;

        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            return $this->extractPathFromConcat($expr);
        }

        return null;
    }

    private function extractPathFromConcat(Node\Expr\BinaryOp\Concat $concat): ?string
    {
        if ($concat->right instanceof Node\Scalar\String_) {
            return $concat->right->value;
        }

        return null;
    }

    private function shouldRemoveRequire(string $requiredPath): bool
    {
        // 始终移除 vendor autoload 相关的 require
        if ($this->isAutoloadPath($requiredPath)) {
            return true;
        }

        // 检查是否匹配任何已合并的文件
        return $this->matchesMergedFile($requiredPath);
    }

    private function isAutoloadPath(string $path): bool
    {
        return str_contains($path, 'vendor/autoload.php')
            || str_contains($path, 'autoload.php');
    }

    private function matchesMergedFile(string $requiredPath): bool
    {
        foreach ($this->mergedFiles as $mergedFile) {
            if ($this->pathsMatch($requiredPath, $mergedFile)) {
                return true;
            }
        }

        return false;
    }

    private function pathsMatch(string $requiredPath, string $mergedFile): bool
    {
        // 完全匹配
        if ($requiredPath === $mergedFile) {
            return true;
        }

        // 检查 basename 匹配
        if (basename($requiredPath) === basename($mergedFile)) {
            return true;
        }

        // 检查路径是否以合并文件结尾（处理相对路径）
        return str_ends_with($mergedFile, $requiredPath)
            || str_ends_with($requiredPath, $mergedFile);
    }
}
