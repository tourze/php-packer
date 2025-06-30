<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Visitor;

use PhpPacker\Visitor\RequireRemovalVisitor;
use PHPUnit\Framework\TestCase;

class RequireRemovalVisitorTest extends TestCase
{
    public function testConstructor(): void
    {
        $visitor = new RequireRemovalVisitor([]);
        $this->assertInstanceOf(RequireRemovalVisitor::class, $visitor);
    }
}