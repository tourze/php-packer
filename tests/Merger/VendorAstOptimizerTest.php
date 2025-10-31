<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\VendorAstOptimizer;
use PhpParser\Modifiers;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(VendorAstOptimizer::class)]
final class VendorAstOptimizerTest extends TestCase
{
    private VendorAstOptimizer $optimizer;

    protected function setUp(): void
    {
        $this->optimizer = new VendorAstOptimizer();
    }

    public function testOptimize(): void
    {
        $ast = [
            new Node\Stmt\Class_('TestClass', [
                'stmts' => [
                    new Node\Stmt\ClassMethod('publicMethod', [
                        'flags' => Modifiers::PUBLIC,
                        'stmts' => [],
                    ]),
                    new Node\Stmt\ClassMethod('privateMethod', [
                        'flags' => Modifiers::PRIVATE,
                        'stmts' => [],
                    ]),
                ],
            ]),
        ];

        $result = $this->optimizer->optimize($ast);

        $this->assertArrayHasKey('ast', $result);
        $this->assertArrayHasKey('stats', $result);
        $this->assertIsArray($result['ast']);
        $this->assertIsArray($result['stats']);
    }

    public function testOptimizeEmptyAst(): void
    {
        $result = $this->optimizer->optimize([]);

        $this->assertArrayHasKey('ast', $result);
        $this->assertArrayHasKey('stats', $result);
        $this->assertEmpty($result['ast']);
    }
}
