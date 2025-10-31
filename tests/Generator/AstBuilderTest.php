<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Generator;

use PhpPacker\Generator\AstBuilder;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AstBuilder::class)]
final class AstBuilderTest extends TestCase
{
    private AstBuilder $astBuilder;

    protected function setUp(): void
    {
        $this->astBuilder = new AstBuilder();
    }

    public function testCreateAstHeader(): void
    {
        $header = $this->astBuilder->createAstHeader();

        $this->assertCount(2, $header);
        $this->assertInstanceOf(Node\Stmt\InlineHTML::class, $header[0]);
        $this->assertInstanceOf(Node\Stmt\Declare_::class, $header[1]);

        // 验证 strict_types 声明
        $declare = $header[1];
        $this->assertInstanceOf(Node\Stmt\Declare_::class, $declare);
        $this->assertCount(1, $declare->declares);
        $this->assertEquals('strict_types', $declare->declares[0]->key);
        $value = $declare->declares[0]->value;
        $this->assertInstanceOf(Node\Scalar\LNumber::class, $value);
        $this->assertEquals(1, $value->value);
    }

    public function testOrganizeNamespacesSingleNamespace(): void
    {
        $mergedAst = [
            new Node\Stmt\Namespace_(
                new Node\Name('App'),
                [
                    new Node\Stmt\Class_('TestClass'),
                    new Node\Stmt\Function_('testFunction'),
                ]
            ),
        ];

        $result = $this->astBuilder->organizeNamespaces($mergedAst);

        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('global', $result);
        $this->assertCount(1, $result['groups']);
        $this->assertArrayHasKey('App', $result['groups']);
        $this->assertCount(2, $result['groups']['App'], 'Expected 2 statements in App namespace');
        $this->assertEmpty($result['global']);
    }

    public function testOrganizeNamespacesMultipleNamespaces(): void
    {
        $mergedAst = [
            new Node\Stmt\Namespace_(
                new Node\Name('App'),
                [new Node\Stmt\Class_('AppClass')]
            ),
            new Node\Stmt\Namespace_(
                new Node\Name('Utils'),
                [new Node\Stmt\Class_('UtilClass')]
            ),
            new Node\Stmt\Expression(
                new Node\Expr\FuncCall(new Node\Name('bootstrap'))
            ),
        ];

        $result = $this->astBuilder->organizeNamespaces($mergedAst);

        $this->assertCount(2, $result['groups']);
        $this->assertArrayHasKey('App', $result['groups']);
        $this->assertArrayHasKey('Utils', $result['groups']);
        $this->assertCount(1, $result['global']);
    }

    public function testOrganizeNamespacesGlobalNamespace(): void
    {
        $mergedAst = [
            new Node\Stmt\Namespace_(
                null,
                [new Node\Stmt\Function_('globalFunction')]
            ),
            new Node\Stmt\Class_('GlobalClass'),
        ];

        $result = $this->astBuilder->organizeNamespaces($mergedAst);

        $this->assertArrayHasKey('__global__', $result['groups']);
        $this->assertCount(1, $result['groups'], 'Expected 1 global namespace group');
        $this->assertCount(1, $result['global']);
    }

    public function testBuildNamespaceStructureSingleNamespace(): void
    {
        $ast = $this->astBuilder->createAstHeader();
        $namespaceGroups = [
            'App' => [
                new Node\Stmt\Class_('TestClass'),
            ],
        ];
        $globalNodes = [];

        $result = $this->astBuilder->buildNamespaceStructure($ast, $namespaceGroups, $globalNodes);

        // 应该包含 header + namespace 声明 + class
        $this->assertCount(4, $result); // 2 header + 1 namespace + 1 class
        $this->assertInstanceOf(Node\Stmt\Namespace_::class, $result[2]);
        $this->assertNotNull($result[2]->name);
        $this->assertEquals('App', $result[2]->name->toString());
    }

    public function testBuildNamespaceStructureMultipleNamespaces(): void
    {
        $ast = $this->astBuilder->createAstHeader();
        $namespaceGroups = [
            'App' => [new Node\Stmt\Class_('AppClass')],
            'Utils' => [new Node\Stmt\Class_('UtilClass')],
        ];
        $globalNodes = [
            new Node\Stmt\Expression(new Node\Expr\FuncCall(new Node\Name('init'))),
        ];

        $result = $this->astBuilder->buildNamespaceStructure($ast, $namespaceGroups, $globalNodes);

        // 找到命名空间节点
        $namespaceCount = 0;
        $globalNamespaceFound = false;
        foreach ($result as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                ++$namespaceCount;
                if (null === $node->name) {
                    $globalNamespaceFound = true;
                }
            }
        }

