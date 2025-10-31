<?php

declare(strict_types=1);

namespace PhpPacker\Storage;

use PhpParser\Node;

class TypeConverter
{
    /**
     * 将类型节点转换为字符串
     */
    public function typeToString(mixed $type): string
    {
        if (null === $type) {
            return '';
        }

        return match (true) {
            $type instanceof Node\Name\Relative => 'namespace\\' . $type->toString(),
            $type instanceof Node\Name => $type->toString(),
            $type instanceof Node\Identifier => $type->toString(),
            $type instanceof Node\UnionType => $this->unionTypeToString($type),
            $type instanceof Node\IntersectionType => $this->intersectionTypeToString($type),
            $type instanceof Node\NullableType => $this->nullableTypeToString($type),
            default => 'mixed',
        };
    }

    private function unionTypeToString(Node\UnionType $type): string
    {
        return implode('|', array_map(fn (mixed $t): string => $this->typeToString($t), $type->types));
    }

    private function intersectionTypeToString(Node\IntersectionType $type): string
    {
        return implode('&', array_map(fn (mixed $t): string => $this->typeToString($t), $type->types));
    }

    private function nullableTypeToString(Node\NullableType $type): string
    {
        $innerType = $this->typeToString($type->type);

        // 如果内部类型是联合类型或交集类型，需要加括号
        $needsParentheses = $type->type instanceof Node\UnionType || $type->type instanceof Node\IntersectionType;

        return $needsParentheses ? '?(' . $innerType . ')' : '?' . $innerType;
    }

    /**
     * 提取节点的完全限定类名
     */
    public function extractFqcn(Node $node, ?Node\Stmt\Namespace_ $namespace = null): ?string
    {
        // 尝试从预解析的属性中获取
        $fqcn = $this->extractFromNodeAttributes($node);
        if (null !== $fqcn) {
            return $fqcn;
        }

        // 尝试从节点名称中获取
        return $this->extractFromNodeName($node, $namespace);
    }

    /**
     * 从节点属性中提取FQCN
     */
    private function extractFromNodeAttributes(Node $node): ?string
    {
        // 优先检查 namespacedName 属性（由 NameResolver 设置）
        $namespacedName = $this->getValidNameAttribute($node, 'namespacedName');
        if (null !== $namespacedName) {
            return $namespacedName;
        }

        // 检查 resolvedName 属性（用于定义类型的节点）
        return $this->getValidNameAttribute($node, 'resolvedName');
    }

    /**
     * 获取有效的名称属性
     */
    private function getValidNameAttribute(Node $node, string $attributeName): ?string
    {
        if (!$node->hasAttribute($attributeName)) {
            return null;
        }

        $attribute = $node->getAttribute($attributeName);

        return $attribute instanceof Node\Name ? $attribute->toString() : null;
    }

    /**
     * 从节点名称中提取FQCN
     */
    private function extractFromNodeName(Node $node, ?Node\Stmt\Namespace_ $namespace): ?string
    {
        if (!property_exists($node, 'name') || null === $node->name) {
            return null;
        }

        $nodeName = $this->getNodeNameAsString($node->name);
        if (null === $nodeName) {
            return null;
        }

        return $this->buildFqcnWithNamespace($nodeName, $namespace);
    }

    /**
     * 将节点名称转换为字符串
     */
    private function getNodeNameAsString(mixed $name): ?string
    {
        return match (true) {
            $name instanceof Node\Name => $name->toString(),
            $name instanceof Node\Identifier => $name->toString(),
            default => null,
        };
    }

    /**
     * 使用命名空间构建FQCN
     */
    private function buildFqcnWithNamespace(string $nodeName, ?Node\Stmt\Namespace_ $namespace): string
    {
        if (null === $namespace || null === $namespace->name) {
            return $nodeName;
        }

        return $namespace->name->toString() . '\\' . $nodeName;
    }

    /**
     * 检查类型是否为标量类型
     */
    public function isScalarType(string $type): bool
    {
        $scalarTypes = [
            'int', 'integer', 'float', 'double', 'string', 'bool', 'boolean',
            'array', 'object', 'resource', 'null', 'callable', 'iterable',
            'void', 'never', 'mixed', 'false', 'true',
        ];

        return in_array(strtolower($type), $scalarTypes, true);
    }

    /**
     * 标准化类型名称
     */
    public function normalizeTypeName(string $typeName): string
    {
        // 移除前导反斜杠
        return ltrim($typeName, '\\');
    }

