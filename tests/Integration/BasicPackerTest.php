<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Integration;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Packer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class BasicPackerTest extends TestCase
{
    private string $tempDir;
    private string $outputPath;

    public function testBasicPacking(): void
    {
        // Create a simple project structure
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0777, true);

        // Create entry file
        file_put_contents($this->tempDir . '/index.php', '<?php
require_once "src/App.php";

$app = new App\Application();
$app->run();
');

        // Create application file
        file_put_contents($srcDir . '/App.php', '<?php
namespace App;

class Application
{
    public function run(): void
    {
        echo "Application is running!";
    }
}
');

        // Create configuration
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
            'include' => [
                '*.php',
                'src/**/*.php'
            ],
            'exclude' => [
                'vendor/**',
                'tests/**'
            ]
        ];

        $configPath = $this->tempDir . '/packer.json';
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));

        // Run packer
        $adapter = new ConfigurationAdapter($configPath, new NullLogger());
        $packer = new Packer($adapter, new NullLogger());
        $packer->pack();

        // Verify output exists
        $this->assertFileExists($this->outputPath);

        // Verify output contains expected content
        $content = file_get_contents($this->outputPath);
        $this->assertStringContainsString('Application', $content);
        $this->assertStringContainsString('namespace App', $content);

        // Test that packed file can be executed
        $output = shell_exec("cd {$this->tempDir} && php packed.php 2>&1");
        $this->assertStringContainsString('Application is running!', $output);
    }

    public function testPackingWithDependencies(): void
    {
        // Create project structure
        $srcDir = $this->tempDir . '/src';
        $libDir = $this->tempDir . '/lib';
        mkdir($srcDir, 0777, true);
        mkdir($libDir, 0777, true);

        // Create entry file
        file_put_contents($this->tempDir . '/index.php', '<?php
require_once "src/App.php";

use App\Application;

$app = new Application();
echo $app->process();
');

        // Create application with dependency
        file_put_contents($srcDir . '/App.php', '<?php
namespace App;

require_once __DIR__ . "/../lib/Helper.php";

use Lib\Helper;

class Application
{
    public function process(): string
    {
        $helper = new Helper();
        return "App: " . $helper->getMessage();
    }
}
');

        // Create helper library
        file_put_contents($libDir . '/Helper.php', '<?php
namespace Lib;

class Helper
{
    public function getMessage(): string
    {
        return "Hello from Helper!";
    }
}
');

        // Create configuration
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
            'include' => [
                '**/*.php'
            ],
            'exclude' => [
                'vendor/**',
                'packed.php'
            ]
        ];

        $configPath = $this->tempDir . '/packer.json';
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));

        // Run packer
        $adapter = new ConfigurationAdapter($configPath, new NullLogger());
        $packer = new Packer($adapter, new NullLogger());
        $packer->pack();

        // Verify output
        $this->assertFileExists($this->outputPath);

        // Test execution
        $output = shell_exec("cd {$this->tempDir} && php packed.php 2>&1");
        $this->assertStringContainsString('App: Hello from Helper!', $output);
    }

    public function testPackingWithNamespaces(): void
    {
        // Create complex namespace structure
        $dirs = [
            $this->tempDir . '/src/Models',
            $this->tempDir . '/src/Services',
            $this->tempDir . '/src/Controllers',
        ];

        foreach ($dirs as $dir) {
            mkdir($dir, 0777, true);
        }

        // Entry file
        file_put_contents($this->tempDir . '/index.php', '<?php
require_once "src/bootstrap.php";

use App\Controllers\MainController;

$controller = new MainController();
$controller->handle();
');

        // Bootstrap
        file_put_contents($this->tempDir . '/src/bootstrap.php', '<?php
// Bootstrap file
require_once __DIR__ . "/Models/User.php";
require_once __DIR__ . "/Services/UserService.php";
require_once __DIR__ . "/Controllers/MainController.php";
');

        // Model
        file_put_contents($this->tempDir . '/src/Models/User.php', '<?php
namespace App\Models;

class User
{
    private string $name;
    
    public function __construct(string $name)
    {
        $this->name = $name;
    }
    
    public function getName(): string
    {
        return $this->name;
    }
}
');

        // Service
        file_put_contents($this->tempDir . '/src/Services/UserService.php', '<?php
namespace App\Services;

use App\Models\User;

class UserService
{
    public function createUser(string $name): User
    {
        return new User($name);
    }
}
');

        // Controller
        file_put_contents($this->tempDir . '/src/Controllers/MainController.php', '<?php
namespace App\Controllers;

use App\Services\UserService;

class MainController
{
    private UserService $userService;
    
    public function __construct()
    {
        $this->userService = new UserService();
    }
    
    public function handle(): void
    {
        $user = $this->userService->createUser("Test User");
        echo "Created user: " . $user->getName();
    }
}
');

        // Configuration
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
            'include' => ['**/*.php'],
            'exclude' => ['vendor/**', 'tests/**', 'packed.php']
        ];

        $configPath = $this->tempDir . '/packer.json';
        file_put_contents($configPath, json_encode($config));

        // Run packer
        $adapter = new ConfigurationAdapter($configPath, new NullLogger());
        $packer = new Packer($adapter, new NullLogger());
        $packer->pack();

        // Verify and test
        $this->assertFileExists($this->outputPath);

        $output = shell_exec("cd {$this->tempDir} && php packed.php 2>&1");
        $this->assertStringContainsString('Created user: Test User', $output);
    }

    public function testSkipVendorFiles(): void
    {
        // Create vendor directory
        $vendorDir = $this->tempDir . '/vendor/package';
        mkdir($vendorDir, 0777, true);

        // Create vendor file
        file_put_contents($vendorDir . '/VendorClass.php', '<?php
namespace Vendor\Package;

class VendorClass
{
    public function vendorMethod(): string
    {
        return "vendor";
    }
}
');

        // Create project file
        file_put_contents($this->tempDir . '/app.php', '<?php
echo "This is the app";
');

        // Configuration
        $config = [
            'entry' => 'app.php',
            'output' => 'packed.php',
            'include' => ['**/*.php']
        ];

        $configPath = $this->tempDir . '/packer.json';
        file_put_contents($configPath, json_encode($config));

        // Run packer
        $adapter = new ConfigurationAdapter($configPath, new NullLogger());
        $packer = new Packer($adapter, new NullLogger());
        $packer->pack();

        // Check database to verify vendor file handling
        $dbPath = $this->tempDir . '/build/packer.db';
        $this->assertFileExists($dbPath);

        $pdo = new \PDO('sqlite:' . $dbPath);
        $stmt = $pdo->query("SELECT * FROM files WHERE path LIKE '%vendor%'");
        $vendorFile = $stmt->fetch();

        if ($vendorFile !== false) {
            $this->assertEquals(1, $vendorFile['is_vendor']);
            $this->assertEquals(1, $vendorFile['skip_ast']);
            $this->assertNull($vendorFile['ast_root_id']);
        }
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/packer_test_' . uniqid();
        $this->outputPath = $this->tempDir . '/packed.php';
        mkdir($this->tempDir, 0777, true);
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

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}