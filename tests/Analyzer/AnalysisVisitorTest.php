<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer;

use PhpPacker\Analyzer\AnalysisVisitor;
use PhpPacker\Storage\SqliteStorage;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(AnalysisVisitor::class)]
final class AnalysisVisitorTest extends TestCase
{
    private static string $dbPath;

    private SqliteStorage $storage;

    protected function setUp(): void
    {
        self::$dbPath = sys_get_temp_dir() . '/test-' . uniqid() . '.db';
        $this->storage = new SqliteStorage(self::$dbPath, new NullLogger());
    }

    protected function tearDown(): void
    {
        if (isset(self::$dbPath) && file_exists(self::$dbPath)) {
            unlink(self::$dbPath);
        }
    }

    public function testGetSymbolCount(): void
    {
        $visitor = new AnalysisVisitor($this->storage, 1, null);

        $this->assertSame(0, $visitor->getSymbolCount());
    }

    public function testGetDependencyCount(): void
    {
        $visitor = new AnalysisVisitor($this->storage, 1, null);

        $this->assertSame(0, $visitor->getDependencyCount());
    }

    public function testEnterNode(): void
    {
        $visitor = new AnalysisVisitor($this->storage, 1, null);

        $node = new String_('test');
        $result = $visitor->enterNode($node);

        $this->assertNull($result);
    }

    public function testEnterNodeWithClass(): void
    {
        $visitor = new AnalysisVisitor($this->storage, 1, null);

        $node = new Class_('TestClass');
        $result = $visitor->enterNode($node);

        $this->assertNull($result);
    }
}
