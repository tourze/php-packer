<?php

namespace PhpPacker\Tests\Adapter;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Adapter\ReflectionServiceAdapter;
use PhpPacker\Analysis\ReflectionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \PhpPacker\Adapter\ReflectionServiceAdapter
 */
class ReflectionServiceAdapterTest extends TestCase
{
    private LoggerInterface $logger;
    private ConfigurationAdapter $config;
    private string $configPath;
    private string $tempDir;

    protected function setUp(): void
    {
        // 创建日志记录器
        $this->logger = new NullLogger();

        // 创建临时目录
        $this->tempDir = sys_get_temp_dir() . '/php-packer-reflection-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);

        // 创建配置文件路径
        $this->configPath = $this->tempDir . '/config.php';

        // 创建测试配置文件
        $configData = [
            'entry' => $this->tempDir . '/entry.php',
            'output' => $this->tempDir . '/output/packed.php',
            'output_dir' => $this->tempDir . '/output',
            'exclude' => ['*vendor*', '*tests*'],
            'source_paths' => [$this->tempDir]
        ];

        // 写入PHP格式的配置文件
        $phpConfig = '<?php return ' . var_export($configData, true) . ';';
        file_put_contents($this->configPath, $phpConfig);

        // 创建测试入口文件
        $entryContent = <<<'PHP'
<?php
namespace App;

use App\Service\TestService;

class Entry {
    public function run() {
        $service = new TestService();
        return $service->doSomething();
    }
}
PHP;
        file_put_contents($this->tempDir . '/entry.php', $entryContent);

        // 创建测试服务类
        mkdir($this->tempDir . '/Service', 0777, true);
        $serviceContent = <<<'PHP'
<?php
namespace App\Service;

class TestService {
    public function doSomething() {
        return "Service result";
    }
}
PHP;
        file_put_contents($this->tempDir . '/Service/TestService.php', $serviceContent);

        // 创建配置适配器
        $this->config = new ConfigurationAdapter($this->configPath, $this->logger);
    }

    protected function tearDown(): void
    {
        // 清理测试文件
        if (file_exists($this->tempDir . '/entry.php')) {
            unlink($this->tempDir . '/entry.php');
        }

        if (file_exists($this->tempDir . '/Service/TestService.php')) {
            unlink($this->tempDir . '/Service/TestService.php');
        }

        if (file_exists($this->configPath)) {
            unlink($this->configPath);
        }

        // 删除临时目录和子目录
        if (is_dir($this->tempDir . '/Service')) {
            rmdir($this->tempDir . '/Service');
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testAdapterCreation(): void
    {
        // 创建反射服务适配器
        $adapter = new ReflectionServiceAdapter($this->config, $this->logger);

        // 验证适配器创建成功
        $this->assertInstanceOf(ReflectionServiceAdapter::class, $adapter);

        // 验证底层对象
        $reflectionService = $adapter->getReflectionService();
        $this->assertInstanceOf(ReflectionService::class, $reflectionService);
    }

    public function testGetClassFileName(): void
    {
        // 创建反射服务适配器
        $adapter = new ReflectionServiceAdapter($this->config, $this->logger);

        // 解析类名
        $filePath = $adapter->getClassFileName('App\Service\TestService');

        // 由于我们没有真正的PSR-4 autoloader配置，这里应该返回null
        // 实际应用中，配置正确的autoloader会返回正确的文件路径
        $this->assertNull($filePath);
    }

    public function testGetFunctionFileName(): void
    {
        // 创建反射服务适配器
        $adapter = new ReflectionServiceAdapter($this->config, $this->logger);

        // 测试获取函数文件名
        $filePath = $adapter->getFunctionFileName('array_map');

        // 内置函数应该返回null
        $this->assertNull($filePath);
    }

    public function testGetReflectionService(): void
    {
        // 创建反射服务适配器
        $adapter = new ReflectionServiceAdapter($this->config, $this->logger);

        // 获取底层服务
        $service = $adapter->getReflectionService();

        // 验证返回类型
        $this->assertInstanceOf(ReflectionService::class, $service);

        // 验证排除模式
        $patterns = $service->getExcludePatterns();
        $this->assertContains('*vendor*', $patterns);
        $this->assertContains('*tests*', $patterns);
    }
}
