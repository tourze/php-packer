<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer;

use PhpPacker\Analyzer\AutoloadResolver;
use PhpPacker\Analyzer\ClassFinder;
use PhpPacker\Analyzer\FileVerifier;
use PhpPacker\Analyzer\PathResolver;
use PhpPacker\Storage\SqliteStorage;
use PhpPacker\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(ClassFinder::class)]
final class ClassFinderTest extends TestCase
{
    private ClassFinder $classFinder;

    private StorageInterface $storage;

    private AutoloadResolver $autoloadResolver;

    private PathResolver $pathResolver;

    private FileVerifier $fileVerifier;

    private string $dbPath;

    protected function setUp(): void
    {
        $logger = new NullLogger();

        // 创建临时数据库
        $this->dbPath = sys_get_temp_dir() . '/test-' . uniqid() . '.db';
        $this->storage = new SqliteStorage($this->dbPath, $logger);

        // 创建真实实现
        $this->autoloadResolver = new AutoloadResolver($this->storage, $logger);
        $this->pathResolver = new PathResolver($logger);
        $this->fileVerifier = new FileVerifier($logger);

        $this->classFinder = new ClassFinder(
            $this->storage,
            $logger,
            $this->autoloadResolver,
            $this->pathResolver,
            $this->fileVerifier
        );
    }

    protected function tearDown(): void
    {
        // 清理临时数据库
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testClassFinderExists(): void
    {
        // 测试 ClassFinder 类是否存在且能被实例化
        $this->assertInstanceOf(ClassFinder::class, $this->classFinder);
    }

    public function testClassFinderStructure(): void
    {
        // 测试 ClassFinder 类的结构
        $this->assertInstanceOf(ClassFinder::class, $this->classFinder);

        // 测试构造函数参数正确
        $reflection = new \ReflectionClass(ClassFinder::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertCount(5, $constructor->getParameters());

        // 验证构造函数参数类型
        $params = $constructor->getParameters();
        $this->assertEquals('PhpPacker\Storage\StorageInterface', $this->getTypeName($params[0]->getType()));
        $this->assertEquals('Psr\Log\LoggerInterface', $this->getTypeName($params[1]->getType()));
        $this->assertEquals('PhpPacker\Analyzer\AutoloadResolver', $this->getTypeName($params[2]->getType()));
        $this->assertEquals('PhpPacker\Analyzer\PathResolver', $this->getTypeName($params[3]->getType()));
        $this->assertEquals('PhpPacker\Analyzer\FileVerifier', $this->getTypeName($params[4]->getType()));
    }

    private function getTypeName(?\ReflectionType $type): ?string
    {
        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        return null;
    }

    public function testFindClassFile(): void
    {
        // 准备测试数据：在数据库中插入一个文件记录
        $fileId = $this->storage->addFile('/test/path/TestClass.php', '<?php class TestClass {}', 'php', false);
        $this->storage->addSymbol($fileId, 'class', 'TestClass', 'TestClass');

        $result = $this->classFinder->findClassFile('TestClass');

        // 验证返回的是绝对路径
        $this->assertNotNull($result);
        $this->assertStringContainsString('TestClass.php', $result);
    }
}
