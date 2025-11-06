<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Storage;

use PhpPacker\Storage\SqliteStorage;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(SqliteStorage::class)]
final class SqliteStorageTest extends TestCase
{
    private static string $dbPath;

    private LoggerInterface $logger;

    private SqliteStorage $storage;

    public function testDatabaseInitialization(): void
    {
        $this->assertFileExists(self::$dbPath);

        // Verify tables exist
        $pdo = $this->storage->getPdo();
        $tables = ['files', 'symbols', 'dependencies', 'autoload_rules', 'analysis_queue', 'ast_nodes'];

        foreach ($tables as $table) {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'");
            $this->assertNotFalse($stmt);
            $result = $stmt->fetch();
            $this->assertIsArray($result);
            $this->assertArrayHasKey('name', $result);
            $this->assertEquals($table, $result['name']);
        }
    }

    public function testAddAndGetFile(): void
    {
        $path = 'test/file.php';
        $content = '<?php echo "test";';
        $fileType = 'script';
        $className = 'Test\TestClass';

        $fileId = $this->storage->addFile($path, $content, $fileType, true, null, $className);

        // $fileId is already typed as int, no need to assert
        $this->assertGreaterThan(0, $fileId);

        $file = $this->storage->getFileByPath($path);

        $this->assertNotNull($file);
        $this->assertArrayHasKey('path', $file);
        $this->assertArrayHasKey('content', $file);
        $this->assertArrayHasKey('file_type', $file);
        $this->assertArrayHasKey('class_name', $file);
        $this->assertArrayHasKey('is_entry', $file);
        $this->assertArrayHasKey('hash', $file);
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
        $this->assertNotNull($file);
        $this->assertArrayHasKey('content', $file);
        $this->assertArrayHasKey('hash', $file);
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

        $this->storage->addSymbol(
            $fileId,
            'class',
            'Test',
            'Test\Test',
            'Test',
            'public'
        );

        // addSymbol now returns void, no ID to assert
        // Verify symbol was added by checking it can be found
        $file = $this->storage->findFileBySymbol('Test\Test');
        $this->assertNotNull($file, 'Symbol should be added and findable');
    }

    public function testAddDependency(): void
    {
        $sourceId = $this->storage->addFile('source.php', '<?php');
        $targetId = $this->storage->addFile('target.php', '<?php');

        $this->storage->addDependency(
            $sourceId,
            $targetId,
            'require',
            null,
            10,
            false,
            'require "target.php";'
        );

        // addDependency now returns void, no ID to assert
        // Verify dependency was added by checking the file's dependencies
        $dependencies = $this->storage->getDependenciesByFile($sourceId);
        $this->assertGreaterThanOrEqual(1, count($dependencies));
    }

    public function testAddDependencyWithoutTarget(): void
    {
        $sourceId = $this->storage->addFile('source.php', '<?php');

        $this->storage->addDependency($sourceId, null, 'use_class', 'Some\Class');

        // addDependency now returns void, no ID to assert
        // Verify dependency was added by checking the file's dependencies
        $dependencies = $this->storage->getDependenciesByFile($sourceId);
        $this->assertGreaterThanOrEqual(1, count($dependencies));
    }

    public function testFindFileBySymbol(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php class Test {}');
        $this->storage->addSymbol($fileId, 'class', 'Test', 'Namespace\Test', 'Namespace');

        $file = $this->storage->findFileBySymbol('Namespace\Test');
        $this->assertNotNull($file);
        $this->assertArrayHasKey('path', $file);
        $this->assertEquals('test.php', $file['path']);

        $notFound = $this->storage->findFileBySymbol('NonExistent\Class');
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
        $entryId = $this->storage->addFile('entry.php', '<?php', null, true);
        $file1Id = $this->storage->addFile('file1.php', '<?php');
        $file2Id = $this->storage->addFile('file2.php', '<?php');

        // 设置依赖关系
        $this->storage->addDependency($entryId, null, 'require', 'file1.php');
        $this->storage->addDependency($file1Id, null, 'require', 'file2.php');

        // 获取依赖关系并解析
        $entryDependencies = $this->storage->getDependenciesByFile($entryId);
        $file1Dependencies = $this->storage->getDependenciesByFile($file1Id);

        if (!empty($entryDependencies)) {
            $this->storage->resolveDependency($entryDependencies[0]['id'], $file1Id);
        }
        if (!empty($file1Dependencies)) {
            $this->storage->resolveDependency($file1Dependencies[0]['id'], $file2Id);
        }

        $files = $this->storage->getAllRequiredFiles($entryId);

        $this->assertCount(3, $files);

        // Verify that all files are included (order may vary)
        $paths = array_column($files, 'path');
        sort($paths);
        $this->assertEquals(['entry.php', 'file1.php', 'file2.php'], $paths);
    }

