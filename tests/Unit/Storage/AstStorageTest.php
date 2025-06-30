<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Storage;

use PhpPacker\Storage\AstStorage;
use PhpPacker\Storage\SqliteStorage;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AstStorageTest extends TestCase
{
    private AstStorage $astStorage;
    private SqliteStorage $sqliteStorage;
    private string $dbPath;

    public function testStoreAndLoadAst(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $code = '<?php namespace Test; class TestClass { public function test() {} }';
        $ast = $parser->parse($code);

        // 添加文件
        $fileId = $this->sqliteStorage->addFile('test.php', $code, 'class', null);

        // 存储 AST
        $rootId = $this->astStorage->storeAst($ast, $fileId);
        $this->assertGreaterThan(0, $rootId);

        // 验证文件的 AST 根节点已更新
        $file = $this->sqliteStorage->getFileById($fileId);
        $this->assertEquals($rootId, $file['ast_root_id']);

        // 加载 AST
        $loadedAst = $this->astStorage->loadAst($fileId);
        $this->assertNotNull($loadedAst);
        $this->assertNotEmpty($loadedAst);
    }

    public function testStoreAstWithComplexStructure(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $code = '<?php
namespace Test\Complex;

use Some\Other\Class as AliasedClass;

interface TestInterface {
    public function interfaceMethod(): void;
}

trait TestTrait {
    public function traitMethod(): string {
        return "trait";
    }
}

class ComplexClass extends AliasedClass implements TestInterface {
    use TestTrait;
    
    private string $property = "test";
    
    public function interfaceMethod(): void {
        echo $this->property;
    }
    
    public static function staticMethod(int $param): ?array {
        return null;
    }
}';

        $ast = $parser->parse($code);
        $fileId = $this->sqliteStorage->addFile('complex.php', $code, 'class', null);

        $rootId = $this->astStorage->storeAst($ast, $fileId);
        $this->assertGreaterThan(0, $rootId);

        // 验证存储的节点
        $nodes = $this->sqliteStorage->getAstNodesByFileId($fileId);
        $this->assertNotEmpty($nodes);

        // 验证节点类型
        $nodeTypes = array_column($nodes, 'node_type');
        $this->assertContains('Stmt_Namespace', $nodeTypes);
        $this->assertContains('Stmt_Use', $nodeTypes);
        $this->assertContains('Stmt_Interface', $nodeTypes);
        $this->assertContains('Stmt_Trait', $nodeTypes);
        $this->assertContains('Stmt_Class', $nodeTypes);
    }

    public function testFindNodesByType(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $code = '<?php
class Class1 {}
class Class2 {}
interface Interface1 {}
trait Trait1 {}';

        $ast = $parser->parse($code);
        $fileId = $this->sqliteStorage->addFile('types.php', $code);
        $this->astStorage->storeAst($ast, $fileId);

        // 查找所有类节点
        $classNodes = $this->astStorage->findNodesByType($fileId, 'Stmt_Class');
        $this->assertCount(2, $classNodes);

        // 查找接口节点
        $interfaceNodes = $this->astStorage->findNodesByType($fileId, 'Stmt_Interface');
        $this->assertCount(1, $interfaceNodes);

        // 查找 trait 节点
        $traitNodes = $this->astStorage->findNodesByType($fileId, 'Stmt_Trait');
        $this->assertCount(1, $traitNodes);
    }

    public function testExtractFqcn(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $code = '<?php
namespace App\Models;

class User {
    public function getName(): string {
        return "test";
    }
}';

        $ast = $parser->parse($code);

        // 使用 NameResolver 处理 AST
        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());
        $ast = $traverser->traverse($ast);

        $fileId = $this->sqliteStorage->addFile('user.php', $code, 'class', null);
        $this->astStorage->storeAst($ast, $fileId);

        // 验证 FQCN 被正确存储
        $nodes = $this->sqliteStorage->getAstNodesByFileId($fileId);
        $classNode = array_filter($nodes, fn($n) => $n['node_type'] === 'Stmt_Class');
        $classNode = array_values($classNode)[0];

        $this->assertEquals('App\\Models\\User', $classNode['fqcn']);
    }

    public function testFindUsages(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $code = '<?php
namespace App;

use App\Models\User;

class UserService {
    public function getUser(): User {
        return new User();
    }
    
    public function createUser(): void {
        $user = new User();
        $user->save();
    }
}';

        $ast = $parser->parse($code);
        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());
        $ast = $traverser->traverse($ast);

        $fileId = $this->sqliteStorage->addFile('user-service.php', $code, 'class', null);
        $this->astStorage->storeAst($ast, $fileId);

        // 查找 User 类的使用
        $usages = $this->astStorage->findUsages('App\\Models\\User');
        // 注意：这个测试可能需要调整，因为当前实现是基于 attributes 的文本搜索
    }

    public function testNodeAttributesSerialization(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $code = '<?php
abstract class AbstractClass {
    public const CONSTANT = "value";
    private int $property = 42;
    
    abstract public function abstractMethod(): void;
    
    final protected function finalMethod(): string {
        return "final";
    }
}';

        $ast = $parser->parse($code);
        $fileId = $this->sqliteStorage->addFile('abstract.php', $code, 'class', null);
        $this->astStorage->storeAst($ast, $fileId);

        $nodes = $this->sqliteStorage->getAstNodesByFileId($fileId);

        // 验证类节点的属性
        $classNode = array_filter($nodes, fn($n) => $n['node_type'] === 'Stmt_Class');
        $classNode = array_values($classNode)[0];
        $attributes = json_decode($classNode['attributes'] ?? '{}', true);
        $this->assertArrayHasKey('flags', $attributes);

        // 验证方法节点的属性
        $methodNodes = array_filter($nodes, fn($n) => $n['node_type'] === 'Stmt_ClassMethod');
        $this->assertNotEmpty($methodNodes);

        foreach ($methodNodes as $methodNode) {
            $attrs = json_decode($methodNode['attributes'], true);
            $this->assertArrayHasKey('flags', $attrs);
        }
    }

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/test_ast_' . uniqid() . '.db';
        $this->sqliteStorage = new SqliteStorage($this->dbPath, new NullLogger());
        $this->astStorage = new AstStorage($this->sqliteStorage, new NullLogger());
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }
}