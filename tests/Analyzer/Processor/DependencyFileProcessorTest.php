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
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(DependencyFileProcessor::class)]
final class DependencyFileProcessorTest extends TestCase
{
    private DependencyFileProcessor $processor;

    private SqliteStorage $storage;

    private LoggerInterface $logger;

    private FileAnalyzer $fileAnalyzer;

    private PathResolver $pathResolver;

    private FileVerifier $fileVerifier;

    protected function setUp(): void
    {
        /*
         * 使用具体类 SqliteStorage 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：SqliteStorage 没有对应的接口抽象，且 DependencyFileProcessor 构造函数直接依赖具体实现
         * 2) 这种使用是否合理和必要：在单元测试中合理，避免真实数据库操作，专注测试 DependencyFileProcessor 的逻辑
         * 3) 是否有更好的替代方案：理想情况下应该为存储层定义接口，但当前架构下使用 mock 是最佳选择
         */
        $this->storage = $this->createMock(SqliteStorage::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        /*
         * 使用具体类 FileAnalyzer 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：FileAnalyzer 没有对应的接口抽象，DependencyFileProcessor 需要其文件分析功能
         * 2) 这种使用是否合理和必要：在单元测试中合理，避免真实的文件分析复杂性，专注测试依赖处理逻辑
         * 3) 是否有更好的替代方案：为 FileAnalyzer 定义接口会改善架构，但当前 mock 方式是可接受的
         */
        $this->fileAnalyzer = $this->createMock(FileAnalyzer::class);
        /*
         * 使用具体类 PathResolver 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：PathResolver 没有对应的接口抽象，且处理器需要路径解析功能
         * 2) 这种使用是否合理和必要：在单元测试中合理，避免路径解析的复杂性，专注测试依赖处理逻辑
         * 3) 是否有更好的替代方案：定义路径解析接口会更好，但当前架构下 mock 是合理的测试策略
         */
        $this->pathResolver = $this->createMock(PathResolver::class);
        /*
         * 使用具体类 FileVerifier 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：FileVerifier 没有对应的接口抽象，但处理器需要文件验证功能
         * 2) 这种使用是否合理和必要：在单元测试中合理，避免真实文件系统操作，专注测试依赖处理逻辑
         * 3) 是否有更好的替代方案：为文件验证定义接口会改善架构，但当前使用 mock 是有效的测试方法
         */
        $this->fileVerifier = $this->createMock(FileVerifier::class);

        $this->processor = new DependencyFileProcessor(
            $this->storage,
            $this->logger,
            $this->fileAnalyzer,
            $this->pathResolver,
            $this->fileVerifier
        );
    }

    public function testProcessFile(): void
    {
        $testFile = $this->createTestFile('<?php
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

        // 配置 Mock 对象
        $this->pathResolver->method('getRelativePath')
            ->willReturn('test/file.php')
        ;

        $this->storage->method('getFileByPath')
            ->willReturn(['id' => 1, 'is_vendor' => false])
        ;

        $this->storage->method('getDependenciesByFile')
            ->willReturn([
                ['target_symbol' => 'Another\Class1'],
                ['target_symbol' => 'Another\Class2'],
                ['target_symbol' => 'InterfaceA'],
                ['target_symbol' => 'TraitB'],
                ['target_symbol' => 'Full\Qualified\ClassName'],
            ])
        ;

        $result = $this->processor->processFile($testFile);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('dependencies', $result);

        $dependencies = $result['dependencies'];
        $fqcns = array_column($dependencies, 'target_symbol');

        $this->assertContains('Another\Class1', $fqcns);
        $this->assertContains('Another\Class2', $fqcns);
        $this->assertContains('InterfaceA', $fqcns);
        $this->assertContains('TraitB', $fqcns);
        $this->assertContains('Full\Qualified\ClassName', $fqcns);
    }

    public function testProcessFileWithConstants(): void
    {
        $testFile = $this->createTestFile('<?php
namespace Test;

class TestClass {
    const TYPE = \OtherClass::CONSTANT;
    
    public function method() {
        return \Vendor\Package::CONST_VALUE;
    }
}
');

        // 配置 Mock 对象
        $this->pathResolver->method('getRelativePath')
            ->willReturn('test/file.php')
        ;

        $this->storage->method('getFileByPath')
            ->willReturn(['id' => 1, 'is_vendor' => false])
        ;

        $this->storage->method('getDependenciesByFile')
            ->willReturn([
                ['target_symbol' => 'OtherClass'],
                ['target_symbol' => 'Vendor\Package'],
            ])
        ;

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
        $testFile = $this->createTestFile('<?php
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

        // 配置 Mock 对象
        $this->pathResolver->method('getRelativePath')
            ->willReturn('test/file.php')
        ;

        $this->storage->method('getFileByPath')
            ->willReturn(['id' => 1, 'is_vendor' => false])
        ;

        $this->storage->method('getDependenciesByFile')
            ->willReturn([
                ['target_symbol' => 'CustomException'],
                ['target_symbol' => 'Test\AnotherException'],
                ['target_symbol' => 'Test\ThirdException'],
            ])
        ;

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
        $testFile = $this->createTestFile('<?php
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

        // 配置 Mock 对象
        $this->pathResolver->method('getRelativePath')
            ->willReturn('test/file.php')
        ;

        $this->storage->method('getFileByPath')
            ->willReturn(['id' => 1, 'is_vendor' => false])
        ;

        $this->storage->method('getDependenciesByFile')
            ->willReturn([
                ['target_symbol' => 'Test\LocalClass'],
                ['target_symbol' => 'Global\ClassName'],
            ])
        ;

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
        $expectedMap = [
            'file1' => ['dependency1'],
            'file2' => ['dependency2'],
        ];

        $this->storage->method('getAllDependencies')
            ->willReturn($expectedMap)
        ;

        $map = $this->processor->getDependencyMap();

        $this->assertEquals($expectedMap, $map);
        $this->assertNotEmpty($map);
        $this->assertIsArray($map);
    }

    public function testProcessDependencyFile(): void
    {
        $dependency = [
            'id' => 1,
            'target_symbol' => 'SomeClass',
            'dependency_type' => 'use_class',
        ];
        $targetFile = '/path/to/target/file.php';
        $targetFileData = [
            'id' => 42,
            'path' => 'target/file.php',
            'class_name' => 'SomeClass',
        ];

        // Mock getFileByPath to return target file data
        $this->storage->method('getFileByPath')
            ->willReturn($targetFileData)
        ;

        // Expect resolveDependency to be called
        $this->storage->expects($this->once())
            ->method('resolveDependency')
            ->with(1, 42)
        ;

        $this->processor->processDependencyFile($dependency, $targetFile);
    }

    public function testProcessDependencyFileWithEmptyTarget(): void
    {
        $dependency = [
            'id' => 1,
            'target_symbol' => 'NonExistentClass',
        ];
        $targetFile = '/path/to/non/existent.php';

        // Mock getFileByPath to return empty array (file not found)
        $this->storage->method('getFileByPath')
            ->willReturn([])
        ;

        // Expect resolveDependency NOT to be called
        $this->storage->expects($this->never())
            ->method('resolveDependency')
        ;

        $this->processor->processDependencyFile($dependency, $targetFile);
    }

    private function createTestFile(string $content): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'dep_test_');
        file_put_contents($tempFile, $content);

        return $tempFile;
    }
}