    public function testGetUnresolvedDependencies(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php');
        $targetId = $this->storage->addFile('target.php', '<?php');

        $this->storage->addDependency($fileId, null, 'use_class', 'UnresolvedClass');

        $this->storage->addDependency($fileId, null, 'extends', 'ResolvedClass');

        // Get the dependencies and resolve the second one
        $dependencies = $this->storage->getDependenciesByFile($fileId);
        if (count($dependencies) >= 2) {
            $this->storage->resolveDependency($dependencies[1]['id'], $targetId);
        }

        $unresolved = $this->storage->getUnresolvedDependencies();

        $this->assertCount(1, $unresolved);
        $this->assertArrayHasKey('target_symbol', $unresolved[0]);
        $this->assertArrayHasKey('source_path', $unresolved[0]);
        $this->assertEquals('UnresolvedClass', $unresolved[0]['target_symbol']);
        $this->assertEquals('test.php', $unresolved[0]['source_path']);
    }

    public function testResolveDependency(): void
    {
        $sourceId = $this->storage->addFile('source.php', '<?php');
        $targetId = $this->storage->addFile('target.php', '<?php');

        $this->storage->addDependency($sourceId, null, 'use_class', 'Some\Class');

        // Get the dependency and resolve it
        $dependencies = $this->storage->getDependenciesByFile($sourceId);
        if (!empty($dependencies)) {
            $this->storage->resolveDependency($dependencies[0]['id'], $targetId);
        }

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

        $this->storage->addDependency($file1Id, null, 'require', null);

        $this->storage->addDependency($file2Id, null, 'require', null);

        // Should not cause infinite loop (depth limit = 100)
        $files = $this->storage->getAllRequiredFiles($file1Id);

        $this->assertLessThanOrEqual(100, count($files));
    }

