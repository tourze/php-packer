<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\NodeDeduplicator;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(NodeDeduplicator::class)]
final class NodeDeduplicatorTest extends TestCase
{
    private NodeDeduplicator $deduplicator;

    protected function setUp(): void
    {
        $logger = new NullLogger();
        $this->deduplicator = new NodeDeduplicator($logger);
    }

    public function testDeduplicateClasses(): void
    {
        $class1 = new Stmt\Class_('TestClass');
        $class2 = new Stmt\Class_('TestClass'); // 重复
        $class3 = new Stmt\Class_('AnotherClass');

        $nodes = [$class1, $class2, $class3];
        $deduplicated = $this->deduplicator->deduplicateNodes($nodes);

        $this->assertCount(2, $deduplicated);
        $this->assertContains($class1, $deduplicated);
        $this->assertContains($class3, $deduplicated);
        $this->assertNotContains($class2, $deduplicated);
    }

    public function testDeduplicateFunctions(): void
    {
        $func1 = new Stmt\Function_('testFunction');
        $func2 = new Stmt\Function_('testFunction'); // 重复
        $func3 = new Stmt\Function_('anotherFunction');

        $nodes = [$func1, $func2, $func3];
        $deduplicated = $this->deduplicator->deduplicateNodes($nodes);

        $this->assertCount(2, $deduplicated);
    }

    public function testDeduplicateUseStatements(): void
    {
        $use1 = new Stmt\Use_([
            new Stmt\UseUse(new Node\Name('Vendor\Package\Class1')),
        ]);
        $use2 = new Stmt\Use_([
            new Stmt\UseUse(new Node\Name('Vendor\Package\Class1')), // 重复
        ]);
        $use3 = new Stmt\Use_([
            new Stmt\UseUse(new Node\Name('Vendor\Package\Class2')),
        ]);

        $nodes = [$use1, $use2, $use3];
        $deduplicated = $this->deduplicator->deduplicateNodes($nodes);

        $this->assertCount(2, $deduplicated);
    }

    public function testDeduplicateWithDifferentContent(): void
    {
        // 同名但内容不同的类
        $class1 = new Stmt\Class_('TestClass', [
            'stmts' => [
                new Stmt\ClassMethod('method1'),
            ],
        ]);

        $class2 = new Stmt\Class_('TestClass', [
            'stmts' => [
                new Stmt\ClassMethod('method2'),
            ],
        ]);

        $nodes = [$class1, $class2];
        $deduplicated = $this->deduplicator->deduplicateNodes($nodes);

        // 应该保留两个，因为内容不同
        $this->assertCount(2, $deduplicated);
    }

    public function testDeduplicateNamespaces(): void
    {
        $ns1 = new Stmt\Namespace_(new Node\Name('App\Service'));
        $ns2 = new Stmt\Namespace_(new Node\Name('App\Service')); // 重复
        $ns3 = new Stmt\Namespace_(new Node\Name('App\Repository'));

        $nodes = [$ns1, $ns2, $ns3];
        $deduplicated = $this->deduplicator->deduplicateNodes($nodes);

        $this->assertCount(2, $deduplicated);
    }

    public function testDeduplicateMixedNodes(): void
    {
        $nodes = [
            new Stmt\Class_('Class1'),
            new Stmt\Class_('Class1'), // 重复
            new Stmt\Function_('func1'),
            new Stmt\Function_('func1'), // 重复
            new Stmt\Interface_('Interface1'),
            new Stmt\Interface_('Interface1'), // 重复
            new Stmt\Trait_('Trait1'),
            new Stmt\Echo_([new Node\Scalar\String_('test')]),
        ];

        $deduplicated = $this->deduplicator->deduplicateNodes($nodes);

        // 应该有5个唯一节点
        $this->assertCount(5, $deduplicated);
    }

    public function testDeduplicateNodesMethodWithEmptyArray(): void
    {
        $result = $this->deduplicator->deduplicateNodes([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDeduplicateNodesMethodWithUniqueNodes(): void
    {
        $nodes = [
            new Stmt\Class_('Class1'),
            new Stmt\Class_('Class2'),
            new Stmt\Function_('func1'),
        ];

        $deduplicated = $this->deduplicator->deduplicateNodes($nodes);

        $this->assertCount(3, $deduplicated);
        $this->assertSame($nodes, $deduplicated);
    }

    public function testGetDuplicateStats(): void
    {
        $nodes = [
            new Stmt\Class_('Class1'),
            new Stmt\Class_('Class1'),
            new Stmt\Class_('Class1'),
            new Stmt\Function_('func1'),
            new Stmt\Function_('func1'),
        ];

        $this->deduplicator->deduplicate($nodes);
        $stats = $this->deduplicator->getDuplicateStats();

        $this->assertEquals(5, $stats['total_nodes']);
        $this->assertEquals(2, $stats['unique_nodes']);
        $this->assertEquals(3, $stats['duplicates_removed']);
        $this->assertArrayHasKey('duplicate_types', $stats);
        $this->assertEquals(2, $stats['duplicate_types']['class']);
        $this->assertEquals(1, $stats['duplicate_types']['function']);
    }

    public function testDeduplicateEmptyArray(): void
    {
        $deduplicated = $this->deduplicator->deduplicate([]);
        $this->assertEmpty($deduplicated);
    }

    public function testDeduplicateWithCustomComparator(): void
    {
        // 设置自定义比较器，只比较类名，忽略内容
        $this->deduplicator->setComparator(function ($node1, $node2) {
            if ($node1 instanceof Stmt\Class_ && $node2 instanceof Stmt\Class_) {
                return null !== $node1->name && null !== $node2->name
                    && $node1->name->toString() === $node2->name->toString();
            }

            return false;
        });

        $class1 = new Stmt\Class_('TestClass', ['stmts' => [new Stmt\ClassMethod('method1')]]);
        $class2 = new Stmt\Class_('TestClass', ['stmts' => [new Stmt\ClassMethod('method2')]]);

        $nodes = [$class1, $class2];
        $deduplicated = $this->deduplicator->deduplicateNodes($nodes);

        // 使用自定义比较器，应该只保留一个
        $this->assertCount(1, $deduplicated);
    }

    public function testReset(): void
    {
        $nodes = [
            new Stmt\Class_('Class1'),
            new Stmt\Class_('Class1'),
        ];

        $this->deduplicator->deduplicate($nodes);
        $this->assertNotEmpty($this->deduplicator->getDuplicateStats()['duplicates_removed']);

        $this->deduplicator->reset();

        $stats = $this->deduplicator->getDuplicateStats();
        $this->assertEquals(0, $stats['total_nodes']);
        $this->assertEquals(0, $stats['duplicates_removed']);
    }
}
