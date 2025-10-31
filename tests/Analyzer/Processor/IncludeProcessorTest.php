<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer\Processor;

use PhpPacker\Analyzer\Processor\IncludeProcessor;
use PhpPacker\Storage\SqliteStorage;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(IncludeProcessor::class)]
final class IncludeProcessorTest extends TestCase
{
    private IncludeProcessor $processor;

    private SqliteStorage $storage;

    protected function setUp(): void
    {
        /*
         * 使用具体类 SqliteStorage 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：SqliteStorage 没有对应的接口抽象，且 IncludeProcessor 构造函数直接依赖具体实现
         * 2) 这种使用是否合理和必要：在单元测试中合理，避免真实数据库操作，专注测试 IncludeProcessor 的 include/require 处理逻辑
         * 3) 是否有更好的替代方案：理想情况下应该为存储层定义接口，但当前架构下使用 mock 是最佳选择
         */
        $this->storage = $this->createMock(SqliteStorage::class);
        $fileId = 1;
        $this->processor = new IncludeProcessor($this->storage, $fileId);
    }

    public function testProcessInclude(): void
    {
        $this->storage->expects($this->once())
            ->method('addDependency')
            ->with(
                1, // fileId
                'include',
                null,
                1, // line number
                false, // isConditional
                'test.php' // context
            )
        ;

        $includeNode = new Include_(
            new String_('test.php'),
            Include_::TYPE_INCLUDE
        );
        $includeNode->setAttributes(['startLine' => 1]);

        $this->processor->processInclude($includeNode, false);
        $this->assertEquals(1, $this->processor->getDependencyCount());
    }

    public function testProcessIncludeOnce(): void
    {
        $this->storage->expects($this->once())
            ->method('addDependency')
            ->with(
                1, // fileId
                'include_once',
                null,
                2, // line number
                false, // isConditional
                'once.php' // context
            )
        ;

        $includeNode = new Include_(
            new String_('once.php'),
            Include_::TYPE_INCLUDE_ONCE
        );
        $includeNode->setAttributes(['startLine' => 2]);

        $this->processor->processInclude($includeNode, false);
        $this->assertEquals(1, $this->processor->getDependencyCount());
    }

    public function testProcessRequire(): void
    {
        $this->storage->expects($this->once())
            ->method('addDependency')
            ->with(
                1, // fileId
                'require',
                null,
                3, // line number
                false, // isConditional
                'required.php' // context
            )
        ;

        $includeNode = new Include_(
            new String_('required.php'),
            Include_::TYPE_REQUIRE
        );
        $includeNode->setAttributes(['startLine' => 3]);

        $this->processor->processInclude($includeNode, false);
        $this->assertEquals(1, $this->processor->getDependencyCount());
    }

    public function testProcessRequireOnce(): void
    {
        $this->storage->expects($this->once())
            ->method('addDependency')
            ->with(
                1, // fileId
                'require_once',
                null,
                4, // line number
                false, // isConditional
                'required_once.php' // context
            )
        ;

        $includeNode = new Include_(
            new String_('required_once.php'),
            Include_::TYPE_REQUIRE_ONCE
        );
        $includeNode->setAttributes(['startLine' => 4]);

        $this->processor->processInclude($includeNode, false);
        $this->assertEquals(1, $this->processor->getDependencyCount());
    }

    public function testProcessConditionalInclude(): void
    {
        $this->storage->expects($this->once())
            ->method('addDependency')
            ->with(
                1, // fileId
                'include',
                null,
                5, // line number
                true, // isConditional
                'conditional.php' // context
            )
        ;

        $includeNode = new Include_(
            new String_('conditional.php'),
            Include_::TYPE_INCLUDE
        );
        $includeNode->setAttributes(['startLine' => 5]);

        $this->processor->processInclude($includeNode, true);
        $this->assertEquals(1, $this->processor->getDependencyCount());
    }

    public function testDependencyCountIncreases(): void
    {
        $this->storage->expects($this->exactly(3))
            ->method('addDependency')
        ;

        $includeNode1 = new Include_(new String_('file1.php'), Include_::TYPE_INCLUDE);
        $includeNode1->setAttributes(['startLine' => 1]);

        $includeNode2 = new Include_(new String_('file2.php'), Include_::TYPE_REQUIRE);
        $includeNode2->setAttributes(['startLine' => 2]);

        $includeNode3 = new Include_(new String_('file3.php'), Include_::TYPE_INCLUDE_ONCE);
        $includeNode3->setAttributes(['startLine' => 3]);

        $this->processor->processInclude($includeNode1, false);
        $this->assertEquals(1, $this->processor->getDependencyCount());

        $this->processor->processInclude($includeNode2, false);
        $this->assertEquals(2, $this->processor->getDependencyCount());

        $this->processor->processInclude($includeNode3, false);
        $this->assertEquals(3, $this->processor->getDependencyCount());
    }
}
