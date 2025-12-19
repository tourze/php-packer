<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\AstMerger;
use PhpPacker\Storage\SqliteStorage;
use PhpParser\Node;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(AstMerger::class)]
final class AstMergerTest extends TestCase
{
    private AstMerger $merger;

    private SqliteStorage $storage;

    private static string $dbPath;

    public function testMergeEmptyFiles(): void
    {
        $result = $this->merger->mergeFiles([]);
        $this->assertEmpty($result);
    }

    public function testMergeVendorAndProjectFiles(): void
    {
        $files = [
            $this->createVendorFile(),
            $this->createProjectFile(),
        ];

        $result = $this->merger->mergeFiles($files);
        $this->assertNotEmpty($result);

        $this->assertVendorClassPresent($result);
        $this->assertVendorCommentPresent($result);
    }

    /**
     * @return array{id: int, path: string, content: string, is_vendor: bool, skip_ast: bool}
     */
    private function createVendorFile(): array
    {
        return [
            'id' => 1,
            'path' => 'vendor/package/file.php',
            'content' => '<?php class VendorClass {}',
            'is_vendor' => true,
            'skip_ast' => true,
        ];
    }

    /**
     * @return array{id: int, path: string, content: string, is_vendor: bool, skip_ast: bool}
     */
    private function createProjectFile(): array
    {
        return [
            'id' => 2,
            'path' => 'src/ProjectClass.php',
            'content' => '<?php namespace App; class ProjectClass {}',
            'is_vendor' => false,
            'skip_ast' => false,
        ];
    }

    /**
     * @param array<Node> $result
     */
    private function assertVendorClassPresent(array $result): void
    {
        foreach ($result as $node) {
            if ($node instanceof Class_ && null !== $node->name) {
                $nameStr = $node->name->toString();
                if ('VendorClass' === $nameStr) {
                    $this->assertInstanceOf(Class_::class, $node);
                    $this->assertEquals('VendorClass', $nameStr);

                    return;
                }
            }
        }
        self::fail('Vendor class should be included');
    }

    /**
     * @param array<Node> $result
     */
    private function assertVendorCommentPresent(array $result): void
    {
        foreach ($result as $node) {
            if ($this->isVendorClassNode($node) && $this->hasVendorFileComment($node)) {
                $this->assertTrue($this->isVendorClassNode($node));
                $this->assertTrue($this->hasVendorFileComment($node));

                return;
            }
        }
        self::fail('Vendor file comment should be present');
    }

    private function isVendorClassNode(Node $node): bool
    {
        return $node instanceof Class_ && null !== $node->name && 'VendorClass' === $node->name->toString();
    }

