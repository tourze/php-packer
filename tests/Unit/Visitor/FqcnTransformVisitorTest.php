<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Visitor;

use PhpPacker\Visitor\FqcnTransformVisitor;
use PHPUnit\Framework\TestCase;

class FqcnTransformVisitorTest extends TestCase
{
    public function testConstructor(): void
    {
        $visitor = new FqcnTransformVisitor();
        $this->assertInstanceOf(FqcnTransformVisitor::class, $visitor);
    }
}