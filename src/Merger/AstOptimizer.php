<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use Psr\Log\LoggerInterface;

class AstOptimizer
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * 优化合并后的 AST
     * @param array<int, Node> $ast
     * @return array<int, Node>
     */
    public function optimizeAst(array $ast): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new OptimizationVisitor());

        return $traverser->traverse($ast);
    }
}
