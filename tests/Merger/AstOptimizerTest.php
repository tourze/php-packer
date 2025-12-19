<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\AstOptimizer;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Echo_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(AstOptimizer::class)]
final class AstOptimizerTest extends TestCase
{
    private AstOptimizer $optimizer;

    protected function setUp(): void
    {
        $logger = new NullLogger();
        $this->optimizer = new AstOptimizer($logger);
    }

    public function testOptimizeAst(): void
    {
        // 创建一个简单的AST节点数组用于测试
        $ast = [
            new Echo_([new String_('Hello, World!')]),
            new Variable('test'),
        ];

        $result = $this->optimizer->optimizeAst($ast);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(Echo_::class, $result[0]);
        $this->assertInstanceOf(Variable::class, $result[1]);
    }

    public function testOptimizeEmptyAst(): void
    {
        $ast = [];
        $result = $this->optimizer->optimizeAst($ast);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testOptimizerWithLogger(): void
    {
        $logger = new NullLogger();
        $optimizer = new AstOptimizer($logger);

        $ast = [new Variable('test')];
        $result = $optimizer->optimizeAst($ast);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }
}
