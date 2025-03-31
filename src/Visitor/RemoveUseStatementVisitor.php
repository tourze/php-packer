<?php

namespace PhpPacker\Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

class RemoveUseStatementVisitor extends NodeVisitorAbstract
{
    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Use_ ||
            $node instanceof Node\Stmt\UseUse ||
            $node instanceof Node\Stmt\GroupUse ||
            $node instanceof Node\Stmt\Declare_
        ) {
            return NodeVisitor::REMOVE_NODE;
        }
        return null;
    }
}
