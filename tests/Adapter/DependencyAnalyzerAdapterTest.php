<?php

namespace PhpPacker\Tests\Adapter;

use PhpPacker\Adapter\DependencyAnalyzerAdapter;
use PhpPacker\Adapter\ReflectionServiceAdapter;
use PhpPacker\Ast\AstManager;
use PhpPacker\Ast\ParserFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \PhpPacker\Adapter\DependencyAnalyzerAdapter
 */
class DependencyAnalyzerAdapterTest extends TestCase
{
    private LoggerInterface $logger;
    private AstManager $astManager;
    /**
     * @var ReflectionServiceAdapter|\PHPUnit\Framework\MockObject\MockObject
     */
    private $reflectionService;
    private string $tempDir;
    private string $testFile;

    protected function setUp(): void
    {
        // 创建日志记录器
        $this->logger = new NullLogger();

        // 创建AST管理器
        $this->astManager = new AstManager($this->logger);

        // 创建临时目录和测试文件
        $this->tempDir = sys_get_temp_dir() . '/php-packer-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);

        // 创建测试文件
        $this->testFile = $this->tempDir . '/test.php';
        $testContent = <<<'PHP'
<?php
// 测试文件
use PhpPacker\Tests\TestClass;

function testFunction() {
    $obj = new TestClass();
    return $obj->test();
}

echo testFunction();
PHP;
        file_put_contents($this->testFile, $testContent);

        // 创建依赖类文件
        $dependencyFile = $this->tempDir . '/TestClass.php';
        $dependencyContent = <<<'PHP'
<?php
namespace PhpPacker\Tests;

class TestClass {
    public function test() {
        return "测试成功";
    }
}
PHP;
        file_put_contents($dependencyFile, $dependencyContent);

        // 创建模拟的反射服务适配器
        $this->reflectionService = $this->createMock(ReflectionServiceAdapter::class);

        // 解析测试文件并添加到AST管理器
        $parser = ParserFactory::createPhp81Parser();
        $ast = $parser->parse(file_get_contents($this->testFile));
        $this->astManager->addAst($this->testFile, $ast);

        // 解析依赖文件并添加到AST管理器
        $ast = $parser->parse(file_get_contents($dependencyFile));
        $this->astManager->addAst($dependencyFile, $ast);
    }

    protected function tearDown(): void
    {
        // 清理测试文件
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }

        $dependencyFile = $this->tempDir . '/TestClass.php';
        if (file_exists($dependencyFile)) {
            unlink($dependencyFile);
        }

        // 删除临时目录
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testCreateAdapter(): void
    {
        // 创建依赖分析器适配器
        $adapter = new DependencyAnalyzerAdapter(
            $this->astManager,
            $this->reflectionService,
            $this->logger
        );

        // 验证适配器创建成功
        $this->assertInstanceOf(DependencyAnalyzerAdapter::class, $adapter);
    }

    public function testGetOptimizedFileOrder(): void
    {
        // 跳过此测试，因为它需要类加载器支持
        $this->markTestSkipped('This test requires a working class loader and autoloader.');
    }

    public function testFindUsedResources(): void
    {
        // 创建依赖分析器适配器
        $adapter = new DependencyAnalyzerAdapter(
            $this->astManager,
            $this->reflectionService,
            $this->logger
        );

        // 查找使用的资源
        $resources = $adapter->findUsedResources(
            $this->testFile,
            $this->astManager->getAst($this->testFile)
        );

        // 在这个简单测试中，没有资源被使用
        $this->assertInstanceOf(\Traversable::class, $resources);
        // 转换为数组以便验证是否为空
        $resourceArray = iterator_to_array($resources);
        $this->assertEmpty($resourceArray);
    }
}
