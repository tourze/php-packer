<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\NodeSymbolExtractor;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(NodeSymbolExtractor::class)]
final class NodeSymbolExtractorTest extends TestCase
{
    private NodeSymbolExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new NodeSymbolExtractor();
    }

    public function testGetNodeSymbolForClass(): void
    {
        $node = new Node\Stmt\Class_('TestClass');
        $symbol = $this->extractor->getNodeSymbol($node);

        $this->assertSame('TestClass', $symbol);
    }

    public function testGetNodeSymbolForFunction(): void
    {
        $node = new Node\Stmt\Function_('testFunction');
        $symbol = $this->extractor->getNodeSymbol($node);

        $this->assertSame('testFunction', $symbol);
    }

    public function testGetNodeSymbolForInterface(): void
    {
        $node = new Node\Stmt\Interface_('TestInterface');
        $symbol = $this->extractor->getNodeSymbol($node);

        $this->assertSame('TestInterface', $symbol);
    }

    public function testGetNodeType(): void
    {
        $node = new Node\Stmt\Class_('TestClass');
        $type = $this->extractor->getNodeType($node);

        $this->assertSame('class', $type);
    }
}
