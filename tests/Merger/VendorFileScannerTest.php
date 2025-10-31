<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\VendorFileScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(VendorFileScanner::class)]
final class VendorFileScannerTest extends TestCase
{
    private VendorFileScanner $scanner;

    private static string $tempDir;

    protected function setUp(): void
    {
        $this->scanner = new VendorFileScanner();
        self::$tempDir = sys_get_temp_dir() . '/vendor-scanner-test-' . uniqid();
        mkdir(self::$tempDir, 0o777, true);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$tempDir)) {
            self::removeDirectory(self::$tempDir);
        }
    }

    public function testFindPhpFiles(): void
    {
        $this->createFile('vendor/library1/src/Client.php', '<?php class Client {}');
        $this->createFile('vendor/library1/src/Server.php', '<?php class Server {}');
        $this->createFile('vendor/library2/Helper.php', '<?php class Helper {}');
        $this->createFile('vendor/library1/README.md', '# Library');
        $this->createFile('vendor/library1/composer.json', '{}');

        $files = $this->scanner->findPhpFiles(self::$tempDir . '/vendor');

        $this->assertIsArray($files);
        $this->assertCount(3, $files);

        $fileNames = array_map('basename', $files);
        $this->assertContains('Client.php', $fileNames);
        $this->assertContains('Server.php', $fileNames);
        $this->assertContains('Helper.php', $fileNames);
        $this->assertNotContains('README.md', $fileNames);
        $this->assertNotContains('composer.json', $fileNames);
    }

    public function testFindPhpFilesEmptyDirectory(): void
    {
        $emptyDir = self::$tempDir . '/empty';
        mkdir($emptyDir);

        $files = $this->scanner->findPhpFiles($emptyDir);

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    public function testFindPhpFilesNonExistentDirectory(): void
    {
        $files = $this->scanner->findPhpFiles(self::$tempDir . '/non-existent');

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    public function testFilterRequiredFiles(): void
    {
        $allFiles = [
            '/vendor/lib1/Client.php' => [
                'classes' => ['Vendor\Lib1\Client', 'Vendor\Lib1\Connection'],
                'functions' => [],
            ],
            '/vendor/lib1/Server.php' => [
                'classes' => ['Vendor\Lib1\Server'],
                'functions' => ['vendor_helper'],
            ],
            '/vendor/lib2/Helper.php' => [
                'classes' => ['Vendor\Lib2\Helper'],
                'functions' => [],
            ],
            '/vendor/lib3/Utility.php' => [
                'classes' => ['Vendor\Lib3\Utility'],
                'functions' => [],
            ],
        ];

        $requiredClasses = ['Vendor\Lib1\Client', 'Vendor\Lib2\Helper'];
        $filtered = $this->scanner->filterRequiredFiles($allFiles, $requiredClasses);

        $this->assertIsArray($filtered);
        $this->assertCount(2, $filtered);
        $this->assertArrayHasKey('/vendor/lib1/Client.php', $filtered);
        $this->assertArrayHasKey('/vendor/lib2/Helper.php', $filtered);
        $this->assertArrayNotHasKey('/vendor/lib1/Server.php', $filtered);
        $this->assertArrayNotHasKey('/vendor/lib3/Utility.php', $filtered);
    }

    public function testFilterRequiredFilesEmpty(): void
    {
        $allFiles = [
            '/vendor/lib1/Client.php' => [
                'classes' => ['Vendor\Lib1\Client'],
                'functions' => [],
            ],
        ];

        $requiredClasses = [];
        $filtered = $this->scanner->filterRequiredFiles($allFiles, $requiredClasses);

        $this->assertIsArray($filtered);
        $this->assertEmpty($filtered);
    }

    public function testFilterRequiredFilesNoMatch(): void
    {
        $allFiles = [
            '/vendor/lib1/Client.php' => [
                'classes' => ['Vendor\Lib1\Client'],
                'functions' => [],
            ],
        ];

        $requiredClasses = ['Vendor\Other\Service'];
        $filtered = $this->scanner->filterRequiredFiles($allFiles, $requiredClasses);

        $this->assertIsArray($filtered);
        $this->assertEmpty($filtered);
    }

    public function testFilterRequiredFilesMultipleClassesInFile(): void
    {
        $allFiles = [
            '/vendor/lib1/Multiple.php' => [
                'classes' => ['Vendor\Lib1\ClassA', 'Vendor\Lib1\ClassB', 'Vendor\Lib1\ClassC'],
                'functions' => [],
            ],
            '/vendor/lib2/Single.php' => [
                'classes' => ['Vendor\Lib2\SingleClass'],
                'functions' => [],
            ],
        ];

        $requiredClasses = ['Vendor\Lib1\ClassB'];
        $filtered = $this->scanner->filterRequiredFiles($allFiles, $requiredClasses);

        $this->assertIsArray($filtered);
        $this->assertCount(1, $filtered);
        $this->assertArrayHasKey('/vendor/lib1/Multiple.php', $filtered);
        $this->assertEquals(['Vendor\Lib1\ClassA', 'Vendor\Lib1\ClassB', 'Vendor\Lib1\ClassC'], $filtered['/vendor/lib1/Multiple.php']['classes']);
    }

    public function testFindPhpFilesNestedStructure(): void
    {
        $this->createFile('vendor/deep/level1/level2/level3/Deep.php', '<?php class Deep {}');
        $this->createFile('vendor/shallow/Shallow.php', '<?php class Shallow {}');
        $this->createFile('vendor/mixed/level1/Mixed.php', '<?php class Mixed {}');

        $files = $this->scanner->findPhpFiles(self::$tempDir . '/vendor');

        $this->assertIsArray($files);
        $this->assertCount(3, $files);

        $fileNames = array_map('basename', $files);
        $this->assertContains('Deep.php', $fileNames);
        $this->assertContains('Shallow.php', $fileNames);
        $this->assertContains('Mixed.php', $fileNames);
    }

    public function testFilterRequiredFilesWithoutClassesKey(): void
    {
        $allFiles = [
            '/vendor/lib1/Invalid.php' => [
                'functions' => ['some_function'],
            ],
            '/vendor/lib2/Valid.php' => [
                'classes' => ['Vendor\Lib2\Valid'],
                'functions' => [],
            ],
        ];

        $requiredClasses = ['Vendor\Lib2\Valid'];
        $filtered = $this->scanner->filterRequiredFiles($allFiles, $requiredClasses);

        $this->assertIsArray($filtered);
        $this->assertCount(1, $filtered);
        $this->assertArrayHasKey('/vendor/lib2/Valid.php', $filtered);
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
