<?php

namespace PhpPacker\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class RemoveNamespaceVisitor extends NodeVisitorAbstract
{
    public string|null $namespace = null;

    public function leaveNode(Node $node) {
        // 删除namespace声明
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name;

            if (empty($node->stmts)) {
                return NodeTraverser::REMOVE_NODE;
            }
            return $node->stmts;
        }

        // 转换类名
        if ($node instanceof Node\Stmt\ClassLike) {
            // 没有类名的话，说明可能是匿名类
            if (!$node->namespacedName) {
                return null;
            }
            $node->name = new Node\Identifier($this->getFormatName($node->namespacedName->toString()));
        }

        // 替换类引用
        if ($node instanceof Node\Name\FullyQualified) {
            //dump($node::class, $node->name);
            return new Node\Name($this->getFormatName($node->name));
        }

        return $node;
    }

    private function getFormatName(string $name): string
    {
        $name = trim($name, '\\');
        return str_replace('\\', '_', $name);
    }
}
