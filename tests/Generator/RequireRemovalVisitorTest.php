<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Generator;

use PhpPacker\Generator\RequireRemovalVisitor;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RequireRemovalVisitor::class)]
final class RequireRemovalVisitorTest extends TestCase
{
    protected function setUp(): void
    {
        // No setup needed for this test
    }

    public function testConstructor(): void
    {
        $visitor = new RequireRemovalVisitor([]);
        $this->assertInstanceOf(RequireRemovalVisitor::class, $visitor);
    }

    public function testLeaveNodeRemovesAutoloadRequire(): void
    {
        $visitor = new RequireRemovalVisitor([]);

        // 创建一个 require 'vendor/autoload.php' 的节点
        $includeExpr = new Node\Expr\Include_(
            new Node\Scalar\String_('vendor/autoload.php'),
            Node\Expr\Include_::TYPE_REQUIRE
        );
        $node = new Node\Stmt\Expression($includeExpr);

        $result = $visitor->leaveNode($node);

        // 应该返回空数组表示删除该节点
        $this->assertSame([], $result);
    }

    public function testLeaveNodeRemovesMergedFileRequire(): void
    {
        $visitor = new RequireRemovalVisitor(['src/Helper.php']);

        // 创建一个 require 'src/Helper.php' 的节点
        $includeExpr = new Node\Expr\Include_(
            new Node\Scalar\String_('src/Helper.php'),
            Node\Expr\Include_::TYPE_REQUIRE
        );
        $node = new Node\Stmt\Expression($includeExpr);

        $result = $visitor->leaveNode($node);

        // 应该返回空数组表示删除该节点
        $this->assertSame([], $result);
    }

    public function testLeaveNodeKeepsUnmergedFileRequire(): void
    {
        $visitor = new RequireRemovalVisitor(['src/Helper.php']);

        // 创建一个 require 'src/Other.php' 的节点（未合并）
        $includeExpr = new Node\Expr\Include_(
            new Node\Scalar\String_('src/Other.php'),
            Node\Expr\Include_::TYPE_REQUIRE
        );
        $node = new Node\Stmt\Expression($includeExpr);

        $result = $visitor->leaveNode($node);

        // 应该返回 null 表示保留该节点
        $this->assertNull($result);
    }

    public function testLeaveNodeIgnoresNonRequireNodes(): void
    {
        $visitor = new RequireRemovalVisitor([]);

        // 创建一个普通表达式节点（非 require）
        $node = new Node\Stmt\Expression(
            new Node\Scalar\String_('test')
        );

        $result = $visitor->leaveNode($node);

        // 应该返回 null 表示不处理
        $this->assertNull($result);
    }
}
