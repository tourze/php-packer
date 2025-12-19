<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer\Processor;

use PhpPacker\Analyzer\Processor\SymbolProcessor;
use PhpPacker\Storage\SqliteStorage;
use PhpParser\Modifiers;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(SymbolProcessor::class)]
final class SymbolProcessorTest extends TestCase
{
    private SymbolProcessor $processor;

    private string $dbPath;

    private SqliteStorage $storage;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/test-' . uniqid() . '.db';
        $this->storage = new SqliteStorage($this->dbPath, new NullLogger());
        $fileId = 1;
        $namespace = 'Test\Namespace';
        $this->processor = new SymbolProcessor($this->storage, $fileId, $namespace);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testProcessClass(): void
    {
        $classNode = new Stmt\Class_('UserService');
        $this->processor->processClass($classNode);
        $this->assertEquals(1, $this->processor->getSymbolCount());
    }

    public function testProcessAbstractClass(): void
    {
        $classNode = new Stmt\Class_('AbstractClass');
        $classNode->flags = Modifiers::ABSTRACT;
        $this->processor->processClass($classNode);
        $this->assertEquals(1, $this->processor->getSymbolCount());
    }

    public function testProcessFinalClass(): void
    {
        $classNode = new Stmt\Class_('FinalClass');
        $classNode->flags = Modifiers::FINAL;
        $this->processor->processClass($classNode);
        $this->assertEquals(1, $this->processor->getSymbolCount());
    }

    public function testProcessInterface(): void
    {
        $interfaceNode = new Stmt\Interface_('UserInterface');
        $this->processor->processInterface($interfaceNode);
        $this->assertEquals(1, $this->processor->getSymbolCount());
    }

    public function testProcessTrait(): void
    {
        $traitNode = new Stmt\Trait_('UserTrait');
        $this->processor->processTrait($traitNode);
        $this->assertEquals(1, $this->processor->getSymbolCount());
    }

    public function testProcessFunction(): void
    {
        $functionNode = new Stmt\Function_('helperFunction');
        $this->processor->processFunction($functionNode);
        $this->assertEquals(1, $this->processor->getSymbolCount());
    }

    public function testSetCurrentNamespace(): void
    {
        $this->processor->setCurrentNamespace('App\Service');

        $classNode = new Stmt\Class_('TestClass');
        $this->processor->processClass($classNode);
        $this->assertEquals(1, $this->processor->getSymbolCount());
    }

    public function testGlobalNamespace(): void
    {
        $processor = new SymbolProcessor($this->storage, 1, null);

        $functionNode = new Stmt\Function_('globalFunction');
        $processor->processFunction($functionNode);
        $this->assertEquals(1, $processor->getSymbolCount());
    }

    public function testSymbolCountIncreases(): void
    {
        $this->processor->processClass(new Stmt\Class_('Class1'));
        $this->assertEquals(1, $this->processor->getSymbolCount());

        $this->processor->processInterface(new Stmt\Interface_('Interface1'));
        $this->assertEquals(2, $this->processor->getSymbolCount());

        $this->processor->processTrait(new Stmt\Trait_('Trait1'));
        $this->assertEquals(3, $this->processor->getSymbolCount());
    }

    public function testProcessClassWithoutName(): void
    {
        $anonymousClass = new Stmt\Class_(null); // Anonymous class
        $this->processor->processClass($anonymousClass);
        $this->assertEquals(0, $this->processor->getSymbolCount());
    }
}
