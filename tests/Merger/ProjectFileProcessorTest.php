<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\NodeDeduplicator;
use PhpPacker\Merger\ProjectFileProcessor;
use PhpParser\Node\Stmt\Namespace_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(ProjectFileProcessor::class)]
final class ProjectFileProcessorTest extends TestCase
{
    private ProjectFileProcessor $processor;

    private static string $tempDir;

    protected function setUp(): void
    {
        // 创建 mock 依赖
        $logger = $this->createMock(LoggerInterface::class);
        $deduplicator = $this->createMock(NodeDeduplicator::class);

        $this->processor = new ProjectFileProcessor($logger, $deduplicator);
        self::$tempDir = sys_get_temp_dir() . '/project-processor-test-' . uniqid();
        mkdir(self::$tempDir, 0o777, true);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$tempDir)) {
            self::removeDirectory(self::$tempDir);
        }
    }

    public function testProcessProjectFile(): void
    {
        $content = '<?php
namespace App\Service;

use App\Entity\User;
use Vendor\External\Library;

class UserService {
    private Library $library;
    
    public function getUser(): ?User {
        return new User();
    }
}
';
        $file = $this->createFile('UserService.php', $content);

        $processed = $this->processor->processFile($file);

        $this->assertArrayHasKey('ast', $processed);
        $this->assertArrayHasKey('dependencies', $processed);
        $this->assertArrayHasKey('symbols', $processed);
        $this->assertArrayHasKey('metadata', $processed);

        $this->assertContains('App\Entity\User', $processed['dependencies']);
        $this->assertContains('Vendor\External\Library', $processed['dependencies']);
        $this->assertContains('App\Service\UserService', $processed['symbols']['classes']);
    }

    public function testProcessMultipleFiles(): void
    {
        $file1 = $this->createFile('Class1.php', '<?php
namespace App;
class Class1 {}
');

        $file2 = $this->createFile('Class2.php', '<?php
namespace App;
class Class2 extends Class1 {}
');

        $files = [$file1, $file2];
        $results = $this->processor->processFiles($files);

        $this->assertCount(2, $results);
        $this->assertArrayHasKey($file1, $results);
        $this->assertArrayHasKey($file2, $results);

        // Class2 应该依赖 Class1
        $this->assertContains('App\Class1', $results[$file2]['dependencies']);
    }

    public function testFilterProjectDependencies(): void
    {
        $dependencies = [
            'App\Service\UserService',
            'App\Entity\User',
            'Vendor\External\Library',
            'DateTime',
            'stdClass',
        ];

        $projectNamespaces = ['App\\'];
        $filtered = $this->processor->filterProjectDependencies($dependencies, $projectNamespaces);

        $this->assertContains('App\Service\UserService', $filtered);
        $this->assertContains('App\Entity\User', $filtered);
        $this->assertNotContains('Vendor\External\Library', $filtered);
        $this->assertNotContains('DateTime', $filtered);
        $this->assertNotContains('stdClass', $filtered);
    }

    public function testExtractProjectMetadata(): void
    {
        $content = '<?php
/**
 * User service for managing users
 * 
 * @package App\Service
 * @author Developer
 */
namespace App\Service;

/**
 * Main user service class
 */
class UserService {
    /**
     * Get user by ID
     * @param int $id
     * @return User|null
     */
    public function getUser(int $id): ?User {
        // Implementation
    }
}
';
        $file = $this->createFile('UserService.php', $content);

        $processed = $this->processor->processFile($file);
        $metadata = $processed['metadata'];

        $this->assertEquals('App\Service', $metadata['namespace']);
        $this->assertArrayHasKey('file_doc', $metadata);
        $this->assertStringContainsString('User service for managing users', $metadata['file_doc']);
        $this->assertArrayHasKey('classes', $metadata);
        $this->assertArrayHasKey('UserService', $metadata['classes']);
    }

    public function testProcessWithSyntaxError(): void
    {
        $file = $this->createFile('Invalid.php', '<?php
class Invalid {
    // Missing closing brace
');

        $processed = $this->processor->processFile($file);

        $this->assertArrayHasKey('error', $processed);
        $this->assertStringContainsString('Syntax error', $processed['error']);
        $this->assertEmpty($processed['ast']);
        $this->assertEmpty($processed['dependencies']);
    }

    public function testProcessDirectory(): void
    {
        $this->createFile('src/Service/UserService.php', '<?php
namespace App\Service;
class UserService {}
');

        $this->createFile('src/Entity/User.php', '<?php
namespace App\Entity;
class User {}
');

        $this->createFile('tests/UserTest.php', '<?php
namespace App\Tests;
class UserTest {}
');

        $files = $this->processor->findProjectFiles(self::$tempDir . '/src');
        $results = $this->processor->processFiles($files);

        $this->assertCount(2, $results);

        // 验证找到了正确的文件
        $paths = array_keys($results);
        $this->assertStringContainsString('UserService.php', $paths[0] . $paths[1]);
        $this->assertStringContainsString('User.php', $paths[0] . $paths[1]);
    }

    public function testGetProcessingStats(): void
    {
        // 重置统计数据
        $this->processor->resetProcessingStats();

        $files = [
            $this->createFile('File1.php', '<?php class Class1 {}'),
            $this->createFile('File2.php', '<?php class Class2 {}'),
            $this->createFile('Invalid.php', '<?php class Invalid {'), // 语法错误
        ];

        $this->processor->processFiles($files);
        $stats = $this->processor->getProcessingStats();

        $this->assertEquals(3, $stats['total_files']);
        $this->assertEquals(2, $stats['successful']);
        $this->assertEquals(1, $stats['failed']);
        $this->assertGreaterThan(0, $stats['processing_time']);
        $this->assertArrayHasKey('memory_usage', $stats);
    }

    public function testProcessWithCircularDependencies(): void
    {
        $file1 = $this->createFile('Class1.php', '<?php
namespace App;
use App\Class2;
class Class1 {
    private Class2 $class2;
}
');

        $file2 = $this->createFile('Class2.php', '<?php
namespace App;
class Class2 {
    private Class1 $class1;
}
');

        $results = $this->processor->processFiles([$file1, $file2]);

        // 应该正确处理循环依赖
        $this->assertContains('App\Class2', $results[$file1]['dependencies']);
        $this->assertContains('App\Class1', $results[$file2]['dependencies']);

        // 检查循环依赖检测
        $circular = $this->processor->detectCircularDependencies([$file1, $file2]);
        $this->assertNotEmpty($circular);
    }

    public function testDetectCircularDependencies(): void
    {
        // 创建有循环依赖的文件
        $file1 = $this->createFile('Circular1.php', '<?php
namespace App;
use App\Circular2;
class Circular1 {
    public function test(Circular2 $c) {}
}
');

        $file2 = $this->createFile('Circular2.php', '<?php
namespace App;
use App\Circular1;
class Circular2 {
    public function test(Circular1 $c) {}
}
');

        $circular = $this->processor->detectCircularDependencies([$file1, $file2]);

        // 应该检测到循环依赖
        $this->assertNotEmpty($circular);
        $this->assertContains('App\Circular1', $circular);
    }

    public function testFindProjectFiles(): void
    {
        // 创建测试文件结构
        $this->createFile('src/Service/Service1.php', '<?php
namespace App\Service;
class Service1 {}
');
        $this->createFile('src/Service/Service2.php', '<?php
namespace App\Service;
class Service2 {}
');
        $this->createFile('src/Entity/User.php', '<?php
namespace App\Entity;
class User {}
');

        $files = $this->processor->findProjectFiles(self::$tempDir . '/src');

        $this->assertIsArray($files);
        $this->assertGreaterThanOrEqual(3, count($files));

        // 验证找到的文件都是 .php 文件
        foreach ($files as $file) {
            $this->assertStringEndsWith('.php', $file);
        }
    }

    public function testMergeProjectFiles(): void
    {
        // 创建项目文件
        $file1 = $this->createFile('File1.php', '<?php
namespace App\Service;
class Service1 {}
');

        $file2 = $this->createFile('File2.php', '<?php
namespace App\Service;
class Service2 {}
');

        // 处理文件并准备合并数据
        $processed1 = $this->processor->processFile($file1);
        $processed2 = $this->processor->processFile($file2);

        $projectFiles = [
            ['path' => $file1, 'content' => file_get_contents($file1)],
            ['path' => $file2, 'content' => file_get_contents($file2)],
        ];

        $merged = $this->processor->mergeProjectFiles($projectFiles);

        $this->assertIsArray($merged);
        $this->assertNotEmpty($merged);

        // 验证合并后的节点包含命名空间
        $hasNamespace = false;
        foreach ($merged as $node) {
            if ($node instanceof Namespace_) {
                $hasNamespace = true;
                break;
            }
        }
        $this->assertTrue($hasNamespace);
    }

    public function testProcessFile(): void
    {
        $content = '<?php
namespace App\Test;

use App\Entity\User;

class TestService {
    private User $user;

    public function getUser(): User {
        return $this->user;
    }
}
';
        $file = $this->createFile('TestService.php', $content);

        $result = $this->processor->processFile($file);

        $this->assertArrayHasKey('ast', $result);
        $this->assertArrayHasKey('dependencies', $result);
        $this->assertArrayHasKey('symbols', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertArrayHasKey('path', $result);

        // 验证依赖
        $this->assertContains('App\Entity\User', $result['dependencies']);

        // 验证符号
        $this->assertArrayHasKey('classes', $result['symbols']);
        $this->assertContains('App\Test\TestService', $result['symbols']['classes']);

        // 验证元数据
        $this->assertEquals('App\Test', $result['metadata']['namespace']);
    }

    public function testProcessFiles(): void
    {
        $file1 = $this->createFile('File1.php', '<?php
namespace App;
class Class1 {}
');

        $file2 = $this->createFile('File2.php', '<?php
namespace App;
class Class2 {}
');

        $file3 = $this->createFile('File3.php', '<?php
namespace App;
class Class3 {}
');

        $results = $this->processor->processFiles([$file1, $file2, $file3]);

        $this->assertCount(3, $results);
        $this->assertArrayHasKey($file1, $results);
        $this->assertArrayHasKey($file2, $results);
        $this->assertArrayHasKey($file3, $results);

        // 验证每个文件都被正确处理
        foreach ($results as $result) {
            $this->assertArrayHasKey('ast', $result);
            $this->assertArrayHasKey('dependencies', $result);
            $this->assertArrayHasKey('symbols', $result);
        }
    }

    public function testResetProcessingStats(): void
    {
        // 先处理一些文件以产生统计数据
        $file = $this->createFile('TestFile.php', '<?php class TestClass {}');
        $this->processor->processFile($file);

        $stats = $this->processor->getProcessingStats();
        $this->assertGreaterThan(0, $stats['total_files']);

        // 重置统计
        $this->processor->resetProcessingStats();

        $resetStats = $this->processor->getProcessingStats();
        $this->assertEquals(0, $resetStats['total_files']);
        $this->assertEquals(0, $resetStats['successful']);
        $this->assertEquals(0, $resetStats['failed']);
        $this->assertEquals(0, $resetStats['processing_time']);
        $this->assertEquals(0, $resetStats['memory_usage']);
    }

    private function createFile(string $path, string $content): string
    {
        $fullPath = self::$tempDir . '/' . $path;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($fullPath, $content);

        return $fullPath;
    }

    private static function removeDirectory(string $dir): void
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
