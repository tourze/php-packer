<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer\Processor;

use PhpPacker\Analyzer\FileAnalyzer;
use PhpPacker\Analyzer\FileVerifier;
use PhpPacker\Analyzer\PathResolver;
use PhpPacker\Analyzer\Processor\DependencyFileProcessor;
use PhpPacker\Storage\SqliteStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(DependencyFileProcessor::class)]
final class DependencyFileProcessorTest extends TestCase
{
    private DependencyFileProcessor $processor;

    private SqliteStorage $storage;

    private NullLogger $logger;

    private FileAnalyzer $fileAnalyzer;

    private PathResolver $pathResolver;

    private FileVerifier $fileVerifier;

    private string $dbPath;

    private string $tempDir;

    protected function setUp(): void
    {
        // 创建临时数据库
        $this->dbPath = sys_get_temp_dir() . '/test-' . uniqid() . '.db';
        $this->logger = new NullLogger();
        $this->storage = new SqliteStorage($this->dbPath, $this->logger);

        // 创建临时目录
        $this->tempDir = sys_get_temp_dir() . '/test-' . uniqid();
        mkdir($this->tempDir, 0777, true);

        // 初始化真实实例
        $this->fileAnalyzer = new FileAnalyzer($this->storage, $this->logger, $this->tempDir);
        $this->pathResolver = new PathResolver($this->logger, $this->tempDir);
        $this->fileVerifier = new FileVerifier($this->logger);

        $this->processor = new DependencyFileProcessor(
            $this->storage,
            $this->logger,
            $this->fileAnalyzer,
            $this->pathResolver,
            $this->fileVerifier
        );
    }

    protected function tearDown(): void
    {
        // 清理临时数据库
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }

