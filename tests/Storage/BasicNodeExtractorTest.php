<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Storage;

use PhpPacker\Storage\BasicNodeExtractor;
use PhpPacker\Storage\TypeConverter;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BasicNodeExtractor::class)]
final class BasicNodeExtractorTest extends TestCase
{
    private BasicNodeExtractor $extractor;

    protected function setUp(): void
    {
        $typeConverter = new TypeConverter();
        $this->extractor = new BasicNodeExtractor($typeConverter);
    }

    public function testExtractBasicNodeData(): void
    {
        $node = new Node\Stmt\Class_('TestClass');

        $result = $this->extractor->extractBasicNodeData($node);

        $this->assertIsString($result['nodeName']);
        $this->assertIsArray($result['attributes']);
    }

    public function testExtractBasicNodeDataWithFunction(): void
    {
        $node = new Node\Stmt\Function_('testFunction');

        $result = $this->extractor->extractBasicNodeData($node);

        $this->assertIsString($result['nodeName']);
        $this->assertIsArray($result['attributes']);
    }
}
