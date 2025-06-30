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
        $tables = ['files', 'symbols', 'dependencies', 'autoload_rules', 'analysis_queue', 'ast_nodes'];

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

        $fileId = $this->storage->addFile($path, $content, $fileType, true);

        // $fileId is already typed as int, no need to assert
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

        // $symbolId is already typed as int, no need to assert
        $this->assertGreaterThan(0, $symbolId);
    }

    public function testAddDependency(): void
    {
        $sourceId = $this->storage->addFile('source.php', '<?php');
        $targetId = $this->storage->addFile('target.php', '<?php');

        $depId = $this->storage->addDependency($sourceId, 'require', null, 10, false, 'require "target.php";');

        // $depId is already typed as int, no need to assert
        $this->assertGreaterThan(0, $depId);
    }

    public function testAddDependencyWithoutTarget(): void
    {
        $sourceId = $this->storage->addFile('source.php', '<?php');

        $depId = $this->storage->addDependency($sourceId, 'use_class', 'Some\\Class');

        // $depId is already typed as int, no need to assert
        $this->assertGreaterThan(0, $depId);
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

        $this->storage->addDependency($entryId, 'require', null);

        $this->storage->addDependency($file1Id, 'require', null);

        $files = $this->storage->getAllRequiredFiles($entryId);

        $this->assertCount(3, $files);

        // Verify order (deepest dependency first)
        $paths = array_column($files, 'path');
        $this->assertEquals(['file2.php', 'file1.php', 'entry.php'], $paths);
    }

    public function testGetUnresolvedDependencies(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php');

        $this->storage->addDependency($fileId, 'use_class', 'UnresolvedClass');

        $this->storage->addDependency($fileId, 'extends', 'ResolvedClass');

        $unresolved = $this->storage->getUnresolvedDependencies();

        $this->assertCount(1, $unresolved);
        $this->assertEquals('UnresolvedClass', $unresolved[0]['target_symbol']);
        $this->assertEquals('test.php', $unresolved[0]['source_path']);
    }

    public function testResolveDependency(): void
    {
        $sourceId = $this->storage->addFile('source.php', '<?php');
        $targetId = $this->storage->addFile('target.php', '<?php');

        $depId = $this->storage->addDependency($sourceId, 'use_class', 'Some\\Class');

        $this->storage->resolveDependency($depId, $targetId);

        // Verify it's resolved
        $unresolved = $this->storage->getUnresolvedDependencies();
        $this->assertCount(0, $unresolved);
    }

    public function testTransactions(): void
    {
        $this->storage->beginTransaction();

        $fileId = $this->storage->addFile('test.php', '<?php');
        // $fileId is already typed as int, no need to assert

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

        $this->storage->addDependency($file1Id, 'require', null);

        $this->storage->addDependency($file2Id, 'require', null);

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

    public function testFileClassification(): void
    {
        // Test vendor file classification
        $vendorPath = 'vendor/package/file.php';
        $vendorId = $this->storage->addFile($vendorPath, '<?php');
        $vendorFile = $this->storage->getFileByPath($vendorPath);
        
        $this->assertEquals(1, $vendorFile['is_vendor']);
        $this->assertEquals(1, $vendorFile['skip_ast']);
        
        // Test autoload file classification
        $autoloadPath = 'vendor/autoload.php';
        $autoloadId = $this->storage->addFile($autoloadPath, '<?php');
        $autoloadFile = $this->storage->getFileByPath($autoloadPath);
        
        $this->assertEquals(1, $autoloadFile['is_vendor']);
        $this->assertEquals(1, $autoloadFile['skip_ast']);
        
        // Test regular project file
        $projectPath = 'src/App.php';
        $projectId = $this->storage->addFile($projectPath, '<?php');
        $projectFile = $this->storage->getFileByPath($projectPath);
        
        $this->assertEquals(0, $projectFile['is_vendor']);
        $this->assertEquals(0, $projectFile['skip_ast']);
    }

    public function testAstNodeStorage(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php class Test {}');
        
        // Add AST root node
        $rootId = $this->storage->addAstNode($fileId, 0, 'Root', '', 0);
        
        $this->assertGreaterThan(0, $rootId);
        
        // Add class node
        $classId = $this->storage->addAstNode($fileId, $rootId, 'Stmt_Class', 'Test', 0);
        
        $this->assertGreaterThan(0, $classId);
        
        // Update file's AST root
        $this->storage->updateFileAstRoot($fileId, $rootId);
        
        // Verify
        $file = $this->storage->getFileById($fileId);
        $this->assertEquals($rootId, $file['ast_root_id']);
        
        // Get AST nodes
        $nodes = $this->storage->getAstNodesByFileId($fileId);
        $this->assertCount(2, $nodes);
    }

    public function testGetAstNodesByFqcn(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php namespace App; class User {}');
        
        $rootId = $this->storage->addAstNode($fileId, 0, 'Root', '', 0);
        
        $classId = $this->storage->addAstNode($fileId, $rootId, 'Stmt_Class', 'App\\User', 0);
        
        $nodes = $this->storage->getAstNodesByFqcn('App\\User');
        $this->assertCount(1, $nodes);
        $this->assertEquals('App\\User', $nodes[0]['fqcn']);
        $this->assertEquals('test.php', $nodes[0]['path']);
    }

    public function testFindAstNodeUsages(): void
    {
        $fileId = $this->storage->addFile('service.php', '<?php use App\\User;');
        
        $nodeId = $this->storage->addAstNode($fileId, 0, 'Expr_New', '', 0);
        
        $usages = $this->storage->findAstNodeUsages('App\\User');
        $this->assertNotEmpty($usages);
        $this->assertEquals('service.php', $usages[0]['path']);
    }

    public function testShouldSkipAst(): void
    {
        // Vendor file should skip AST
        $this->storage->addFile('vendor/lib/file.php', '<?php');
        $this->assertTrue($this->storage->shouldSkipAst('vendor/lib/file.php'));
        
        // Regular file should not skip AST
        $this->storage->addFile('src/app.php', '<?php');
        $this->assertFalse($this->storage->shouldSkipAst('src/app.php'));
        
        // Non-existent file
        $this->assertFalse($this->storage->shouldSkipAst('non/existent.php'));
    }

    public function testGetStatistics(): void
    {
        // Add some test data
        $this->storage->addFile('file1.php', '<?php', 'class');
        $this->storage->addFile('file2.php', '<?php', 'class');
        $this->storage->addFile('file3.php', '<?php', 'script');
        
        $this->storage->addAutoloadRule('psr4', '/src', 'App\\');
        
        $fileId = $this->storage->addFile('file4.php', '<?php');
        $this->storage->addDependency($fileId, 'use', null);
        
        $stats = $this->storage->getStatistics();
        
        $this->assertEquals(4, $stats['total_files']);
        $this->assertEquals(2, $stats['total_classes']);
        $this->assertEquals(1, $stats['total_dependencies']);
        $this->assertEquals(1, $stats['total_autoload_rules']);
    }

    public function testPreserveEntryFlagOnUpdate(): void
    {
        $path = 'entry.php';
        
        // First add as entry file
        $this->storage->addFile($path, '<?php echo 1;', null, null, true);
        $file1 = $this->storage->getFileByPath($path);
        $this->assertEquals(1, $file1['is_entry']);
        
        // Update without entry flag - should preserve
        $this->storage->addFile($path, '<?php echo 2;', null, null, false);
        $file2 = $this->storage->getFileByPath($path);
        $this->assertEquals(1, $file2['is_entry']);
        
        // New file without entry flag
        $this->storage->addFile('other.php', '<?php', null, null, false);
        $file3 = $this->storage->getFileByPath('other.php');
        $this->assertEquals(0, $file3['is_entry']);
    }
}