<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Analyzer;

use PhpPacker\Analyzer\AutoloadResolver;
use PhpPacker\Analyzer\DependencyResolver;
use PhpPacker\Analyzer\FileAnalyzer;
use PhpPacker\Storage\SqliteStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DependencyResolverTest extends TestCase
{
    private DependencyResolver $resolver;
    private SqliteStorage $storage;
    private AutoloadResolver $autoloadResolver;
    private FileAnalyzer $fileAnalyzer;
    private LoggerInterface $logger;
    private string $dbPath;
    private string $tempDir;

    public function testResolveSimpleDependencies(): void
    {
        // Create entry file
        $entryFile = $this->createFile('index.php', '<?php
require_once "bootstrap.php";
use App\\Service;

$service = new Service();
');

        // Create bootstrap file
        $this->createFile('bootstrap.php', '<?php
define("APP_ROOT", __DIR__);
require_once "config.php";
');

        // Create config file
        $this->createFile('config.php', '<?php
define("APP_ENV", "production");
');

        // Create service file
        $this->createFile('src/Service.php', '<?php
namespace App;
class Service {}
');

        // Set up autoload
        $this->autoloadResolver->loadComposerAutoload($this->createComposerJson([
            'autoload' => ['psr-4' => ['App\\' => 'src/']]
        ]));

        // Resolve dependencies
        $this->resolver->resolveAllDependencies($entryFile);

        // Check all files were analyzed
        $files = ['index.php', 'bootstrap.php', 'config.php', 'src/Service.php'];
        foreach ($files as $file) {
            $fileData = $this->storage->getFileByPath($file);
            $this->assertNotNull($fileData, "File $file should be in storage");
        }

        // Check dependencies are resolved
        $unresolved = $this->storage->getUnresolvedDependencies();
        $this->assertCount(0, $unresolved);
    }

    private function createFile(string $path, string $content): string
    {
        $fullPath = $this->tempDir . '/' . $path;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, $content);
        return $fullPath;
    }

    private function createComposerJson(array $data): string
    {
        $path = $this->tempDir . '/composer.json';
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
        return $path;
    }

    public function testCircularDependencyDetection(): void
    {
        // Create circular dependency: file1 -> file2 -> file3 -> file1
        $file1 = $this->createFile('file1.php', '<?php require "file2.php";');
        $file2 = $this->createFile('file2.php', '<?php require "file3.php";');
        $file3 = $this->createFile('file3.php', '<?php require "file1.php";');

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('Circular dependency detected'));

        $this->resolver->resolveAllDependencies($file1);

        // Should complete without infinite loop
        $this->assertTrue(true);
    }

    public function testGetLoadOrder(): void
    {
        // Create dependency chain: entry -> a -> b -> c
        $entryId = $this->storage->addFile('entry.php', '<?php');
        $aId = $this->storage->addFile('a.php', '<?php');
        $bId = $this->storage->addFile('b.php', '<?php');
        $cId = $this->storage->addFile('c.php', '<?php');

        // Add dependencies
        $this->storage->addDependency([
            'source_file_id' => $entryId,
            'target_file_id' => $aId,
            'dependency_type' => 'require',
            'is_resolved' => true
        ]);

        $this->storage->addDependency([
            'source_file_id' => $aId,
            'target_file_id' => $bId,
            'dependency_type' => 'require',
            'is_resolved' => true
        ]);

        $this->storage->addDependency([
            'source_file_id' => $bId,
            'target_file_id' => $cId,
            'dependency_type' => 'require',
            'is_resolved' => true
        ]);

        $loadOrder = $this->resolver->getLoadOrder($entryId);

        // Should load in reverse dependency order
        $this->assertCount(4, $loadOrder);
        $this->assertEquals('c.php', $loadOrder[0]['path']);
        $this->assertEquals('b.php', $loadOrder[1]['path']);
        $this->assertEquals('a.php', $loadOrder[2]['path']);
        $this->assertEquals('entry.php', $loadOrder[3]['path']);
    }

    public function testResolveClassDependencies(): void
    {
        // Create files with class dependencies
        $this->createFile('src/Base.php', '<?php
namespace App;
abstract class Base {}
');

        $this->createFile('src/Child.php', '<?php
namespace App;
class Child extends Base implements \\App\\Contract {
    use \\App\\Helper;
}
');

        $this->createFile('src/Contract.php', '<?php
namespace App;
interface Contract {}
');

        $this->createFile('src/Helper.php', '<?php
namespace App;
trait Helper {}
');

        $entryFile = $this->createFile('index.php', '<?php
use App\\Child;
$obj = new Child();
');

        // Set up autoload
        $this->autoloadResolver->loadComposerAutoload($this->createComposerJson([
            'autoload' => ['psr-4' => ['App\\' => 'src/']]
        ]));

        $this->resolver->resolveAllDependencies($entryFile);

        // Check all class files were found and analyzed
        $expectedFiles = ['src/Base.php', 'src/Child.php', 'src/Contract.php', 'src/Helper.php'];
        foreach ($expectedFiles as $file) {
            $fileData = $this->storage->getFileByPath($file);
            $this->assertNotNull($fileData, "File $file should be in storage");
        }
    }

    public function testResolveDynamicInclude(): void
    {
        $entryFile = $this->createFile('index.php', '<?php
$file = "dynamic.php";
require $file;
');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Cannot resolve dynamic include',
                $this->arrayHasKey('context')
            );

        $this->resolver->resolveAllDependencies($entryFile);
    }

    public function testResolveConditionalDependencies(): void
    {
        $entryFile = $this->createFile('index.php', '<?php
if (PHP_VERSION_ID >= 80000) {
    require "php8-features.php";
} else {
    require "php7-compat.php";
}
');

        $this->createFile('php8-features.php', '<?php echo "PHP 8";');
        $this->createFile('php7-compat.php', '<?php echo "PHP 7";');

        $this->resolver->resolveAllDependencies($entryFile);

        // Both files should be included as they're conditional
        $php8 = $this->storage->getFileByPath('php8-features.php');
        $php7 = $this->storage->getFileByPath('php7-compat.php');
        
        $this->assertNotNull($php8);
        $this->assertNotNull($php7);
    }

    public function testResolveRelativeIncludes(): void
    {
        $this->createFile('lib/loader.php', '<?php
require_once __DIR__ . "/helper.php";
require_once __DIR__ . "/../config.php";
');

        $this->createFile('lib/helper.php', '<?php function help() {}');
        $this->createFile('config.php', '<?php define("CONFIG", true);');

        $entryFile = $this->createFile('index.php', '<?php require "lib/loader.php";');

        $this->resolver->resolveAllDependencies($entryFile);

        // Check all files were resolved
        $this->assertNotNull($this->storage->getFileByPath('lib/loader.php'));
        $this->assertNotNull($this->storage->getFileByPath('lib/helper.php'));
        $this->assertNotNull($this->storage->getFileByPath('config.php'));
    }

    public function testUnresolvedClassWarning(): void
    {
        $entryFile = $this->createFile('index.php', '<?php
use NonExistent\\Class;
$obj = new Class();
');

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                'Class not found',
                $this->callback(function ($context) {
                    return isset($context['class']) && 
                           $context['class'] === 'NonExistent\\Class';
                })
            );

        $this->resolver->resolveAllDependencies($entryFile);
    }

    public function testMaxIterationsForUnresolved(): void
    {
        // Create file with unresolvable dependency
        $fileId = $this->storage->addFile('test.php', '<?php');
        $this->storage->addDependency([
            'source_file_id' => $fileId,
            'dependency_type' => 'use_class',
            'target_symbol' => 'Unresolvable\\Class',
            'is_resolved' => false
        ]);

        // Set up entry file
        $entryFile = $this->createFile('entry.php', '<?php');
        
        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                'Some dependencies remain unresolved',
                $this->arrayHasKey('count')
            );

        $this->resolver->resolveAllDependencies($entryFile);
    }

    public function testComplexDependencyGraph(): void
    {
        // Create complex dependency graph
        //       entry
        //      /  |  \
        //     a   b   c
        //    / \ / \ / \
        //   d   e   f   g
        //    \ / \ / \ /
        //      h   i

        $files = ['entry', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'];
        $fileIds = [];
        
        foreach ($files as $file) {
            $fileIds[$file] = $this->storage->addFile("$file.php", '<?php');
        }

        // Set up dependencies
        $deps = [
            ['entry', 'a'], ['entry', 'b'], ['entry', 'c'],
            ['a', 'd'], ['a', 'e'], ['b', 'e'], ['b', 'f'],
            ['c', 'f'], ['c', 'g'], ['d', 'h'], ['e', 'h'],
            ['e', 'i'], ['f', 'i'], ['g', 'i']
        ];

        foreach ($deps as [$source, $target]) {
            $this->storage->addDependency([
                'source_file_id' => $fileIds[$source],
                'target_file_id' => $fileIds[$target],
                'dependency_type' => 'require',
                'is_resolved' => true
            ]);
        }

        $loadOrder = $this->resolver->getLoadOrder($fileIds['entry']);
        
        // Verify topological sort
        $positions = array_flip(array_column($loadOrder, 'path'));
        
        foreach ($deps as [$source, $target]) {
            $sourcePos = $positions["$source.php"];
            $targetPos = $positions["$target.php"];
            
            $this->assertGreaterThan(
                $targetPos,
                $sourcePos,
                "$source should be loaded after $target"
            );
        }
    }

    public function testFileAnalysisError(): void
    {
        $invalidFile = $this->createFile('invalid.php', '<?php class { }'); // syntax error

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to process file',
                $this->callback(function ($context) {
                    return strpos($context['error'], 'Parse error') !== false;
                })
            );

        $this->resolver->resolveAllDependencies($invalidFile);
    }

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/php-packer-test-' . uniqid() . '.db';
        $this->tempDir = sys_get_temp_dir() . '/php-packer-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->storage = new SqliteStorage($this->dbPath, $this->logger);
        $this->autoloadResolver = new AutoloadResolver($this->storage, $this->logger, $this->tempDir);
        $this->fileAnalyzer = new FileAnalyzer($this->storage, $this->logger, $this->tempDir);

        $this->resolver = new DependencyResolver(
            $this->storage,
            $this->logger,
            $this->autoloadResolver,
            $this->fileAnalyzer
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }

        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        
        rmdir($dir);
    }
}