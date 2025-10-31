<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\VendorUsageAnalysisVisitor;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(VendorUsageAnalysisVisitor::class)]
final class VendorUsageAnalysisVisitorTest extends TestCase
{
    public function testEnterNodeWithMethodCall(): void
    {
        $visitor = new VendorUsageAnalysisVisitor();

        $methodCall = new Node\Expr\MethodCall(
            new Node\Expr\Variable('obj'),
            new Node\Identifier('testMethod')
        );

        $visitor->enterNode($methodCall);

        $usageData = $visitor->getUsageData();
        $this->assertContains('testMethod', $usageData['methods']);
    }

    public function testEnterNodeWithPropertyFetch(): void
    {
        $visitor = new VendorUsageAnalysisVisitor();

        $propertyFetch = new Node\Expr\PropertyFetch(
            new Node\Expr\Variable('obj'),
            new Node\Identifier('testProperty')
        );

        $visitor->enterNode($propertyFetch);

        $usageData = $visitor->getUsageData();
        $this->assertContains('testProperty', $usageData['properties']);
    }

    protected function setUp(): void
    {
        // No setup needed for this test
    }
}
