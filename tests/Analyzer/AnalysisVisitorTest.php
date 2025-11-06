<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer;

use PhpPacker\Analyzer\AnalysisVisitor;
use PhpPacker\Storage\StorageInterface;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AnalysisVisitor::class)]
final class AnalysisVisitorTest extends TestCase
{
    protected function setUp(): void
    {
        // No setup needed for this test
    }

    public function testGetSymbolCount(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $visitor = new AnalysisVisitor($storage, 1, null, '/test/file.php');

        $this->assertSame(0, $visitor->getSymbolCount());
    }

    public function testGetDependencyCount(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $visitor = new AnalysisVisitor($storage, 1, null, '/test/file.php');

        $this->assertSame(0, $visitor->getDependencyCount());
    }

    public function testEnterNode(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $visitor = new AnalysisVisitor($storage, 1, null, '/test/file.php');

        $node = $this->createMock(Node::class);
        $result = $visitor->enterNode($node);

        $this->assertNull($result);
    }
}
