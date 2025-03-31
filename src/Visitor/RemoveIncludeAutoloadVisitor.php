<?php

namespace PhpPacker\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class RemoveIncludeAutoloadVisitor extends NodeVisitorAbstract
{
    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Expression &&
            $node->expr instanceof Node\Expr\Include_ &&
            (
                ($node->expr->expr instanceof Node\Expr\BinaryOp\Concat &&
                    $node->expr->expr->right instanceof Node\Scalar\String_ &&
                    str_contains($node->expr->expr->right->value, 'vendor/autoload.php')
                ) ||
                ($node->expr->expr instanceof Node\Scalar\String_ &&
                    str_contains($node->expr->expr->value, 'vendor/autoload.php')
                )
            )
        ) {
            return NodeTraverser::REMOVE_NODE;
        }
        return null;
    }
}
