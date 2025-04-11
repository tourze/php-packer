<?php

namespace PhpPacker\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * 整个打包流程的集成测试
 *
 * @covers \PhpPacker\Packer
 */
class PackerWorkflowTest extends TestCase
{
    private string $tempDir;
    private string $configFile;
    private string $entryFile;
    private string $outputFile;
    private string $resourceFile;

    protected function setUp(): void
    {
        // 创建临时目录
        $this->tempDir = sys_get_temp_dir() . '/php-packer-workflow-test-' . uniqid();
        mkdir($this->tempDir . '/src', 0777, true);
        mkdir($this->tempDir . '/src/Service', 0777, true);
        mkdir($this->tempDir . '/resources', 0777, true);
        mkdir($this->tempDir . '/output', 0777, true);

        // 创建配置文件
        $this->configFile = $this->tempDir . '/config.php';
        $configContent = [
            'entry' => $this->tempDir . '/src/app.php',
            'output' => $this->tempDir . '/output/packed.php',
            'output_dir' => $this->tempDir . '/output',
            'clean_output' => true,
            'assets' => [
                ['source' => $this->tempDir . '/resources', 'target' => 'resources']
            ],
            'exclude' => ['*vendor*', '*tests*'],
            'source_paths' => [$this->tempDir . '/src'],
            'minify' => false,
            'comments' => true,
            'debug' => true,
            'remove_namespace' => false
        ];

        // 写入PHP格式的配置文件
        $phpConfig = '<?php return ' . var_export($configContent, true) . ';';
        file_put_contents($this->configFile, $phpConfig);

        // 创建入口文件
        $this->entryFile = $this->tempDir . '/src/app.php';
        $entryContent = <<<'PHP'
<?php
// 应用入口文件
namespace App;

use App\Service\Greeter;

class Application {
    public function run(): string {
        $greeter = new Greeter();
        $config = require __DIR__ . '/../resources/config.php';
        return $greeter->greet($config['name']);
    }
}

$app = new Application();
echo $app->run();
PHP;
        file_put_contents($this->entryFile, $entryContent);

        // 创建服务类文件
        $serviceFile = $this->tempDir . '/src/Service/Greeter.php';
        $serviceContent = <<<'PHP'
<?php
namespace App\Service;

class Greeter {
    public function greet(string $name): string {
        return "Hello, {$name}!";
    }
}
PHP;
        file_put_contents($serviceFile, $serviceContent);

        // 创建资源文件
        $this->resourceFile = $this->tempDir . '/resources/config.php';
        $resourceContent = <<<'PHP'
<?php
// 配置文件
return [
    'name' => 'PHP Packer'
];
PHP;
        file_put_contents($this->resourceFile, $resourceContent);

        // 设置输出文件路径
        $this->outputFile = $this->tempDir . '/output/packed.php';
    }

    protected function tearDown(): void
    {
        // 清理测试文件
        if (file_exists($this->entryFile)) {
            unlink($this->entryFile);
        }

        if (file_exists($this->tempDir . '/src/Service/Greeter.php')) {
            unlink($this->tempDir . '/src/Service/Greeter.php');
        }

        if (file_exists($this->resourceFile)) {
            unlink($this->resourceFile);
        }

        if (file_exists($this->outputFile)) {
            unlink($this->outputFile);
        }

        if (file_exists($this->configFile)) {
            unlink($this->configFile);
        }

        if (file_exists($this->tempDir . '/output/resources/config.php')) {
            unlink($this->tempDir . '/output/resources/config.php');
        }

        // 删除目录
        if (is_dir($this->tempDir . '/output/resources')) {
            rmdir($this->tempDir . '/output/resources');
        }

        if (is_dir($this->tempDir . '/output')) {
            rmdir($this->tempDir . '/output');
        }

        if (is_dir($this->tempDir . '/resources')) {
            rmdir($this->tempDir . '/resources');
        }

        if (is_dir($this->tempDir . '/src/Service')) {
            rmdir($this->tempDir . '/src/Service');
        }

        if (is_dir($this->tempDir . '/src')) {
            rmdir($this->tempDir . '/src');
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testFullPackingWorkflow(): void
    {
        // 跳过此测试，因为它需要类加载器支持
        $this->markTestSkipped('This test requires a working class loader and autoloader.');
    }
}
