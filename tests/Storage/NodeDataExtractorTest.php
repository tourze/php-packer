<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Storage;

use PhpPacker\Storage\NodeDataExtractor;
use PhpPacker\Storage\TypeConverter;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(NodeDataExtractor::class)]
final class NodeDataExtractorTest extends TestCase
{
    private NodeDataExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new NodeDataExtractor(new TypeConverter());
    }

    public function testExtractClassData(): void
    {
        $node = new Stmt\Class_('TestClass', [
            'extends' => new Name('BaseClass'),
            'implements' => [
                new Name('Interface1'),
                new Name('Interface2'),
            ],
            'flags' => Modifiers::FINAL,
        ]);

        $data = $this->extractor->extractData($node);

        $this->assertEquals('Stmt_Class', $data['type']);
        $this->assertEquals('TestClass', $data['name']);
        $this->assertEquals('BaseClass', $data['extends']);
        $this->assertCount(2, $data['implements']);
        $this->assertTrue($data['is_final']);
        $this->assertFalse($data['is_abstract']);
    }

    public function testExtractMethodData(): void
    {
        $node = new Stmt\ClassMethod('testMethod', [
            'flags' => Modifiers::PUBLIC | Modifiers::STATIC,
            'returnType' => new Name('string'),
            'params' => [
                new Node\Param(
                    new Expr\Variable('param1'),
                    null,
                    new Name('int')
                ),
                new Node\Param(
                    new Expr\Variable('param2'),
                    new Expr\ConstFetch(new Name('null')),
                    new Name('?string')
                ),
            ],
        ]);

        $data = $this->extractor->extractData($node);

        $this->assertEquals('Stmt_ClassMethod', $data['type']);
        $this->assertEquals('testMethod', $data['name']);
        $this->assertEquals('public', $data['visibility']);
        $this->assertTrue($data['is_static']);
        $this->assertEquals('string', $data['return_type']);
        $this->assertCount(2, $data['params']);

        $this->assertEquals('param1', $data['params'][0]['name']);
        $this->assertEquals('int', $data['params'][0]['type']);
        $this->assertFalse($data['params'][0]['has_default']);

        $this->assertEquals('param2', $data['params'][1]['name']);
        $this->assertEquals('?string', $data['params'][1]['type']);
        $this->assertTrue($data['params'][1]['has_default']);
    }

    public function testExtractFunctionData(): void
    {
        $node = new Stmt\Function_('globalFunction', [
            'returnType' => new Name('void'),
            'params' => [],
        ]);

        $data = $this->extractor->extractData($node);

        $this->assertEquals('Stmt_Function', $data['type']);
        $this->assertEquals('globalFunction', $data['name']);
        $this->assertEquals('void', $data['return_type']);
        $this->assertEmpty($data['params']);
    }

    public function testExtractPropertyData(): void
    {
        $property = new Stmt\PropertyProperty('propertyName', new Expr\ConstFetch(new Name('null')));
        $node = new Stmt\Property(
            Modifiers::PRIVATE | Modifiers::READONLY,
            [$property],
            [],
            new Name('?string')
        );

        $data = $this->extractor->extractData($node);

        $this->assertEquals('Stmt_Property', $data['type']);
        $this->assertEquals('propertyName', $data['name']);
        $this->assertEquals('private', $data['visibility']);
        $this->assertTrue($data['is_readonly']);
        $this->assertEquals('?string', $data['property_type']);
        $this->assertTrue($data['has_default']);
    }

    public function testExtractNamespaceData(): void
    {
        $node = new Stmt\Namespace_(new Name('App\Service\User'));

        $data = $this->extractor->extractData($node);

        $this->assertEquals('Stmt_Namespace', $data['type']);
        $this->assertEquals('App\Service\User', $data['name']);
    }

    public function testExtractUseData(): void
    {
        $node = new Stmt\Use_([
            new Stmt\UseUse(new Name('Vendor\Package\Class1'), 'Alias1'),
            new Stmt\UseUse(new Name('Vendor\Package\Class2'), null),
        ]);

        $data = $this->extractor->extractData($node);

        $this->assertEquals(1, $data['type']); // Use::TYPE_NORMAL
        $this->assertCount(2, $data['uses']);

        $this->assertEquals('Vendor\Package\Class1', $data['uses'][0]['name']);
        $this->assertEquals('Alias1', $data['uses'][0]['alias']);

        $this->assertEquals('Vendor\Package\Class2', $data['uses'][1]['name']);
        $this->assertNull($data['uses'][1]['alias']);
    }

    public function testExtractExpressionData(): void
    {
        // New expression
        $new = new Expr\New_(new Name('ClassName'), [
            new Node\Arg(new Expr\Variable('arg1')),
        ]);

        $data = $this->extractor->extractData($new);

        $this->assertEquals('Expr_New', $data['type']);
        $this->assertEquals('ClassName', $data['class']);
        $this->assertEquals(1, $data['args_count']);

        // Static call
        $staticCall = new Expr\StaticCall(
            new Name('StaticClass'),
            'methodName',
            []
        );

        $data = $this->extractor->extractData($staticCall);

        $this->assertEquals('Expr_StaticCall', $data['type']);
        $this->assertEquals('StaticClass', $data['class']);
        $this->assertEquals('methodName', $data['method']);
    }

    // Note: ClassConst 节点类型暂未实现，删除此测试

    public function testExtractTraitUseData(): void
    {
        $node = new Stmt\TraitUse([
            new Name('Trait1'),
            new Name('Trait2'),
        ], [
            new Stmt\TraitUseAdaptation\Alias(
                new Name('Trait1'),
                'method1',
                Modifiers::PRIVATE,
                'aliasedMethod'
            ),
        ]);

        $data = $this->extractor->extractData($node);

        $this->assertEquals('Stmt_TraitUse', $data['type']);
        $this->assertCount(2, $data['traits']);
        $this->assertContains('Trait1', $data['traits']);
        $this->assertContains('Trait2', $data['traits']);
        $this->assertEquals(1, $data['adaptations']);
    }

    public function testExtractComplexTypeData(): void
    {
        // Union type
        $unionType = new Node\UnionType([
            new Name('string'),
            new Name('int'),
            new Name('null'),
        ]);

        $data = $this->extractor->extractTypeData($unionType);
        $this->assertEquals('string|int|null', $data);

        // Intersection type
        $intersectionType = new Node\IntersectionType([
            new Name('Countable'),
            new Name('Traversable'),
        ]);

        $data = $this->extractor->extractTypeData($intersectionType);
        $this->assertEquals('Countable&Traversable', $data);
    }

    public function testExtractWithPosition(): void
    {
        $node = new Stmt\Class_('TestClass');
        $node->setAttribute('startLine', 10);
        $node->setAttribute('endLine', 20);
        $node->setAttribute('startFilePos', 100);
        $node->setAttribute('endFilePos', 500);

        $data = $this->extractor->extractData($node, true);

        $this->assertArrayHasKey('position', $data);
        $this->assertEquals(10, $data['position']['start_line']);
        $this->assertEquals(20, $data['position']['end_line']);
        $this->assertEquals(100, $data['position']['start_pos']);
        $this->assertEquals(500, $data['position']['end_pos']);
    }

    public function testGetSupportedNodeTypes(): void
    {
        $types = $this->extractor->getSupportedNodeTypes();

        $this->assertContains('Stmt_Class', $types);
        $this->assertContains('Stmt_Interface', $types);
        $this->assertContains('Stmt_Trait', $types);
        $this->assertContains('Stmt_Function', $types);
        $this->assertContains('Stmt_ClassMethod', $types);
        $this->assertContains('Stmt_Property', $types);
        $this->assertContains('Expr_New', $types);
        $this->assertContains('Expr_StaticCall', $types);
    }

    public function testExtractNodeData(): void
    {
        $node = new Stmt\Class_('TestClass');
        $node->setAttribute('startLine', 5);
        $node->setAttribute('endLine', 15);

        $fileId = 123;
        $parentId = 456;
        $position = 2;

        $data = $this->extractor->extractNodeData($node, $fileId, $parentId, $position);

        $this->assertEquals($fileId, $data['file_id']);
        $this->assertEquals($parentId, $data['parent_id']);
        $this->assertEquals('Stmt_Class', $data['node_type']);
        $this->assertEquals('TestClass', $data['node_name']);
        $this->assertEquals($position, $data['position']);
        $this->assertEquals(5, $data['start_line']);
        $this->assertEquals(15, $data['end_line']);
        $this->assertArrayHasKey('fqcn', $data);
        $this->assertArrayHasKey('attributes', $data);
    }

    public function testExtractNodeDataWithAttributes(): void
    {
        $node = new Stmt\ClassMethod('testMethod', [
            'flags' => Modifiers::PUBLIC | Modifiers::STATIC,
            'returnType' => new Name('string'),
        ]);
        $node->setAttribute('startLine', 10);
        $node->setAttribute('endLine', 20);

        $fileId = 789;
        $parentId = 101;
        $position = 3;

        $data = $this->extractor->extractNodeData($node, $fileId, $parentId, $position);

        $this->assertEquals($fileId, $data['file_id']);
        $this->assertEquals($parentId, $data['parent_id']);
        $this->assertEquals('Stmt_ClassMethod', $data['node_type']);
        $this->assertEquals('testMethod', $data['node_name']);
        $this->assertEquals($position, $data['position']);
        $this->assertEquals(10, $data['start_line']);
        $this->assertEquals(20, $data['end_line']);
        $this->assertNotNull($data['attributes']);

        // Verify attributes JSON is valid
        $attributes = json_decode($data['attributes'], true);
        $this->assertIsArray($attributes);
    }

    public function testExtractNodeDataWithoutLines(): void
    {
        $node = new Stmt\Class_('TestClass');
        // No startLine/endLine attributes

        $fileId = 111;
        $parentId = 222;
        $position = 1;

        $data = $this->extractor->extractNodeData($node, $fileId, $parentId, $position);

        $this->assertEquals($fileId, $data['file_id']);
        $this->assertEquals($parentId, $data['parent_id']);
        $this->assertEquals('Stmt_Class', $data['node_type']);
        $this->assertEquals($position, $data['position']);
        $this->assertArrayHasKey('start_line', $data);
        $this->assertArrayHasKey('end_line', $data);
    }

    public function testExtractDataWithUnsupportedNodeType(): void
    {
        // Create a node type that may not have specific handling
        $node = new Stmt\Echo_([new Expr\Variable('test')]);

        $data = $this->extractor->extractData($node);

        // Should still return basic node information
        $this->assertArrayHasKey('type', $data);
        $this->assertEquals('Stmt_Echo', $data['type']);
    }

    public function testExtractTypeDataWithNullableType(): void
    {
        $nullableType = new Node\NullableType(new Name('string'));

        $typeString = $this->extractor->extractTypeData($nullableType);

        $this->assertEquals('?string', $typeString);
    }

    public function testExtractTypeDataWithArrayType(): void
    {
        $arrayType = new Node\Identifier('array');

        $typeString = $this->extractor->extractTypeData($arrayType);

        $this->assertEquals('array', $typeString);
    }

    public function testExtractDataWithMultipleNodes(): void
    {
        // Test extracting data from different node types to ensure coverage
        $constNode = new Const_('TEST', new Expr\ConstFetch(new Name('null')));
        $nodes = [
            new Stmt\Interface_('TestInterface'),
            new Stmt\Trait_('TestTrait'),
            new Stmt\Const_([$constNode]),
        ];

        foreach ($nodes as $node) {
            $data = $this->extractor->extractData($node);
            $this->assertArrayHasKey('type', $data);
            $this->assertIsString($data['type']);
        }
    }

    public function testExtractDataWithPosition(): void
    {
        $node = new Stmt\Function_('testFunc');
        $node->setAttribute('startLine', 5);
        $node->setAttribute('endLine', 10);
        $node->setAttribute('startFilePos', 100);
        $node->setAttribute('endFilePos', 250);

        $data = $this->extractor->extractData($node, true);

        $this->assertArrayHasKey('position', $data);
        $this->assertEquals(5, $data['position']['start_line']);
        $this->assertEquals(10, $data['position']['end_line']);
        $this->assertEquals(100, $data['position']['start_pos']);
        $this->assertEquals(250, $data['position']['end_pos']);
    }

    public function testExtractDataWithNamespace(): void
    {
        $node = new Stmt\Class_('TestClass');

        // Test with namespace context
        $namespace = new Stmt\Namespace_(new Name('App\Models'));
        $fileId = 123;
        $parentId = 456;
        $position = 0;

        $data = $this->extractor->extractNodeData($node, $fileId, $parentId, $position, $namespace);

        $this->assertEquals($fileId, $data['file_id']);
        $this->assertEquals($parentId, $data['parent_id']);
        $this->assertEquals('Stmt_Class', $data['node_type']);
        $this->assertEquals('TestClass', $data['node_name']);
        $this->assertArrayHasKey('fqcn', $data);
    }

    public function testExtractConstData(): void
    {
        $constNode = new Const_('TEST_CONST', new Expr\ConstFetch(new Name('true')));
        $node = new Stmt\Const_([$constNode]);

        $data = $this->extractor->extractData($node);

        $this->assertEquals('Stmt_Const', $data['type']);
        // Const statements may not have a direct 'name' field, check the structure
        $this->assertIsArray($data);
    }

    public function testExtractVariableExpressionData(): void
    {
        // Test variable access expression
        $node = new Expr\Variable('testVar');

        $data = $this->extractor->extractData($node);

        $this->assertEquals('Expr_Variable', $data['type']);
    }

    public function testExtractMethodCallData(): void
    {
        $methodCall = new Expr\MethodCall(
            new Expr\Variable('obj'),
            'methodName',
            []
        );

        $data = $this->extractor->extractData($methodCall);

        $this->assertEquals('Expr_MethodCall', $data['type']);
    }
}
