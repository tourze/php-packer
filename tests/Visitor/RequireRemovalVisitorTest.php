<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Visitor;

use PhpPacker\Visitor\RequireRemovalVisitor;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RequireRemovalVisitor::class)]
final class RequireRemovalVisitorTest extends TestCase
{
    public function testConstructor(): void
    {
        $visitor = new RequireRemovalVisitor([]);
        $this->assertInstanceOf(RequireRemovalVisitor::class, $visitor);
    }

    public function testConstructorWithRemoveAll(): void
    {
        $visitor = new RequireRemovalVisitor([], true);
        $this->assertInstanceOf(RequireRemovalVisitor::class, $visitor);
    }

    public function testLeaveNode(): void
    {
        // 通用的 testLeaveNode 测试方法，覆盖核心功能
        $mergedFiles = ['test.php', 'helper.php'];
        $visitor = new RequireRemovalVisitor($mergedFiles);

        // 测试移除已合并的文件
        $includeNode = new Node\Stmt\Expression(
            new Node\Expr\Include_(
                new Node\Scalar\String_('test.php'),
                Node\Expr\Include_::TYPE_REQUIRE
            )
        );

        $result = $visitor->leaveNode($includeNode);
        $this->assertEquals(NodeVisitorAbstract::REMOVE_NODE, $result);

        // 测试保留未合并的文件
        $otherNode = new Node\Stmt\Expression(
            new Node\Expr\Include_(
                new Node\Scalar\String_('other.php'),
                Node\Expr\Include_::TYPE_REQUIRE
            )
        );

        $result = $visitor->leaveNode($otherNode);
        $this->assertNull($result);
    }

    public function testLeaveNodeRemovesAllWhenRemoveAllIsTrue(): void
    {
        $visitor = new RequireRemovalVisitor([], true);

        $includeNode = new Node\Stmt\Expression(
            new Node\Expr\Include_(
                new Node\Scalar\String_('test.php'),
                Node\Expr\Include_::TYPE_REQUIRE
            )
        );

        $result = $visitor->leaveNode($includeNode);

        $this->assertEquals(NodeVisitorAbstract::REMOVE_NODE, $result);
    }

    public function testLeaveNodeRemovesMergedFile(): void
    {
        $mergedFiles = ['src/test.php', 'src/helper.php'];
        $visitor = new RequireRemovalVisitor($mergedFiles);

        $includeNode = new Node\Stmt\Expression(
            new Node\Expr\Include_(
                new Node\Scalar\String_('src/test.php'),
                Node\Expr\Include_::TYPE_REQUIRE
            )
        );

        $result = $visitor->leaveNode($includeNode);

        $this->assertEquals(NodeVisitorAbstract::REMOVE_NODE, $result);
    }

    public function testLeaveNodeKeepsNonMergedFile(): void
    {
        $mergedFiles = ['src/test.php'];
        $visitor = new RequireRemovalVisitor($mergedFiles);

        $includeNode = new Node\Stmt\Expression(
            new Node\Expr\Include_(
                new Node\Scalar\String_('src/other.php'),
                Node\Expr\Include_::TYPE_REQUIRE
            )
        );

        $result = $visitor->leaveNode($includeNode);

        $this->assertNull($result);
    }

    public function testLeaveNodeIgnoresNonIncludeNodes(): void
    {
        $visitor = new RequireRemovalVisitor(['test.php']);

        $classNode = new Node\Stmt\Class_('TestClass');

        $result = $visitor->leaveNode($classNode);

        $this->assertNull($result);
    }

    public function testLeaveNodeIgnoresNonExpressionStatements(): void
    {
        $visitor = new RequireRemovalVisitor(['test.php']);

        $echoNode = new Node\Stmt\Echo_([
            new Node\Scalar\String_('Hello World'),
        ]);

        $result = $visitor->leaveNode($echoNode);

        $this->assertNull($result);
    }

    public function testLeaveNodeIgnoresExpressionWithoutInclude(): void
    {
        $visitor = new RequireRemovalVisitor(['test.php']);

        $expressionNode = new Node\Stmt\Expression(
            new Node\Expr\Variable('test')
        );

        $result = $visitor->leaveNode($expressionNode);

        $this->assertNull($result);
    }

