<?php

namespace PhpPacker\Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * include / require 语法，目前无法支持。
 */
class RemoveIncludeVisitor extends NodeVisitorAbstract
{
    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\Include_) {
            return NodeVisitor::REMOVE_NODE;
        }
        return null;
    }
}
