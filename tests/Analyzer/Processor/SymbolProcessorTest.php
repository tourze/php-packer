<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer\Processor;

use PhpPacker\Analyzer\Processor\SymbolProcessor;
use PhpPacker\Storage\StorageInterface;
use PhpParser\Modifiers;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SymbolProcessor::class)]
final class SymbolProcessorTest extends TestCase
{
    private SymbolProcessor $processor;

    private StorageInterface $storage;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(StorageInterface::class);
        $fileId = 1;
        $namespace = 'Test\Namespace';
        $this->processor = new SymbolProcessor($this->storage, $fileId, $namespace);
    }

    public function testProcessClass(): void
    {
        $this->storage->expects($this->once())
            ->method('addSymbol')
            ->with(
                1, // fileId
                'class',
                'UserService',
                'Test\Namespace\UserService', // FQN
                'Test\Namespace',
                'public' // visibility
            )
        ;

        $classNode = new Stmt\Class_('UserService');
        $this->processor->processClass($classNode);
        $this->assertEquals(1, $this->processor->getSymbolCount());
    }

    public function testProcessAbstractClass(): void
    {
        $this->storage->expects($this->once())
            ->method('addSymbol')
            ->with(
                1, // fileId
                'class',
                'AbstractClass',
                'Test\Namespace\AbstractClass', // FQN
                'Test\Namespace',
                'abstract' // visibility
            )
        ;

        $classNode = new Stmt\Class_('AbstractClass');
        $classNode->flags = Modifiers::ABSTRACT;
        $this->processor->processClass($classNode);
        $this->assertEquals(1, $this->processor->getSymbolCount());
    }

    public function testProcessFinalClass(): void
    {
        $this->storage->expects($this->once())
            ->method('addSymbol')
            ->with(
                1, // fileId
                'class',
                'FinalClass',
                'Test\Namespace\FinalClass', // FQN
                'Test\Namespace',
                'final' // visibility
            )
        ;

        $classNode = new Stmt\Class_('FinalClass');
        $classNode->flags = Modifiers::FINAL;
        $this->processor->processClass($classNode);
        $this->assertEquals(1, $this->processor->getSymbolCount());
    }

    public function testProcessInterface(): void
    {
        $this->storage->expects($this->once())
            ->method('addSymbol')
            ->with(
                1, // fileId
                'interface',
                'UserInterface',
                'Test\Namespace\UserInterface', // FQN
                'Test\Namespace'
            )
        ;

        $interfaceNode = new Stmt\Interface_('UserInterface');
        $this->processor->processInterface($interfaceNode);
        $this->assertEquals(1, $this->processor->getSymbolCount());
    }

    public function testProcessTrait(): void
    {
        $this->storage->expects($this->once())
            ->method('addSymbol')
            ->with(
                1, // fileId
                'trait',
                'UserTrait',
                'Test\Namespace\UserTrait', // FQN
                'Test\Namespace'
            )
        ;

        $traitNode = new Stmt\Trait_('UserTrait');
        $this->processor->processTrait($traitNode);
        $this->assertEquals(1, $this->processor->getSymbolCount());
    }

    public function testProcessFunction(): void
    {
        $this->storage->expects($this->once())
            ->method('addSymbol')
            ->with(
                1, // fileId
                'function',
                'helperFunction',
                'Test\Namespace\helperFunction', // FQN
                'Test\Namespace'
            )
        ;

        $functionNode = new Stmt\Function_('helperFunction');
        $this->processor->processFunction($functionNode);
        $this->assertEquals(1, $this->processor->getSymbolCount());
    }

    public function testSetCurrentNamespace(): void
    {
        $this->processor->setCurrentNamespace('App\Service');

        $this->storage->expects($this->once())
            ->method('addSymbol')
            ->with(
                1, // fileId
                'class',
                'TestClass',
                'App\Service\TestClass', // Updated FQN
                'App\Service' // Updated namespace
            )
        ;

        $classNode = new Stmt\Class_('TestClass');
        $this->processor->processClass($classNode);
    }

    public function testGlobalNamespace(): void
    {
        $processor = new SymbolProcessor($this->storage, 1, null);

        $this->storage->expects($this->once())
            ->method('addSymbol')
            ->with(
                1, // fileId
                'function',
                'globalFunction',
                'globalFunction', // No namespace prefix
                null // No namespace
            )
        ;

        $functionNode = new Stmt\Function_('globalFunction');
        $processor->processFunction($functionNode);
    }

    public function testSymbolCountIncreases(): void
    {
        $this->storage->expects($this->exactly(3))
            ->method('addSymbol')
        ;

        $this->processor->processClass(new Stmt\Class_('Class1'));
        $this->assertEquals(1, $this->processor->getSymbolCount());

        $this->processor->processInterface(new Stmt\Interface_('Interface1'));
        $this->assertEquals(2, $this->processor->getSymbolCount());

        $this->processor->processTrait(new Stmt\Trait_('Trait1'));
        $this->assertEquals(3, $this->processor->getSymbolCount());
    }

    public function testProcessClassWithoutName(): void
    {
        $this->storage->expects($this->never())
            ->method('addSymbol')
        ;

        $anonymousClass = new Stmt\Class_(null); // Anonymous class
        $this->processor->processClass($anonymousClass);
        $this->assertEquals(0, $this->processor->getSymbolCount());
    }
}
