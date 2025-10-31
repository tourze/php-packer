<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\DuplicationHandler;
use PhpPacker\Merger\NodeSymbolExtractor;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(DuplicationHandler::class)]
final class DuplicationHandlerTest extends TestCase
{
    private DuplicationHandler $handler;

    protected function setUp(): void
    {
        $logger = new NullLogger();
        $symbolExtractor = new NodeSymbolExtractor();
        $this->handler = new DuplicationHandler($logger, $symbolExtractor);
    }

    public function testHandleDuplicateSymbol(): void
    {
        $node = new Node\Stmt\Function_('test');
        $symbol = 'test';
        $state = [
            'uniqueNodes' => [],
            'seenSymbols' => [],
            'conditionalFunctions' => [],
        ];

        $state = $this->handler->handleDuplicateSymbol($node, $symbol, $state);

        $this->assertArrayHasKey('conditionalFunctions', $state);
    }

    public function testAreClassesEqual(): void
    {
        // 测试相同的类（相同的方法）
        $class1 = new Node\Stmt\Class_('TestClass', [
            'stmts' => [
                new Node\Stmt\ClassMethod('method1'),
                new Node\Stmt\ClassMethod('method2'),
            ],
        ]);

        $class2 = new Node\Stmt\Class_('TestClass', [
            'stmts' => [
                new Node\Stmt\ClassMethod('method1'),
                new Node\Stmt\ClassMethod('method2'),
            ],
        ]);

        $this->assertTrue($this->handler->areClassesEqual($class1, $class2));
    }

    public function testAreClassesEqualWithDifferentMethodCount(): void
    {
        $class1 = new Node\Stmt\Class_('TestClass', [
            'stmts' => [
                new Node\Stmt\ClassMethod('method1'),
            ],
        ]);

        $class2 = new Node\Stmt\Class_('TestClass', [
            'stmts' => [
                new Node\Stmt\ClassMethod('method1'),
                new Node\Stmt\ClassMethod('method2'),
            ],
        ]);

        $this->assertFalse($this->handler->areClassesEqual($class1, $class2));
    }

    public function testAreClassesEqualWithDifferentMethodNames(): void
    {
        $class1 = new Node\Stmt\Class_('TestClass', [
            'stmts' => [
                new Node\Stmt\ClassMethod('method1'),
                new Node\Stmt\ClassMethod('method2'),
            ],
        ]);

        $class2 = new Node\Stmt\Class_('TestClass', [
            'stmts' => [
                new Node\Stmt\ClassMethod('method1'),
                new Node\Stmt\ClassMethod('method3'),
            ],
        ]);

        $this->assertFalse($this->handler->areClassesEqual($class1, $class2));
    }

    public function testAreClassesEqualWithDifferentMethodOrder(): void
    {
        // 方法顺序不同，但方法名相同，应该相等
        $class1 = new Node\Stmt\Class_('TestClass', [
            'stmts' => [
                new Node\Stmt\ClassMethod('methodA'),
                new Node\Stmt\ClassMethod('methodB'),
            ],
        ]);

        $class2 = new Node\Stmt\Class_('TestClass', [
            'stmts' => [
                new Node\Stmt\ClassMethod('methodB'),
                new Node\Stmt\ClassMethod('methodA'),
            ],
        ]);

        $this->assertTrue($this->handler->areClassesEqual($class1, $class2));
    }

    public function testAreClassesEqualWithNoMethods(): void
    {
        $class1 = new Node\Stmt\Class_('EmptyClass', [
            'stmts' => [],
        ]);

        $class2 = new Node\Stmt\Class_('EmptyClass', [
            'stmts' => [],
        ]);

        $this->assertTrue($this->handler->areClassesEqual($class1, $class2));
    }

    public function testAreClassesEqualWithNonMethodStatements(): void
    {
        // 类中包含非方法语句（如属性），应该被忽略
        $class1 = new Node\Stmt\Class_('TestClass', [
            'stmts' => [
                new Node\Stmt\Property(0, [new Node\Stmt\PropertyProperty('property1')]),
                new Node\Stmt\ClassMethod('method1'),
            ],
        ]);

        $class2 = new Node\Stmt\Class_('TestClass', [
            'stmts' => [
                new Node\Stmt\Property(0, [new Node\Stmt\PropertyProperty('property2')]),
                new Node\Stmt\ClassMethod('method1'),
            ],
        ]);

        // 只比较方法，属性不同不影响
        $this->assertTrue($this->handler->areClassesEqual($class1, $class2));
    }

    public function testAreClassesEqualWithNullStmts(): void
    {
        $class1 = new Node\Stmt\Class_('TestClass');
        $class2 = new Node\Stmt\Class_('TestClass');

        // 两个类都没有语句，应该相等
        $this->assertTrue($this->handler->areClassesEqual($class1, $class2));
    }
}
