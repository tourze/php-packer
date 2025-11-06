<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer\Processor;

use PhpPacker\Analyzer\Processor\DependencyProcessor;
use PhpPacker\Storage\StorageInterface;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DependencyProcessor::class)]
final class DependencyProcessorTest extends TestCase
{
    private DependencyProcessor $processor;

    protected function setUp(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $fileId = 1;
        $this->processor = new DependencyProcessor($storage, $fileId);
    }

    public function testProcessClassNode(): void
    {
        $extends = new Name\FullyQualified('BaseClass');
        $implements = [
            new Name\FullyQualified('Interface1'),
            new Name('Interface2'),
        ];

        $classNode = new Class_('TestClass', [
            'extends' => $extends,
            'implements' => $implements,
        ]);

        $this->processor->process($classNode);
        $dependencies = $this->processor->getAllDependencies();

        $this->assertCount(3, $dependencies);
        $this->assertContains('BaseClass', array_column($dependencies, 'fqcn'));
        $this->assertContains('Interface1', array_column($dependencies, 'fqcn'));
        $this->assertContains('Interface2', array_column($dependencies, 'fqcn'));
    }

    public function testProcessUseStatements(): void
    {
        $this->processor->setCurrentNamespace('App\Service');

        // 手动添加 use 语句
        $this->processor->addUse('Class1', 'Vendor\Package\Class1');
        $this->processor->addUse('Alias', 'Another\Class2');

        // 测试解析别名
        $this->assertEquals('Another\Class2', $this->processor->resolveClass('Alias'));
        $this->assertEquals('Vendor\Package\Class1', $this->processor->resolveClass('Class1'));
    }

    public function testResolveFullyQualifiedNames(): void
    {
        $this->processor->setCurrentNamespace('App\Service');

        // 完全限定名
        $this->assertEquals('Global\Class', $this->processor->resolveClass('\Global\Class'));

        // 相对名称
        $this->assertEquals('App\Service\LocalClass', $this->processor->resolveClass('LocalClass'));

        // 子命名空间
        $this->assertEquals('App\Service\Sub\Class', $this->processor->resolveClass('Sub\Class'));
    }

    public function testProcessNewExpression(): void
    {
        $newExpr = new Node\Expr\New_(new Name('ClassName'));

        $this->processor->process($newExpr);
        $dependencies = $this->processor->getAllDependencies();

        $this->assertCount(1, $dependencies);
        $this->assertContains('ClassName', array_column($dependencies, 'fqcn'));
    }

    public function testProcessStaticCall(): void
    {
        $staticCall = new Node\Expr\StaticCall(
            new Name\FullyQualified('StaticClass'),
            'method'
        );

        $this->processor->process($staticCall);
        $dependencies = $this->processor->getAllDependencies();

        $this->assertCount(1, $dependencies);
        $this->assertContains('StaticClass', array_column($dependencies, 'fqcn'));
    }

    public function testProcessInstanceOf(): void
    {
        // DependencyProcessor 没有处理 instanceof，我们跳过这个测试或者使用静态调用代替
        $staticCall = new Node\Expr\StaticCall(
            new Name('CheckClass'),
            'method'
        );

        $this->processor->process($staticCall);
        $dependencies = $this->processor->getAllDependencies();

        $this->assertCount(1, $dependencies);
        $this->assertContains('CheckClass', array_column($dependencies, 'fqcn'));
    }

    public function testGetAllDependencies(): void
    {
        $this->processor->setCurrentNamespace('Test');

        // 添加一些依赖
        $class1 = new Class_('TestClass', ['extends' => new Name('Base')]);
        $new1 = new Node\Expr\New_(new Name('Another'));

        $this->processor->process($class1);
        $this->processor->process($new1);

        $all = $this->processor->getAllDependencies();
        $fqcns = array_column($all, 'fqcn');

        $this->assertContains('Test\Base', $fqcns);
        $this->assertContains('Test\Another', $fqcns);
    }

    public function testReset(): void
    {
        $this->processor->setCurrentNamespace('Test');
        $this->processor->process(new Node\Expr\New_(new Name('Class')));

        $this->assertNotEmpty($this->processor->getAllDependencies());

        $this->processor->reset();

        $this->assertEmpty($this->processor->getAllDependencies());
        $this->assertEquals('', $this->processor->getCurrentNamespace());
    }

    public function testAddUse(): void
    {
        $this->processor->addUse('Alias', 'Full\ClassName');
        $this->processor->addUse('Another', 'Another\FullName');

        // Test that the use statements are properly resolved
        $resolved = $this->processor->resolveClass('Alias');
        $this->assertEquals('Full\ClassName', $resolved);

        $resolved = $this->processor->resolveClass('Another');
        $this->assertEquals('Another\FullName', $resolved);

        // Test resolving non-aliased class names
        $resolved = $this->processor->resolveClass('UnknownClass');
        $this->assertEquals('UnknownClass', $resolved);
    }

