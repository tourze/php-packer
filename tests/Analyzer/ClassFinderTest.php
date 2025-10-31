<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer;

use PhpPacker\Analyzer\AutoloadResolver;
use PhpPacker\Analyzer\ClassFinder;
use PhpPacker\Analyzer\FileVerifier;
use PhpPacker\Analyzer\PathResolver;
use PhpPacker\Storage\SqliteStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(ClassFinder::class)]
final class ClassFinderTest extends TestCase
{
    private ClassFinder $classFinder;

    private SqliteStorage $storage;

    private LoggerInterface $logger;

    private AutoloadResolver $autoloadResolver;

    private PathResolver $pathResolver;

    private FileVerifier $fileVerifier;

    protected function setUp(): void
    {
        // 创建 Mock 对象
        /*
         * 使用具体类 SqliteStorage 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：SqliteStorage 没有对应的接口抽象，且 ClassFinder 构造函数直接依赖具体实现
         * 2) 这种使用是否合理和必要：在单元测试中合理，因为我们只需要验证 ClassFinder 的行为而不关心存储的具体实现
         * 3) 是否有更好的替代方案：理想情况下应该为存储层定义接口，但当前架构下使用 mock 是最佳选择
         */
        $this->storage = $this->createMock(SqliteStorage::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        /*
         * 使用具体类 AutoloadResolver 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：AutoloadResolver 没有对应的接口抽象，ClassFinder 需要依赖其具体方法
         * 2) 这种使用是否合理和必要：在单元测试中合理，允许我们隔离测试 ClassFinder 而不依赖 AutoloadResolver 的实际实现
         * 3) 是否有更好的替代方案：应该为 AutoloadResolver 定义接口来改善可测试性，但当前使用 mock 是可接受的
         */
        $this->autoloadResolver = $this->createMock(AutoloadResolver::class);
        /*
         * 使用具体类 PathResolver 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：PathResolver 没有对应的接口抽象，ClassFinder 直接依赖其具体实现
         * 2) 这种使用是否合理和必要：在单元测试中合理，避免了路径解析的复杂性，专注测试 ClassFinder 的核心逻辑
         * 3) 是否有更好的替代方案：定义路径解析接口会更好，但当前架构下 mock 是合理的测试策略
         */
        $this->pathResolver = $this->createMock(PathResolver::class);
        /*
         * 使用具体类 FileVerifier 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：FileVerifier 没有对应的接口抽象，ClassFinder 需要其文件验证功能
         * 2) 这种使用是否合理和必要：在单元测试中合理，避免真实的文件系统操作，提高测试的独立性和速度
         * 3) 是否有更好的替代方案：为文件验证定义接口会改善架构，但当前使用 mock 是有效的测试方法
         */
        $this->fileVerifier = $this->createMock(FileVerifier::class);

        $this->classFinder = new ClassFinder(
            $this->storage,
            $this->logger,
            $this->autoloadResolver,
            $this->pathResolver,
            $this->fileVerifier
        );
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
        $this->assertEquals('PhpPacker\Storage\SqliteStorage', $this->getTypeName($params[0]->getType()));
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
        $this->storage->expects($this->once())
            ->method('findFileBySymbol')
            ->with('TestClass')
            ->willReturn(['path' => '/test/path/TestClass.php'])
        ;

        $this->pathResolver->expects($this->once())
            ->method('makeAbsolutePath')
            ->with('/test/path/TestClass.php')
            ->willReturn('/absolute/test/path/TestClass.php')
        ;

        $result = $this->classFinder->findClassFile('TestClass');

        $this->assertEquals('/absolute/test/path/TestClass.php', $result);
    }
}
