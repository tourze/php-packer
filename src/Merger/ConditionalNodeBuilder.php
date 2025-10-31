<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpParser\Node;
use PhpParser\PrettyPrinter;

class ConditionalNodeBuilder
{
    private PrettyPrinter\Standard $printer;

    public function __construct()
    {
        $this->printer = new PrettyPrinter\Standard();
    }

    /**
     * @param array<int, Node\Stmt> $functions
     */
    public function createConditionalNode(array $functions): ?Node
    {
        if (0 === count($functions)) {
            return null;
        }

        if (2 === count($functions)) {
            $conditionalDef = $this->createConditionalFunctionDefinition($functions);

            return $conditionalDef ?? $functions[0];
        }

        return $functions[0];
    }

    /**
     * @param array<int, Node\Stmt> $functions
     */
    private function createConditionalFunctionDefinition(array $functions): ?Node\Stmt\If_
    {
        if (2 !== count($functions)) {
            return null;
        }

        $func1 = $functions[0];
        $func2 = $functions[1];

        // Ensure functions are statements
        assert($func1 instanceof Node\Stmt);
        assert($func2 instanceof Node\Stmt);

        $code1 = $this->printer->prettyPrint([$func1]);
        $code2 = $this->printer->prettyPrint([$func2]);

        $isPhp8InFirst = false !== stripos($code1, 'PHP 8');
        $isPhp7InSecond = false !== stripos($code2, 'PHP 7');

        if ($isPhp8InFirst && $isPhp7InSecond) {
            return new Node\Stmt\If_(
                new Node\Expr\BinaryOp\GreaterOrEqual(
                    new Node\Expr\ConstFetch(new Node\Name('PHP_VERSION_ID')),
                    new Node\Scalar\LNumber(80000)
                ),
                [
                    'stmts' => [$func1],
                    'else' => new Node\Stmt\Else_([$func2]),
                ]
            );
        }

        if (!$isPhp8InFirst && !$isPhp7InSecond) {
            return new Node\Stmt\If_(
                new Node\Expr\BinaryOp\GreaterOrEqual(
                    new Node\Expr\ConstFetch(new Node\Name('PHP_VERSION_ID')),
                    new Node\Scalar\LNumber(80000)
                ),
                [
                    'stmts' => [$func2],
                    'else' => new Node\Stmt\Else_([$func1]),
                ]
            );
        }

        return null;
    }
}