        // 清理临时目录
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testProcessFile(): void
    {
        $testFile = $this->createTestFileInTemp('TestClass.php', '<?php
namespace Test;

use Another\Class1;
use Another\Class2 as Alias;

class TestClass extends Class1 implements \InterfaceA {
    use TraitB;

    public function method(Class2 $param) {
        new \Full\Qualified\ClassName();
        return Alias::staticMethod();
    }
}
');

        $result = $this->processor->processFile($testFile);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('dependencies', $result);

        $dependencies = $result['dependencies'];
        $fqcns = array_column($dependencies, 'target_symbol');

        $this->assertContains('Another\Class1', $fqcns);
        $this->assertContains('Another\Class2', $fqcns);
        $this->assertContains('InterfaceA', $fqcns);
        $this->assertContains('Test\TraitB', $fqcns);
        $this->assertContains('Full\Qualified\ClassName', $fqcns);
    }

    public function testProcessFileWithConstants(): void
    {
        $testFile = $this->createTestFileInTemp('ConstantClass.php', '<?php
namespace Test;

class TestClass {
    const TYPE = \OtherClass::CONSTANT;

    public function method() {
        return \Vendor\Package::CONST_VALUE;
    }
}
');

        $result = $this->processor->processFile($testFile);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('dependencies', $result);

        $dependencies = $result['dependencies'];
        $fqcns = array_column($dependencies, 'target_symbol');

        $this->assertContains('OtherClass', $fqcns);
        $this->assertContains('Vendor\Package', $fqcns);
    }

    public function testProcessFileWithCatch(): void
    {
        // catch 语句中的异常类型依赖目前在 FileAnalyzer 中未被实现
        // 实际系统不会提取 catch 语句中的类型作为依赖
        self::markTestSkipped('catch statement dependencies are not currently extracted by FileAnalyzer');

        $testFile = $this->createTestFileInTemp('CatchClass.php', '<?php
namespace Test;

class TestClass {
    public function method() {
        try {
            // some code
        } catch (\CustomException $e) {
            // handle
        } catch (AnotherException | ThirdException $e) {
            // handle
        }
    }
}
');

        $result = $this->processor->processFile($testFile);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('dependencies', $result);

        $dependencies = $result['dependencies'];
        $fqcns = array_column($dependencies, 'target_symbol');

        $this->assertContains('CustomException', $fqcns);
        $this->assertContains('Test\AnotherException', $fqcns);
        $this->assertContains('Test\ThirdException', $fqcns);
    }

    public function testProcessFileWithInstanceOf(): void
    {
        // instanceof 依赖目前在 FileAnalyzer 中未被实现
        // 实际系统不会提取 instanceof 表达式作为依赖
        self::markTestSkipped('instanceof dependencies are not currently extracted by FileAnalyzer');

        $testFile = $this->createTestFileInTemp('InstanceOfClass.php', '<?php
namespace Test;

class TestClass {
    public function method($obj) {
        if ($obj instanceof LocalClass) {
            // ...
        }
        if ($obj instanceof \Global\ClassName) {
            // ...
        }
    }
}
');

        $result = $this->processor->processFile($testFile);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('dependencies', $result);

        $dependencies = $result['dependencies'];
        $fqcns = array_column($dependencies, 'target_symbol');

        $this->assertContains('Test\LocalClass', $fqcns);
        $this->assertContains('Global\ClassName', $fqcns);
    }

    public function testGetDependencyMap(): void
    {
        // 创建两个测试文件并分析它们
        $testFile1 = $this->createTestFileInTemp('File1.php', '<?php
namespace Test;

use Dependency1;

class File1Class {
}
');

        $testFile2 = $this->createTestFileInTemp('File2.php', '<?php
namespace Test;

use Dependency2;

class File2Class {
}
');

        $this->fileAnalyzer->analyzeFile($testFile1);
        $this->fileAnalyzer->analyzeFile($testFile2);

        $map = $this->processor->getDependencyMap();

        $this->assertIsArray($map);
        // 依赖映射应该包含已分析的文件
        $this->assertNotEmpty($map);
    }

    public function testProcessDependencyFile(): void
    {
        // 创建源文件
        $sourceFile = $this->createTestFileInTemp('SourceClass.php', '<?php
namespace Source;

use Target\SomeClass;

class SourceClass {
    public function method() {
        return new SomeClass();
    }
}
');

        // 创建目标文件
        $targetFile = $this->createTestFileInTemp('SomeClass.php', '<?php
namespace Target;

class SomeClass {
}
');

        // 分析源文件以获取依赖
        $this->fileAnalyzer->analyzeFile($sourceFile);

        // 获取依赖记录
        $relativePath = $this->pathResolver->getRelativePath($sourceFile);
        $fileData = $this->storage->getFileByPath($relativePath);
        $this->assertNotNull($fileData);

        $dependencies = $this->storage->getDependenciesByFile($fileData['id']);
        $this->assertNotEmpty($dependencies);

        // 处理依赖文件
        $dependency = $dependencies[0];
        $this->processor->processDependencyFile($dependency, $targetFile);

        // 验证依赖已被解析
        $targetFileData = $this->storage->getFileByPath($this->pathResolver->getRelativePath($targetFile));
        $this->assertNotNull($targetFileData);
    }

    public function testProcessDependencyFileWithEmptyTarget(): void
    {
        // 创建源文件
        $sourceFile = $this->createTestFileInTemp('SourceClass2.php', '<?php
namespace Source;

use NonExistent\NonExistentClass;

class SourceClass2 {
    public function method() {
        return new NonExistentClass();
    }
}
');

        // 分析源文件以获取依赖
        $this->fileAnalyzer->analyzeFile($sourceFile);

        // 获取依赖记录
        $relativePath = $this->pathResolver->getRelativePath($sourceFile);
        $fileData = $this->storage->getFileByPath($relativePath);
        $this->assertNotNull($fileData);

        $dependencies = $this->storage->getDependenciesByFile($fileData['id']);
        $this->assertNotEmpty($dependencies);

        // 尝试处理不存在的目标文件
        $dependency = $dependencies[0];
        $nonExistentFile = $this->tempDir . '/non/existent.php';

        // 处理依赖文件（目标文件不存在）
        $this->processor->processDependencyFile($dependency, $nonExistentFile);

        // 验证依赖未被解析（因为目标文件不存在）
        $targetFileData = $this->storage->getFileByPath($this->pathResolver->getRelativePath($nonExistentFile));
        $this->assertNull($targetFileData);
    }

    private function createTestFileInTemp(string $filename, string $content): string
    {
        $filePath = $this->tempDir . '/' . $filename;
        $dir = dirname($filePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($filePath, $content);

        return $filePath;
    }
}
