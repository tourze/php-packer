<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer;

use PhpPacker\Analyzer\AnalysisVisitor;
use PhpPacker\Storage\SqliteStorage;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AnalysisVisitor::class)]
final class AnalysisVisitorTest extends TestCase
{
    protected function setUp(): void
    {
        // No setup needed for this test
    }

    public function testGetSymbolCount(): void
    {
        /*
         * 使用具体类 SqliteStorage 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：SqliteStorage 没有对应的接口抽象，且 AnalysisVisitor 构造函数直接依赖具体实现
         * 2) 这种使用是否合理和必要：在单元测试中合理，因为我们只需要验证 AnalysisVisitor 的行为而不关心存储的具体实现
         * 3) 是否有更好的替代方案：理想情况下应该为存储层定义接口，但当前架构下使用 mock 是最佳选择
         */
        $storage = $this->createMock(SqliteStorage::class);
        $visitor = new AnalysisVisitor($storage, 1, null, '/test/file.php');

        $this->assertSame(0, $visitor->getSymbolCount());
    }

    public function testGetDependencyCount(): void
    {
        /*
         * 使用具体类 SqliteStorage 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：SqliteStorage 没有对应的接口抽象，且 AnalysisVisitor 构造函数直接依赖具体实现
         * 2) 这种使用是否合理和必要：在单元测试中合理，因为我们只需要验证 AnalysisVisitor 的行为而不关心存储的具体实现
         * 3) 是否有更好的替代方案：理想情况下应该为存储层定义接口，但当前架构下使用 mock 是最佳选择
         */
        $storage = $this->createMock(SqliteStorage::class);
        $visitor = new AnalysisVisitor($storage, 1, null, '/test/file.php');

        $this->assertSame(0, $visitor->getDependencyCount());
    }

    public function testEnterNode(): void
    {
        /*
         * 使用具体类 SqliteStorage 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：SqliteStorage 没有对应的接口抽象，且 AnalysisVisitor 构造函数直接依赖具体实现
         * 2) 这种使用是否合理和必要：在单元测试中合理，因为我们只需要验证 AnalysisVisitor 的行为而不关心存储的具体实现
         * 3) 是否有更好的替代方案：理想情况下应该为存储层定义接口，但当前架构下使用 mock 是最佳选择
         */
        $storage = $this->createMock(SqliteStorage::class);
        $visitor = new AnalysisVisitor($storage, 1, null, '/test/file.php');

        $node = $this->createMock(Node::class);
        $result = $visitor->enterNode($node);

        $this->assertNull($result);
    }
}
