<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Generator;

use PhpPacker\Generator\SmartCommentRemovalVisitor;
use PHPUnit\Framework\TestCase;

class SmartCommentRemovalVisitorTest extends TestCase
{
    public function testConstructor(): void
    {
        $visitor = new SmartCommentRemovalVisitor();
        $this->assertInstanceOf(SmartCommentRemovalVisitor::class, $visitor);
    }
}