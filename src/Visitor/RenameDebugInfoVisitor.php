<?php

namespace PhpPacker\Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class RenameDebugInfoVisitor extends NodeVisitorAbstract
{
    const NEW_NAME = 'not_support_in_kphp__debugInfo';

    public function leaveNode(Node $node)
    {
        // 检查节点是否是方法名称，且名称为 __debugInfo
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier && $node->name->name === '__debugInfo') {
            // 修改方法名为 not_support_in_kphp__debugInfo
            $node->name->name = self::NEW_NAME;
        }

        // 如果是类方法声明，也进行替换
        if ($node instanceof Node\Stmt\ClassMethod && $node->name->name === '__debugInfo') {
            $node->name->name = self::NEW_NAME;
        }

        return null; // 返回 null 表示不修改 AST
    }
}
