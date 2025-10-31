<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Generator;

use PhpPacker\Generator\ExecutionNodeExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ExecutionNodeExtractor::class)]
final class ExecutionNodeExtractorTest extends TestCase
{
    private ExecutionNodeExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ExecutionNodeExtractor([]);
    }

    public function testExtractExecutionNodes(): void
    {
        $nodes = [
            new Stmt\Expression(
                new Expr\FuncCall(new Node\Name('echo'), [
                    new Node\Arg(new Node\Scalar\String_('Hello')),
                ])
            ),
            new Stmt\Class_('TestClass'),
            new Stmt\Expression(
                new Expr\Assign(
                    new Expr\Variable('var'),
                    new Node\Scalar\String_('value')
                )
            ),
        ];

        $execNodes = $this->extractor->extract($nodes);

        // 应该提取两个执行节点（两个Expression）
        $this->assertCount(2, $execNodes);
        $this->assertInstanceOf(Stmt\Expression::class, $execNodes[0]);
        $this->assertInstanceOf(Stmt\Expression::class, $execNodes[1]);
    }

    public function testExtractFromNamespace(): void
    {
        $nodes = [
            new Stmt\Namespace_(
                new Node\Name('App'),
                [
                    new Stmt\Expression(
                        new Expr\FuncCall(new Node\Name('bootstrap'))
                    ),
                    new Stmt\Class_('AppClass'),
                ]
            ),
        ];

        $execNodes = $this->extractor->extract($nodes);

        // 应该从命名空间中提取执行节点
        $this->assertCount(1, $execNodes);
        $this->assertInstanceOf(Stmt\Expression::class, $execNodes[0]);
    }

    public function testExtractWithControlStructures(): void
    {
        $nodes = [
            new Stmt\If_(
                new Expr\Variable('condition'),
                [
                    'stmts' => [
                        new Stmt\Expression(new Expr\Exit_()),
                    ],
                ]
            ),
            new Stmt\While_(
                new Expr\ConstFetch(new Node\Name('true')),
                [
                    new Stmt\Expression(new Expr\FuncCall(new Node\Name('process'))),
                ]
            ),
        ];

        $execNodes = $this->extractor->extract($nodes);

        // 控制结构本身是执行节点
        $this->assertCount(2, $execNodes);
        $this->assertInstanceOf(Stmt\If_::class, $execNodes[0]);
        $this->assertInstanceOf(Stmt\While_::class, $execNodes[1]);
    }

    public function testExtractWithTryCatch(): void
    {
        $nodes = [
            new Stmt\TryCatch(
                [
                    new Stmt\Expression(
                        new Expr\FuncCall(new Node\Name('riskyOperation'))
                    ),
                ],
                [
                    new Stmt\Catch_(
                        [new Node\Name('Exception')],
                        new Expr\Variable('e'),
                        []
                    ),
                ]
            ),
        ];

        $execNodes = $this->extractor->extract($nodes);

        // Try-catch 是执行节点
        $this->assertCount(1, $execNodes);
        $this->assertInstanceOf(Stmt\TryCatch::class, $execNodes[0]);
    }

    public function testExtractWithReturn(): void
    {
        $nodes = [
            new Stmt\Return_(
                new Expr\Variable('result')
            ),
        ];

        $execNodes = $this->extractor->extract($nodes);

        // Return 语句是执行节点
        $this->assertCount(1, $execNodes);
        $this->assertInstanceOf(Stmt\Return_::class, $execNodes[0]);
    }

    public function testExtractOnlyDeclarations(): void
    {
        $nodes = [
            new Stmt\Class_('MyClass'),
            new Stmt\Interface_('MyInterface'),
            new Stmt\Trait_('MyTrait'),
            new Stmt\Function_('myFunction'),
            new Stmt\Declare_([]),
            new Stmt\Use_([]),
        ];

        $execNodes = $this->extractor->extract($nodes);

        // 纯声明不是执行节点（除了 Declare 被跳过）
        $this->assertEmpty($execNodes);
    }

    public function testExtractWithUseStatements(): void
    {
        $nodes = [
            new Stmt\Use_([
                new Stmt\UseUse(new Node\Name('Some\Namespace')),
            ]),
            new Stmt\Expression(
                new Expr\New_(new Node\Name('Namespace\Class'))
            ),
        ];

        $execNodes = $this->extractor->extract($nodes);

        // Use 语句不是执行节点，但 new 表达式是
        $this->assertCount(1, $execNodes);
        $this->assertInstanceOf(Stmt\Expression::class, $execNodes[0]);
    }

    public function testExtractEmptyArray(): void
    {
        $execNodes = $this->extractor->extract([]);
        $this->assertEmpty($execNodes);
    }

    public function testExtractWithIncludeStatements(): void
    {
        $nodes = [
            new Stmt\Expression(
                new Expr\Include_(
                    new Node\Scalar\String_('file1.php'),
                    Expr\Include_::TYPE_REQUIRE
                )
            ),
            new Stmt\Expression(
                new Expr\Include_(
                    new Node\Scalar\String_('file2.php'),
                    Expr\Include_::TYPE_REQUIRE_ONCE
                )
            ),
        ];

        $execNodes = $this->extractor->extract($nodes);

        // 两个 include 语句都应该被提取
        $this->assertCount(2, $execNodes);
    }

    public function testExtractWithMergedFiles(): void
    {
        // 创建带有已合并文件的提取器
        $extractorWithMerged = new ExecutionNodeExtractor(['merged.php', 'config.php']);

        $nodes = [
            new Stmt\Expression(
                new Expr\Include_(
                    new Node\Scalar\String_('merged.php'),
                    Expr\Include_::TYPE_REQUIRE
                )
            ),
            new Stmt\Expression(
                new Expr\Include_(
                    new Node\Scalar\String_('other.php'),
                    Expr\Include_::TYPE_REQUIRE
                )
            ),
            new Stmt\Expression(
                new Expr\Include_(
                    new Node\Scalar\String_('config.php'),
                    Expr\Include_::TYPE_REQUIRE_ONCE
                )
            ),
        ];

        $execNodes = $extractorWithMerged->extract($nodes);

        // 只有 'other.php' 应该被保留，因为其他两个已经被合并
        $this->assertCount(1, $execNodes);
        $firstNode = $execNodes[0];
        $this->assertInstanceOf(Stmt\Expression::class, $firstNode);
        $includeExpr = $firstNode->expr;
        $this->assertInstanceOf(Expr\Include_::class, $includeExpr);
        $fileExpr = $includeExpr->expr;
        $this->assertInstanceOf(Node\Scalar\String_::class, $fileExpr);
        $this->assertEquals('other.php', $fileExpr->value);
    }

    public function testExtractWithDynamicInclude(): void
    {
        $nodes = [
            new Stmt\Expression(
                new Expr\Include_(
                    new Expr\Variable('filename'),
                    Expr\Include_::TYPE_INCLUDE
                )
            ),
        ];

        $execNodes = $this->extractor->extract($nodes);

        // 动态包含应该被保留
        $this->assertCount(1, $execNodes);
    }

    public function testExtractWithGlobalStatements(): void
    {
        $nodes = [
            new Stmt\Global_([
                new Expr\Variable('globalVar'),
            ]),
            new Stmt\Static_([
                new Stmt\StaticVar(new Expr\Variable('staticVar')),
            ]),
            new Stmt\Echo_([
                new Node\Scalar\String_('Hello'),
            ]),
        ];

        $execNodes = $this->extractor->extract($nodes);

        // 所有语句都是执行节点
        $this->assertCount(3, $execNodes);
        $this->assertInstanceOf(Stmt\Global_::class, $execNodes[0]);
        $this->assertInstanceOf(Stmt\Static_::class, $execNodes[1]);
        $this->assertInstanceOf(Stmt\Echo_::class, $execNodes[2]);
    }

    public function testExtractNestedNamespaces(): void
    {
        $nodes = [
            new Stmt\Namespace_(
                new Node\Name('Outer'),
                [
                    new Stmt\Namespace_(
                        new Node\Name('Inner'),
                        [
                            new Stmt\Expression(
                                new Expr\FuncCall(new Node\Name('test'))
                            ),
                        ]
                    ),
                ]
            ),
        ];

        $execNodes = $this->extractor->extract($nodes);

        // 应该递归提取嵌套命名空间中的执行代码
        $this->assertCount(1, $execNodes);
        $this->assertInstanceOf(Stmt\Expression::class, $execNodes[0]);
    }
}