    public function testLeaveNodeIgnoresIncludeWithNonStringExpression(): void
    {
        $visitor = new RequireRemovalVisitor(['test.php']);

        $includeNode = new Node\Stmt\Expression(
            new Node\Expr\Include_(
                new Node\Expr\Variable('filename'),
                Node\Expr\Include_::TYPE_REQUIRE
            )
        );

        $result = $visitor->leaveNode($includeNode);

        $this->assertNull($result);
    }

    public function testLeaveNodeHandlesBasenameMatching(): void
    {
        $mergedFiles = ['/full/path/to/test.php'];
        $visitor = new RequireRemovalVisitor($mergedFiles);

        $includeNode = new Node\Stmt\Expression(
            new Node\Expr\Include_(
                new Node\Scalar\String_('test.php'),
                Node\Expr\Include_::TYPE_REQUIRE
            )
        );

        $result = $visitor->leaveNode($includeNode);

        $this->assertEquals(NodeVisitorAbstract::REMOVE_NODE, $result);
    }

    public function testLeaveNodeHandlesPathEndMatching(): void
    {
        $mergedFiles = ['src/lib/helper.php'];
        $visitor = new RequireRemovalVisitor($mergedFiles);

        $includeNode = new Node\Stmt\Expression(
            new Node\Expr\Include_(
                new Node\Scalar\String_('lib/helper.php'),
                Node\Expr\Include_::TYPE_REQUIRE
            )
        );

        $result = $visitor->leaveNode($includeNode);

        $this->assertEquals(NodeVisitorAbstract::REMOVE_NODE, $result);
    }

    public function testLeaveNodeHandlesReversePathEndMatching(): void
    {
        $mergedFiles = ['helper.php'];
        $visitor = new RequireRemovalVisitor($mergedFiles);

        $includeNode = new Node\Stmt\Expression(
            new Node\Expr\Include_(
                new Node\Scalar\String_('src/lib/helper.php'),
                Node\Expr\Include_::TYPE_REQUIRE
            )
        );

        $result = $visitor->leaveNode($includeNode);

        $this->assertEquals(NodeVisitorAbstract::REMOVE_NODE, $result);
    }

    public function testLeaveNodeWithDifferentIncludeTypes(): void
    {
        $mergedFiles = ['test.php'];
        $visitor = new RequireRemovalVisitor($mergedFiles);

        // Test require_once
        $requireOnceNode = new Node\Stmt\Expression(
            new Node\Expr\Include_(
                new Node\Scalar\String_('test.php'),
                Node\Expr\Include_::TYPE_REQUIRE_ONCE
            )
        );

        $result = $visitor->leaveNode($requireOnceNode);
        $this->assertEquals(NodeVisitorAbstract::REMOVE_NODE, $result);

        // Test include
        $includeNode = new Node\Stmt\Expression(
            new Node\Expr\Include_(
                new Node\Scalar\String_('test.php'),
                Node\Expr\Include_::TYPE_INCLUDE
            )
        );

        $result = $visitor->leaveNode($includeNode);
        $this->assertEquals(NodeVisitorAbstract::REMOVE_NODE, $result);

        // Test include_once
        $includeOnceNode = new Node\Stmt\Expression(
            new Node\Expr\Include_(
                new Node\Scalar\String_('test.php'),
                Node\Expr\Include_::TYPE_INCLUDE_ONCE
            )
        );

        $result = $visitor->leaveNode($includeOnceNode);
        $this->assertEquals(NodeVisitorAbstract::REMOVE_NODE, $result);
    }

    public function testLeaveNodeWithEmptyMergedFilesList(): void
    {
        $visitor = new RequireRemovalVisitor([]);

        $includeNode = new Node\Stmt\Expression(
            new Node\Expr\Include_(
                new Node\Scalar\String_('test.php'),
                Node\Expr\Include_::TYPE_REQUIRE
            )
        );

        $result = $visitor->leaveNode($includeNode);

        $this->assertNull($result);
    }
}
