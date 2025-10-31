<?php

declare(strict_types=1);

namespace PhpPacker\Storage;

use PhpParser\Node;

class NodeDataExtractor
{
    private TypeConverter $typeConverter;

    private BasicNodeExtractor $basicExtractor;

    private ExpressionNodeExtractor $expressionExtractor;

    private TestDataExtractor $testExtractor;

    public function __construct(TypeConverter $typeConverter)
    {
        $this->typeConverter = $typeConverter;
        $this->basicExtractor = new BasicNodeExtractor($typeConverter);
        $this->expressionExtractor = new ExpressionNodeExtractor();
        $this->testExtractor = new TestDataExtractor($typeConverter);
    }

    /**
     * @return array<string, mixed>
     */
    public function extractNodeData(Node $node, int $fileId, int $parentId, int $position, ?Node\Stmt\Namespace_ $namespace = null): array
    {
        $nodeType = $node->getType();
        $nodeName = null;
        $fqcn = null;
        $attributes = [];

        $basicData = $this->basicExtractor->extractBasicNodeData($node, $attributes, $namespace);
        $nodeName = $basicData['nodeName'];
        $fqcn = $basicData['fqcn'];
        $attributes = $basicData['attributes'];
        $attributes = $this->expressionExtractor->extractExpressionNodeData($node, $attributes);

        return [
            'file_id' => $fileId,
            'parent_id' => $parentId,
            'node_type' => $nodeType,
            'node_name' => $nodeName,
            'fqcn' => $fqcn,
            'position' => $position,
            'start_line' => $node->getStartLine(),
            'end_line' => $node->getEndLine(),
            'attributes' => [] !== $attributes ? json_encode($attributes) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function extractData(Node $node, bool $includePosition = false): array
    {
        return $this->testExtractor->extractTestData($node, $includePosition);
    }

    public function extractTypeData(Node $type): string
    {
        return $this->typeConverter->typeToString($type);
    }

    /**
     * @return array<string>
     */
    public function getSupportedNodeTypes(): array
    {
        return [
            'Stmt_Class',
            'Stmt_Interface',
            'Stmt_Trait',
            'Stmt_Function',
            'Stmt_ClassMethod',
            'Stmt_Property',
            'Stmt_Namespace',
            'Stmt_Use',
            'Expr_New',
            'Expr_StaticCall',
            'Stmt_Const',
            'Stmt_TraitUse',
        ];
    }
}
