<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Generator;

use PhpPacker\Generator\SmartCommentRemovalVisitor;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SmartCommentRemovalVisitor::class)]
final class SmartCommentRemovalVisitorTest extends TestCase
{
    protected function setUp(): void
    {
        // No setup needed for this test
    }

    public function testConstructor(): void
    {
        $visitor = new SmartCommentRemovalVisitor();
        $this->assertInstanceOf(SmartCommentRemovalVisitor::class, $visitor);
    }

    public function testEnterNode(): void
    {
        $visitor = new SmartCommentRemovalVisitor();

        // 测试普通节点 - 注释应该被移除
        $classNode = new Class_('TestClass');
        $classNode->setAttribute('comments', [
            new Comment('// This is a comment'),
        ]);

        $result = $visitor->enterNode($classNode);

        $this->assertNull($result);
        $this->assertEmpty($classNode->getAttribute('comments'));
    }

    public function testEnterNodeRemovesAllCommentsFromRegularNodes(): void
    {
        $visitor = new SmartCommentRemovalVisitor();

        $expressionNode = new Expression(
            new Variable('test')
        );
        $expressionNode->setAttribute('comments', [
            new Comment('// Comment 1'),
            new Comment('// Comment 2'),
        ]);

        $visitor->enterNode($expressionNode);

        $this->assertEmpty($expressionNode->getAttribute('comments'));
    }

    public function testEnterNodeWithMethodNodeRemovesComments(): void
    {
        $visitor = new SmartCommentRemovalVisitor();

        // 创建一个带完整类型提示的方法，所有注解都是冗余的
        $param = new Param(
            new Variable('value'),
            null,
            new Name('string')
        );

        $methodNode = new ClassMethod('testMethod', [
            'params' => [$param],
            'returnType' => new Name('string'),
        ]);

        // 添加冗余的注释
        $methodNode->setDocComment(new Doc(
            "/**\n * @param string \$value\n * @return string\n */"
        ));

        $visitor->enterNode($methodNode);

        $docComment = $methodNode->getDocComment();
        // 所有注解都是冗余的，应该被完全移除
        $this->assertNull($docComment);
    }

    public function testEnterNodeWithAnnotationsOnMethod(): void
    {
        $visitor = new SmartCommentRemovalVisitor();

        // 创建一个带注解的方法（使用一行格式）
        $methodNode = new ClassMethod('importantMethod');
        $methodNode->setDocComment(new Doc(
            '/** @deprecated Use newMethod instead */'
        ));

        $result = $visitor->enterNode($methodNode);

        // enterNode 应该返回 null（继续遍历）
        $this->assertNull($result);

        // 检查节点的 comments 属性被清除
        $this->assertEmpty($methodNode->getAttribute('comments'));
    }

    public function testEnterNodeFiltersRedundantComments(): void
    {
        $visitor = new SmartCommentRemovalVisitor();

        // 创建一个带完整类型提示的方法
        $param = new Param(
            new Variable('value'),
            null,
            new Name('string')
        );

        $methodNode = new ClassMethod('testMethod', [
            'params' => [$param],
            'returnType' => new Name('string'),
        ]);

        // 添加冗余注释（类型已经在代码中声明）
        $methodNode->setDocComment(new Doc(
            '/** @param string $value @return string */'
        ));

        $visitor->enterNode($methodNode);

        // 冗余的注释应该被移除
        $docComment = $methodNode->getDocComment();
        $this->assertNull($docComment);
    }

    public function testEnterNodeWithFunctionNode(): void
    {
        $visitor = new SmartCommentRemovalVisitor();

        // 创建一个带类型提示的函数
        $param = new Param(
            new Variable('test'),
            null,
            new Name('int')
        );

        $functionNode = new Function_('testFunction', [
            'params' => [$param],
            'returnType' => new Name('bool'),
        ]);

        $functionNode->setDocComment(new Doc(
            '/** @param int $test @return bool */'
        ));

        $result = $visitor->enterNode($functionNode);

        // 应该返回 null 并处理注释
        $this->assertNull($result);

        // 冗余的注解应该被移除
        $docComment = $functionNode->getDocComment();
        $this->assertNull($docComment);
    }

    public function testEnterNodeProcessesDifferentNodeTypes(): void
    {
        $visitor = new SmartCommentRemovalVisitor();

        // 测试类方法
        $methodNode = new ClassMethod('method');
        $methodNode->setAttribute('comments', [new Comment('// comment')]);
        $result = $visitor->enterNode($methodNode);
        $this->assertNull($result);
        $this->assertEmpty($methodNode->getAttribute('comments'));

        // 测试函数
        $functionNode = new Function_('func');
        $functionNode->setAttribute('comments', [new Comment('// comment')]);
        $result = $visitor->enterNode($functionNode);
        $this->assertNull($result);
        $this->assertEmpty($functionNode->getAttribute('comments'));

        // 测试普通节点（不是函数/方法）
        $classNode = new Class_('TestClass');
        $classNode->setAttribute('comments', [new Comment('// comment')]);
        $result = $visitor->enterNode($classNode);
        $this->assertNull($result);
        $this->assertEmpty($classNode->getAttribute('comments'));
    }
}
