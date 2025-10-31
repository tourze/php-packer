<?php

declare(strict_types=1);

namespace PhpPacker\Storage;

use PhpParser\Node;

class ExpressionNodeExtractor
{
    /**
     * @param Node $node
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function extractExpressionNodeData(Node $node, array $attributes): array
    {
        if ($node instanceof Node\Expr\New_) {
            return $this->extractNewExprData($node, $attributes);
        }
        if ($node instanceof Node\Expr\StaticCall) {
            return $this->extractStaticCallData($node, $attributes);
        }
        if ($node instanceof Node\Expr\ClassConstFetch) {
            return $this->extractClassConstData($node, $attributes);
        }

        return $attributes;
    }

    /**
     * @param Node\Expr\New_ $node
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function extractNewExprData(Node\Expr\New_ $node, array $attributes): array
    {
        if ($node->class instanceof Node\Name) {
            $attributes['class'] = $node->class->toString();
        }

        return $attributes;
    }

    /**
     * @param Node\Expr\StaticCall $node
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function extractStaticCallData(Node\Expr\StaticCall $node, array $attributes): array
    {
        if ($node->class instanceof Node\Name) {
            $attributes['class'] = $node->class->toString();
        }
        $attributes['method'] = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

        return $attributes;
    }

    /**
     * @param Node\Expr\ClassConstFetch $node
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function extractClassConstData(Node\Expr\ClassConstFetch $node, array $attributes): array
    {
        if ($node->class instanceof Node\Name) {
            $attributes['class'] = $node->class->toString();
        }
        $attributes['const'] = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

        return $attributes;
    }
}
