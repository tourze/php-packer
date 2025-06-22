<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Storage;

use PhpPacker\Storage\SqliteStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SqliteStorageTest extends TestCase
{
    private string $dbPath;
    private LoggerInterface $logger;
    private SqliteStorage $storage;

    public function testDatabaseInitialization(): void
    {
        $this->assertFileExists($this->dbPath);

        // Verify tables exist
        $pdo = $this->storage->getPdo();
        $tables = ['files', 'symbols', 'dependencies', 'autoload_rules', 'analysis_queue'];

        foreach ($tables as $table) {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
            $result = $stmt->fetch();
            $this->assertEquals($table, $result['name']);
        }
    }

    public function testAddAndGetFile(): void
    {
        $path = 'test/file.php';
        $content = '<?php echo "test";';
        $fileType = 'script';
        $className = 'Test\\TestClass';

        $fileId = $this->storage->addFile($path, $content, $fileType, $className, true);

        $this->assertIsInt($fileId);
        $this->assertGreaterThan(0, $fileId);

        $file = $this->storage->getFileByPath($path);

        $this->assertNotNull($file);
        $this->assertEquals($path, $file['path']);
        $this->assertEquals($content, $file['content']);
        $this->assertEquals($fileType, $file['file_type']);
        $this->assertEquals($className, $file['class_name']);
        $this->assertEquals(1, $file['is_entry']);
        $this->assertEquals(hash('sha256', $content), $file['hash']);
    }

    public function testAddFileReplaceExisting(): void
    {
        $path = 'test/file.php';
        $content1 = '<?php echo "test1";';
        $content2 = '<?php echo "test2";';

        $fileId1 = $this->storage->addFile($path, $content1);
        $fileId2 = $this->storage->addFile($path, $content2);

        // Should replace, not create new
        $file = $this->storage->getFileByPath($path);
        $this->assertEquals($content2, $file['content']);
        $this->assertEquals(hash('sha256', $content2), $file['hash']);
    }

    public function testGetFileByPathNotFound(): void
    {
        $file = $this->storage->getFileByPath('non/existent/file.php');
        $this->assertNull($file);
    }

    public function testAddSymbol(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php class Test {}');

        $symbolId = $this->storage->addSymbol(
            $fileId,
            'class',
            'Test',
            'Test\\Test',
            'Test',
            'public'
        );

        $this->assertIsInt($symbolId);
        $this->assertGreaterThan(0, $symbolId);
    }

    public function testAddDependency(): void
    {
        $sourceId = $this->storage->addFile('source.php', '<?php');
        $targetId = $this->storage->addFile('target.php', '<?php');

        $depId = $this->storage->addDependency([
            'source_file_id' => $sourceId,
            'target_file_id' => $targetId,
            'dependency_type' => 'require',
            'target_symbol' => null,
            'line_number' => 10,
            'is_conditional' => false,
            'is_resolved' => true,
            'context' => 'require "target.php";'
        ]);

        $this->assertIsInt($depId);
        $this->assertGreaterThan(0, $depId);
    }

    public function testAddDependencyWithoutTarget(): void
    {
        $sourceId = $this->storage->addFile('source.php', '<?php');

        $depId = $this->storage->addDependency([
            'source_file_id' => $sourceId,
            'target_file_id' => null,
            'dependency_type' => 'use_class',
            'target_symbol' => 'Some\\Class',
            'is_resolved' => false
        ]);

        $this->assertIsInt($depId);
        $this->assertGreaterThan(0, $depId);
    }

    public function testAnalysisQueue(): void
    {
        // Add items to queue
        $this->storage->addToAnalysisQueue('file1.php', 10);
        $this->storage->addToAnalysisQueue('file2.php', 20);
        $this->storage->addToAnalysisQueue('file3.php', 5);

        // Get highest priority first
        $item1 = $this->storage->getNextFromQueue();
        $this->assertNotNull($item1);
        $this->assertEquals('file2.php', $item1['file_path']);
        $this->assertEquals(20, $item1['priority']);

        // Mark as completed
        $this->storage->markQueueItemCompleted($item1['id']);

        // Get next
        $item2 = $this->storage->getNextFromQueue();
        $this->assertEquals('file1.php', $item2['file_path']);

        // Mark as failed
        $this->storage->markQueueItemFailed($item2['id']);

        // Get last
        $item3 = $this->storage->getNextFromQueue();
        $this->assertEquals('file3.php', $item3['file_path']);
    }

    public function testAddToAnalysisQueueIgnoreDuplicate(): void
    {
        $this->storage->addToAnalysisQueue('file.php', 10);
        $this->storage->addToAnalysisQueue('file.php', 20); // Should be ignored

        $item = $this->storage->getNextFromQueue();
        $this->assertEquals(10, $item['priority']); // Original priority
    }

    public function testFindFileBySymbol(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php class Test {}');
        $this->storage->addSymbol($fileId, 'class', 'Test', 'Namespace\\Test', 'Namespace');

        $file = $this->storage->findFileBySymbol('Namespace\\Test');
        $this->assertNotNull($file);
        $this->assertEquals('test.php', $file['path']);

        $notFound = $this->storage->findFileBySymbol('NonExistent\\Class');
        $this->assertNull($notFound);
    }

    public function testAutoloadRules(): void
    {
        $this->storage->addAutoloadRule('psr4', '/path/to/src', 'App\\', 100);
        $this->storage->addAutoloadRule('psr0', '/path/to/lib', 'Legacy_', 50);
        $this->storage->addAutoloadRule('files', '/path/to/functions.php', null, 150);

        $rules = $this->storage->getAutoloadRules();

        $this->assertCount(3, $rules);

        // Should be ordered by priority DESC
        $this->assertEquals('files', $rules[0]['type']);
        $this->assertEquals('psr4', $rules[1]['type']);
        $this->assertEquals('psr0', $rules[2]['type']);
    }

    public function testGetAllRequiredFiles(): void
    {
        // Create a dependency chain: entry -> file1 -> file2
        $entryId = $this->storage->addFile('entry.php', '<?php', null, null, true);
        $file1Id = $this->storage->addFile('file1.php', '<?php');
        $file2Id = $this->storage->addFile('file2.php', '<?php');

        $this->storage->addDependency([
            'source_file_id' => $entryId,
            'target_file_id' => $file1Id,
            'dependency_type' => 'require',
            'is_resolved' => true
        ]);

        $this->storage->addDependency([
            'source_file_id' => $file1Id,
            'target_file_id' => $file2Id,
            'dependency_type' => 'require',
            'is_resolved' => true
        ]);

        $files = $this->storage->getAllRequiredFiles($entryId);

        $this->assertCount(3, $files);

        // Verify order (deepest dependency first)
        $paths = array_column($files, 'path');
        $this->assertEquals(['file2.php', 'file1.php', 'entry.php'], $paths);
    }

    public function testGetUnresolvedDependencies(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php');

        $this->storage->addDependency([
            'source_file_id' => $fileId,
            'dependency_type' => 'use_class',
            'target_symbol' => 'UnresolvedClass',
            'is_resolved' => false
        ]);

        $this->storage->addDependency([
            'source_file_id' => $fileId,
            'dependency_type' => 'extends',
            'target_symbol' => 'ResolvedClass',
            'is_resolved' => true,
            'target_file_id' => $fileId
        ]);

        $unresolved = $this->storage->getUnresolvedDependencies();

        $this->assertCount(1, $unresolved);
        $this->assertEquals('UnresolvedClass', $unresolved[0]['target_symbol']);
        $this->assertEquals('test.php', $unresolved[0]['source_path']);
    }

    public function testResolveDependency(): void
    {
        $sourceId = $this->storage->addFile('source.php', '<?php');
        $targetId = $this->storage->addFile('target.php', '<?php');

        $depId = $this->storage->addDependency([
            'source_file_id' => $sourceId,
            'dependency_type' => 'use_class',
            'target_symbol' => 'Some\\Class',
            'is_resolved' => false
        ]);

        $this->storage->resolveDependency($depId, $targetId);

        // Verify it's resolved
        $unresolved = $this->storage->getUnresolvedDependencies();
        $this->assertCount(0, $unresolved);
    }

    public function testTransactions(): void
    {
        $this->storage->beginTransaction();

        $fileId = $this->storage->addFile('test.php', '<?php');
        $this->assertIsInt($fileId);

        $this->storage->rollback();

        // File should not exist after rollback
        $file = $this->storage->getFileByPath('test.php');
        $this->assertNull($file);

        // Test commit
        $this->storage->beginTransaction();
        $fileId = $this->storage->addFile('test2.php', '<?php');
        $this->storage->commit();

        $file = $this->storage->getFileByPath('test2.php');
        $this->assertNotNull($file);
    }

    public function testCircularDependencyInGetAllRequiredFiles(): void
    {
        // Create circular dependency: file1 -> file2 -> file1
        $file1Id = $this->storage->addFile('file1.php', '<?php');
        $file2Id = $this->storage->addFile('file2.php', '<?php');

        $this->storage->addDependency([
            'source_file_id' => $file1Id,
            'target_file_id' => $file2Id,
            'dependency_type' => 'require',
            'is_resolved' => true
        ]);

        $this->storage->addDependency([
            'source_file_id' => $file2Id,
            'target_file_id' => $file1Id,
            'dependency_type' => 'require',
            'is_resolved' => true
        ]);

        // Should not cause infinite loop (depth limit = 100)
        $files = $this->storage->getAllRequiredFiles($file1Id);

        $this->assertLessThanOrEqual(100, count($files));
    }

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/php-packer-test-' . uniqid() . '.db';
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->storage = new SqliteStorage($this->dbPath, $this->logger);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }
}