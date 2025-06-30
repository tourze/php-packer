<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Integration;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Packer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class FullWorkflowTest extends TestCase
{
    private string $tempDir;
    private LoggerInterface $logger;

    public function testSimpleClassDependency(): void
    {
        // 创建测试文件
        $helperCode = '<?php
namespace App\Helper;

class Calculator 
{
    public function add(int $a, int $b): int 
    {
        return $a + $b;
    }
}';

        $mainCode = '<?php
use App\Helper\Calculator;

$calc = new Calculator();
echo $calc->add(2, 3);';

        // 写入文件
        mkdir($this->tempDir . '/src/Helper', 0755, true);
        file_put_contents($this->tempDir . '/src/Helper/Calculator.php', $helperCode);
        file_put_contents($this->tempDir . '/main.php', $mainCode);

        // 创建配置
        $config = [
            'entry' => 'main.php',
            'output' => 'packed.php',
            'include' => ['src/**/*.php'],
            'database' => 'test.db'
        ];

        $configPath = $this->tempDir . '/config.json';
        file_put_contents($configPath, json_encode($config));

        // 创建配置适配器和打包器
        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);

        // 执行打包
        $packer->pack();

        // 验证输出文件
        $outputFile = $this->tempDir . '/packed.php';
        $this->assertFileExists($outputFile);

        // 验证内容包含两个类
        $content = file_get_contents($outputFile);
        $this->assertStringContainsString('class Calculator', $content);
        $this->assertStringContainsString('Calculator()', $content);

        // 测试运行
        ob_start();
        include $outputFile;
        $output = ob_get_clean();
        $this->assertEquals('5', $output);
    }

    public function testInterfaceImplementation(): void
    {
        // 创建接口
        $interfaceCode = '<?php
namespace App\Contract;

interface CalculatorInterface 
{
    public function calculate(int $a, int $b): int;
}';

        // 创建实现类
        $implementationCode = '<?php
namespace App\Service;

use App\Contract\CalculatorInterface;

class Adder implements CalculatorInterface
{
    public function calculate(int $a, int $b): int 
    {
        return $a + $b;
    }
}';

        // 创建主文件
        $mainCode = '<?php
use App\Contract\CalculatorInterface;
use App\Service\Adder;

$calculator = new Adder();
echo $calculator->calculate(10, 5);';

        // 写入文件
        mkdir($this->tempDir . '/src/Contract', 0755, true);
        mkdir($this->tempDir . '/src/Service', 0755, true);
        file_put_contents($this->tempDir . '/src/Contract/CalculatorInterface.php', $interfaceCode);
        file_put_contents($this->tempDir . '/src/Service/Adder.php', $implementationCode);
        file_put_contents($this->tempDir . '/main.php', $mainCode);

        // 创建配置
        $config = [
            'entry' => 'main.php',
            'output' => 'packed.php',
            'include' => ['src/**/*.php'],
            'database' => 'test.db'
        ];

        $configPath = $this->tempDir . '/config.json';
        file_put_contents($configPath, json_encode($config));

        // 执行打包
        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);
        $packer->pack();

        // 验证输出
        $outputFile = $this->tempDir . '/packed.php';
        $this->assertFileExists($outputFile);

        $content = file_get_contents($outputFile);
        $this->assertStringContainsString('interface CalculatorInterface', $content);
        $this->assertStringContainsString('class Adder', $content);
        $this->assertStringContainsString('implements', $content);

        // 测试运行
        ob_start();
        include $outputFile;
        $output = ob_get_clean();
        $this->assertEquals('15', $output);
    }

    public function testNamespaceResolution(): void
    {
        // 创建有命名空间冲突的类
        $math1Code = '<?php
namespace App\Math\V1;

class Calculator 
{
    public function multiply(int $a, int $b): int 
    {
        return $a * $b;
    }
}';

        $math2Code = '<?php
namespace App\Math\V2;

class Calculator 
{
    public function multiply(int $a, int $b): int 
    {
        return $a * $b * 2; // 不同的实现
    }
}';

        $mainCode = '<?php
use App\Math\V1\Calculator as CalcV1;
use App\Math\V2\Calculator as CalcV2;

$calc1 = new CalcV1();
$calc2 = new CalcV2();

echo $calc1->multiply(3, 4) . "," . $calc2->multiply(3, 4);';

        // 写入文件
        mkdir($this->tempDir . '/src/Math/V1', 0755, true);
        mkdir($this->tempDir . '/src/Math/V2', 0755, true);
        file_put_contents($this->tempDir . '/src/Math/V1/Calculator.php', $math1Code);
        file_put_contents($this->tempDir . '/src/Math/V2/Calculator.php', $math2Code);
        file_put_contents($this->tempDir . '/main.php', $mainCode);

        // 创建配置
        $config = [
            'entry' => 'main.php',
            'output' => 'packed.php',
            'include' => ['src/**/*.php'],
            'database' => 'test.db'
        ];

        $configPath = $this->tempDir . '/config.json';
        file_put_contents($configPath, json_encode($config));

        // 执行打包
        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);
        $packer->pack();

        // 验证输出
        $outputFile = $this->tempDir . '/packed.php';
        $this->assertFileExists($outputFile);

        $content = file_get_contents($outputFile);
        $this->assertStringContainsString('namespace App\\Math\\V1', $content);
        $this->assertStringContainsString('namespace App\\Math\\V2', $content);

        // 测试运行
        ob_start();
        include $outputFile;
        $output = ob_get_clean();
        $this->assertEquals('12,24', $output);
    }

    public function testDatabaseContent(): void
    {
        // 创建简单的测试文件
        $helperCode = '<?php
namespace Test;

class Helper 
{
    public function greet(): string 
    {
        return "Hello World";
    }
}';

        $mainCode = '<?php
use Test\Helper;

$helper = new Helper();
echo $helper->greet();';

        // 写入文件
        mkdir($this->tempDir . '/src', 0755, true);
        file_put_contents($this->tempDir . '/src/Helper.php', $helperCode);
        file_put_contents($this->tempDir . '/main.php', $mainCode);

        // 创建配置
        $config = [
            'entry' => 'main.php',
            'output' => 'packed.php',
            'include' => ['src/**/*.php'],
            'database' => 'test.db'
        ];

        $configPath = $this->tempDir . '/config.json';
        file_put_contents($configPath, json_encode($config));

        // 执行打包
        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);
        $packer->pack();

        // 验证数据库内容
        $dbPath = $this->tempDir . '/test.db';
        $this->assertFileExists($dbPath);

        $pdo = new \PDO('sqlite:' . $dbPath);

        // 检查文件表
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM files');
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertGreaterThan(0, $result['count']);

        // 检查符号表
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM symbols');
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertGreaterThan(0, $result['count']);

        // 检查依赖表
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM dependencies');
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertGreaterThan(0, $result['count']);

        // 检查AST表
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM ast_nodes');
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertGreaterThan(0, $result['count']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/php-packer-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->logger = new NullLogger();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}