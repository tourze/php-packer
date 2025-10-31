<?php

declare(strict_types=1);

namespace PhpPacker\Storage;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt;

class TestDataExtractor
{
    public function __construct(private TypeConverter $typeConverter)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function extractTestData(Node $node, bool $includePosition = false): array
    {
        $nodeType = $this->getNodeTypeString($node);
        $data = ['type' => $nodeType];

        $data = array_merge($data, $this->extractSpecificNodeData($node));

        if ($includePosition) {
            $data['position'] = [
                'start_line' => $node->getStartLine(),
                'end_line' => $node->getEndLine(),
                'start_pos' => $node->getAttribute('startFilePos'),
                'end_pos' => $node->getAttribute('endFilePos'),
            ];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSpecificNodeData(Node $node): array
    {
        return match (true) {
            $node instanceof Stmt\Class_ => $this->extractClassDataForTest($node),
            $node instanceof Stmt\Interface_ => $this->extractInterfaceDataForTest($node),
            $node instanceof Stmt\Trait_ => $this->extractTraitDataForTest($node),
            $node instanceof Stmt\Function_ => $this->extractFunctionDataForTest($node),
            $node instanceof Stmt\ClassMethod => $this->extractMethodDataForTest($node),
            $node instanceof Stmt\Property => $this->extractPropertyDataForTest($node),
            $node instanceof Stmt\Namespace_ => $this->extractNamespaceDataForTest($node),
            $node instanceof Stmt\Use_ => $this->extractUseDataForTest($node),
            $node instanceof Node\Expr\New_ => $this->extractNewExprDataForTest($node),
            $node instanceof Node\Expr\StaticCall => $this->extractStaticCallDataForTest($node),
            $node instanceof Stmt\Const_ => $this->extractConstDataForTest($node),
            $node instanceof Stmt\TraitUse => $this->extractTraitUseDataForTest($node),
            default => [],
        };
    }

    private function getNodeTypeString(Node $node): string
    {
        $class = get_class($node);
        $shortName = str_replace(['PhpParser\Node\\', '\\'], ['', '_'], $class);

        return rtrim($shortName, '_');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractClassDataForTest(Stmt\Class_ $node): array
    {
        return [
            'name' => $node->name?->toString(),
            'extends' => $node->extends?->toString(),
            'implements' => array_map(fn ($i) => $i->toString(), $node->implements),
            'is_final' => ($node->flags & Modifiers::FINAL) !== 0,
            'is_abstract' => ($node->flags & Modifiers::ABSTRACT) !== 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractInterfaceDataForTest(Stmt\Interface_ $node): array
    {
        return [
            'name' => $node->name?->toString(),
            'extends' => array_map(fn ($e) => $e->toString(), $node->extends),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTraitDataForTest(Stmt\Trait_ $node): array
    {
        return [
            'name' => $node->name?->toString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFunctionDataForTest(Stmt\Function_ $node): array
    {
        return [
            'name' => $node->name->toString(),
            'return_type' => null !== $node->returnType ? $this->typeConverter->typeToString($node->returnType) : null,
            'params' => array_map(fn ($param) => $this->extractParamData($param), $node->params),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractMethodDataForTest(Stmt\ClassMethod $node): array
    {
        $visibility = $this->getVisibility($node->flags);

        return [
            'name' => $node->name->toString(),
            'visibility' => $visibility,
            'is_static' => ($node->flags & Modifiers::STATIC) !== 0,
            'is_final' => ($node->flags & Modifiers::FINAL) !== 0,
            'is_abstract' => ($node->flags & Modifiers::ABSTRACT) !== 0,
            'return_type' => null !== $node->returnType ? $this->typeConverter->typeToString($node->returnType) : null,
            'params' => array_map(fn ($param) => $this->extractParamData($param), $node->params),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPropertyDataForTest(Stmt\Property $node): array
    {
        $visibility = $this->getVisibility($node->flags);

        $properties = [];
        foreach ($node->props as $prop) {
            $properties[] = [
                'name' => $prop->name->toString(),
                'has_default' => null !== $prop->default,
            ];
        }

        return [
            'name' => $properties[0]['name'] ?? null,
            'visibility' => $visibility,
            'is_static' => ($node->flags & Modifiers::STATIC) !== 0,
            'is_readonly' => ($node->flags & Modifiers::READONLY) !== 0,
            'property_type' => null !== $node->type ? $this->typeConverter->typeToString($node->type) : null,
            'has_default' => $properties[0]['has_default'] ?? false,
            'properties' => $properties,
        ];
    }

    private function getVisibility(int $flags): string
    {
        if (($flags & Modifiers::PRIVATE) !== 0) {
            return 'private';
        }
        if (($flags & Modifiers::PROTECTED) !== 0) {
            return 'protected';
        }

        return 'public';
    }

    /**
     * @return array<string, mixed>
     */
    private function extractNamespaceDataForTest(Stmt\Namespace_ $node): array
    {
        return [
            'name' => $node->name?->toString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractUseDataForTest(Stmt\Use_ $node): array
    {
        $uses = [];
        foreach ($node->uses as $use) {
            $uses[] = [
                'name' => $use->name->toString(),
                'alias' => $use->alias?->toString(),
            ];
        }

        return [
            'type' => $node->type,
            'uses' => $uses,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractNewExprDataForTest(Node\Expr\New_ $node): array
    {
        return [
            'class' => $node->class instanceof Node\Name ? $node->class->toString() : null,
            'args_count' => count($node->args),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractStaticCallDataForTest(Node\Expr\StaticCall $node): array
    {
        return [
            'class' => $node->class instanceof Node\Name ? $node->class->toString() : null,
            'method' => $node->name instanceof Node\Identifier ? $node->name->toString() : null,
            'args_count' => count($node->args),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractConstDataForTest(Stmt\Const_ $node): array
    {
        $consts = [];
        foreach ($node->consts as $const) {
            $consts[] = [
                'name' => $const->name->toString(),
                'value' => $const->value,
            ];
        }

        return [
            'consts' => $consts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTraitUseDataForTest(Stmt\TraitUse $node): array
    {
        return [
            'traits' => array_map(fn ($trait) => $trait->toString(), $node->traits),
            'adaptations' => count($node->adaptations),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractParamData(Node\Param $param): array
    {
        return [
            'name' => $param->var instanceof Node\Expr\Variable ? $param->var->name : null,
            'type' => null !== $param->type ? $this->typeConverter->typeToString($param->type) : null,
            'has_default' => null !== $param->default,
        ];
    }
}
