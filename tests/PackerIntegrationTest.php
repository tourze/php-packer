<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Integration;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Packer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(Packer::class)]
final class PackerIntegrationTest extends TestCase
{
    private static string $tempDir;

    private LoggerInterface $logger;

    public function testPackSimpleProject(): void
    {
        // Create a simple project structure
        $this->createFile('index.php', '<?php
require_once "src/bootstrap.php";

use App\Application;

$app = new Application();
echo $app->run();
');

        $this->createFile('src/bootstrap.php', '<?php
define("APP_ROOT", dirname(__DIR__));

spl_autoload_register(function ($class) {
    $prefix = "App\\\";
    $baseDir = APP_ROOT . "/src/";
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace("\\\", "/", $relativeClass) . ".php";
    
    if (file_exists($file)) {
        require $file;
    }
});
');

        $this->createFile('src/Application.php', '<?php
namespace App;

class Application
{
    public function run(): string
    {
        return "Hello from packed application!\n";
    }
}
');

        // Create config
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
            'database' => 'build/packer.db',
            'optimization' => [
                'remove_comments' => true,
            ],
        ];

        $configPath = $this->createJsonConfig($config);

        // Run packer
        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);
        $packer->pack();

        // Verify output
        $outputPath = self::$tempDir . '/packed.php';
        $this->assertFileExists($outputPath);

        // Execute packed file
        $output = $this->executePhp($outputPath);
        $this->assertEquals("Hello from packed application!\n", $output);
    }

    private function createFile(string $path, string $content): void
    {
        $fullPath = self::$tempDir . '/' . $path;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($fullPath, $content);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createJsonConfig(array $config): string
    {
        $path = self::$tempDir . '/config.json';
        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT));

        return $path;
    }

    private function executePhp(string $file): string
    {
        $output = shell_exec(PHP_BINARY . ' ' . escapeshellarg($file) . ' 2>&1');

        return is_string($output) ? $output : '';
    }

    public function testPackProjectWithDependencies(): void
    {
        // Create project with multiple dependencies
        $this->createFile('index.php', '<?php
require_once "vendor/autoload.php";

use App\Controller\HomeController;
use App\Service\GreetingService;

$service = new GreetingService();
$controller = new HomeController($service);
echo $controller->index();
');

        $this->createFile('src/Controller/HomeController.php', '<?php
namespace App\Controller;

use App\Service\GreetingService;

class HomeController extends BaseController
{
    private GreetingService $service;
    
    public function __construct(GreetingService $service)
    {
        parent::__construct();
        $this->service = $service;
    }
    
    public function index(): string
    {
        return $this->service->greet("World");
    }
}
');

        $this->createFile('src/Controller/BaseController.php', '<?php
namespace App\Controller;

abstract class BaseController
{
    protected array $config;
    
    public function __construct()
    {
        $this->config = ["version" => "1.0"];
    }
}
');

        $this->createFile('src/Service/GreetingService.php', '<?php
namespace App\Service;

use App\Traits\FormatterTrait;

class GreetingService implements ServiceInterface
{
    use FormatterTrait;
    
    public function greet(string $name): string
    {
        return $this->format("Hello, " . $name . "!");
    }
}
');

        $this->createFile('src/Service/ServiceInterface.php', '<?php
namespace App\Service;

interface ServiceInterface
{
    public function greet(string $name): string;
}
');

        $this->createFile('src/Traits/FormatterTrait.php', '<?php
namespace App\Traits;

trait FormatterTrait
{
    protected function format(string $text): string
    {
        return strtoupper($text) . "\n";
    }
}
');

        // Create composer.json
        $composer = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
        ];
        file_put_contents(self::$tempDir . '/composer.json', json_encode($composer));

        // Create simple autoloader
        $this->createFile('vendor/autoload.php', '<?php
spl_autoload_register(function ($class) {
    $prefix = "App\\\";
    $baseDir = __DIR__ . "/../src/";
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace("\\\", "/", $relativeClass) . ".php";
    
    if (file_exists($file)) {
        require $file;
    }
});
');

        // Create config
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
        ];

        $configPath = $this->createJsonConfig($config);

        // Run packer
        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);
        $packer->pack();

        // Execute packed file
        $output = $this->executePhp(self::$tempDir . '/packed.php');
        $this->assertEquals("HELLO, WORLD!\n", $output);
    }

    public function testPackWithExcludePatterns(): void
    {
        // Create files
        $this->createFile('index.php', '<?php
require_once "src/App.php";
$app = new App();
echo $app->getName();
');

        $this->createFile('src/App.php', '<?php
class App {
    public function getName(): string {
        return "Main App\n";
    }
}
');

        $this->createFile('src/App.test.php', '<?php
class AppTest {
    public function test() {}
}
');

        $this->createFile('tests/TestCase.php', '<?php
class TestCase {}
');

        // Config with exclude patterns
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
            'exclude_patterns' => [
                '**/*.test.php',
                '**/tests/**',
            ],
        ];

        $configPath = $this->createJsonConfig($config);

        // Run packer
        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);
        $packer->pack();

        // Check packed content doesn't include test files
        $packedContent = file_get_contents(self::$tempDir . '/packed.php');
        if (false === $packedContent) {
            self::fail('Failed to read packed file');
        }
        $this->assertStringNotContainsString('AppTest', $packedContent);
        $this->assertStringNotContainsString('TestCase', $packedContent);

        // But should include main files
        $this->assertStringContainsString('class App', $packedContent);

        // Execute should work
        $output = $this->executePhp(self::$tempDir . '/packed.php');
        $this->assertEquals("Main App\n", $output);
    }

    /**
     * 注意：当前不支持条件包含功能 - 见 issue #930
     * 此测试被删除，因为条件包含是设计限制，不是 bug
     */
    public function testPackEmptyProject(): void
    {
        $this->createFile('index.php', '<?php echo "Hello\n";');

        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
        ];

        $configPath = $this->createJsonConfig($config);

        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);
        $packer->pack();

        $output = $this->executePhp(self::$tempDir . '/packed.php');
        $this->assertEquals("Hello\n", $output);
    }

    public function testPackWithCircularDependencies(): void
    {
        $this->createFile('a.php', '<?php
require_once "b.php";
class A {
    public function test() {
        return "A";
    }
}
');

        $this->createFile('b.php', '<?php
require_once "c.php";
class B extends C {
    public function test() {
        return "B";
    }
}
');

        $this->createFile('c.php', '<?php
require_once "a.php"; // Circular dependency
class C {
    public function test() {
        return "C";
    }
}
');

        $this->createFile('index.php', '<?php
require_once "a.php";
$a = new A();
echo $a->test() . "\n";
');

        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
        ];

        $configPath = $this->createJsonConfig($config);

        // Should handle circular dependencies without infinite loop
        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);
        $packer->pack();

        $this->assertFileExists(self::$tempDir . '/packed.php');
    }

    protected function setUp(): void
    {
        self::$tempDir = sys_get_temp_dir() . '/php-packer-integration-' . uniqid();
        mkdir(self::$tempDir, 0o777, true);

        $this->logger = $this->createMock(LoggerInterface::class);
    }
}
