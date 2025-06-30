<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Merger;

use PhpPacker\Merger\AstMerger;
use PhpPacker\Storage\SqliteStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AstMergerTest extends TestCase
{
    private AstMerger $merger;
    private SqliteStorage $storage;
    private string $dbPath;

    public function testMergeEmptyFiles(): void
    {
        $result = $this->merger->mergeFiles([]);
        $this->assertEmpty($result);
    }

    public function testMergeVendorAndProjectFiles(): void
    {
        // 准备测试数据
        $vendorFile = [
            'id' => 1,
            'path' => 'vendor/package/file.php',
            'content' => '<?php class VendorClass {}',
            'is_vendor' => 1,
            'skip_ast' => 1,
            'ast_root_id' => null,
        ];

        $projectFile = [
            'id' => 2,
            'path' => 'src/ProjectClass.php',
            'content' => '<?php namespace App; class ProjectClass {}',
            'is_vendor' => 0,
            'skip_ast' => 0,
            'ast_root_id' => 123, // 模拟已存储的 AST
        ];

        $files = [$vendorFile, $projectFile];

        $result = $this->merger->mergeFiles($files);
        $this->assertNotEmpty($result);

        // 验证 vendor 文件被包含（作为普通的 AST 节点）
        $hasVendorClass = false;
        $hasVendorComment = false;
        foreach ($result as $node) {
            // 检查是否有 vendor 类
            if ($node instanceof \PhpParser\Node\Stmt\Class_ && $node->name->toString() === 'VendorClass') {
                $hasVendorClass = true;
                // 检查是否有 vendor 注释
                $comments = $node->getAttribute('comments', []);
                foreach ($comments as $comment) {
                    if (str_contains($comment->getText(), 'Vendor file:')) {
                        $hasVendorComment = true;
                        break;
                    }
                }
            }
        }
        $this->assertTrue($hasVendorClass, 'Vendor class should be included');
        $this->assertTrue($hasVendorComment, 'Vendor file comment should be present');
    }

    public function testMergeFilesWithSameNamespace(): void
    {
        $file1 = [
            'id' => 1,
            'path' => 'src/File1.php',
            'content' => '<?php namespace App\Models; class User {}',
            'is_vendor' => 0,
            'skip_ast' => 0,
            'ast_root_id' => 1,
        ];

        $file2 = [
            'id' => 2,
            'path' => 'src/File2.php',
            'content' => '<?php namespace App\Models; class Product {}',
            'is_vendor' => 0,
            'skip_ast' => 0,
            'ast_root_id' => 2,
        ];

        $files = [$file1, $file2];

        $result = $this->merger->mergeFiles($files);

        // 应该有一个命名空间节点包含两个类
        $namespaceNodes = array_filter($result, function ($node) {
            return $node instanceof \PhpParser\Node\Stmt\Namespace_;
        });

        $this->assertNotEmpty($namespaceNodes);
    }

    public function testMergeFilesWithDifferentNamespaces(): void
    {
        $file1 = [
            'id' => 1,
            'path' => 'src/Models/User.php',
            'content' => '<?php namespace App\Models; class User {}',
            'is_vendor' => 0,
            'skip_ast' => 0,
            'ast_root_id' => 1,
        ];

        $file2 = [
            'id' => 2,
            'path' => 'src/Services/UserService.php',
            'content' => '<?php namespace App\Services; class UserService {}',
            'is_vendor' => 0,
            'skip_ast' => 0,
            'ast_root_id' => 2,
        ];

        $files = [$file1, $file2];

        $result = $this->merger->mergeFiles($files);

        // 应该有两个不同的命名空间节点
        $namespaceNodes = array_filter($result, function ($node) {
            return $node instanceof \PhpParser\Node\Stmt\Namespace_;
        });

        $this->assertCount(2, $namespaceNodes);
    }

    public function testDeduplicateSymbols(): void
    {
        // 两个文件定义了相同的类（应该去重）
        $file1 = [
            'id' => 1,
            'path' => 'src/User1.php',
            'content' => '<?php namespace App; class User { public function getName() {} }',
            'is_vendor' => 0,
            'skip_ast' => 0,
            'ast_root_id' => 1,
        ];

        $file2 = [
            'id' => 2,
            'path' => 'src/User2.php',
            'content' => '<?php namespace App; class User { public function getEmail() {} }',
            'is_vendor' => 0,
            'skip_ast' => 0,
            'ast_root_id' => 2,
        ];

        $files = [$file1, $file2];

        $result = $this->merger->mergeFiles($files);

        // 计算结果中 User 类的数量
        $userClassCount = 0;
        $countRef = &$userClassCount;
        $traverser = new \PhpParser\NodeTraverser();
        $visitor = new class($countRef) extends \PhpParser\NodeVisitorAbstract {
            private $countRef;

            public function __construct(&$count)
            {
                $this->countRef = &$count;
            }

            public function enterNode(\PhpParser\Node $node)
            {
                if ($node instanceof \PhpParser\Node\Stmt\Class_ &&
                    $node->name !== null && $node->name->toString() === 'User') {
                    $this->countRef++;
                }
                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($result);

        // 应该只有一个 User 类（去重后）
        $this->assertEquals(1, $userClassCount);
    }

    public function testOptimizeAst(): void
    {
        $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
        $code = '<?php
namespace App;

class User {
    public function getName() {
        return "test";
    }
}

class UnusedClass {
    public function unused() {
        return "unused";
    }
}';

        $ast = $parser->parse($code);

        // 优化 AST
        $optimized = $this->merger->optimizeAst($ast);

        // 目前优化器是空实现，所以应该返回相同的 AST
        $this->assertCount(count($ast), $optimized);
    }

    public function testMergeGlobalNamespaceFiles(): void
    {
        $file1 = [
            'id' => 1,
            'path' => 'functions.php',
            'content' => '<?php function helper() { return "help"; }',
            'is_vendor' => 0,
            'skip_ast' => 0,
            'ast_root_id' => 1,
        ];

        $file2 = [
            'id' => 2,
            'path' => 'constants.php',
            'content' => '<?php const APP_VERSION = "1.0.0";',
            'is_vendor' => 0,
            'skip_ast' => 0,
            'ast_root_id' => 2,
        ];

        $files = [$file1, $file2];

        $result = $this->merger->mergeFiles($files);

        // 全局命名空间的内容应该直接在顶层
        $hasFunction = false;
        $hasConst = false;

        foreach ($result as $node) {
            if ($node instanceof \PhpParser\Node\Stmt\Function_) {
                $hasFunction = true;
            } elseif ($node instanceof \PhpParser\Node\Stmt\Const_) {
                $hasConst = true;
            }
        }

        $this->assertTrue($hasFunction || $hasConst);
    }

    public function testMergeWithAutoloadFiles(): void
    {
        $autoloadFile = [
            'id' => 1,
            'path' => 'vendor/autoload.php',
            'content' => '<?php require __DIR__ . "/composer/autoload_real.php";',
            'is_vendor' => 1,
            'skip_ast' => 1,
            'ast_root_id' => null,
        ];

        $projectFile = [
            'id' => 2,
            'path' => 'src/App.php',
            'content' => '<?php namespace App; class App {}',
            'is_vendor' => 0,
            'skip_ast' => 0,
            'ast_root_id' => 1,
        ];

        $files = [$autoloadFile, $projectFile];

        $result = $this->merger->mergeFiles($files);
        $this->assertNotEmpty($result);

        // Autoload 文件应该被包含（作为普通的 AST 节点）
        $hasRequireStatement = false;
        $hasVendorComment = false;
        foreach ($result as $node) {
            // 检查是否有 require 语句
            if ($node instanceof \PhpParser\Node\Stmt\Expression &&
                $node->expr instanceof \PhpParser\Node\Expr\Include_) {
                $hasRequireStatement = true;
            }
            
            // 检查 vendor 注释
            $comments = $node->getAttribute('comments', []);
            foreach ($comments as $comment) {
                if (str_contains($comment->getText(), 'Vendor file: vendor/autoload.php')) {
                    $hasVendorComment = true;
                    break;
                }
            }
        }
        $this->assertTrue($hasRequireStatement, 'Require statement should be present');
        $this->assertTrue($hasVendorComment, 'Vendor file comment should be present');
    }

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/test_merger_' . uniqid() . '.db';
        $this->storage = new SqliteStorage($this->dbPath, new NullLogger());
        $this->merger = new AstMerger($this->storage, new NullLogger());
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }
}