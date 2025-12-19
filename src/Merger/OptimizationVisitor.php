<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * AST 优化访问器
 */
class OptimizationVisitor extends NodeVisitorAbstract
{
    private bool $collectMode = true;

    public function beforeTraverse(array $nodes)
    {
        // 第一遍遍历：收集使用的符号
        $this->collectMode = true;

        return null;
    }

    public function enterNode(Node $node)
    {
        if ($this->collectMode) {
            // 收集使用的符号 - 当前实现为空
            // 可以在这里添加符号收集逻辑
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        // 可以在这里实现删除未使用代码的逻辑
        // 但为了安全起见，暂时不删除任何代码
        return null;
    }
}