        $this->assertEquals(3, $namespaceCount); // App, Utils, global
        $this->assertTrue($globalNamespaceFound);
    }

    public function testBuildNamespaceStructureGlobalOnly(): void
    {
        $ast = $this->astBuilder->createAstHeader();
        $namespaceGroups = [];
        $globalNodes = [
            new Node\Stmt\Function_('globalFunc'),
            new Node\Stmt\Class_('GlobalClass'),
        ];

        $result = $this->astBuilder->buildNamespaceStructure($ast, $namespaceGroups, $globalNodes);

        // 应该包含 header + global nodes
        $this->assertCount(4, $result); // 2 header + 2 global nodes
        $this->assertInstanceOf(Node\Stmt\Function_::class, $result[2]);
        $this->assertInstanceOf(Node\Stmt\Class_::class, $result[3]);
    }

    public function testAddExecutionCodeToAstSingleNamespace(): void
    {
        $ast = [
            new Node\Stmt\Namespace_(
                new Node\Name('App'),
                [new Node\Stmt\Class_('TestClass')]
            ),
        ];
        $executionCode = [
            new Node\Stmt\Expression(
                new Node\Expr\FuncCall(new Node\Name('run'))
            ),
        ];
        $namespaceGroups = ['App' => []];
        $globalNodes = [];

        $result = $this->astBuilder->addExecutionCodeToAst($ast, $executionCode, $namespaceGroups, $globalNodes);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(Node\Stmt\Expression::class, $result[1]);
    }

    public function testAddExecutionCodeToAstMultipleNamespaces(): void
    {
        $ast = [
            new Node\Stmt\Namespace_(
                new Node\Name('App'),
                [new Node\Stmt\Class_('AppClass')]
            ),
            new Node\Stmt\Namespace_(
                null,
                [new Node\Stmt\Function_('globalFunc')]
            ),
        ];
        $executionCode = [
            new Node\Stmt\Expression(
                new Node\Expr\FuncCall(new Node\Name('execute'))
            ),
        ];
        $namespaceGroups = ['App' => [], '__global__' => []];
        $globalNodes = [];

        $result = $this->astBuilder->addExecutionCodeToAst($ast, $executionCode, $namespaceGroups, $globalNodes);

        // 验证执行代码被添加到了全局命名空间
        $globalNamespace = null;
        foreach ($result as $node) {
            if ($node instanceof Node\Stmt\Namespace_ && null === $node->name) {
                $globalNamespace = $node;
                break;
            }
        }

        $this->assertNotNull($globalNamespace);
        $this->assertGreaterThan(1, count($globalNamespace->stmts));

        // 查找执行代码
        $executionFound = false;
        foreach ($globalNamespace->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Expression
                && $stmt->expr instanceof Node\Expr\FuncCall
                && $stmt->expr->name instanceof Node\Name
                && 'execute' === $stmt->expr->name->toString()) {
                $executionFound = true;
                break;
            }
        }
        $this->assertTrue($executionFound);
    }

    public function testAddExecutionCodeToAstCreateGlobalNamespace(): void
    {
        $ast = [
            new Node\Stmt\Namespace_(
                new Node\Name('App'),
                [new Node\Stmt\Class_('AppClass')]
            ),
        ];
        $executionCode = [
            new Node\Stmt\Expression(
                new Node\Expr\FuncCall(new Node\Name('init'))
            ),
        ];
        $namespaceGroups = ['App' => []];
        $globalNodes = [new Node\Stmt\Function_('helper')];

        $result = $this->astBuilder->addExecutionCodeToAst($ast, $executionCode, $namespaceGroups, $globalNodes);

        // 应该创建新的全局命名空间
        $this->assertCount(2, $result);
        $this->assertInstanceOf(Node\Stmt\Namespace_::class, $result[1]);
        $this->assertNull($result[1]->name);
        $this->assertCount(1, $result[1]->stmts);
    }
}
