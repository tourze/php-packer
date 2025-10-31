<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer;

use PhpPacker\Analyzer\TopologicalSortState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TopologicalSortState::class)]
final class TopologicalSortStateTest extends TestCase
{
    private TopologicalSortState $state;

    protected function setUp(): void
    {
        $this->state = new TopologicalSortState();
    }

    public function testInitialState(): void
    {
        $this->assertEmpty($this->state->visited);
        $this->assertEmpty($this->state->recursionStack);
        $this->assertEmpty($this->state->result);
    }

    public function testMarkVisited(): void
    {
        $this->state->markVisited('node1');
        $this->state->markVisited(123);

        $this->assertTrue(isset($this->state->visited['node1']));
        $this->assertTrue(isset($this->state->visited[123]));
        $this->assertTrue($this->state->visited['node1']);
        $this->assertTrue($this->state->visited[123]);
    }

    public function testIsVisited(): void
    {
        $this->assertFalse($this->state->isVisited('node1'));
        $this->assertFalse($this->state->isVisited(123));

        $this->state->markVisited('node1');
        $this->state->markVisited(123);

        $this->assertTrue($this->state->isVisited('node1'));
        $this->assertTrue($this->state->isVisited(123));
        $this->assertFalse($this->state->isVisited('unvisited'));
        $this->assertFalse($this->state->isVisited(999));
    }

    public function testMarkInRecursionStack(): void
    {
        $this->state->markInRecursionStack('node1');
        $this->state->markInRecursionStack(123);

        $this->assertTrue(isset($this->state->recursionStack['node1']));
        $this->assertTrue(isset($this->state->recursionStack[123]));
        $this->assertTrue($this->state->recursionStack['node1']);
        $this->assertTrue($this->state->recursionStack[123]);
    }

    public function testIsInRecursionStack(): void
    {
        $this->assertFalse($this->state->isInRecursionStack('node1'));
        $this->assertFalse($this->state->isInRecursionStack(123));

        $this->state->markInRecursionStack('node1');
        $this->state->markInRecursionStack(123);

        $this->assertTrue($this->state->isInRecursionStack('node1'));
        $this->assertTrue($this->state->isInRecursionStack(123));
        $this->assertFalse($this->state->isInRecursionStack('notinstack'));
        $this->assertFalse($this->state->isInRecursionStack(999));
    }

    public function testRemoveFromRecursionStack(): void
    {
        $this->state->markInRecursionStack('node1');
        $this->state->markInRecursionStack(123);

        $this->assertTrue($this->state->isInRecursionStack('node1'));
        $this->assertTrue($this->state->isInRecursionStack(123));

        $this->state->removeFromRecursionStack('node1');
        $this->assertFalse($this->state->isInRecursionStack('node1'));
        $this->assertTrue($this->state->isInRecursionStack(123));

        $this->state->removeFromRecursionStack(123);
        $this->assertFalse($this->state->isInRecursionStack(123));
    }

    public function testRemoveFromRecursionStackNonExistent(): void
    {
        // Should not throw exception when removing non-existent node
        $this->state->removeFromRecursionStack('nonexistent');
        $this->state->removeFromRecursionStack(999);

        $this->assertEmpty($this->state->recursionStack);
    }

    public function testAddToResult(): void
    {
        $this->state->addToResult('first');
        $this->state->addToResult(123);
        $this->state->addToResult('last');

        $this->assertCount(3, $this->state->result);
        $this->assertEquals('first', $this->state->result[0]);
        $this->assertEquals(123, $this->state->result[1]);
        $this->assertEquals('last', $this->state->result[2]);
    }

    public function testAddToResultOrder(): void
    {
        // Test that order is preserved
        for ($i = 0; $i < 10; ++$i) {
            $this->state->addToResult($i);
        }

        for ($i = 0; $i < 10; ++$i) {
            $this->assertEquals($i, $this->state->result[$i]);
        }
    }

    public function testCompleteWorkflow(): void
    {
        // Simulate a typical topological sort workflow

        // Visit node A
        $this->state->markVisited('A');
        $this->state->markInRecursionStack('A');

        // Visit node B (dependency of A)
        $this->state->markVisited('B');
        $this->state->markInRecursionStack('B');

        // Visit node C (dependency of B)
        $this->state->markVisited('C');
        $this->state->markInRecursionStack('C');

        // Finish processing C
        $this->state->removeFromRecursionStack('C');
        $this->state->addToResult('C');

        // Finish processing B
        $this->state->removeFromRecursionStack('B');
        $this->state->addToResult('B');

        // Finish processing A
        $this->state->removeFromRecursionStack('A');
        $this->state->addToResult('A');

        // Verify final state
        $this->assertTrue($this->state->isVisited('A'));
        $this->assertTrue($this->state->isVisited('B'));
        $this->assertTrue($this->state->isVisited('C'));

        $this->assertFalse($this->state->isInRecursionStack('A'));
        $this->assertFalse($this->state->isInRecursionStack('B'));
        $this->assertFalse($this->state->isInRecursionStack('C'));

        $this->assertEquals(['C', 'B', 'A'], $this->state->result);
    }

    public function testMixedNodeTypes(): void
    {
        // Test with mixed string and integer node types
        $nodes = ['string1', 42, 'string2', 0, '0'];

        foreach ($nodes as $node) {
            $this->state->markVisited($node);
            $this->state->addToResult($node);
        }

        foreach ($nodes as $node) {
            $this->assertTrue($this->state->isVisited($node));
        }

        $this->assertEquals($nodes, $this->state->result);
    }

    public function testCircularDetection(): void
    {
        // Simulate circular dependency detection

        // Start with node A
        $this->state->markVisited('A');
        $this->state->markInRecursionStack('A');

        // Visit node B
        $this->state->markVisited('B');
        $this->state->markInRecursionStack('B');

        // Now B tries to visit A again - this would be detected as circular
        $this->assertTrue($this->state->isInRecursionStack('A'));

        // Clean up the recursion stack
        $this->state->removeFromRecursionStack('B');
        $this->state->removeFromRecursionStack('A');

        $this->assertFalse($this->state->isInRecursionStack('A'));
        $this->assertFalse($this->state->isInRecursionStack('B'));
    }

    public function testStatePersistence(): void
    {
        // Test that state persists across multiple operations
        $this->state->markVisited('persistent');
        $this->state->markInRecursionStack('persistent');

        // Do other operations
        $this->state->markVisited('other1');
        $this->state->addToResult('other1');
        $this->state->markVisited('other2');
        $this->state->addToResult('other2');

        // Original state should still be there
        $this->assertTrue($this->state->isVisited('persistent'));
        $this->assertTrue($this->state->isInRecursionStack('persistent'));

        // Remove from recursion stack and add to result
        $this->state->removeFromRecursionStack('persistent');
        $this->state->addToResult('persistent');

        $this->assertTrue($this->state->isVisited('persistent'));
        $this->assertFalse($this->state->isInRecursionStack('persistent'));
        $this->assertContains('persistent', $this->state->result);
    }

    public function testEmptyNodeHandling(): void
    {
        // Test with empty string
        $this->state->markVisited('');
        $this->assertTrue($this->state->isVisited(''));

        $this->state->markInRecursionStack('');
        $this->assertTrue($this->state->isInRecursionStack(''));

        $this->state->removeFromRecursionStack('');
        $this->assertFalse($this->state->isInRecursionStack(''));

        $this->state->addToResult('');
        $this->assertContains('', $this->state->result);
    }
}
