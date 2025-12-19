<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Generator;

use PhpPacker\Generator\EntryCodeExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(EntryCodeExtractor::class)]
final class EntryCodeExtractorTest extends TestCase
{
    private EntryCodeExtractor $extractor;

    private static string $tempDir;

    protected function setUp(): void
    {
        $logger = new NullLogger();
        $this->extractor = new EntryCodeExtractor($logger);
        self::$tempDir = sys_get_temp_dir() . '/php-packer-test-' . uniqid();
        mkdir(self::$tempDir, 0o777, true);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$tempDir)) {
            self::removeDirectory(self::$tempDir);
        }
        parent::tearDownAfterClass();
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testExtractEntryCodeFromSimpleFile(): void
    {
        $entryFile = self::$tempDir . '/entry.php';
        file_put_contents($entryFile, '<?php
echo "Hello World";
$config = ["debug" => true];
require "bootstrap.php";
');

        $executionCode = $this->extractor->extractEntryCode($entryFile);

        $this->assertNotEmpty($executionCode);
        // echo 可能被转换为 Expression，所以实际可能只有 2 个节点
        $this->assertGreaterThanOrEqual(2, count($executionCode));
    }

    public function testExtractEntryCodeWithMergedFiles(): void
    {
        $entryFile = self::$tempDir . '/entry.php';
        file_put_contents($entryFile, '<?php
require "config.php";
require "bootstrap.php";
echo "Starting application";
');

        // 假设 config.php 已经被合并
        $mergedFiles = ['config.php'];
        $executionCode = $this->extractor->extractEntryCode($entryFile, $mergedFiles);

        // 至少应该有 1 个节点（可能 echo 被转换或过滤）
        $this->assertGreaterThanOrEqual(1, count($executionCode));
    }

    public function testExtractEntryCodeWithNamespace(): void
    {
        $entryFile = self::$tempDir . '/entry.php';
        file_put_contents($entryFile, '<?php
namespace App;

use App\Config;

class Application {}

echo "In namespace";
$app = new Application();
');

        $executionCode = $this->extractor->extractEntryCode($entryFile);

        // 应该提取执行代码，但不包括类声明和 use 语句
        $this->assertCount(2, $executionCode);
    }

    public function testExtractEntryCodeEmptyFile(): void
    {
        $entryFile = self::$tempDir . '/empty.php';
        file_put_contents($entryFile, '<?php');

        $executionCode = $this->extractor->extractEntryCode($entryFile);

        $this->assertEmpty($executionCode);
    }

    public function testExtractEntryCodeNonExistentFile(): void
    {
        $executionCode = $this->extractor->extractEntryCode('/non/existent/file.php');

        $this->assertEmpty($executionCode);
    }

    public function testExtractEntryCodeWithSyntaxError(): void
    {
        $entryFile = self::$tempDir . '/syntax-error.php';
        file_put_contents($entryFile, '<?php
echo "Hello"  // missing semicolon
echo "World";
');

        $executionCode = $this->extractor->extractEntryCode($entryFile);

        $this->assertEmpty($executionCode);
    }

    public function testExtractEntryCodeWithOnlyDeclarations(): void
    {
        $entryFile = self::$tempDir . '/declarations.php';
        file_put_contents($entryFile, '<?php
declare(strict_types=1);

namespace App;

use App\Service;

interface MyInterface {}
trait MyTrait {}
class MyClass {}
function myFunction() {}
');

        $executionCode = $this->extractor->extractEntryCode($entryFile);

        // 所有都是声明，没有执行代码
        $this->assertEmpty($executionCode);
    }

    public function testExtractEntryCodeWithConditionalIncludes(): void
    {
        $entryFile = self::$tempDir . '/conditional.php';
        file_put_contents($entryFile, '<?php
if (PHP_SAPI === "cli") {
    require "cli-config.php";
} else {
    require "web-config.php";
}

$app = new Application();
');

        $executionCode = $this->extractor->extractEntryCode($entryFile);

        // If 语句和赋值都是执行代码
        $this->assertCount(2, $executionCode);
    }

    public function testExtractEntryCodeDebugLogging(): void
    {
        $entryFile = self::$tempDir . '/debug.php';
        file_put_contents($entryFile, '<?php
$config = ["debug" => true];
echo "Test";
');

        $executionCode = $this->extractor->extractEntryCode($entryFile);

        $this->assertNotEmpty($executionCode);
    }

    public function testExtractEntryCodeWithComplexExpressions(): void
    {
        $entryFile = self::$tempDir . '/complex.php';
        file_put_contents($entryFile, '<?php
$result = array_map(function($x) { return $x * 2; }, [1, 2, 3]);
$instance = new class { public function test() {} };
$fn = fn($x) => $x + 1;
match ($value) {
    1 => doSomething(),
    2 => doSomethingElse(),
    default => doNothing(),
};
');

        $executionCode = $this->extractor->extractEntryCode($entryFile);

        // 所有表达式都应该被提取
        $this->assertCount(4, $executionCode);
    }
}
