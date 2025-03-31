<?php

namespace PhpPacker\Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

class RemoveDeclareStatementVisitor extends NodeVisitorAbstract
{
    public function leaveNode(Node $node)
    {
        if (
            $node instanceof Node\Stmt\Declare_
        ) {
            return NodeVisitor::REMOVE_NODE;
        }
        return null;
    }
}
