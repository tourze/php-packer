<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\VendorOptimizationVisitor;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(VendorOptimizationVisitor::class)]
final class VendorOptimizationVisitorTest extends TestCase
{
    public function testLeaveNode(): void
    {
        $stats = ['removed_methods' => 0, 'removed_properties' => 0];
        $visitor = new VendorOptimizationVisitor($stats);

        $class = new Node\Stmt\Class_('TestClass');
        $result = $visitor->leaveNode($class);

        $this->assertInstanceOf(Node\Stmt\Class_::class, $result);
    }

    public function testLeaveNodeWithNonClass(): void
    {
        $stats = ['removed_methods' => 0, 'removed_properties' => 0];
        $visitor = new VendorOptimizationVisitor($stats);

        $function = new Node\Stmt\Function_('testFunction');
        $result = $visitor->leaveNode($function);

        $this->assertInstanceOf(Node\Stmt\Function_::class, $result);
    }

    protected function setUp(): void
    {
        // No setup needed for this test
    }
}
