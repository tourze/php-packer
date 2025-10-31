<?php

declare(strict_types=1);

namespace PhpPacker\Storage;

use PhpParser\Node;
use PhpParser\Node\Stmt;

class BasicNodeExtractor
{
    public function __construct(private TypeConverter $typeConverter)
    {
    }

    /**
     * @param Node $node
     * @param array<string, mixed> $attributes
     * @param ?Stmt\Namespace_ $namespace
     * @return array{nodeName: string|null, fqcn: string|null, attributes: array<string, mixed>}
     */
    public function extractBasicNodeData(Node $node, array $attributes = [], ?Stmt\Namespace_ $namespace = null): array
    {
        $result = $this->extractNodeData($node, $namespace);

        return [
            'nodeName' => $result['nodeName'],
            'fqcn' => $result['fqcn'],
            'attributes' => array_merge($attributes, $result['attributes']),
        ];
    }

    /**
     * 新的无引用方法来提取节点数据
     * @param Node $node
     * @param ?Stmt\Namespace_ $namespace
     * @return array{nodeName: string|null, fqcn: string|null, attributes: array<string, mixed>}
     */
    private function extractNodeData(Node $node, ?Stmt\Namespace_ $namespace = null): array
    {
        $nodeName = null;
        $fqcn = null;
        $attributes = [];

        [$n1, $f1, $a1] = $this->handleClassLike($node, $namespace);
        $nodeName = $n1 ?? $nodeName;
        $fqcn = $f1 ?? $fqcn;
        $attributes = array_merge($attributes, $a1);

        [$n2, $f2, $a2] = $this->handleCallable($node, $namespace);
        $nodeName = $n2 ?? $nodeName;
        $fqcn = $f2 ?? $fqcn;
        $attributes = array_merge($attributes, $a2);

        [$n3, $f3, $a3] = $this->handleStructural($node);
        $nodeName = $n3 ?? $nodeName;
        $fqcn = $f3 ?? $fqcn;
        $attributes = array_merge($attributes, $a3);

        return [
            'nodeName' => $nodeName,
            'fqcn' => $fqcn,
            'attributes' => $attributes,
        ];
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: array<string, mixed>}
     */
    private function handleClassLike(Node $node, ?Stmt\Namespace_ $namespace): array
    {
        $nodeName = null;
        $fqcn = null;
        $attributes = [];

        if ($node instanceof Stmt\Class_) {
            $nodeName = null !== $node->name ? $node->name->toString() : null;
            $fqcn = $this->typeConverter->extractFqcn($node, $namespace);
            $attributes['flags'] = $node->flags;
            $attributes['extends'] = null !== $node->extends ? $node->extends->toString() : null;
            $attributes['implements'] = array_map(fn ($i) => $i->toString(), $node->implements);
        } elseif ($node instanceof Stmt\Interface_) {
            $nodeName = null !== $node->name ? $node->name->toString() : null;
            $fqcn = $this->typeConverter->extractFqcn($node, $namespace) ?? $nodeName;
            $attributes['extends'] = array_map(fn ($e) => $e->toString(), $node->extends);
        } elseif ($node instanceof Stmt\Trait_) {
            $nodeName = null !== $node->name ? $node->name->toString() : null;
            $fqcn = $this->typeConverter->extractFqcn($node, $namespace) ?? $nodeName;
        }

        return [$nodeName, $fqcn, $attributes];
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: array<string, mixed>}
     */
    private function handleCallable(Node $node, ?Stmt\Namespace_ $namespace): array
    {
        $nodeName = null;
        $fqcn = null;
        $attributes = [];

        if ($node instanceof Stmt\Function_) {
            $nodeName = $node->name->toString();
            $fqcn = $this->typeConverter->extractFqcn($node, $namespace) ?? $nodeName;
        } elseif ($node instanceof Stmt\ClassMethod) {
            $nodeName = $node->name->toString();
            $attributes['flags'] = $node->flags;
            $attributes['returnType'] = null !== $node->returnType ? $this->typeConverter->typeToString($node->returnType) : null;
        } elseif ($node instanceof Stmt\Property) {
            $attributes['flags'] = $node->flags;
            $attributes['type'] = null !== $node->type ? $this->typeConverter->typeToString($node->type) : null;
            $nodeName = $node->props[0]->name->toString();
        }

        return [$nodeName, $fqcn, $attributes];
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: array<string, mixed>}
     */
    private function handleStructural(Node $node): array
    {
        $nodeName = null;
        $fqcn = null;
        $attributes = [];

        if ($node instanceof Node\Name) {
            $fqcn = $node->toString();
            $nodeName = $node->getLast();
        } elseif ($node instanceof Stmt\Use_) {
            $attributes['type'] = $node->type;
        } elseif ($node instanceof Stmt\Namespace_) {
            $nodeName = null !== $node->name ? $node->name->toString() : null;
        }

        return [$nodeName, $fqcn, $attributes];
    }
}
