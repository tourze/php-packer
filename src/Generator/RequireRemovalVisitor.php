<?php

namespace PhpPacker\Generator;

/**
 * 移除已合并文件的 require 语句
 */
class RequireRemovalVisitor extends \PhpParser\NodeVisitorAbstract
{
    private array $mergedFiles;

    public function __construct(array $mergedFiles)
    {
        $this->mergedFiles = $mergedFiles;
    }

    public function leaveNode(\PhpParser\Node $node)
    {
        // 移除已合并文件的 require/include 语句
        if ($node instanceof \PhpParser\Node\Stmt\Expression &&
            $node->expr instanceof \PhpParser\Node\Expr\Include_) {
            
            // 获取 require/include 的路径
            $requiredPath = null;
            
            if ($node->expr->expr instanceof \PhpParser\Node\Scalar\String_) {
                $requiredPath = $node->expr->expr->value;
            } elseif ($node->expr->expr instanceof \PhpParser\Node\Expr\BinaryOp\Concat) {
                // 处理类似 __DIR__ . '/vendor/autoload.php' 的情况
                $right = $node->expr->expr->right;
                if ($right instanceof \PhpParser\Node\Scalar\String_) {
                    $requiredPath = $right->value;
                }
            }
            
            if ($requiredPath !== null) {
                // 始终移除 vendor autoload 相关的 require
                if (str_contains($requiredPath, 'vendor/autoload.php') || 
                    str_contains($requiredPath, 'autoload.php')) {
                    return [];
                }
                
                // 检查是否匹配任何已合并的文件
                foreach ($this->mergedFiles as $mergedFile) {
                    // 完全匹配
                    if ($requiredPath === $mergedFile) {
                        return [];
                    }
                    // 检查 basename 匹配
                    if (basename($requiredPath) === basename($mergedFile)) {
                        return [];
                    }
                    // 检查路径是否以合并文件结尾（处理相对路径）
                    if (str_ends_with($mergedFile, $requiredPath) || str_ends_with($requiredPath, $mergedFile)) {
                        return [];
                    }
                }
            }
        }

        return null;
    }
}
