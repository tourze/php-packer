<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer;

use PhpPacker\Analyzer\TopologicalSorter;
use PhpPacker\Storage\SqliteStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(TopologicalSorter::class)]
final class TopologicalSorterTest extends TestCase
{
    private TopologicalSorter $sorter;

    protected function setUp(): void
    {
        /*
         * 使用具体类 SqliteStorage 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：SqliteStorage 没有对应的接口拽象，且 TopologicalSorter 构造函数直接依赖具体实现
         * 2) 这种使用是否合理和必要：在单元测试中合理，避免真实数据库操作，专注测试 TopologicalSorter 的拓扑排序算法
         * 3) 是否有更好的替代方案：理想情况下应该为存储层定义接口，但当前架构下使用 mock 是最佳选择
         */
        $storage = $this->createMock(SqliteStorage::class);
        $logger = $this->createMock(LoggerInterface::class);
        $this->sorter = new TopologicalSorter($storage, $logger);
    }

    public function testSortSimpleDependencies(): void
    {
        // A depends on B, B depends on C
        $dependencies = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => [],
        ];

        $sorted = $this->sorter->sort($dependencies);

        $this->assertEquals(['C', 'B', 'A'], $sorted);
    }

    public function testSortComplexDependencies(): void
    {
        // 更复杂的依赖关系
        $dependencies = [
            'A' => ['B', 'C'],
            'B' => ['D'],
            'C' => ['D', 'E'],
            'D' => [],
            'E' => [],
        ];

        $sorted = $this->sorter->sort($dependencies);

        // D 和 E 必须在 B 和 C 之前
        $dIndex = array_search('D', $sorted, true);
        $eIndex = array_search('E', $sorted, true);
        $bIndex = array_search('B', $sorted, true);
        $cIndex = array_search('C', $sorted, true);
        $aIndex = array_search('A', $sorted, true);

        $this->assertLessThan($bIndex, $dIndex);
        $this->assertLessThan($cIndex, $dIndex);
        $this->assertLessThan($cIndex, $eIndex);
        $this->assertLessThan($aIndex, $bIndex);
        $this->assertLessThan($aIndex, $cIndex);
    }

    public function testSortWithNoDependencies(): void
    {
        $dependencies = [
            'A' => [],
            'B' => [],
            'C' => [],
        ];

        $sorted = $this->sorter->sort($dependencies);

        // 没有依赖关系时，顺序可以是任意的，但所有元素都应该存在
        $this->assertCount(3, $sorted);
        $this->assertContains('A', $sorted);
        $this->assertContains('B', $sorted);
        $this->assertContains('C', $sorted);
    }

    public function testSortDetectsCyclicDependencies(): void
    {
        // A -> B -> C -> A (循环依赖)
        $dependencies = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['A'],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cyclic dependency detected');

        $this->sorter->sort($dependencies);
    }

    public function testSortWithSelfDependency(): void
    {
        // A 依赖自己
        $dependencies = [
            'A' => ['A'],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cyclic dependency detected');

        $this->sorter->sort($dependencies);
    }

    public function testSortWithDisconnectedGraphs(): void
    {
        // 两个独立的依赖图
        $dependencies = [
            'A' => ['B'],
            'B' => [],
            'C' => ['D'],
            'D' => [],
        ];

        $sorted = $this->sorter->sort($dependencies);

        $this->assertCount(4, $sorted);

        // B 必须在 A 之前，D 必须在 C 之前
        $aIndex = array_search('A', $sorted, true);
        $bIndex = array_search('B', $sorted, true);
        $cIndex = array_search('C', $sorted, true);
        $dIndex = array_search('D', $sorted, true);

        $this->assertLessThan($aIndex, $bIndex);
        $this->assertLessThan($cIndex, $dIndex);
    }

    public function testSortEmptyDependencies(): void
    {
        $sorted = $this->sorter->sort([]);
        $this->assertEmpty($sorted);
    }

    public function testSortWithMissingDependencies(): void
    {
        // A 依赖不存在的 B
        $dependencies = [
            'A' => ['B'],
        ];

        // 应该处理缺失的依赖
        $sorted = $this->sorter->sort($dependencies);

        $this->assertCount(2, $sorted);
        $this->assertContains('A', $sorted);
        $this->assertContains('B', $sorted);

        // B 应该在 A 之前
        $aIndex = array_search('A', $sorted, true);
        $bIndex = array_search('B', $sorted, true);
        $this->assertLessThan($aIndex, $bIndex);
    }
}
