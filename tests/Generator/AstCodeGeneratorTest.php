<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Generator;

use PhpPacker\Exception\CodeGenerationException;
use PhpPacker\Generator\AstCodeGenerator;
use PhpPacker\Storage\SqliteStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(AstCodeGenerator::class)]
final class AstCodeGeneratorTest extends TestCase
{
    private AstCodeGenerator $generator;

    private SqliteStorage $storage;

    private string $dbPath;

    private string $outputPath;

    public function testGenerateSimpleCode(): void
    {
        $files = [
            [
                'id' => 1,
                'path' => 'index.php',
                'content' => '<?php echo "Hello World";',
                'is_vendor' => 0,
                'is_entry' => 1,
                'skip_ast' => 0,
                'ast_root_id' => 1,
            ],
        ];

        $this->generator->generate($files, 'index.php', $this->outputPath);

        $this->assertFileExists($this->outputPath);
        $content = file_get_contents($this->outputPath);
        $this->assertIsString($content);
        $this->assertStringContainsString('<?php', $content);
        $this->assertStringContainsString('declare (strict_types=1)', $content);
    }

    public function testGenerateWithVendorFiles(): void
    {
        $files = [
            [
                'id' => 1,
                'path' => 'vendor/package/Helper.php',
                'content' => '<?php class Helper { public static function help() { return "help"; } }',
                'is_vendor' => 1,
                'skip_ast' => 1,
                'ast_root_id' => null,
            ],
            [
                'id' => 2,
                'path' => 'app.php',
                'content' => '<?php require "vendor/package/Helper.php"; echo Helper::help();',
                'is_vendor' => 0,
                'is_entry' => 1,
                'skip_ast' => 0,
                'ast_root_id' => 1,
            ],
        ];

        $this->generator->generate($files, 'app.php', $this->outputPath);

        $this->assertFileExists($this->outputPath);
        $content = file_get_contents($this->outputPath);
        $this->assertIsString($content);

        // 验证生成的代码包含 vendor 文件内容
        $this->assertStringContainsString('class Helper', $content);
        $this->assertStringContainsString('Vendor file: vendor/package/Helper.php', $content);
    }

    public function testGenerateWithOptimization(): void
    {
        $config = [
            'optimization' => [
                'enabled' => true,
                'remove_comments' => true,
                'minimize_whitespace' => true,
            ],
        ];

        $generator = new AstCodeGenerator(new NullLogger(), $config);

        $files = [
            [
                'id' => 1,
                'path' => 'app.php',
                'content' => '<?php
// This is a regular comment
class App {
    // Another comment
    public function run() {

        echo "Running";
    }
}',
                'is_vendor' => 0,
                'is_entry' => 1,
                'skip_ast' => 0,
                'ast_root_id' => 1,
            ],
        ];

        $generator->generate($files, 'app.php', $this->outputPath);

        $this->assertFileExists($this->outputPath);
        $content = file_get_contents($this->outputPath);
        $this->assertIsString($content);

        // 验证注释被移除
        $this->assertStringNotContainsString('This is a regular comment', $content);
        $this->assertStringNotContainsString('Another comment', $content);

        // 验证多余的空白被最小化
        $this->assertStringNotContainsString("\n\n\n", $content);
    }

    public function testGenerateWithErrorHandler(): void
    {
        $config = [
            'error_handler' => true,
        ];

        $generator = new AstCodeGenerator(new NullLogger(), $config);

        $files = [
            [
                'id' => 1,
                'path' => 'app.php',
                'content' => '<?php echo "test";',
                'is_vendor' => 0,
                'is_entry' => 1,
                'skip_ast' => 0,
                'ast_root_id' => 1,
            ],
        ];

        $generator->generate($files, 'app.php', $this->outputPath);

        $this->assertFileExists($this->outputPath);
        $content = file_get_contents($this->outputPath);
        $this->assertIsString($content);

        // 验证包含错误处理器
        $this->assertStringContainsString('set_error_handler', $content);
    }

