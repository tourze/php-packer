<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class SymbolExtractorVisitor extends NodeVisitorAbstract
{
    /** @var array{classes: array<string>, functions: array<string>, constants: array<string>} */
    private array $symbols = [
        'classes' => [],
        'functions' => [],
        'constants' => [],
    ];

    public function __construct()
    {
    }

    /**
     * @return array{classes: array<string>, functions: array<string>, constants: array<string>}
     */
    public function getSymbols(): array
    {
        return [
            'classes' => array_unique($this->symbols['classes']),
            'functions' => array_unique($this->symbols['functions']),
            'constants' => array_unique($this->symbols['constants']),
        ];
    }

    public function enterNode(Node $node): ?int
    {
        $this->extractClassSymbol($node);
        $this->extractFunctionSymbol($node);
        $this->extractConstantSymbols($node);

        return null;
    }

    private function extractClassSymbol(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_) {
            if (isset($node->namespacedName)) {
                $this->symbols['classes'][] = $node->namespacedName->toString();
            } elseif (null !== $node->name) {
                $this->symbols['classes'][] = $node->name->toString();
            }
        }
    }

    private function extractFunctionSymbol(Node $node): void
    {
        if ($node instanceof Node\Stmt\Function_) {
            if (isset($node->namespacedName)) {
                $this->symbols['functions'][] = $node->namespacedName->toString();
            } elseif (null !== $node->name) {
                $this->symbols['functions'][] = $node->name->toString();
            }
        }
    }

    private function extractConstantSymbols(Node $node): void
    {
        if ($node instanceof Node\Stmt\Const_) {
            foreach ($node->consts as $const) {
                $this->symbols['constants'][] = $const->name->toString();
            }
        }
    }
}
