<?php

namespace PhpPacker\Tests\Adapter;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \PhpPacker\Adapter\ConfigurationAdapter
 */
class ConfigurationAdapterTest extends TestCase
{
    private LoggerInterface $logger;
    private string $configPath;
    private string $entryFilePath;

    protected function setUp(): void
    {
        // 创建配置文件路径
        $this->configPath = __DIR__ . '/../data/config.php';
        $this->entryFilePath = __DIR__ . '/../data/entry.php';

        // 创建测试目录
        if (!is_dir(dirname($this->configPath))) {
            mkdir(dirname($this->configPath), 0777, true);
        }

        // 确保entry.php文件存在
        if (!file_exists($this->entryFilePath)) {
            file_put_contents($this->entryFilePath, "<?php\necho 'Test entry file';");
        }

        // 创建测试配置文件
        $configData = [
            'entry' => $this->entryFilePath,
            'output' => __DIR__ . '/../data/output/packed.php',
            'output_dir' => __DIR__ . '/../data/output',
            'clean_output' => true,
            'minify' => false,
            'comments' => true,
            'debug' => true,
            'assets' => [
                'src/resources' => 'resources'
            ],
            'exclude' => ['vendor', 'tests'],
            'source_paths' => [__DIR__ . '/../data/src'],
            'remove_namespace' => true,
            'for_kphp' => false
        ];

        // 写入PHP格式的配置文件
        $phpConfig = '<?php return ' . var_export($configData, true) . ';';
        file_put_contents($this->configPath, $phpConfig);

        // 创建日志记录器
        $this->logger = new NullLogger();
    }

    protected function tearDown(): void
    {
        // 清理测试文件
        if (file_exists($this->configPath)) {
            unlink($this->configPath);
        }

        // 删除测试目录
        if (is_dir(dirname($this->configPath))) {
            rmdir(dirname($this->configPath));
        }
    }

    public function testAdapterCorrectlyWrapsUnderlyingConfiguration(): void
    {
        // 创建配置适配器
        $adapter = new ConfigurationAdapter($this->configPath, $this->logger);

        // 测试获取底层配置对象
        $config = $adapter->getConfiguration();
        $this->assertInstanceOf(Configuration::class, $config);

        // 获取入口文件路径
        $entryFilePath = __DIR__ . '/../data/entry.php';

        // 测试各种方法代理
        $this->assertEquals($this->entryFilePath, $adapter->getEntryFile());
        $this->assertEquals(__DIR__ . '/../data/output/packed.php', $adapter->getOutputFile());
        $this->assertEquals(__DIR__ . '/../data/output', $adapter->getOutputDirectory());
        $this->assertEquals(['vendor', 'tests'], $adapter->getExclude());
        $this->assertEquals(['src/resources' => 'resources'], $adapter->getAssets());
        $this->assertFalse($adapter->shouldMinify());
        $this->assertTrue($adapter->shouldKeepComments());
        $this->assertTrue($adapter->isDebug());
        $this->assertTrue($adapter->shouldCleanOutput());
        $this->assertTrue($adapter->shouldRemoveNamespace());
        $this->assertFalse($adapter->forKphp());
    }

    public function testRawConfigData(): void
    {
        // 创建配置适配器
        $adapter = new ConfigurationAdapter($this->configPath, $this->logger);

        // 获取原始配置数据
        $rawConfig = $adapter->getRaw();

        // 验证原始配置数据
        $this->assertArrayHasKey('entry', $rawConfig);
        $this->assertArrayHasKey('output', $rawConfig);
        $this->assertArrayHasKey('debug', $rawConfig);
        $this->assertTrue($rawConfig['debug']);
    }
}
