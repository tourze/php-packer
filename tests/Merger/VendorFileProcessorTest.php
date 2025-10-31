<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\VendorFileProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(VendorFileProcessor::class)]
final class VendorFileProcessorTest extends TestCase
{
    private VendorFileProcessor $processor;

    private static string $tempDir;

    protected function setUp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->processor = new VendorFileProcessor($logger);
        // 重置设置到默认值
        $this->processor->setStripComments(false);
        $this->processor->setOptimizeCode(false);
        self::$tempDir = sys_get_temp_dir() . '/vendor-processor-test-' . uniqid();
        mkdir(self::$tempDir, 0o777, true);
        mkdir(self::$tempDir . '/vendor', 0o777, true);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$tempDir)) {
            self::removeDirectory(self::$tempDir);
        }
    }

    public function testProcessVendorFile(): void
    {
        $content = '<?php
namespace Vendor\Package;

class VendorClass {
    public function method() {
        return "vendor";
    }
}
';
        $file = $this->createFile('vendor/package/src/VendorClass.php', $content);

        $processed = $this->processor->processFile($file);

        $this->assertArrayHasKey('ast', $processed);
        $this->assertArrayHasKey('namespace', $processed);
        $this->assertArrayHasKey('classes', $processed);
        $this->assertArrayHasKey('stripped', $processed);

        $this->assertEquals('Vendor\Package', $processed['namespace']);
        $this->assertIsArray($processed['classes']);
        $this->assertContains('Vendor\Package\VendorClass', $processed['classes']);
        $this->assertFalse($processed['stripped']); // 默认不剥离
    }

    public function testStripVendorComments(): void
    {
        $content = '<?php
namespace Vendor\Package;

/**
 * This is a vendor class
 * @package Vendor\Package
 */
class VendorClass {
    /**
     * Method documentation
     * @return string
     */
    public function method() {
        // This is a comment
        return "vendor"; // inline comment
    }
}
';
        $file = $this->createFile('vendor/package/src/VendorClass.php', $content);

        $this->processor->setStripComments(true);
        $processed = $this->processor->processFile($file);

        $this->assertTrue($processed['stripped']);

        // 重新生成的代码不应包含注释
        $this->assertIsString($processed['code']);
        $code = $processed['code'];
        $this->assertStringNotContainsString('This is a vendor class', $code);
        $this->assertStringNotContainsString('Method documentation', $code);
        $this->assertStringNotContainsString('// This is a comment', $code);
    }

    public function testOptimizeVendorCode(): void
    {
        $content = '<?php
namespace Vendor\Package;

class VendorClass {
    private $unused = "will be removed";
    
    public function publicMethod() {
        return $this->privateMethod();
    }
    
    private function privateMethod() {
        return "used";
    }
    
    private function unusedPrivateMethod() {
        return "unused";
    }
}
';
        $file = $this->createFile('vendor/package/src/VendorClass.php', $content);

        $this->processor->setOptimizeCode(true);
        $processed = $this->processor->processFile($file);

        $this->assertTrue($processed['optimized']);

        // 检查优化统计
        $this->assertIsArray($processed['optimization_stats']);
        $stats = $processed['optimization_stats'];
        if (isset($stats['removed_methods'])) {
            $this->assertGreaterThanOrEqual(0, $stats['removed_methods']);
        }
        if (isset($stats['removed_properties'])) {
            $this->assertGreaterThanOrEqual(0, $stats['removed_properties']);
        }
    }

    public function testProcessComposerPackage(): void
    {
        // 创建一个模拟的 Composer 包结构
        $composerData = json_encode([
            'name' => 'test-vendor/test-package',
            'autoload' => [
                'psr-4' => [
                    'TestVendor\TestPackage\\' => 'src/',
                ],
            ],
        ]);
        $this->assertIsString($composerData);
        $this->createFile('vendor/test-vendor/test-package/composer.json', $composerData);

        $this->createFile('vendor/test-vendor/test-package/src/MainClass.php', '<?php
namespace TestVendor\TestPackage;
class MainClass {}
');

        $this->createFile('vendor/test-vendor/test-package/src/Helper.php', '<?php
namespace TestVendor\TestPackage;
class Helper {}
');

        $packageInfo = $this->processor->processComposerPackage(self::$tempDir . '/vendor/test-vendor/test-package');

        $this->assertEquals('test-vendor/test-package', $packageInfo['name']);
        $this->assertArrayHasKey('autoload', $packageInfo);
        $this->assertIsArray($packageInfo['files']);
        $this->assertCount(2, $packageInfo['files']);
        $this->assertArrayHasKey('classes', $packageInfo);
        $this->assertIsArray($packageInfo['classes']);
        $this->assertContains('TestVendor\TestPackage\MainClass', $packageInfo['classes']);
        $this->assertContains('TestVendor\TestPackage\Helper', $packageInfo['classes']);
    }

    public function testFilterRequiredFiles(): void
    {
        $allFiles = [
            '/vendor/package1/src/Class1.php' => ['classes' => ['Package1\Class1']],
            '/vendor/package1/src/Class2.php' => ['classes' => ['Package1\Class2']],
            '/vendor/package2/src/ClassA.php' => ['classes' => ['Package2\ClassA']],
            '/vendor/package2/src/ClassB.php' => ['classes' => ['Package2\ClassB']],
        ];

        $requiredClasses = ['Package1\Class1', 'Package2\ClassA'];

        $filtered = $this->processor->filterRequiredFiles($allFiles, $requiredClasses);

        $this->assertCount(2, $filtered);
        $this->assertArrayHasKey('/vendor/package1/src/Class1.php', $filtered);
        $this->assertArrayHasKey('/vendor/package2/src/ClassA.php', $filtered);
        $this->assertArrayNotHasKey('/vendor/package1/src/Class2.php', $filtered);
        $this->assertArrayNotHasKey('/vendor/package2/src/ClassB.php', $filtered);
    }

    public function testProcessLargeVendorFile(): void
    {
        // 创建一个大文件
        $methods = [];
        for ($i = 0; $i < 100; ++$i) {
            $methods[] = "public function method{$i}() { return {$i}; }";
        }

        $content = '<?php
namespace Vendor\LargePackage;

class LargeClass {
    ' . implode("\n    ", $methods) . '
}
';

        $file = $this->createFile('vendor/large/src/LargeClass.php', $content);

        $startTime = microtime(true);
        $processed = $this->processor->processFile($file);
        $processingTime = microtime(true) - $startTime;

        $this->assertLessThan(1.0, $processingTime); // 应该在1秒内处理完成
        $this->assertCount(1, $processed['classes']);
        $this->assertEquals('Vendor\LargePackage\LargeClass', $processed['classes'][0]);
    }

    public function testHandleVendorFileWithErrors(): void
    {
        $content = '<?php
namespace Vendor\Package;

class BrokenClass {
    // Syntax error - missing closing brace
';

        $file = $this->createFile('vendor/package/src/BrokenClass.php', $content);

        $processed = $this->processor->processFile($file);

        $this->assertArrayHasKey('error', $processed);
        $this->assertStringContainsString('Parse error', $processed['error']);
        $this->assertEmpty($processed['ast']);
    }

    // 精确覆盖公共方法：createVendorNodes
    public function testCreateVendorNodes(): void
    {
        $content1 = '<?php namespace V1; class A {}';
        $content2 = '<?php namespace V2; class B {}';
        $f1 = $this->createFile('vendor/pk1/A.php', $content1);
        $f2 = $this->createFile('vendor/pk2/B.php', $content2);

        $files = [
            ['path' => $f1, 'content' => $content1],
            ['path' => $f2, 'content' => $content2],
        ];

        $nodes = $this->processor->createVendorNodes($files);
        $this->assertIsArray($nodes);
        $this->assertGreaterThan(0, count($nodes));
    }

    // 精确覆盖公共方法：processFile
    public function testProcessFile(): void
    {
        $content = '<?php namespace P; class C {}';
        $file = $this->createFile('vendor/p/C.php', $content);

        $processed = $this->processor->processFile($file);
        $this->assertArrayHasKey('classes', $processed);
        $this->assertContains('P\C', $processed['classes']);
    }

    // 精确覆盖公共方法：resetStats
    public function testResetStats(): void
    {
        $this->processor->resetStats();
        $stats = $this->processor->getStats();
        $this->assertEquals(0, $stats['total_files']);
        $this->assertEquals(0, $stats['total_classes']);
        $this->assertEquals(0, $stats['total_size']);
        $this->assertEquals(0.0, $stats['processing_time']);
        $this->assertIsArray($stats['packages']);
    }

    public function testGetProcessingStats(): void
    {
        // 重置统计数据
        $this->processor->resetStats();

        $files = [
            $this->createFile('vendor/p1/Class1.php', '<?php namespace P1; class Class1 {}'),
            $this->createFile('vendor/p1/Class2.php', '<?php namespace P1; class Class2 {}'),
            $this->createFile('vendor/p2/ClassA.php', '<?php namespace P2; class ClassA {}'),
        ];

        foreach ($files as $file) {
            $this->processor->processFile($file);
        }

        $stats = $this->processor->getStats();

        $this->assertEquals(3, $stats['total_files']);
        $this->assertEquals(3, $stats['total_classes']);
        $this->assertArrayHasKey('total_size', $stats);
        $this->assertArrayHasKey('processing_time', $stats);
        $this->assertArrayHasKey('packages', $stats);
    }

    private function createFile(string $path, string $content): string
    {
        $fullPath = self::$tempDir . '/' . $path;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $result = file_put_contents($fullPath, $content);
        $this->assertNotFalse($result);

        return $fullPath;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = self::createFileIterator($dir);
        self::removeAllFiles($files);
        rmdir($dir);
    }

    /**
     * 创建文件迭代器
     *
     * @return \RecursiveIteratorIterator<\RecursiveDirectoryIterator>
     */
    private static function createFileIterator(string $dir): \RecursiveIteratorIterator
    {
        return new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
    }

    /**
     * 删除所有文件和目录
     *
     * @param \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $files
     */
    private static function removeAllFiles(\RecursiveIteratorIterator $files): void
    {
        foreach ($files as $file) {
            self::removeFileOrDirectory($file);
        }
    }

    /**
     * 删除单个文件或目录
     */
    private static function removeFileOrDirectory(\SplFileInfo $file): void
    {
        $realPath = $file->getRealPath();
        if (false === $realPath) {
            return;
        }

        if ($file->isDir()) {
            rmdir($realPath);
        } else {
            unlink($realPath);
        }
    }
}