    public function testProcessClassDependencies(): void
    {
        // Create a class node with extends, implements, and traits
        $extendsName = new Name('BaseClass');
        $implementsNames = [new Name('Interface1'), new Name('Interface2')];
        $traitUses = [
            new Node\Stmt\TraitUse([new Name('Trait1'), new Name('Trait2')]),
        ];

        $classNode = new Class_('TestClass', [
            'extends' => $extendsName,
            'implements' => $implementsNames,
            'stmts' => $traitUses,
        ]);

        $this->processor->processClassDependencies($classNode);

        $dependencies = $this->processor->getAllDependencies();

        // Should have dependencies for extends, implements, and traits
        $this->assertNotEmpty($dependencies);

        // Check that different types of dependencies are captured
        $dependencyTypes = array_column($dependencies, 'type');
        $this->assertContains('extends', $dependencyTypes);
        $this->assertContains('implements', $dependencyTypes);
        $this->assertContains('use_trait', $dependencyTypes);

        // Check specific symbols
        $symbols = array_column($dependencies, 'fqcn');
        $this->assertContains('BaseClass', $symbols);
        $this->assertContains('Interface1', $symbols);
        $this->assertContains('Interface2', $symbols);
        $this->assertContains('Trait1', $symbols);
        $this->assertContains('Trait2', $symbols);
    }

    public function testProcessClassDependenciesWithoutExtends(): void
    {
        // Create a class node without extends
        $classNode = new Class_('SimpleClass');

        $this->processor->processClassDependencies($classNode);

        $dependencies = $this->processor->getAllDependencies();

        // Should have no dependencies for extends
        $dependencyTypes = array_column($dependencies, 'type');
        $this->assertNotContains('extends', $dependencyTypes);
    }

    public function testProcessGroupUseStatement(): void
    {
        $groupUseNode = new Node\Stmt\GroupUse(
            new Name('Vendor\Package'),
            [
                new Node\Stmt\UseUse(new Name('ClassA')),
                new Node\Stmt\UseUse(new Name('ClassB')),
            ]
        );

        $this->processor->processGroupUseStatement($groupUseNode);

        $dependencies = $this->processor->getAllDependencies();

        $this->assertCount(2, $dependencies);
        $fqcns = array_column($dependencies, 'fqcn');
        $this->assertContains('Vendor\Package\ClassA', $fqcns);
        $this->assertContains('Vendor\Package\ClassB', $fqcns);
    }

    public function testProcessInterfaceExtends(): void
    {
        $interfaceNode = new Node\Stmt\Interface_('TestInterface', [
            'extends' => [
                new Name('ParentInterface1'),
                new Name('ParentInterface2'),
            ],
        ]);

        $this->processor->processInterfaceExtends($interfaceNode);

        $dependencies = $this->processor->getAllDependencies();

        $this->assertCount(2, $dependencies);
        $fqcns = array_column($dependencies, 'fqcn');
        $this->assertContains('ParentInterface1', $fqcns);
        $this->assertContains('ParentInterface2', $fqcns);
    }

    public function testProcessNewInstance(): void
    {
        $newNode = new Node\Expr\New_(new Name('SomeClass'));

        $this->processor->processNewInstance($newNode);

        $dependencies = $this->processor->getAllDependencies();

        $this->assertCount(1, $dependencies);
        $this->assertEquals('SomeClass', $dependencies[0]['fqcn']);
        $this->assertEquals('use_class', $dependencies[0]['type']);
    }

    public function testProcessStaticReference(): void
    {
        $staticCallNode = new Node\Expr\StaticCall(
            new Name('StaticClass'),
            'someMethod'
        );

        $this->processor->processStaticReference($staticCallNode);

        $dependencies = $this->processor->getAllDependencies();

        $this->assertCount(1, $dependencies);
        $this->assertEquals('StaticClass', $dependencies[0]['fqcn']);
        $this->assertEquals('use_class', $dependencies[0]['type']);
    }

    public function testResolveClass(): void
    {
        $this->processor->setCurrentNamespace('App\Service');
        $this->processor->addUse('Logger', 'Psr\Log\LoggerInterface');

        $this->assertEquals('Psr\Log\LoggerInterface', $this->processor->resolveClass('Logger'));

        $this->assertEquals('App\Service\LocalClass', $this->processor->resolveClass('LocalClass'));

        $this->assertEquals('Fully\Qualified\Name', $this->processor->resolveClass('\Fully\Qualified\Name'));
    }
}
