<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\OptimizationVisitor;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Echo_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(OptimizationVisitor::class)]
final class OptimizationVisitorTest extends TestCase
{
    private OptimizationVisitor $visitor;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->visitor = new OptimizationVisitor($this->logger);
    }

    public function testBeforeTraverse(): void
    {
        $nodes = [new Variable('test')];
        $result = $this->visitor->beforeTraverse($nodes);

        $this->assertNull($result);
    }

    public function testEnterNode(): void
    {
        $node = new Variable('test');
        $result = $this->visitor->enterNode($node);

        $this->assertNull($result);
    }

    public function testLeaveNode(): void
    {
        $node = new Echo_([new String_('test')]);
        $result = $this->visitor->leaveNode($node);

        $this->assertNull($result);
    }

    public function testVisitorWithDifferentNodeTypes(): void
    {
        $nodes = [
            new Variable('var1'),
            new Echo_([new String_('hello')]),
            new Variable('var2'),
        ];

        // Test beforeTraverse
        $this->assertNull($this->visitor->beforeTraverse($nodes));

        // Test enterNode for each node
        foreach ($nodes as $node) {
            $this->assertNull($this->visitor->enterNode($node));
        }

        // Test leaveNode for each node
        foreach ($nodes as $node) {
            $this->assertNull($this->visitor->leaveNode($node));
        }
    }
}
