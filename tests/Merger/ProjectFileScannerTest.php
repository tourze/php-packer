<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\ProjectFileScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ProjectFileScanner::class)]
final class ProjectFileScannerTest extends TestCase
{
    private ProjectFileScanner $scanner;

    private static string $tempDir;

    protected function setUp(): void
    {
        $this->scanner = new ProjectFileScanner();
        self::$tempDir = sys_get_temp_dir() . '/project-scanner-test-' . uniqid();
        mkdir(self::$tempDir, 0o777, true);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$tempDir)) {
            self::removeDirectory(self::$tempDir);
        }
    }

    public function testFindProjectFiles(): void
    {
        $this->createFile('src/Class1.php', '<?php class Class1 {}');
        $this->createFile('src/Service/UserService.php', '<?php namespace App\Service; class UserService {}');
        $this->createFile('src/Entity/User.php', '<?php namespace App\Entity; class User {}');
        $this->createFile('src/config.xml', '<config></config>');
        $this->createFile('README.md', '# Project');

        $files = $this->scanner->findProjectFiles(self::$tempDir . '/src');

        $this->assertIsArray($files);
        $this->assertCount(3, $files);

        $fileNames = array_map('basename', $files);
        $this->assertContains('Class1.php', $fileNames);
        $this->assertContains('UserService.php', $fileNames);
        $this->assertContains('User.php', $fileNames);
        $this->assertNotContains('config.xml', $fileNames);
    }

    public function testFindProjectFilesEmptyDirectory(): void
    {
        $emptyDir = self::$tempDir . '/empty';
        mkdir($emptyDir);

        $files = $this->scanner->findProjectFiles($emptyDir);

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    public function testFindProjectFilesNonExistentDirectory(): void
    {
        $files = $this->scanner->findProjectFiles(self::$tempDir . '/non-existent');

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    public function testFindProjectFilesNestedStructure(): void
    {
        $this->createFile('src/level1/Class1.php', '<?php class Class1 {}');
        $this->createFile('src/level1/level2/Class2.php', '<?php class Class2 {}');
        $this->createFile('src/level1/level2/level3/Class3.php', '<?php class Class3 {}');
        $this->createFile('src/level1/readme.txt', 'Not a PHP file');

        $files = $this->scanner->findProjectFiles(self::$tempDir . '/src');

        $this->assertIsArray($files);
        $this->assertCount(3, $files);

        $fileNames = array_map('basename', $files);
        $this->assertContains('Class1.php', $fileNames);
        $this->assertContains('Class2.php', $fileNames);
        $this->assertContains('Class3.php', $fileNames);
        $this->assertNotContains('readme.txt', $fileNames);
    }

    public function testFindProjectFilesOnlyPhpFiles(): void
    {
        $this->createFile('src/app.php', '<?php echo "Hello";');
        $this->createFile('src/script.js', 'console.log("Hello");');
        $this->createFile('src/style.css', 'body { color: red; }');
        $this->createFile('src/config.json', '{"name": "test"}');
        $this->createFile('src/index.html', '<html></html>');

        $files = $this->scanner->findProjectFiles(self::$tempDir . '/src');

        $this->assertIsArray($files);
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('app.php', $files[0]);
    }

    public function testFindProjectFilesAbsolutePaths(): void
    {
        $this->createFile('src/Test.php', '<?php class Test {}');

        $files = $this->scanner->findProjectFiles(self::$tempDir . '/src');

        $this->assertIsArray($files);
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('/', $files[0]);
        $this->assertStringContainsString(self::$tempDir, $files[0]);
    }

    public function testFindProjectFilesWithDotFiles(): void
    {
        $this->createFile('src/.hidden.php', '<?php class Hidden {}');
        $this->createFile('src/visible.php', '<?php class Visible {}');
        $this->createFile('src/.config', 'config content');

        $files = $this->scanner->findProjectFiles(self::$tempDir . '/src');

        $this->assertIsArray($files);
        $this->assertCount(2, $files);

        $fileNames = array_map('basename', $files);
        $this->assertContains('.hidden.php', $fileNames);
        $this->assertContains('visible.php', $fileNames);
        $this->assertNotContains('.config', $fileNames);
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
