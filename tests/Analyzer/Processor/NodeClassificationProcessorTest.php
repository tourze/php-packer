<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer\Processor;

use PhpPacker\Analyzer\Processor\NodeClassificationProcessor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(NodeClassificationProcessor::class)]
final class NodeClassificationProcessorTest extends TestCase
{
    private NodeClassificationProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new NodeClassificationProcessor();
    }

    public function testIsNamespaceNode(): void
    {
        $node = new Stmt\Namespace_(new Node\Name('App\Service'));
        $this->assertTrue($this->processor->isNamespaceNode($node));

        $notNamespaceNode = new Stmt\Class_('TestClass');
        $this->assertFalse($this->processor->isNamespaceNode($notNamespaceNode));
    }

    public function testIsUseNode(): void
    {
        $node = new Stmt\Use_([
            new Stmt\UseUse(new Node\Name('Vendor\Package\Class'), 'Alias'),
        ]);
        $this->assertTrue($this->processor->isUseNode($node));

        $notUseNode = new Stmt\Class_('TestClass');
        $this->assertFalse($this->processor->isUseNode($notUseNode));
    }

    public function testIsGroupUseNode(): void
    {
        $node = new Stmt\GroupUse(
            new Node\Name('Vendor\Package'),
            [new Stmt\UseUse(new Node\Name('Class1'))]
        );
        $this->assertTrue($this->processor->isGroupUseNode($node));

        $notGroupUseNode = new Stmt\Class_('TestClass');
        $this->assertFalse($this->processor->isGroupUseNode($notGroupUseNode));
    }

    public function testIsClassNode(): void
    {
        $node = new Stmt\Class_('TestClass');
        $this->assertTrue($this->processor->isClassNode($node));

        $notClassNode = new Stmt\Interface_('TestInterface');
        $this->assertFalse($this->processor->isClassNode($notClassNode));
    }

    public function testIsInterfaceNode(): void
    {
        $node = new Stmt\Interface_('TestInterface');
        $this->assertTrue($this->processor->isInterfaceNode($node));

        $notInterfaceNode = new Stmt\Class_('TestClass');
        $this->assertFalse($this->processor->isInterfaceNode($notInterfaceNode));
    }

    public function testIsTraitNode(): void
    {
        $node = new Stmt\Trait_('TestTrait');
        $this->assertTrue($this->processor->isTraitNode($node));

        $notTraitNode = new Stmt\Class_('TestClass');
        $this->assertFalse($this->processor->isTraitNode($notTraitNode));
    }

    public function testIsFunctionNode(): void
    {
        $node = new Stmt\Function_('testFunction');
        $this->assertTrue($this->processor->isFunctionNode($node));

        $notFunctionNode = new Stmt\Class_('TestClass');
        $this->assertFalse($this->processor->isFunctionNode($notFunctionNode));
    }

    public function testIsConditionalNode(): void
    {
        $ifNode = new Stmt\If_(new Expr\Variable('condition'));
        $this->assertTrue($this->processor->isConditionalNode($ifNode));

        $elseIfNode = new Stmt\ElseIf_(new Expr\Variable('condition'));
        $this->assertTrue($this->processor->isConditionalNode($elseIfNode));

        $elseNode = new Stmt\Else_();
        $this->assertTrue($this->processor->isConditionalNode($elseNode));

        $tryCatchNode = new Stmt\TryCatch([], []);
        $this->assertTrue($this->processor->isConditionalNode($tryCatchNode));

        $notConditionalNode = new Stmt\Class_('TestClass');
        $this->assertFalse($this->processor->isConditionalNode($notConditionalNode));
    }

    public function testIsIncludeNode(): void
    {
        $node = new Expr\Include_(
            new Node\Scalar\String_('test.php'),
            Expr\Include_::TYPE_INCLUDE
        );
        $this->assertTrue($this->processor->isIncludeNode($node));

        $notIncludeNode = new Stmt\Class_('TestClass');
        $this->assertFalse($this->processor->isIncludeNode($notIncludeNode));
    }

    public function testIsNewInstanceNode(): void
    {
        $node = new Expr\New_(new Node\Name('ClassName'));
        $this->assertTrue($this->processor->isNewInstanceNode($node));

        $notNewInstanceNode = new Stmt\Class_('TestClass');
        $this->assertFalse($this->processor->isNewInstanceNode($notNewInstanceNode));
    }

    public function testIsStaticReferenceNode(): void
    {
        $staticCallNode = new Expr\StaticCall(
            new Node\Name('ClassName'),
            'methodName'
        );
        $this->assertTrue($this->processor->isStaticReferenceNode($staticCallNode));

        $classConstFetchNode = new Expr\ClassConstFetch(
            new Node\Name('ClassName'),
            'CONSTANT'
        );
        $this->assertTrue($this->processor->isStaticReferenceNode($classConstFetchNode));

        $notStaticReferenceNode = new Stmt\Class_('TestClass');
        $this->assertFalse($this->processor->isStaticReferenceNode($notStaticReferenceNode));
    }
}