    protected function setUp(): void
    {
        self::$dbPath = sys_get_temp_dir() . '/php-packer-test-' . uniqid() . '.db';
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->storage = new SqliteStorage(self::$dbPath, $this->logger);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$dbPath) && file_exists(self::$dbPath)) {
            unlink(self::$dbPath);
        }
    }

    public function testFileClassification(): void
    {
        // Test vendor file classification
        $vendorPath = 'vendor/package/file.php';
        $vendorId = $this->storage->addFile($vendorPath, '<?php');
        $vendorFile = $this->storage->getFileByPath($vendorPath);

        $this->assertNotNull($vendorFile);
        $this->assertArrayHasKey('is_vendor', $vendorFile);
        $this->assertArrayHasKey('skip_ast', $vendorFile);
        $this->assertEquals(1, $vendorFile['is_vendor']);
        $this->assertEquals(1, $vendorFile['skip_ast']);

        // Test autoload file classification
        $autoloadPath = 'vendor/autoload.php';
        $autoloadId = $this->storage->addFile($autoloadPath, '<?php');
        $autoloadFile = $this->storage->getFileByPath($autoloadPath);

        $this->assertNotNull($autoloadFile);
        $this->assertArrayHasKey('is_vendor', $autoloadFile);
        $this->assertArrayHasKey('skip_ast', $autoloadFile);
        $this->assertEquals(1, $autoloadFile['is_vendor']);
        $this->assertEquals(1, $autoloadFile['skip_ast']);

        // Test regular project file
        $projectPath = 'src/App.php';
        $projectId = $this->storage->addFile($projectPath, '<?php');
        $projectFile = $this->storage->getFileByPath($projectPath);

        $this->assertNotNull($projectFile);
        $this->assertArrayHasKey('is_vendor', $projectFile);
        $this->assertArrayHasKey('skip_ast', $projectFile);
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
        $this->assertNotNull($file);
        $this->assertArrayHasKey('ast_root_id', $file);
        $this->assertEquals($rootId, $file['ast_root_id']);

        // Get AST nodes
        $nodes = $this->storage->getAstNodesByFileId($fileId);
        $this->assertCount(2, $nodes);
    }

    public function testGetAstNodesByFqcn(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php namespace App; class User {}');

        $rootId = $this->storage->addAstNode($fileId, 0, 'Root', '', 0);

        $classId = $this->storage->addAstNode($fileId, $rootId, 'Stmt_Class', 'App\User', 0);

        $nodes = $this->storage->getAstNodesByFqcn('App\User');
        $this->assertCount(1, $nodes);
        $this->assertArrayHasKey('fqcn', $nodes[0]);
        $this->assertArrayHasKey('path', $nodes[0]);
        $this->assertEquals('App\User', $nodes[0]['fqcn']);
        $this->assertEquals('test.php', $nodes[0]['path']);
    }

    public function testFindAstNodeUsages(): void
    {
        $fileId = $this->storage->addFile('service.php', '<?php use App\User;');

        $nodeId = $this->storage->addAstNode($fileId, 0, 'Expr_New', 'App\User', 0);

        $usages = $this->storage->findAstNodeUsages('App\User');
        $this->assertNotEmpty($usages);
        $this->assertArrayHasKey('path', $usages[0]);
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
        $file1Id = $this->storage->addFile('file1.php', '<?php', 'class');
        $file2Id = $this->storage->addFile('file2.php', '<?php', 'class');
        $file3Id = $this->storage->addFile('file3.php', '<?php', 'script');

        // Add symbols to represent classes
        $this->storage->addSymbol($file1Id, 'class', 'Class1', 'App\Class1');
        $this->storage->addSymbol($file2Id, 'class', 'Class2', 'App\Class2');

        $this->storage->addAutoloadRule('psr4', '/src', 'App\\');

        $fileId = $this->storage->addFile('file4.php', '<?php');
        $this->storage->addDependency($fileId, null, 'use', 'SomeClass');

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
        $this->storage->addFile($path, '<?php echo 1;', null, true);
        $file1 = $this->storage->getFileByPath($path);
        $this->assertNotNull($file1);
        $this->assertArrayHasKey('is_entry', $file1);
        $this->assertEquals(1, $file1['is_entry']);

        // Update without entry flag - should preserve
        $this->storage->addFile($path, '<?php echo 2;');
        $file2 = $this->storage->getFileByPath($path);
        $this->assertNotNull($file2);
        $this->assertArrayHasKey('is_entry', $file2);
        $this->assertEquals(1, $file2['is_entry']);

        // New file without entry flag
        $this->storage->addFile('other.php', '<?php', null, null, false);
        $file3 = $this->storage->getFileByPath('other.php');
        $this->assertNotNull($file3);
        $this->assertArrayHasKey('is_entry', $file3);
        $this->assertEquals(0, $file3['is_entry']);
    }

    public function testGetDependenciesByFile(): void
    {
        // Create test files
        $sourceFileId = $this->storage->addFile('source.php', '<?php');
        $targetFileId = $this->storage->addFile('target.php', '<?php');

        // Add dependencies
        $this->storage->addDependency($sourceFileId, null, 'use_class', 'App\TestClass', 10);
        $this->storage->addDependency($sourceFileId, null, 'extends', 'App\BaseClass', 20);

        // Get dependencies and resolve the first one
        $dependencies = $this->storage->getDependenciesByFile($sourceFileId);
        if (!empty($dependencies)) {
            $this->storage->resolveDependency($dependencies[0]['id'], $targetFileId);
        }

        // Get dependencies for the source file
        $dependencies = $this->storage->getDependenciesByFile($sourceFileId);

        $this->assertCount(2, $dependencies);
        $this->assertArrayHasKey('source_path', $dependencies[0]);
        $this->assertArrayHasKey('dependency_type', $dependencies[0]);
        $this->assertArrayHasKey('target_symbol', $dependencies[0]);
        $this->assertArrayHasKey('line_number', $dependencies[0]);
        $this->assertArrayHasKey('is_resolved', $dependencies[0]);
        $this->assertArrayHasKey('target_path', $dependencies[0]);
        $this->assertEquals('source.php', $dependencies[0]['source_path']);
        $this->assertEquals('use_class', $dependencies[0]['dependency_type']);
        $this->assertEquals('App\TestClass', $dependencies[0]['target_symbol']);
        $this->assertEquals(10, $dependencies[0]['line_number']);
        $this->assertEquals(1, $dependencies[0]['is_resolved']);
        $this->assertEquals('target.php', $dependencies[0]['target_path']);

        $this->assertArrayHasKey('dependency_type', $dependencies[1]);
        $this->assertArrayHasKey('target_symbol', $dependencies[1]);
        $this->assertArrayHasKey('is_resolved', $dependencies[1]);
        $this->assertArrayHasKey('target_path', $dependencies[1]);
        $this->assertEquals('extends', $dependencies[1]['dependency_type']);
        $this->assertEquals('App\BaseClass', $dependencies[1]['target_symbol']);
        $this->assertEquals(0, $dependencies[1]['is_resolved']);
        $this->assertNull($dependencies[1]['target_path']);
    }

    public function testGetAllDependencies(): void
    {
        // Create test files
        $file1Id = $this->storage->addFile('file1.php', '<?php');
        $file2Id = $this->storage->addFile('file2.php', '<?php');
        $file3Id = $this->storage->addFile('file3.php', '<?php');

        // Add dependencies from different files
        $this->storage->addDependency($file1Id, null, 'use_class', 'App\ClassA', 5);
        $this->storage->addDependency($file2Id, null, 'extends', 'App\ClassB', 15);
        $this->storage->addDependency($file1Id, null, 'implements', 'App\InterfaceC', 25);

        // Get all dependencies
        $allDependencies = $this->storage->getAllDependencies();

        $this->assertCount(3, $allDependencies);

        // Check that dependencies are ordered by source_file_id, then by id
        $this->assertArrayHasKey('source_path', $allDependencies[0]);
        $this->assertArrayHasKey('dependency_type', $allDependencies[0]);
        $this->assertArrayHasKey('target_symbol', $allDependencies[0]);
        $this->assertEquals('file1.php', $allDependencies[0]['source_path']);
        $this->assertEquals('use_class', $allDependencies[0]['dependency_type']);
        $this->assertEquals('App\ClassA', $allDependencies[0]['target_symbol']);

        $this->assertArrayHasKey('source_path', $allDependencies[1]);
        $this->assertArrayHasKey('dependency_type', $allDependencies[1]);
        $this->assertArrayHasKey('target_symbol', $allDependencies[1]);
        $this->assertEquals('file1.php', $allDependencies[1]['source_path']);
        $this->assertEquals('implements', $allDependencies[1]['dependency_type']);
        $this->assertEquals('App\InterfaceC', $allDependencies[1]['target_symbol']);

        $this->assertArrayHasKey('source_path', $allDependencies[2]);
        $this->assertArrayHasKey('dependency_type', $allDependencies[2]);
        $this->assertArrayHasKey('target_symbol', $allDependencies[2]);
        $this->assertEquals('file2.php', $allDependencies[2]['source_path']);
        $this->assertEquals('extends', $allDependencies[2]['dependency_type']);
        $this->assertEquals('App\ClassB', $allDependencies[2]['target_symbol']);
    }

    public function testAddAstNodeWithData(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php class TestClass {}');

        $nodeData = [
            'file_id' => $fileId,
            'parent_id' => null,
            'node_type' => 'Stmt_Class',
            'start_line' => 1,
            'end_line' => 3,
            'position' => 0,
            'fqcn' => 'TestClass',
            'attributes' => '{"name":"TestClass","type":"class"}',
        ];

        $nodeId = $this->storage->addAstNodeWithData($nodeData);

        $this->assertGreaterThan(0, $nodeId);

        // Verify the node was stored correctly
        $nodes = $this->storage->getAstNodesByFileId($fileId);
        $this->assertCount(1, $nodes);

        $node = $nodes[0];
        $this->assertEquals('Stmt_Class', $node['node_type']);
        $this->assertEquals(1, $node['start_line']);
        $this->assertEquals(3, $node['end_line']);
        $this->assertEquals(0, $node['position']);
        $this->assertEquals('TestClass', $node['fqcn']);
        $this->assertEquals('{"name":"TestClass","type":"class"}', $node['attributes']);
    }

    public function testMarkFileAnalyzed(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php');

        // Initially the file should be pending analysis
        $file = $this->storage->getFileById($fileId);
        $this->assertNotNull($file);
        $this->assertEquals('pending', $file['analysis_status']);
        $this->assertEquals(0, $file['analyzed_dependencies']);

        // Mark as analyzed
        $this->storage->markFileAnalyzed($fileId);

        // Check status
        $file = $this->storage->getFileById($fileId);
        $this->assertNotNull($file);
        $this->assertEquals('completed', $file['analysis_status']);
        $this->assertEquals(1, $file['analyzed_dependencies']);
    }

    public function testMarkFileAnalysisFailed(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php');

        // Mark analysis as failed
        $this->storage->markFileAnalysisFailed($fileId);

        // Check status
        $file = $this->storage->getFileById($fileId);
        $this->assertNotNull($file);
        $this->assertEquals('failed', $file['analysis_status']);
    }

    public function testGetNextFileToAnalyze(): void
    {
        // Add some files
        $entryFileId = $this->storage->addFile('entry.php', '<?php', null, true);
        $regularFileId = $this->storage->addFile('regular.php', '<?php');
        $analyzedFileId = $this->storage->addFile('analyzed.php', '<?php');

        // Mark one file as analyzed
        $this->storage->markFileAnalyzed($analyzedFileId);

        // Get next file to analyze - should prioritize entry files
        $nextFile = $this->storage->getNextFileToAnalyze();
        $this->assertNotNull($nextFile);
        $this->assertEquals('entry.php', $nextFile['path']);
        $this->assertEquals(1, $nextFile['is_entry']);

        // Mark entry file as analyzed
        $this->storage->markFileAnalyzed($entryFileId);

        // Next should be the regular file
        $nextFile = $this->storage->getNextFileToAnalyze();
        $this->assertNotNull($nextFile);
        $this->assertEquals('regular.php', $nextFile['path']);
        $this->assertEquals(0, $nextFile['is_entry']);

        // Mark all files as analyzed
        $this->storage->markFileAnalyzed($regularFileId);

        // Should return null when no more files to analyze
        $nextFile = $this->storage->getNextFileToAnalyze();
        $this->assertNull($nextFile);
    }

    public function testStoreAst(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php class Test {}');

        // Create a mock AST array with a simple node (normally from PhpParser)
        $ast = [new String_('test')]; // Non-empty AST for testing

        // Store AST
        $this->storage->storeAst($fileId, $ast);

        // Verify file has ast_root_id
        $file = $this->storage->getFileById($fileId);
        $this->assertNotNull($file);
        $this->assertNotNull($file['ast_root_id']);
        $this->assertGreaterThan(0, $file['ast_root_id']);

        // Verify AST nodes were stored
        $nodes = $this->storage->getAstNodesByFileId($fileId);
        $this->assertCount(1, $nodes); // Should have root node
        $this->assertEquals('Root', $nodes[0]['node_type']);
    }

    public function testStoreAstWithEmptyArray(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php');

        // Store empty AST
        $this->storage->storeAst($fileId, []);

        // Should not create any nodes and not update ast_root_id
        $file = $this->storage->getFileById($fileId);
        $this->assertNotNull($file);
        $this->assertNull($file['ast_root_id']);

        $nodes = $this->storage->getAstNodesByFileId($fileId);
        $this->assertCount(0, $nodes);
    }

    public function testGetFileByIdNotFound(): void
    {
        $file = $this->storage->getFileById(999999);
        $this->assertNull($file);
    }

    public function testGetPdo(): void
    {
        $pdo = $this->storage->getPdo();

        $this->assertInstanceOf(\PDO::class, $pdo);

        // Verify it's the same instance
        $pdo2 = $this->storage->getPdo();
        $this->assertSame($pdo, $pdo2);

        // Test that we can use it to query the database
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' LIMIT 1");
        $this->assertNotFalse($stmt);
        $result = $stmt->fetch();
        $this->assertIsArray($result);
    }

    public function testAddSymbolWithDifferentVisibilities(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php');

        // Test different visibility levels
        $this->storage->addSymbol($fileId, 'method', 'publicMethod', 'TestClass::publicMethod', 'TestClass', 'public');
        $this->storage->addSymbol($fileId, 'method', 'protectedMethod', 'TestClass::protectedMethod', 'TestClass', 'protected');
        $this->storage->addSymbol($fileId, 'method', 'privateMethod', 'TestClass::privateMethod', 'TestClass', 'private');

        // addSymbol now returns void, no IDs to assert
        // Verify symbols were added by checking they can be found
        $publicFile = $this->storage->findFileBySymbol('TestClass::publicMethod');
        $protectedFile = $this->storage->findFileBySymbol('TestClass::protectedMethod');
        $privateFile = $this->storage->findFileBySymbol('TestClass::privateMethod');

        $this->assertNotNull($publicFile, 'Public method symbol should be findable');
        $this->assertNotNull($protectedFile, 'Protected method symbol should be findable');
        $this->assertNotNull($privateFile, 'Private method symbol should be findable');
    }

    public function testAddSymbolWithAbstractAndFinal(): void
    {
        $fileId = $this->storage->addFile('test.php', '<?php');

        // Test abstract and final symbols
        $this->storage->addSymbol($fileId, 'class', 'AbstractClass', 'AbstractClass', '', 'public', true);
        $this->storage->addSymbol($fileId, 'class', 'FinalClass', 'FinalClass', '', 'public', false, true);

        // addSymbol now returns void, no IDs to assert
        // Verify symbols were added by checking they can be found
        $abstractFile = $this->storage->findFileBySymbol('AbstractClass');
        $finalFile = $this->storage->findFileBySymbol('FinalClass');

        $this->assertNotNull($abstractFile, 'Abstract class symbol should be findable');
        $this->assertNotNull($finalFile, 'Final class symbol should be findable');
    }

    public function testAddDependencyWithCompleteContext(): void
    {
        $sourceId = $this->storage->addFile('source.php', '<?php');
        $targetId = $this->storage->addFile('target.php', '<?php');

        // Test dependency with full context
        $this->storage->addDependency(
            $sourceId,
            null,
            'use_class',
            'App\TestClass',
            15,
            true, // conditional
            'use App\TestClass;'
        );

        // addDependency now returns void, no ID to assert

        // Verify dependency was stored with correct context
        $dependencies = $this->storage->getDependenciesByFile($sourceId);
        $this->assertCount(1, $dependencies);
        $this->assertEquals('use_class', $dependencies[0]['dependency_type']);
        $this->assertEquals('App\TestClass', $dependencies[0]['target_symbol']);
        $this->assertEquals(15, $dependencies[0]['line_number']);
        $this->assertEquals('use App\TestClass;', $dependencies[0]['context']);
    }

    public function testAddFileWithSpecialCharacters(): void
    {
        $path = 'special/file with spaces & symbols.php';
        $content = '<?php echo "special content";';

        $fileId = $this->storage->addFile($path, $content);
        $this->assertGreaterThan(0, $fileId);

        $file = $this->storage->getFileByPath($path);
        $this->assertNotNull($file);
        $this->assertEquals($path, $file['path']);
        $this->assertEquals($content, $file['content']);
    }

    public function testAutoloadRulesOrdering(): void
    {
        // Add rules with different priorities
        $this->storage->addAutoloadRule('psr4', '/path/high', 'High\\', 200);
        $this->storage->addAutoloadRule('psr0', '/path/medium', 'Medium_', 100);
        $this->storage->addAutoloadRule('files', '/path/low.php', null, 50);

        $rules = $this->storage->getAutoloadRules();

        $this->assertCount(3, $rules);

        // Verify ordering by priority DESC
        $this->assertEquals(200, $rules[0]['priority']);
        $this->assertEquals(100, $rules[1]['priority']);
        $this->assertEquals(50, $rules[2]['priority']);
    }

    public function testFindFileBySymbolWithDifferentTypes(): void
    {
        $fileId = $this->storage->addFile('multi.php', '<?php');

        // Add different types of symbols
        $this->storage->addSymbol($fileId, 'class', 'TestClass', 'TestClass');
        $this->storage->addSymbol($fileId, 'interface', 'TestInterface', 'TestInterface');
        $this->storage->addSymbol($fileId, 'trait', 'TestTrait', 'TestTrait');
        $this->storage->addSymbol($fileId, 'function', 'testFunction', 'testFunction');

        // Test finding each type
        $classFile = $this->storage->findFileBySymbol('TestClass');
        $interfaceFile = $this->storage->findFileBySymbol('TestInterface');
        $traitFile = $this->storage->findFileBySymbol('TestTrait');
        $functionFile = $this->storage->findFileBySymbol('testFunction');

        $this->assertNotNull($classFile);
        $this->assertNotNull($interfaceFile);
        $this->assertNotNull($traitFile);
        $this->assertNotNull($functionFile);

        $this->assertEquals('multi.php', $classFile['path']);
        $this->assertEquals('multi.php', $interfaceFile['path']);
        $this->assertEquals('multi.php', $traitFile['path']);
        $this->assertEquals('multi.php', $functionFile['path']);
    }

    public function testMultipleUnresolvedDependencies(): void
    {
        $sourceId = $this->storage->addFile('source.php', '<?php');

        // Add multiple unresolved dependencies
        $this->storage->addDependency($sourceId, null, 'use_class', 'UnresolvedClass1', 10);
        $this->storage->addDependency($sourceId, null, 'extends', 'UnresolvedClass2', 20);
        $this->storage->addDependency($sourceId, null, 'implements', 'UnresolvedInterface', 30);

        $unresolved = $this->storage->getUnresolvedDependencies();

        $this->assertCount(3, $unresolved);

        // Verify all are unresolved
        foreach ($unresolved as $dep) {
            $this->assertEquals(0, $dep['is_resolved']);
            $this->assertEquals('source.php', $dep['source_path']);
        }
    }

    public function testComplexDependencyChain(): void
    {
        // Create a complex dependency chain: A -> B -> C -> D
        $fileA = $this->storage->addFile('A.php', '<?php');
        $fileB = $this->storage->addFile('B.php', '<?php');
        $fileC = $this->storage->addFile('C.php', '<?php');
        $fileD = $this->storage->addFile('D.php', '<?php');

        // Create chain
        $this->storage->addDependency($fileA, null, 'use_class', 'ClassB');
        $this->storage->addDependency($fileB, null, 'extends', 'ClassC');
        $this->storage->addDependency($fileC, null, 'implements', 'InterfaceD');

        // Get dependencies and resolve them
        $depsA = $this->storage->getDependenciesByFile($fileA);
        $depsB = $this->storage->getDependenciesByFile($fileB);
        $depsC = $this->storage->getDependenciesByFile($fileC);

        if (!empty($depsA)) {
            $this->storage->resolveDependency($depsA[0]['id'], $fileB);
        }
        if (!empty($depsB)) {
            $this->storage->resolveDependency($depsB[0]['id'], $fileC);
        }
        if (!empty($depsC)) {
            $this->storage->resolveDependency($depsC[0]['id'], $fileD);
        }

        // Get all required files from A
        $required = $this->storage->getAllRequiredFiles($fileA);

        // Should include all files in the chain
        $paths = array_column($required, 'path');
        $this->assertContains('A.php', $paths);
        $this->assertContains('B.php', $paths);
        $this->assertContains('C.php', $paths);
        $this->assertContains('D.php', $paths);
    }

    public function testAnalysisStatusTransitions(): void
    {
        $fileId = $this->storage->addFile('analysis.php', '<?php');

        // Initially should be pending
        $file = $this->storage->getFileById($fileId);
        $this->assertNotNull($file);
        $this->assertEquals('pending', $file['analysis_status']);
        $this->assertEquals(0, $file['analyzed_dependencies']);

        // Mark as analyzed
        $this->storage->markFileAnalyzed($fileId);
        $file = $this->storage->getFileById($fileId);
        $this->assertNotNull($file);
        $this->assertEquals('completed', $file['analysis_status']);
        $this->assertEquals(1, $file['analyzed_dependencies']);

        // Mark as failed (simulate retry scenario)
        $this->storage->markFileAnalysisFailed($fileId);
        $file = $this->storage->getFileById($fileId);
        $this->assertNotNull($file);
        $this->assertEquals('failed', $file['analysis_status']);
    }

    public function testGetStatisticsWithComplexData(): void
    {
        // Create more complex data for statistics
        $file1Id = $this->storage->addFile('src/Class1.php', '<?php', 'class');
        $file2Id = $this->storage->addFile('src/Class2.php', '<?php', 'class');
        $file3Id = $this->storage->addFile('scripts/script.php', '<?php', 'script');

        // Add symbols
        $this->storage->addSymbol($file1Id, 'class', 'Class1', 'App\Class1');
        $this->storage->addSymbol($file1Id, 'method', 'method1', 'App\Class1::method1');
        $this->storage->addSymbol($file2Id, 'class', 'Class2', 'App\Class2');
        $this->storage->addSymbol($file2Id, 'interface', 'Interface2', 'App\Interface2');

        // Add dependencies
        $this->storage->addDependency($file1Id, null, 'use_class', 'App\Class2');
        $this->storage->addDependency($file2Id, null, 'extends', 'App\BaseClass');
        $this->storage->addDependency($file3Id, null, 'require', 'config.php');

        // Add autoload rules
        $this->storage->addAutoloadRule('psr4', '/src', 'App\\');
        $this->storage->addAutoloadRule('files', '/bootstrap.php');

        $stats = $this->storage->getStatistics();

        $this->assertEquals(3, $stats['total_files']);
        $this->assertEquals(2, $stats['total_classes']); // Only class symbols (Class1 and Class2)
        $this->assertEquals(3, $stats['total_dependencies']);
        $this->assertEquals(2, $stats['total_autoload_rules']);
    }

    public function testAddAutoloadRuleValidation(): void
    {
        // Test adding PSR-4 autoload rule
        $this->storage->addAutoloadRule('psr4', '/path/to/src', 'App\Namespace\\', 150);

        $rules = $this->storage->getAutoloadRules();
        $psr4Rule = array_filter($rules, fn ($rule) => isset($rule['type'], $rule['prefix']) && 'psr4' === $rule['type'] && 'App\Namespace\\' === $rule['prefix']);
        $psr4Rule = array_values($psr4Rule)[0] ?? null;

        $this->assertNotNull($psr4Rule);
        $this->assertArrayHasKey('type', $psr4Rule);
        $this->assertArrayHasKey('path', $psr4Rule);
        $this->assertArrayHasKey('prefix', $psr4Rule);
        $this->assertArrayHasKey('priority', $psr4Rule);
        $this->assertEquals('psr4', $psr4Rule['type']);
        $this->assertEquals('/path/to/src', $psr4Rule['path']);
        $this->assertEquals('App\Namespace\\', $psr4Rule['prefix']);
        $this->assertEquals(150, $psr4Rule['priority']);

        // Test adding files autoload rule (without prefix)
        $this->storage->addAutoloadRule('files', '/path/to/functions.php');

        $rules = $this->storage->getAutoloadRules();
        $filesRule = array_filter($rules, fn ($rule) => isset($rule['type'], $rule['path']) && 'files' === $rule['type'] && '/path/to/functions.php' === $rule['path']);
        $filesRule = array_values($filesRule)[0] ?? null;

        $this->assertNotNull($filesRule);
        $this->assertArrayHasKey('type', $filesRule);
        $this->assertArrayHasKey('path', $filesRule);
        $this->assertArrayHasKey('prefix', $filesRule);
        $this->assertArrayHasKey('priority', $filesRule);
        $this->assertEquals('files', $filesRule['type']);
        $this->assertEquals('/path/to/functions.php', $filesRule['path']);
        $this->assertNull($filesRule['prefix']);
        $this->assertEquals(100, $filesRule['priority']); // default priority
    }

    public function testTransactionRollbackBehavior(): void
    {
        // Start transaction
        $this->storage->beginTransaction();

        // Add data within transaction
        $fileId = $this->storage->addFile('transaction_test.php', '<?php echo "test";');
        $this->assertGreaterThan(0, $fileId);

        // Verify data exists within transaction
        $file = $this->storage->getFileByPath('transaction_test.php');
        $this->assertNotNull($file);
        $this->assertEquals('transaction_test.php', $file['path']);

        // Rollback transaction
        $this->storage->rollback();

        // Verify data is rolled back
        $file = $this->storage->getFileByPath('transaction_test.php');
        $this->assertNull($file);
    }

    public function testTransactionCommitBehavior(): void
    {
        // Start transaction
        $this->storage->beginTransaction();

        // Add data within transaction
        $fileId = $this->storage->addFile('commit_test.php', '<?php echo "commit";');
        $this->storage->addSymbol($fileId, 'function', 'testFunc', 'testFunc');

        $this->assertGreaterThan(0, $fileId);
        // addSymbol now returns void, no ID to assert
        // Verify symbol was added by checking it can be found
        $symbolFile = $this->storage->findFileBySymbol('testFunc');
        $this->assertNotNull($symbolFile);

        // Commit transaction
        $this->storage->commit();

        // Verify data persists after commit
        $file = $this->storage->getFileByPath('commit_test.php');
        $this->assertNotNull($file);
        $this->assertEquals('commit_test.php', $file['path']);
        $this->assertEquals('<?php echo "commit";', $file['content']);

        // Verify symbol exists
        $symbolFile = $this->storage->findFileBySymbol('testFunc');
        $this->assertNotNull($symbolFile);
        $this->assertEquals('commit_test.php', $symbolFile['path']);
    }

    public function testUpdateFileAstRootValidation(): void
    {
        // Create file and AST node
        $fileId = $this->storage->addFile('ast_update.php', '<?php class TestAst {}');
        $rootNodeId = $this->storage->addAstNode($fileId, 0, 'Root', '', 0);

        $this->assertGreaterThan(0, $rootNodeId);

        // Update file with AST root
        $this->storage->updateFileAstRoot($fileId, $rootNodeId);

        // Verify AST root is set correctly
        $file = $this->storage->getFileById($fileId);
        $this->assertNotNull($file);
        $this->assertEquals($rootNodeId, $file['ast_root_id']);

        // Test updating with different root
        $newRootNodeId = $this->storage->addAstNode($fileId, 0, 'NewRoot', '', 0);
        $this->storage->updateFileAstRoot($fileId, $newRootNodeId);

        $file = $this->storage->getFileById($fileId);
        $this->assertNotNull($file);
        $this->assertEquals($newRootNodeId, $file['ast_root_id']);
        $this->assertNotEquals($rootNodeId, $file['ast_root_id']);
    }

    public function testBeginTransaction(): void
    {
        $pdo = $this->storage->getPdo();

        // Verify not in transaction initially
        $this->assertFalse($pdo->inTransaction());

        // Begin transaction
        $this->storage->beginTransaction();

        // Verify transaction started
        $this->assertTrue($pdo->inTransaction());

        // Cleanup - rollback to restore state
        $this->storage->rollback();
        $this->assertFalse($pdo->inTransaction());
    }

    public function testCommit(): void
    {
        // Start transaction
        $this->storage->beginTransaction();
        $pdo = $this->storage->getPdo();
        $this->assertTrue($pdo->inTransaction());

        // Add data within transaction
        $fileId = $this->storage->addFile('commit_specific_test.php', '<?php echo "commit test";');
        $this->assertGreaterThan(0, $fileId);

        // Commit transaction
        $this->storage->commit();

        // Verify transaction ended
        $this->assertFalse($pdo->inTransaction());

        // Verify data persisted after commit
        $file = $this->storage->getFileByPath('commit_specific_test.php');
        $this->assertNotNull($file);
        $this->assertEquals('commit_specific_test.php', $file['path']);
        $this->assertEquals('<?php echo "commit test";', $file['content']);
    }

    public function testRollback(): void
    {
        // Start transaction
        $this->storage->beginTransaction();
        $pdo = $this->storage->getPdo();
        $this->assertTrue($pdo->inTransaction());

        // Add data within transaction
        $fileId = $this->storage->addFile('rollback_specific_test.php', '<?php echo "rollback test";');
        $this->assertGreaterThan(0, $fileId);

        // Verify data exists within transaction
        $file = $this->storage->getFileByPath('rollback_specific_test.php');
        $this->assertNotNull($file);

        // Rollback transaction
        $this->storage->rollback();

        // Verify transaction ended
        $this->assertFalse($pdo->inTransaction());

        // Verify data was rolled back
        $file = $this->storage->getFileByPath('rollback_specific_test.php');
        $this->assertNull($file);
    }

    public function testNestedTransactionHandling(): void
    {
        $pdo = $this->storage->getPdo();

        // SQLite doesn't support nested transactions, but we can test the behavior
        $this->storage->beginTransaction();
        $this->assertTrue($pdo->inTransaction());

        // Attempting to begin another transaction in SQLite would throw an exception
        $this->expectException(\PDOException::class);
        $this->storage->beginTransaction();
    }

    public function testTransactionConsistency(): void
    {
        // Test that multiple operations in a transaction are atomic
        $this->storage->beginTransaction();

        $fileId = $this->storage->addFile('consistency_test.php', '<?php');
        $symbolId = $this->storage->addSymbol($fileId, 'class', 'TestClass', 'TestClass');
        $this->storage->addDependency($fileId, null, 'use_class', 'OtherClass');

        // All should be present in transaction
        $this->assertNotNull($this->storage->getFileByPath('consistency_test.php'));
        $this->assertNotNull($this->storage->findFileBySymbol('TestClass'));

        // Rollback
        $this->storage->rollback();

        // All should be gone
        $this->assertNull($this->storage->getFileByPath('consistency_test.php'));
        $this->assertNull($this->storage->findFileBySymbol('TestClass'));
    }
}
