<?php

declare(strict_types=1);

namespace PhpPacker\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * 移除已合并文件的require/include语句
 */
class RequireRemovalVisitor extends NodeVisitorAbstract
{
    private array $mergedFiles;
    private bool $removeAll;
    
    public function __construct(array $mergedFiles, bool $removeAll = false)
    {
        $this->mergedFiles = $mergedFiles;
        $this->removeAll = $removeAll;
    }
    
    public function leaveNode(Node $node)
    {
        // 处理 require/include 语句
        if ($node instanceof Node\Stmt\Expression &&
            $node->expr instanceof Node\Expr\Include_) {
            
            // 如果设置了移除所有，直接移除
            if ($this->removeAll) {
                return NodeTraverser::REMOVE_NODE;
            }
            
            // 检查是否是已合并的文件
            if ($node->expr->expr instanceof Node\Scalar\String_) {
                $requiredFile = $node->expr->expr->value;
                
                // 检查多种匹配方式
                foreach ($this->mergedFiles as $mergedFile) {
                    if ($requiredFile === $mergedFile ||
                        basename($requiredFile) === basename($mergedFile) ||
                        str_ends_with($mergedFile, $requiredFile) ||
                        str_ends_with($requiredFile, $mergedFile)) {
                        // 跳过已合并的文件
                        return NodeTraverser::REMOVE_NODE;
                    }
                }
            }
        }
        
        return null;
    }
}