<?php

declare(strict_types=1);

namespace PhpPacker\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * 确保所有类名都使用FQCN，并移除use语句
 */
class FqcnTransformVisitor extends NodeVisitorAbstract
{
    /**
     * 移除use语句
     */
    public function leaveNode(Node $node)
    {
        // 移除所有use语句
        if ($node instanceof Node\Stmt\Use_ || 
            $node instanceof Node\Stmt\GroupUse) {
            return NodeTraverser::REMOVE_NODE;
        }
        
        return null;
    }
}