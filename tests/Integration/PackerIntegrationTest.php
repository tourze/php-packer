<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Integration;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\LegacyPacker as Packer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PackerIntegrationTest extends TestCase
{
    private string $tempDir;
    private LoggerInterface $logger;

    public function testPackSimpleProject(): void
    {
        // Create a simple project structure
        $this->createFile('index.php', '<?php
require_once "src/bootstrap.php";

use App\\Application;

$app = new Application();
echo $app->run();
');

        $this->createFile('src/bootstrap.php', '<?php
define("APP_ROOT", dirname(__DIR__));

spl_autoload_register(function ($class) {
    $prefix = "App\\\\";
    $baseDir = APP_ROOT . "/src/";
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace("\\\\", "/", $relativeClass) . ".php";
    
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
        return "Hello from packed application!\\n";
    }
}
');

        // Create config
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
            'database' => 'build/packer.db',
            'optimization' => [
                'remove_comments' => true
            ]
        ];

        $configPath = $this->createJsonConfig($config);

        // Run packer
        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);
        $packer->pack();

        // Verify output
        $outputPath = $this->tempDir . '/packed.php';
        $this->assertFileExists($outputPath);

        // Execute packed file
        $output = $this->executePhp($outputPath);
        $this->assertEquals("Hello from packed application!\n", $output);
    }

    private function createFile(string $path, string $content): void
    {
        $fullPath = $this->tempDir . '/' . $path;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, $content);
    }

    private function createJsonConfig(array $config): string
    {
        $path = $this->tempDir . '/config.json';
        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT));
        return $path;
    }

    private function executePhp(string $file): string
    {
        $output = shell_exec(PHP_BINARY . ' ' . escapeshellarg($file) . ' 2>&1');
        return $output ?? '';
    }

    public function testPackProjectWithDependencies(): void
    {
        // Create project with multiple dependencies
        $this->createFile('index.php', '<?php
require_once "vendor/autoload.php";

use App\\Controller\\HomeController;
use App\\Service\\GreetingService;

$service = new GreetingService();
$controller = new HomeController($service);
echo $controller->index();
');

        $this->createFile('src/Controller/HomeController.php', '<?php
namespace App\\Controller;

use App\\Service\\GreetingService;

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
namespace App\\Controller;

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
namespace App\\Service;

use App\\Traits\\FormatterTrait;

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
namespace App\\Service;

interface ServiceInterface
{
    public function greet(string $name): string;
}
');

        $this->createFile('src/Traits/FormatterTrait.php', '<?php
namespace App\\Traits;

trait FormatterTrait
{
    protected function format(string $text): string
    {
        return strtoupper($text) . "\\n";
    }
}
');

        // Create composer.json
        $composer = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/'
                ]
            ]
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        // Create simple autoloader
        $this->createFile('vendor/autoload.php', '<?php
spl_autoload_register(function ($class) {
    $prefix = "App\\\\";
    $baseDir = __DIR__ . "/../src/";
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace("\\\\", "/", $relativeClass) . ".php";
    
    if (file_exists($file)) {
        require $file;
    }
});
');

        // Create config
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php'
        ];

        $configPath = $this->createJsonConfig($config);

        // Run packer
        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);
        $packer->pack();

        // Execute packed file
        $output = $this->executePhp($this->tempDir . '/packed.php');
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
        return "Main App\\n";
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
                '**/tests/**'
            ]
        ];

        $configPath = $this->createJsonConfig($config);

        // Run packer
        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);
        $packer->pack();

        // Check packed content doesn't include test files
        $packedContent = file_get_contents($this->tempDir . '/packed.php');
        $this->assertStringNotContainsString('AppTest', $packedContent);
        $this->assertStringNotContainsString('TestCase', $packedContent);

        // But should include main files
        $this->assertStringContainsString('class App', $packedContent);

        // Execute should work
        $output = $this->executePhp($this->tempDir . '/packed.php');
        $this->assertEquals("Main App\n", $output);
    }

    public function testPackWithConditionalIncludes(): void
    {
        $this->createFile('index.php', '<?php
if (PHP_VERSION_ID >= 80000) {
    require "php8.php";
} else {
    require "php7.php";
}

echo getVersion();
');

        $this->createFile('php8.php', '<?php
function getVersion(): string {
    return "Running on PHP 8+\\n";
}
');

        $this->createFile('php7.php', '<?php
function getVersion(): string {
    return "Running on PHP 7\\n";
}
');

        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php'
        ];

        $configPath = $this->createJsonConfig($config);

        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);
        $packer->pack();

        // Both files should be included
        $packedContent = file_get_contents($this->tempDir . '/packed.php');
        $this->assertStringContainsString('Running on PHP 8+', $packedContent);
        $this->assertStringContainsString('Running on PHP 7', $packedContent);

        // Execute should work based on current PHP version
        $output = $this->executePhp($this->tempDir . '/packed.php');
        if (PHP_VERSION_ID >= 80000) {
            $this->assertEquals("Running on PHP 8+\n", $output);
        } else {
            $this->assertEquals("Running on PHP 7\n", $output);
        }
    }

    public function testPackEmptyProject(): void
    {
        $this->createFile('index.php', '<?php echo "Hello\\n";');

        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php'
        ];

        $configPath = $this->createJsonConfig($config);

        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);
        $packer->pack();

        $output = $this->executePhp($this->tempDir . '/packed.php');
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
echo $a->test() . "\\n";
');

        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php'
        ];

        $configPath = $this->createJsonConfig($config);

        // Should handle circular dependencies without infinite loop
        $adapter = new ConfigurationAdapter($configPath, $this->logger);
        $packer = new Packer($adapter, $this->logger);
        $packer->pack();

        $this->assertFileExists($this->tempDir . '/packed.php');
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/php-packer-integration-' . uniqid();
        mkdir($this->tempDir, 0777, true);

        $this->logger = $this->createMock(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        
        rmdir($dir);
    }
}