    /**
     * 将值转换为字符串表示
     */
    public function valueToString(mixed $value): string
    {
        return match (true) {
            is_null($value) => 'null',
            is_bool($value) => $this->convertBoolToString($value),
            is_string($value) => $this->convertStringToString($value),
            is_numeric($value) => (string) $value,
            is_array($value) => $this->convertArrayToString($value),
            is_object($value) => $this->convertObjectToString($value),
            default => 'mixed',
        };
    }

    private function convertBoolToString(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function convertStringToString(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }

    /**
     * @param array<mixed, mixed> $value
     */
    private function convertArrayToString(array $value): string
    {
        if (array_is_list($value)) {
            return $this->convertIndexedArrayToString($value);
        }

        return $this->convertAssociativeArrayToString($value);
    }

    /**
     * @param array<int, mixed> $value
     */
    private function convertIndexedArrayToString(array $value): string
    {
        $elements = array_map(fn ($v) => $this->valueToString($v), $value);

        return '[' . implode(', ', $elements) . ']';
    }

    /**
     * @param array<string|int, mixed> $value
     */
    private function convertAssociativeArrayToString(array $value): string
    {
        $parts = [];
        foreach ($value as $key => $val) {
            $parts[] = $this->valueToString($key) . ' => ' . $this->valueToString($val);
        }

        return '[' . implode(', ', $parts) . ']';
    }

    private function convertObjectToString(object $value): string
    {
        return get_class($value) . '::class';
    }

    /**
     * 将字符串转换为类型
     */
    public function stringToType(?string $typeString): mixed
    {
        if (null === $typeString) {
            return null;
        }

        $typeString = trim($typeString);
        if ('' === $typeString) {
            return null;
        }

        [$isNullable, $cleanTypeString] = $this->extractNullability($typeString);

        return match (true) {
            str_contains($cleanTypeString, '|') => $this->parseUnionType($cleanTypeString, $isNullable),
            str_contains($cleanTypeString, '&') => $this->parseIntersectionType($cleanTypeString, $isNullable),
            default => $this->parseSimpleType($cleanTypeString, $isNullable),
        };
    }

    /** @return array{bool, string} */
    private function extractNullability(string $typeString): array
    {
        $isNullable = str_starts_with($typeString, '?');
        $cleanTypeString = $isNullable ? ltrim($typeString, '?') : $typeString;

        return [$isNullable, $cleanTypeString];
    }

    private function parseUnionType(string $typeString, bool $isNullable): Node\UnionType
    {
        $types = array_map('trim', explode('|', $typeString));
        if ($isNullable && !in_array('null', $types, true)) {
            $types[] = 'null';
        }
        $unionTypes = array_map(fn (string $t) => $this->createTypeFromString($t), $types);

        return new Node\UnionType($unionTypes);
    }

    private function parseIntersectionType(string $typeString, bool $isNullable): Node\UnionType|Node\IntersectionType
    {
        $types = array_map('trim', explode('&', $typeString));
        $intersectionTypes = array_map(fn (string $t) => $this->createTypeFromString($t), $types);
        $intersectionType = new Node\IntersectionType($intersectionTypes);

        // 交集类型不能直接 nullable，需要用联合类型
        return $isNullable
            ? new Node\UnionType([$intersectionType, new Node\Identifier('null')])
            : $intersectionType;
    }

    private function parseSimpleType(string $typeString, bool $isNullable): Node\NullableType|Node\Identifier|Node\Name
    {
        $type = $this->createTypeFromString($typeString);

        return $isNullable ? new Node\NullableType($type) : $type;
    }

    /**
     * 从字符串创建单个类型
     */
    private function createTypeFromString(string $typeString): Node\Identifier|Node\Name
    {
        $typeString = trim($typeString);

        // 检查是否是类名（包含反斜杠）
        if (str_contains($typeString, '\\')) {
            if (str_starts_with($typeString, '\\')) {
                // 完全限定名
                return new Node\Name\FullyQualified(ltrim($typeString, '\\'));
            }
            if (str_starts_with($typeString, 'namespace\\')) {
                // 相对名
                return new Node\Name\Relative(substr($typeString, 10));
            }

            // 普通名
            return new Node\Name($typeString);
        }

        // 内置类型
        return new Node\Identifier($typeString);
    }
}
