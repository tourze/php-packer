<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class VendorAstOptimizer
{
    /**
     * @param array<Node> $ast
     * @return array{ast: array<Node>, stats: array<string, mixed>}
     */
    public function optimize(array $ast): array
    {
        $visitor = $this->createOptimizationVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $optimizedAst = $traverser->traverse($ast);

        return [
            'ast' => $optimizedAst,
            'stats' => $visitor->getStats(),
        ];
    }

    private function createOptimizationVisitor(): VendorOptimizationVisitor
    {
        return new VendorOptimizationVisitor();
    }
}
