<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Storage;

use PhpPacker\Storage\ExpressionNodeExtractor;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ExpressionNodeExtractor::class)]
final class ExpressionNodeExtractorTest extends TestCase
{
    private ExpressionNodeExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ExpressionNodeExtractor();
    }

    public function testExtractExpressionNodeData(): void
    {
        $node = new Node\Expr\New_(new Node\Name('TestClass'));
        $attributes = [];

        $result = $this->extractor->extractExpressionNodeData($node, $attributes);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('class', $result);
        $this->assertSame('TestClass', $result['class']);
    }

    public function testExtractExpressionNodeDataWithStaticCall(): void
    {
        $node = new Node\Expr\StaticCall(
            new Node\Name('TestClass'),
            new Node\Identifier('testMethod')
        );
        $attributes = [];

        $result = $this->extractor->extractExpressionNodeData($node, $attributes);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('class', $result);
        $this->assertSame('TestClass', $result['class']);
        $this->assertArrayHasKey('method', $result);
        $this->assertSame('testMethod', $result['method']);
    }
}
