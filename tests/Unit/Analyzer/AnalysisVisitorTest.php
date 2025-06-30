<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Analyzer;

use PhpPacker\Analyzer\AnalysisVisitor;
use PhpPacker\Storage\SqliteStorage;
use PHPUnit\Framework\TestCase;

class AnalysisVisitorTest extends TestCase
{
    public function testGetSymbolCount(): void
    {
        $storage = $this->createMock(SqliteStorage::class);
        $visitor = new AnalysisVisitor($storage, 1, null, '/test/file.php');
        
        $this->assertSame(0, $visitor->getSymbolCount());
    }

    public function testGetDependencyCount(): void
    {
        $storage = $this->createMock(SqliteStorage::class);
        $visitor = new AnalysisVisitor($storage, 1, null, '/test/file.php');
        
        $this->assertSame(0, $visitor->getDependencyCount());
    }
}