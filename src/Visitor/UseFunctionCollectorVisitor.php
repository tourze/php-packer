<?php

namespace PhpPacker\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeVisitorAbstract;

/**
 * 使用到的函数，也要列出来
 */
class UseFunctionCollectorVisitor extends NodeVisitorAbstract
{
    /** @var array<string> */
    private array $usedFunctions = [];

    private string|null $namespace = null;

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name;
        }

        // 处理函数调用
        if ($node instanceof Expr\FuncCall) {
            if ($node->name instanceof Node\Name) {
                $this->addFunction($node->name->toString());
            }
        }

        // 处理use语句
        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                if ($use->type === Use_::TYPE_FUNCTION) {
                    $this->addFunction($use->name->toString());
                }
            }
        }

        // PHP动态语言嘛，字符串也可能是一个函数
        if ($node instanceof Node\Scalar\String_) {
            $v = $node->value;
            // 我们只关注有namespace的函数喔
            if (str_contains($v, '\\') && function_exists($v)) {
                $this->addFunction($v);
            }
        }
    }

    private function addFunction(string $name): void
    {
        if ($this->namespace) {
            $newName = $this->namespace . '\\' . trim($name, '\\');
            if (function_exists($newName)) {
                $this->usedFunctions[] = $newName;
                $this->usedFunctions = array_values(array_unique($this->usedFunctions));
                return;
            }
        }

        $this->usedFunctions[] = $name;
        $this->usedFunctions = array_values(array_unique($this->usedFunctions));
    }

    public function getUsedFunctions(): array
    {
        return $this->usedFunctions;
    }
}