    private function hasVendorFileComment(Node $node): bool
    {
        $comments = $node->getAttribute('comments', []);
        if (!\is_array($comments)) {
            return false;
        }

        foreach ($comments as $comment) {
            if (\is_object($comment) && \method_exists($comment, 'getText')) {
                if (str_contains($comment->getText(), 'Vendor file:')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function testMergeFilesWithSameNamespace(): void
    {
        $file1 = [
            'id' => 1,
            'path' => 'src/File1.php',
            'content' => '<?php namespace App\Models; class User {}',
            'is_vendor' => false,
            'skip_ast' => false,
            'ast_root_id' => 1,
        ];

        $file2 = [
            'id' => 2,
            'path' => 'src/File2.php',
            'content' => '<?php namespace App\Models; class Product {}',
            'is_vendor' => false,
            'skip_ast' => false,
            'ast_root_id' => 2,
        ];

        $files = [$file1, $file2];

        $result = $this->merger->mergeFiles($files);

        // 应该有一个命名空间节点包含两个类
        $namespaceNodes = array_filter($result, function ($node) {
            return $node instanceof Namespace_;
        });

        $this->assertNotEmpty($namespaceNodes);
    }

    public function testMergeFilesWithDifferentNamespaces(): void
    {
        $file1 = [
            'id' => 1,
            'path' => 'src/Models/User.php',
            'content' => '<?php namespace App\Models; class User {}',
            'is_vendor' => false,
            'skip_ast' => false,
            'ast_root_id' => 1,
        ];

        $file2 = [
            'id' => 2,
            'path' => 'src/Services/UserService.php',
            'content' => '<?php namespace App\Services; class UserService {}',
            'is_vendor' => false,
            'skip_ast' => false,
            'ast_root_id' => 2,
        ];

        $files = [$file1, $file2];

        $result = $this->merger->mergeFiles($files);

        // 应该有两个不同的命名空间节点
        $namespaceNodes = array_filter($result, function ($node) {
            return $node instanceof Namespace_;
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
            'is_vendor' => false,
            'skip_ast' => false,
            'ast_root_id' => 1,
        ];

        $file2 = [
            'id' => 2,
            'path' => 'src/User2.php',
            'content' => '<?php namespace App; class User { public function getName() {} }',
            'is_vendor' => false,
            'skip_ast' => false,
            'ast_root_id' => 2,
        ];

        $result = $this->merger->mergeFiles([$file1, $file2]);
        $userClassCount = $this->countUserClasses($result);

        // 应该只有一个 User 类（去重后）
        $this->assertEquals(1, $userClassCount);
    }

    /**
     * @param array<Node> $nodes
     */
    private function countUserClasses(array $nodes): int
    {
        $count = 0;
        foreach ($nodes as $node) {
            if ($node instanceof Namespace_) {
                $count += $this->countUserClassesInNamespace($node);
            }
        }

        return $count;
    }

    private function countUserClassesInNamespace(Namespace_ $namespace): int
    {
        $count = 0;
        foreach ($namespace->stmts as $stmt) {
            if ($this->isUserClass($stmt)) {
                ++$count;
            }
        }

        return $count;
    }

    private function isUserClass(Node $node): bool
    {
        return $node instanceof Class_ && null !== $node->name && 'User' === $node->name->toString();
    }

    public function testOptimizeAst(): void
    {
        $factory = new ParserFactory();
        $parser = $factory->createForNewestSupportedVersion();
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
        $this->assertNotNull($ast);

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
            'is_vendor' => false,
            'skip_ast' => false,
            'ast_root_id' => 1,
        ];

        $file2 = [
            'id' => 2,
            'path' => 'constants.php',
            'content' => '<?php const APP_VERSION = "1.0.0";',
            'is_vendor' => false,
            'skip_ast' => false,
            'ast_root_id' => 2,
        ];

        $files = [$file1, $file2];

        $result = $this->merger->mergeFiles($files);

        // 全局命名空间的内容应该直接在顶层
        $hasFunction = false;
        $hasConst = false;

        foreach ($result as $node) {
            if ($node instanceof Function_) {
                $hasFunction = true;
            } elseif ($node instanceof Const_) {
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
            'is_vendor' => true,
            'skip_ast' => true,
            'ast_root_id' => null,
        ];

        $projectFile = [
            'id' => 2,
            'path' => 'src/App.php',
            'content' => '<?php namespace App; class App {}',
            'is_vendor' => false,
            'skip_ast' => false,
            'ast_root_id' => 1,
        ];

        $files = [$autoloadFile, $projectFile];

        $result = $this->merger->mergeFiles($files);
        $this->assertNotEmpty($result);

        // Autoload 文件应该被包含（作为普通的 AST 节点）
        $this->assertTrue($this->hasRequireStatement($result), 'Require statement should be present');
        $this->assertTrue($this->hasCommentContaining($result, 'Vendor file: vendor/autoload.php'), 'Vendor file comment should be present');
    }

    /**
     * @param array<Node> $nodes
     */
    private function hasRequireStatement(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof Expression && $node->expr instanceof Include_) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<Node> $nodes
     */
    private function hasCommentContaining(array $nodes, string $needle): bool
    {
        foreach ($nodes as $node) {
            if ($this->nodeHasCommentContaining($node, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function nodeHasCommentContaining(Node $node, string $needle): bool
    {
        $comments = $node->getAttribute('comments', []);
        if (!\is_array($comments)) {
            return false;
        }

        foreach ($comments as $comment) {
            if ($this->commentContainsText($comment, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $comment
     */
    private function commentContainsText($comment, string $needle): bool
    {
        if (!\is_object($comment) || !\method_exists($comment, 'getText')) {
            return false;
        }

        return str_contains($comment->getText(), $needle);
    }

    protected function setUp(): void
    {
        self::$dbPath = sys_get_temp_dir() . '/test_merger_' . uniqid() . '.db';
        $this->storage = new SqliteStorage(self::$dbPath, new NullLogger());
        $this->merger = new AstMerger(new NullLogger());
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$dbPath) && file_exists(self::$dbPath)) {
            unlink(self::$dbPath);
        }
        parent::tearDownAfterClass();
    }
}
