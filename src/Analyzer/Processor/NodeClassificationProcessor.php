<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer\Processor;

use PhpParser\Node;

class NodeClassificationProcessor
{
    public function isNamespaceNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Namespace_;
    }

    public function isUseNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Use_;
    }

    public function isGroupUseNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\GroupUse;
    }

    public function isClassNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Class_;
    }

    public function isInterfaceNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Interface_;
    }

    public function isTraitNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Trait_;
    }

    public function isFunctionNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Function_;
    }

    public function isConditionalNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\ElseIf_
            || $node instanceof Node\Stmt\Else_
            || $node instanceof Node\Stmt\TryCatch;
    }

    public function isIncludeNode(Node $node): bool
    {
        return $node instanceof Node\Expr\Include_;
    }

    public function isNewInstanceNode(Node $node): bool
    {
        return $node instanceof Node\Expr\New_;
    }

    public function isStaticReferenceNode(Node $node): bool
    {
        return $node instanceof Node\Expr\StaticCall
            || $node instanceof Node\Expr\ClassConstFetch;
    }
}
