<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer;

use PhpPacker\Analyzer\ClassScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClassScanner::class)]
final class ClassScannerTest extends TestCase
{
    private ClassScanner $classScanner;

    protected function setUp(): void
    {
        $this->classScanner = new ClassScanner();
    }

    public function testScanFileForClasses(): void
    {
        $testFile = $this->createTempFile('<?php
namespace Test;

class TestClass {
    public function method() {}
}

interface TestInterface {}

trait TestTrait {}
');

        $classes = $this->classScanner->scanFileForClasses($testFile);

        $this->assertCount(3, $classes);
        $this->assertArrayHasKey('Test\TestClass', $classes);
        $this->assertArrayHasKey('Test\TestInterface', $classes);
        $this->assertArrayHasKey('Test\TestTrait', $classes);
    }

    public function testScanFileForClassesWithNoNamespace(): void
    {
        $testFile = $this->createTempFile('<?php
class GlobalClass {}
');

        $classes = $this->classScanner->scanFileForClasses($testFile);

        $this->assertCount(1, $classes);
        $this->assertArrayHasKey('GlobalClass', $classes);
    }

    public function testScanEmptyFile(): void
    {
        $testFile = $this->createTempFile('<?php
// Empty file
');

        $classes = $this->classScanner->scanFileForClasses($testFile);

        $this->assertCount(0, $classes);
    }

    public function testScanInvalidPhpFile(): void
    {
        $testFile = $this->createTempFile('Not PHP content');

        $classes = $this->classScanner->scanFileForClasses($testFile);

        $this->assertCount(0, $classes);
    }

    private function createTempFile(string $content): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.php';
        file_put_contents($tempFile, $content);

        return $tempFile;
    }

    public function testScanClassMap(): void
    {
        $testFile = $this->createTempFile('<?php
namespace Test;

class TestClass {}
');

        $classMap = $this->classScanner->scanClassMap($testFile);

        $this->assertNotEmpty($classMap);
        $this->assertArrayHasKey('Test\TestClass', $classMap);
        $this->assertEquals($testFile, $classMap['Test\TestClass']);
    }

    public function testScanClassMapWithDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_scanner_' . uniqid();
        mkdir($tempDir);

        $testFile1 = $tempDir . '/TestClass.php';
        file_put_contents($testFile1, '<?php
namespace Test;

class TestClass {}
');

        $testFile2 = $tempDir . '/AnotherClass.php';
        file_put_contents($testFile2, '<?php
namespace Test;

class AnotherClass {}
');

        $classMap = $this->classScanner->scanClassMap($tempDir);

        $this->assertArrayHasKey('Test\TestClass', $classMap);
        $this->assertArrayHasKey('Test\AnotherClass', $classMap);

        unlink($testFile1);
        unlink($testFile2);
        rmdir($tempDir);
    }
}
