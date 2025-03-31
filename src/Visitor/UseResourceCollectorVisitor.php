<?php

namespace PhpPacker\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\NodeVisitorAbstract;

class UseResourceCollectorVisitor extends NodeVisitorAbstract
{
    private string $currentFile;
    private array $resources = [];

    public function __construct(
        string $currentFile,
    )
    {
        $this->currentFile = realpath($currentFile);
    }

    public function getResources(): array
    {
        return $this->resources;
    }

    // 处理文件读取相关的函数调用
    public function enterNode(Node $node)
    {
        if ($node instanceof Concat) {
            // __DIR__ . "/mime.types";
            if ($node->left instanceof Node\Scalar\MagicConst\Dir && $node->right instanceof Node\Scalar\String_) {
                $fileName = dirname($this->currentFile) . $node->right->value;
                dump($fileName);
                $this->resources[] = $fileName;
            }
        }
    }
}