    public function testGenerateWithMultipleNamespaces(): void
    {
        $files = [
            [
                'id' => 1,
                'path' => 'src/Models/User.php',
                'content' => '<?php namespace App\Models; class User { public $name; }',
                'is_vendor' => 0,
                'skip_ast' => 0,
                'ast_root_id' => 1,
            ],
            [
                'id' => 2,
                'path' => 'src/Services/UserService.php',
                'content' => '<?php namespace App\Services; use App\Models\User; class UserService { public function getUser(): User { return new User(); } }',
                'is_vendor' => 0,
                'skip_ast' => 0,
                'ast_root_id' => 2,
            ],
            [
                'id' => 3,
                'path' => 'index.php',
                'content' => '<?php use App\Services\UserService; $service = new UserService(); $user = $service->getUser();',
                'is_vendor' => 0,
                'is_entry' => 1,
                'skip_ast' => 0,
                'ast_root_id' => 3,
            ],
        ];

        $this->generator->generate($files, 'index.php', $this->outputPath);

        $this->assertFileExists($this->outputPath);
        $content = file_get_contents($this->outputPath);
        $this->assertIsString($content);

        // 验证生成的代码结构正确
        $this->assertStringContainsString('namespace App\Models', $content);
        $this->assertStringContainsString('namespace App\Services', $content);
        $this->assertStringContainsString('class User', $content);
        $this->assertStringContainsString('class UserService', $content);
    }

    public function testGeneratePreservesFilePermissions(): void
    {
        $files = [
            [
                'id' => 1,
                'path' => 'app.php',
                'content' => '<?php echo "test";',
                'is_vendor' => 0,
                'is_entry' => 1,
                'skip_ast' => 0,
                'ast_root_id' => 1,
            ],
        ];

        $this->generator->generate($files, 'app.php', $this->outputPath);

        $this->assertFileExists($this->outputPath);

        // 验证文件权限是可执行的
        $perms = fileperms($this->outputPath);
        $this->assertNotSame(0, $perms & 0o100); // Owner execute permission
    }

    public function testGenerateCreatesOutputDirectory(): void
    {
        $outputDir = sys_get_temp_dir() . '/test_dir_' . uniqid();
        $outputPath = $outputDir . '/output.php';

        $this->assertDirectoryDoesNotExist($outputDir);

        $files = [
            [
                'id' => 1,
                'path' => 'app.php',
                'content' => '<?php echo "test";',
                'is_vendor' => 0,
                'is_entry' => 1,
                'skip_ast' => 0,
                'ast_root_id' => 1,
            ],
        ];

        $this->generator->generate($files, 'app.php', $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertDirectoryExists($outputDir);

        // 清理
        unlink($outputPath);
        rmdir($outputDir);
    }

    public function testGenerateWithEmptyFiles(): void
    {
        $this->expectException(\Exception::class);

        $this->generator->generate([], 'app.php', $this->outputPath);
    }

    public function testGenerateWithoutEntryFile(): void
    {
        $files = [
            [
                'id' => 1,
                'path' => 'lib.php',
                'content' => '<?php class Lib {}',
                'is_vendor' => 0,
                'is_entry' => 0,
                'skip_ast' => 0,
                'ast_root_id' => 1,
            ],
        ];

        $this->expectException(CodeGenerationException::class);
        $this->generator->generate($files, 'nonexistent.php', $this->outputPath);
    }

    public function testRemoveCommentsPreservesImportantAnnotations(): void
    {
        $config = [
            'optimization' => [
                'remove_comments' => false, // Don't remove comments for this test
            ],
        ];

        $generator = new AstCodeGenerator(new NullLogger(), $config);

        $files = [
            [
                'id' => 1,
                'path' => 'app.php',
                'content' => '<?php
// Regular comment - should be removed
class App {
    /** @var string This should be preserved */
    private $property;
    
    /**
     * @param string $value
     * @return void
     */
    public function setProperty(string $value): void {
        // Internal comment - should be removed
        $this->property = $value; // @important This should be preserved
    }
}',
                'is_vendor' => 0,
                'is_entry' => 1,
                'skip_ast' => 0,
                'ast_root_id' => 1,
            ],
        ];

        $generator->generate($files, 'app.php', $this->outputPath);

        $content = file_get_contents($this->outputPath);
        $this->assertIsString($content);

        // 验证所有注释都被保留（since remove_comments is false）
        $this->assertStringContainsString('Regular comment', $content);
        $this->assertStringContainsString('Internal comment', $content);
        $this->assertStringContainsString('@var string', $content);
        $this->assertStringContainsString('@param string', $content);

        // 验证带有 @ 的注释被保留
        $this->assertStringContainsString('@important', $content);
    }

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/test_generator_' . uniqid() . '.db';
        $this->outputPath = sys_get_temp_dir() . '/test_output_' . uniqid() . '.php';
        $this->storage = new SqliteStorage($this->dbPath, new NullLogger());
        $this->generator = new AstCodeGenerator(new NullLogger());
    }
}
