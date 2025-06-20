<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Analyzer;

use PhpPacker\Analyzer\AutoloadResolver;
use PhpPacker\Storage\SqliteStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AutoloadResolverTest extends TestCase
{
    private AutoloadResolver $resolver;
    private SqliteStorage $storage;
    private LoggerInterface $logger;
    private string $dbPath;
    private string $tempDir;

    public function testLoadComposerAutoloadPsr4(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'Tests\\' => ['tests/', 'tests-legacy/']
                ]
            ]
        ];

        $this->createComposerJson($composerJson);
        $this->createDirectory('src/Controller');
        $this->createFile('src/Controller/HomeController.php', '<?php namespace App\\Controller; class HomeController {}');

        $this->resolver->loadComposerAutoload($this->tempDir . '/composer.json');

        $resolvedPath = $this->resolver->resolveClass('App\\Controller\\HomeController');
        $this->assertNotNull($resolvedPath);
        $this->assertStringEndsWith('src/Controller/HomeController.php', $resolvedPath);

        // Check autoload rules were saved
        $rules = $this->storage->getAutoloadRules();
        $psr4Rules = array_filter($rules, fn($r) => $r['type'] === 'psr4');
        $this->assertCount(3, $psr4Rules); // App\\ -> src/, Tests\\ -> tests/ and tests-legacy/
    }

    private function createComposerJson(array $data): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    private function createDirectory(string $path): void
    {
        $fullPath = $this->tempDir . '/' . $path;
        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0777, true);
        }
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

    public function testLoadComposerAutoloadPsr0(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-0' => [
                    'Legacy_' => 'lib/',
                    '' => 'fallback/'
                ]
            ]
        ];

        $this->createComposerJson($composerJson);
        $this->createDirectory('lib/Legacy/Database');
        $this->createFile('lib/Legacy/Database/Connection.php', '<?php class Legacy_Database_Connection {}');

        $this->resolver->loadComposerAutoload($this->tempDir . '/composer.json');

        $resolvedPath = $this->resolver->resolveClass('Legacy_Database_Connection');
        $this->assertNotNull($resolvedPath);
        $this->assertStringEndsWith('lib/Legacy/Database/Connection.php', $resolvedPath);
    }

    public function testLoadComposerAutoloadClassmap(): void
    {
        $composerJson = [
            'autoload' => [
                'classmap' => [
                    'lib/classes/',
                    'lib/single-file.php'
                ]
            ]
        ];

        $this->createComposerJson($composerJson);
        $this->createDirectory('lib/classes');
        $this->createFile('lib/classes/SomeClass.php', '<?php class SomeClass {}');
        $this->createFile('lib/classes/AnotherClass.php', '<?php namespace Lib; class AnotherClass {}');
        $this->createFile('lib/single-file.php', '<?php class SingleFileClass {}');

        $this->resolver->loadComposerAutoload($this->tempDir . '/composer.json');

        $this->assertNotNull($this->resolver->resolveClass('SomeClass'));
        $this->assertNotNull($this->resolver->resolveClass('Lib\\AnotherClass'));
        $this->assertNotNull($this->resolver->resolveClass('SingleFileClass'));
    }

    public function testLoadComposerAutoloadFiles(): void
    {
        $composerJson = [
            'autoload' => [
                'files' => [
                    'bootstrap/helpers.php',
                    'bootstrap/constants.php'
                ]
            ]
        ];

        $this->createComposerJson($composerJson);
        $this->createDirectory('bootstrap');
        $this->createFile('bootstrap/helpers.php', '<?php function helper() {}');
        $this->createFile('bootstrap/constants.php', '<?php define("APP_VERSION", "1.0");');

        $this->resolver->loadComposerAutoload($this->tempDir . '/composer.json');

        $files = $this->resolver->getRequiredFiles();
        $this->assertCount(2, $files);
        $this->assertStringEndsWith('bootstrap/helpers.php', $files[0]);
        $this->assertStringEndsWith('bootstrap/constants.php', $files[1]);
    }

    public function testLoadComposerAutoloadDev(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => ['App\\' => 'src/']
            ],
            'autoload-dev' => [
                'psr-4' => ['Tests\\' => 'tests/']
            ]
        ];

        $this->createComposerJson($composerJson);
        $this->resolver->loadComposerAutoload($this->tempDir . '/composer.json');

        $rules = $this->storage->getAutoloadRules();

        // Dev autoload should have lower priority
        $appRule = array_filter($rules, fn($r) => $r['prefix'] === 'App\\');
        $testRule = array_filter($rules, fn($r) => $r['prefix'] === 'Tests\\');

        $this->assertEquals(100, reset($appRule)['priority']);
        $this->assertEquals(50, reset($testRule)['priority']);
    }

    public function testResolveClassNotFound(): void
    {
        $this->createComposerJson(['autoload' => ['psr-4' => ['App\\' => 'src/']]]);
        $this->resolver->loadComposerAutoload($this->tempDir . '/composer.json');

        $result = $this->resolver->resolveClass('NonExistent\\Class');
        $this->assertNull($result);
    }

    public function testResolveClassWithMultiplePsr4Paths(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => ['src/', 'lib/']
                ]
            ]
        ];

        $this->createComposerJson($composerJson);
        $this->createDirectory('lib/Service');
        $this->createFile('lib/Service/UserService.php', '<?php namespace App\\Service; class UserService {}');

        $this->resolver->loadComposerAutoload($this->tempDir . '/composer.json');

        $resolvedPath = $this->resolver->resolveClass('App\\Service\\UserService');
        $this->assertNotNull($resolvedPath);
        $this->assertStringEndsWith('lib/Service/UserService.php', $resolvedPath);
    }

    public function testLoadVendorAutoload(): void
    {
        $this->createComposerJson(['autoload' => ['psr-4' => ['App\\' => 'src/']]]);

        // Create vendor directory structure
        $this->createDirectory('vendor/composer');
        $this->createDirectory('vendor/package/name/src');

        $installedJson = [
            'packages' => [
                [
                    'name' => 'package/name',
                    'autoload' => [
                        'psr-4' => ['Package\\' => 'src/']
                    ]
                ]
            ]
        ];

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode($installedJson)
        );

        $this->createFile(
            'vendor/package/name/src/SomeClass.php',
            '<?php namespace Package; class SomeClass {}'
        );

        $this->resolver->loadComposerAutoload($this->tempDir . '/composer.json');

        $resolvedPath = $this->resolver->resolveClass('Package\\SomeClass');
        $this->assertNotNull($resolvedPath);
        $this->assertStringContains('vendor/package/name/src/SomeClass.php', $resolvedPath);
    }

    public function testClassmapScanningWithComplexFile(): void
    {
        $composerJson = [
            'autoload' => [
                'classmap' => ['complex/']
            ]
        ];

        $this->createComposerJson($composerJson);
        $this->createDirectory('complex');

        // Create a complex file with multiple classes/interfaces/traits
        $complexFile = '<?php
namespace Complex\Name\Space;

interface FirstInterface {}

trait FirstTrait {}

abstract class AbstractClass {}

final class FinalClass extends AbstractClass implements FirstInterface {
    use FirstTrait;
}

// Global namespace
namespace {
    class GlobalClass {}
}';

        $this->createFile('complex/multiple.php', $complexFile);

        $this->resolver->loadComposerAutoload($this->tempDir . '/composer.json');

        $this->assertNotNull($this->resolver->resolveClass('Complex\\Name\\Space\\FirstInterface'));
        $this->assertNotNull($this->resolver->resolveClass('Complex\\Name\\Space\\FirstTrait'));
        $this->assertNotNull($this->resolver->resolveClass('Complex\\Name\\Space\\AbstractClass'));
        $this->assertNotNull($this->resolver->resolveClass('Complex\\Name\\Space\\FinalClass'));
        $this->assertNotNull($this->resolver->resolveClass('GlobalClass'));
    }

    public function testEmptyComposerJson(): void
    {
        $this->createComposerJson([]);

        $this->logger->expects($this->never())
            ->method('error');

        $this->resolver->loadComposerAutoload($this->tempDir . '/composer.json');

        $rules = $this->storage->getAutoloadRules();
        $this->assertCount(0, $rules);
    }

    public function testInvalidComposerJson(): void
    {
        file_put_contents($this->tempDir . '/composer.json', 'invalid json');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Invalid composer.json');

        $this->resolver->loadComposerAutoload($this->tempDir . '/composer.json');
    }

    public function testNonExistentComposerJson(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Composer.json not found');

        $this->resolver->loadComposerAutoload($this->tempDir . '/non-existent.json');
    }

    public function testPathNormalization(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => './src/../src/.//',
                    'Test\\' => 'tests\\\\'
                ]
            ]
        ];

        $this->createComposerJson($composerJson);
        $this->createDirectory('src');
        $this->createFile('src/Test.php', '<?php namespace App; class Test {}');

        $this->resolver->loadComposerAutoload($this->tempDir . '/composer.json');

        $resolvedPath = $this->resolver->resolveClass('App\\Test');
        $this->assertNotNull($resolvedPath);
        $this->assertStringEndsWith('src/Test.php', str_replace('\\', '/', $resolvedPath));
    }

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/php-packer-test-' . uniqid() . '.db';
        $this->tempDir = sys_get_temp_dir() . '/php-packer-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->storage = new SqliteStorage($this->dbPath, $this->logger);
        $this->resolver = new AutoloadResolver($this->storage, $this->logger, $this->tempDir);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }

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