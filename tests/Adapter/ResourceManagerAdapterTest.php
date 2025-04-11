<?php

namespace PhpPacker\Tests\Adapter;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Adapter\ResourceManagerAdapter;
use PhpPacker\Resource\ResourceFinder;
use PhpPacker\Resource\ResourceManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \PhpPacker\Adapter\ResourceManagerAdapter
 */
class ResourceManagerAdapterTest extends TestCase
{
    private LoggerInterface $logger;
    private string $configPath;
    private string $resourceDir;
    private string $outputDir;
    private string $entryFilePath;
    private ConfigurationAdapter $config;

    protected function setUp(): void
    {
        // 创建日志记录器
        $this->logger = new NullLogger();

        // 创建测试目录
        $this->configPath = __DIR__ . '/../data/config.php';
        $this->resourceDir = __DIR__ . '/../data/resources';
        $this->outputDir = __DIR__ . '/../data/output';
        $this->entryFilePath = __DIR__ . '/../data/entry.php';

        if (!is_dir(dirname($this->configPath))) {
            mkdir(dirname($this->configPath), 0777, true);
        }

        if (!is_dir($this->resourceDir)) {
            mkdir($this->resourceDir, 0777, true);
        }

        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0777, true);
        }

        // 确保entry.php文件存在
        if (!file_exists($this->entryFilePath)) {
            file_put_contents($this->entryFilePath, "<?php\necho 'Test entry file';");
        }

        // 创建测试资源文件
        file_put_contents($this->resourceDir . '/test.txt', 'This is a test resource file');

        // 创建测试配置文件
        $configData = [
            'entry' => $this->entryFilePath,
            'output' => $this->outputDir . '/packed.php',
            'output_dir' => $this->outputDir,
            'clean_output' => true,
            'assets' => [
                $this->resourceDir => 'resources'
            ],
            'exclude' => ['vendor', 'tests'],
            'source_paths' => [__DIR__ . '/../data/src']
        ];

        // 写入PHP格式的配置文件
        $phpConfig = '<?php return ' . var_export($configData, true) . ';';
        file_put_contents($this->configPath, $phpConfig);

        // 创建配置适配器
        $this->config = new ConfigurationAdapter($this->configPath, $this->logger);
    }

    protected function tearDown(): void
    {
        // 清理测试文件
        if (file_exists($this->resourceDir . '/test.txt')) {
            unlink($this->resourceDir . '/test.txt');
        }

        if (file_exists($this->outputDir . '/resources/test.txt')) {
            unlink($this->outputDir . '/resources/test.txt');
        }

        if (file_exists($this->configPath)) {
            unlink($this->configPath);
        }

        // 删除测试目录
        if (is_dir($this->outputDir . '/resources')) {
            rmdir($this->outputDir . '/resources');
        }

        if (is_dir($this->outputDir)) {
            rmdir($this->outputDir);
        }

        if (is_dir($this->resourceDir)) {
            rmdir($this->resourceDir);
        }

        if (is_dir(dirname($this->configPath))) {
            rmdir(dirname($this->configPath));
        }
    }

    public function testAdapterCorrectlyWrapsUnderlyingResourceManager(): void
    {
        // 创建资源管理器适配器
        $adapter = new ResourceManagerAdapter($this->config, $this->logger);

        // 测试获取底层对象
        $resourceManager = $adapter->getResourceManager();
        $resourceFinder = $adapter->getResourceFinder();

        $this->assertInstanceOf(ResourceManager::class, $resourceManager);
        $this->assertInstanceOf(ResourceFinder::class, $resourceFinder);
    }

    public function testIsResourceFile(): void
    {
        // 创建资源管理器适配器
        $adapter = new ResourceManagerAdapter($this->config, $this->logger);

        // 测试是否为资源文件
        $this->assertTrue($adapter->isResourceFile($this->resourceDir . '/test.txt'));
        $this->assertFalse($adapter->isResourceFile(__FILE__));
    }

    public function testCopyResources(): void
    {
        // 创建资源管理器适配器
        $adapter = new ResourceManagerAdapter($this->config, $this->logger);

        // 复制资源文件
        $adapter->copyResources();

        // 验证资源文件是否被复制
        $this->assertFileExists($this->outputDir . '/resources/test.txt');

        // 验证文件内容
        $content = file_get_contents($this->outputDir . '/resources/test.txt');
        $this->assertEquals('This is a test resource file', $content);
    }

    public function testCleanOutputDirectory(): void
    {
        // 首先创建一些测试文件
        file_put_contents($this->outputDir . '/test-file.txt', 'Test content');

        // 创建资源管理器适配器
        $adapter = new ResourceManagerAdapter($this->config, $this->logger);

        // 清理输出目录
        $adapter->cleanOutputDirectory();

        // 验证文件是否被删除
        $this->assertFileDoesNotExist($this->outputDir . '/test-file.txt');
    }

    public function testCopyResource(): void
    {
        // 创建资源管理器适配器
        $adapter = new ResourceManagerAdapter($this->config, $this->logger);

        // 复制单个资源文件
        $adapter->copyResource(
            $this->resourceDir . '/test.txt',
            $this->outputDir . '/single-test.txt'
        );

        // 验证文件是否被复制
        $this->assertFileExists($this->outputDir . '/single-test.txt');

        // 清理测试文件
        if (file_exists($this->outputDir . '/single-test.txt')) {
            unlink($this->outputDir . '/single-test.txt');
        }
    }

    public function testValidateResources(): void
    {
        // 创建资源管理器适配器
        $adapter = new ResourceManagerAdapter($this->config, $this->logger);

        // 验证资源文件
        $adapter->validateResources();

        // 如果没有抛出异常，则表示验证通过
        $this->assertTrue(true);
    }
}
