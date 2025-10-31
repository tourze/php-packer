<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Visitor;

use PhpPacker\Visitor\FqcnTransformVisitor;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FqcnTransformVisitor::class)]
final class FqcnTransformVisitorTest extends TestCase
{
    private FqcnTransformVisitor $visitor;

    protected function setUp(): void
    {
        $this->visitor = new FqcnTransformVisitor();
    }

    public function testConstructor(): void
    {
        $visitor = new FqcnTransformVisitor();
        $this->assertInstanceOf(FqcnTransformVisitor::class, $visitor);
    }

    public function testLeaveNodeRemovesUseStatement(): void
    {
        $useNode = new Node\Stmt\Use_([]);

        $result = $this->visitor->leaveNode($useNode);

        $this->assertEquals(NodeVisitorAbstract::REMOVE_NODE, $result);
    }

    public function testLeaveNodeRemovesGroupUseStatement(): void
    {
        $groupUseNode = new Node\Stmt\GroupUse(
            new Node\Name('Vendor\Package'),
            []
        );

        $result = $this->visitor->leaveNode($groupUseNode);

        $this->assertEquals(NodeVisitorAbstract::REMOVE_NODE, $result);
    }

    public function testLeaveNodeIgnoresOtherNodes(): void
    {
        $classNode = new Node\Stmt\Class_('TestClass');

        $result = $this->visitor->leaveNode($classNode);

        $this->assertNull($result);
    }

    public function testLeaveNodeIgnoresRegularStatements(): void
    {
        $echoNode = new Node\Stmt\Echo_([
            new Node\Scalar\String_('Hello World'),
        ]);

        $result = $this->visitor->leaveNode($echoNode);

        $this->assertNull($result);
    }

    public function testLeaveNodeIgnoresExpressions(): void
    {
        $variableNode = new Node\Expr\Variable('test');

        $result = $this->visitor->leaveNode($variableNode);

        $this->assertNull($result);
    }

    public function testLeaveNodeIgnoresNamespaceDeclaration(): void
    {
        $namespaceNode = new Node\Stmt\Namespace_(
            new Node\Name('App\Service')
        );

        $result = $this->visitor->leaveNode($namespaceNode);

        $this->assertNull($result);
    }

    public function testLeaveNodeWithComplexUseStatement(): void
    {
        $useNode = new Node\Stmt\Use_([
            new Node\Stmt\UseUse(
                new Node\Name('Vendor\Package\ClassA'),
                'AliasA'
            ),
            new Node\Stmt\UseUse(
                new Node\Name('Vendor\Package\ClassB'),
                null
            ),
        ]);

        $result = $this->visitor->leaveNode($useNode);

        $this->assertEquals(NodeVisitorAbstract::REMOVE_NODE, $result);
    }
}
