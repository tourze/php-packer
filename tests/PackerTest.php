<?php

namespace PhpPacker\Tests;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Adapter\DependencyAnalyzerAdapter;
use PhpPacker\Adapter\ReflectionServiceAdapter;
use PhpPacker\Adapter\ResourceManagerAdapter;
use PhpPacker\Ast\AstManager;
use PhpPacker\Ast\ParserFactory;
use PhpPacker\Generator\CodeGenerator;
use PhpPacker\Packer;
use PhpPacker\Parser\CodeParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \PhpPacker\Packer
 */
class PackerTest extends TestCase
{
    private LoggerInterface $logger;
    private ConfigurationAdapter $config;
    private string $configPath;

    protected function setUp(): void
    {
        // 创建配置文件路径
        $this->configPath = __DIR__ . '/data/config.php';

        // 创建测试配置文件
        $configData = [
            'entry' => __DIR__ . '/data/entry.php',
            'output' => __DIR__ . '/data/output/packed.php',
            'output_dir' => __DIR__ . '/data/output',
            'clean_output' => true,
            'minify' => false,
            'comments' => true,
            'debug' => true,
            'assets' => [],
            'exclude' => [],
            'source_paths' => [__DIR__ . '/data']
        ];

        // 创建测试目录
        if (!is_dir(__DIR__ . '/data')) {
            mkdir(__DIR__ . '/data', 0777, true);
        }
        if (!is_dir(__DIR__ . '/data/output')) {
            mkdir(__DIR__ . '/data/output', 0777, true);
        }

        // 写入PHP格式的配置文件
        $phpConfig = '<?php return ' . var_export($configData, true) . ';';
        file_put_contents($this->configPath, $phpConfig);

        // 创建测试入口文件
        $entryContent = <<<'PHP'
<?php
// 测试入口文件
echo "Hello World";

function test() {
    return "This is a test function";
}
PHP;
        file_put_contents(__DIR__ . '/data/entry.php', $entryContent);

        // 创建日志记录器
        $this->logger = new NullLogger();

        // 创建配置适配器
        $this->config = new ConfigurationAdapter($this->configPath, $this->logger);
    }

    protected function tearDown(): void
    {
        // 清理测试文件
        if (file_exists($this->configPath)) {
            unlink($this->configPath);
        }
        if (file_exists(__DIR__ . '/data/entry.php')) {
            unlink(__DIR__ . '/data/entry.php');
        }
        if (file_exists(__DIR__ . '/data/output/packed.php')) {
            unlink(__DIR__ . '/data/output/packed.php');
        }

        // 删除测试目录
        if (is_dir(__DIR__ . '/data/output')) {
            rmdir(__DIR__ . '/data/output');
        }
        if (is_dir(__DIR__ . '/data')) {
            rmdir(__DIR__ . '/data');
        }
    }

    public function testPacker(): void
    {
        // 创建Packer实例
        $packer = new Packer($this->config, $this->logger);

        // 测试打包功能
        $packer->pack();

        // 验证输出文件是否存在
        $this->assertFileExists(__DIR__ . '/data/output/packed.php');

        // 验证输出文件内容是否正确
        $content = file_get_contents(__DIR__ . '/data/output/packed.php');
        $this->assertStringContainsString('Hello World', $content);
        $this->assertStringContainsString('function test', $content);
    }

    public function testAdapterIntegration(): void
    {
        // 测试适配器是否正确集成了子包功能
        $reflectionService = new ReflectionServiceAdapter($this->config, $this->logger);
        $astManager = new AstManager($this->logger);
        $analyzer = new DependencyAnalyzerAdapter($astManager, $reflectionService, $this->logger);
        $resourceManager = new ResourceManagerAdapter($this->config, $this->logger);

        // 测试AstManager功能
        $entryFile = __DIR__ . '/data/entry.php';
        $content = file_get_contents($entryFile);

        // 使用PHP-Parser解析代码
        $parser = ParserFactory::createPhp81Parser();
        $ast = $parser->parse($content);

        // 使用AstManager添加AST
        $astManager->addAst($entryFile, $ast);
        $this->assertTrue($astManager->hasAst($entryFile));

        // 测试ResourceManager功能
        $isResource = $resourceManager->isResourceFile($entryFile);
        $this->assertFalse($isResource);

        // 测试DependencyAnalyzer功能
        $files = $analyzer->getOptimizedFileOrder($entryFile);
        $this->assertContains($entryFile, $files);
    }

    public function testPackerComponents(): void
    {
        // 测试Packer中直接使用的各个组件
        $packer = new Packer($this->config, $this->logger);

        // 使用反射获取私有属性
        $reflection = new \ReflectionClass($packer);

        $parserProp = $reflection->getProperty('parser');
        $parserProp->setAccessible(true);
        $parser = $parserProp->getValue($packer);

        $generatorProp = $reflection->getProperty('generator');
        $generatorProp->setAccessible(true);
        $generator = $generatorProp->getValue($packer);

        // 验证组件类型
        $this->assertInstanceOf(CodeParser::class, $parser);
        $this->assertInstanceOf(CodeGenerator::class, $generator);

        // 验证组件功能
        $entryFile = $this->config->getEntryFile();
        $this->assertEquals(__DIR__ . '/data/entry.php', $entryFile);
    }
}
