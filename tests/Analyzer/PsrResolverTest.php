<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer;

use PhpPacker\Analyzer\ComposerConfigParser;
use PhpPacker\Analyzer\PsrResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(PsrResolver::class)]
final class PsrResolverTest extends TestCase
{
    private PsrResolver $resolver;

    protected function setUp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        /*
         * 使用具体类 ComposerConfigParser 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：ComposerConfigParser 没有对应的接口拽象，且 PsrResolver 构造函数直接依赖具体实现
         * 2) 这种使用是否合理和必要：在单元测试中合理，避免真实的 composer.json 文件解析，专注测试 PSR 解析逻辑
         * 3) 是否有更好的替代方案：为配置解析器定义接口会改善架构，但当前使用 mock 是有效的测试方法
         */
        $configParser = $this->createMock(ComposerConfigParser::class);
        $configParser->method('normalizePath')->willReturnCallback(function ($path) {
            $path = str_replace('\\', '/', $path);
            $normalizedPath = preg_replace('#/+#', '/', $path);
            $this->assertIsString($normalizedPath);

            $parts = explode('/', $normalizedPath);
            $absolute = [];

            foreach ($parts as $part) {
                if ('.' === $part) {
                    continue;
                }
                if ('..' === $part) {
                    array_pop($absolute);
                } else {
                    $absolute[] = $part;
                }
            }

            return implode('/', $absolute);
        });
        $this->resolver = new PsrResolver($logger, $configParser);
    }

    public function testResolvePsr4(): void
    {
        $this->resolver->addPsr4('App\\', 'src/');

        $controllerPaths = $this->resolver->resolvePossiblePaths('App\Controller\HomeController');
        $this->assertNotEmpty($controllerPaths, 'Should return possible paths for controller');
        $this->assertPathPatternExists(
            $controllerPaths,
            ['src', 'Controller', 'HomeController.php'],
            'PSR-4 controller path'
        );

        $servicePaths = $this->resolver->resolvePossiblePaths('App\Service\UserService');
        $this->assertNotEmpty($servicePaths, 'Should return possible paths for service');
        $this->assertPathPatternExists(
            $servicePaths,
            ['src', 'Service', 'UserService.php'],
            'PSR-4 service path'
        );
    }

    /**
     * @param array<int, string> $paths
     * @param array<int, string> $requiredParts
     */
    private function assertPathPatternExists(array $paths, array $requiredParts, string $description): void
    {
        foreach ($paths as $path) {
            $matches = true;
            foreach ($requiredParts as $part) {
                if (!str_contains($path, $part)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                $this->assertTrue(true, "Found expected {$description} pattern");

                return;
            }
        }
        self::fail("Did not find expected {$description} pattern in: " . implode(', ', $paths));
    }

    public function testResolvePsr4WithMultiplePaths(): void
    {
        $this->resolver->addPsr4('App\\', ['src/', 'lib/']);

        $paths = $this->resolver->resolvePossiblePaths('App\Entity\User');

        // 调试：查看实际返回的路径
        $this->assertGreaterThanOrEqual(2, count($paths), 'Expected at least 2 paths, got: ' . implode(', ', $paths));

        // 检查是否包含预期的路径（可能有不同的格式）
        $foundSrc = false;
        $foundLib = false;
        foreach ($paths as $path) {
            if (str_contains($path, 'src') && str_contains($path, 'Entity/User.php')) {
                $foundSrc = true;
            }
            if (str_contains($path, 'lib') && str_contains($path, 'Entity/User.php')) {
                $foundLib = true;
            }
        }

        $this->assertTrue($foundSrc, 'Did not find src path in: ' . implode(', ', $paths));
        $this->assertTrue($foundLib, 'Did not find lib path in: ' . implode(', ', $paths));
    }

    public function testResolvePsr4WithTrailingSlash(): void
    {
        $this->resolver->addPsr4('App\\', 'src/');
        $this->resolver->addPsr4('App\Tests\\', 'tests/');

        $possiblePaths = $this->resolver->resolvePossiblePaths('App\Tests\Unit\UserTest');

        // 检查是否包含预期路径模式
        $found = false;
        foreach ($possiblePaths as $path) {
            if (str_contains($path, 'tests') && str_contains($path, 'Unit') && str_contains($path, 'UserTest.php')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Did not find expected test path pattern in: ' . implode(', ', $possiblePaths));
    }

    public function testResolvePsr0(): void
    {
        $this->resolver->addPsr0Prefix('Legacy_', 'lib/');

        // 模拟文件存在的情况
        $configParser = $this->createMock(ComposerConfigParser::class);
        $configParser->method('normalizePath')
            ->willReturnCallback(function ($path) {
                return str_replace('\\', '/', $path);
            })
        ;

        $reflection = new \ReflectionProperty($this->resolver, 'configParser');
        $reflection->setAccessible(true);
        $reflection->setValue($this->resolver, $configParser);

        // 测试 PSR-0 解析逻辑
        $possiblePaths = $this->resolver->resolvePossiblePaths('Legacy_Database_Connection');

        // 调试输出
        $this->assertGreaterThan(0, count($possiblePaths), 'Expected at least one path, got: ' . implode(', ', $possiblePaths));

        // 检查是否包含预期路径或类似路径
        $found = false;
        foreach ($possiblePaths as $path) {
            if (str_contains($path, 'Legacy') && str_contains($path, 'Database') && str_contains($path, 'Connection.php')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Did not find expected PSR-0 path pattern in: ' . implode(', ', $possiblePaths));
    }

    public function testResolvePsr0WithNamespace(): void
    {
        $this->resolver->addPsr0Prefix('Vendor\Package\\', 'vendor/');

        // 测试 PSR-0 解析逻辑（使用 resolvePossiblePaths 而不是 resolve，因为文件不存在）
        $possiblePaths = $this->resolver->resolvePossiblePaths('Vendor\Package\Class');

        // 检查是否包含预期路径或类似路径
        $found = false;
        foreach ($possiblePaths as $path) {
            if (str_contains($path, 'vendor') && str_contains($path, 'Vendor') && str_contains($path, 'Package') && str_contains($path, 'Class.php')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Did not find expected PSR-0 namespace path pattern in: ' . implode(', ', $possiblePaths));
    }

    public function testResolveClassmap(): void
    {
        $this->resolver->addClassmap([
            'SpecialClass' => 'custom/path/to/SpecialClass.php',
            'Another\Special' => 'another/special.php',
        ]);

        // 测试可能的路径（不依赖文件存在）
        $possiblePaths = $this->resolver->resolvePossiblePaths('SpecialClass');
        $this->assertContains('custom/path/to/SpecialClass.php', $possiblePaths);

        $possiblePaths = $this->resolver->resolvePossiblePaths('Another\Special');
        $this->assertContains('another/special.php', $possiblePaths);
    }

    public function testResolveWithFallback(): void
    {
        $this->resolver->addPsr4('App\\', 'src/');
        $this->resolver->setFallbackDirs(['fallback/', 'vendor/']);

        // 应该先尝试 PSR-4
        $possiblePaths = $this->resolver->resolvePossiblePaths('App\Controller');
        $this->assertContains('src/Controller.php', $possiblePaths);

        // 未匹配的应该尝试 fallback
        $paths = $this->resolver->resolvePossiblePaths('Unknown\Class');
        $this->assertContains('fallback/Unknown/Class.php', $paths);
        $this->assertContains('vendor/Unknown/Class.php', $paths);
    }

    public function testResolveUnknownClass(): void
    {
        $path = $this->resolver->resolve('Unknown\Namespace\Class');
        $this->assertNull($path);
    }

    public function testGetNamespaces(): void
    {
        $this->resolver->addPsr4('App\\', 'src/');
        $this->resolver->addPsr4('App\Tests\\', 'tests/');
        $this->resolver->addPsr0Prefix('Legacy_', 'lib/');

        $namespaces = $this->resolver->getNamespaces();

        $this->assertContains('App', $namespaces);
        $this->assertContains('App\Tests', $namespaces);
        $this->assertContains('Legacy_', $namespaces);
    }

    public function testResolveWithBaseDir(): void
    {
        $this->resolver->setBaseDir('/project/');
        $this->resolver->addPsr4('App\\', 'src/');

        // 测试可能的路径生成逻辑（不依赖文件存在）
        $possiblePaths = $this->resolver->resolvePossiblePaths('App\Model\User');
        $this->assertContains('/project/src/Model/User.php', $possiblePaths);
    }

    public function testClearMappings(): void
    {
        $this->resolver->addPsr4('App\\', 'src/');
        $this->resolver->addClassmap(['Test' => 'test.php']);

        $this->resolver->clearMappings();

        $this->assertNull($this->resolver->resolve('App\Test'));
        $this->assertNull($this->resolver->resolve('Test'));
    }

    public function testAddClassmap(): void
    {
        $classmap = [
            'MyClass' => '/path/to/MyClass.php',
            'Another\Class' => '/path/to/Another/Class.php',
        ];

        $this->resolver->addClassmap($classmap);

        $paths = $this->resolver->resolvePossiblePaths('MyClass');
        $this->assertContains('/path/to/MyClass.php', $paths);

        $paths = $this->resolver->resolvePossiblePaths('Another\Class');
        $this->assertContains('/path/to/Another/Class.php', $paths);
    }

    public function testAddPsr0Prefix(): void
    {
        $this->resolver->addPsr0Prefix('Vendor_', 'lib/');

        $paths = $this->resolver->resolvePossiblePaths('Vendor_Package_Class');

        $found = false;
        foreach ($paths as $path) {
            if (str_contains($path, 'lib') && str_contains($path, 'Vendor') && str_contains($path, 'Class.php')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }

    public function testAddPsr4(): void
    {
        $this->resolver->addPsr4('App\\', ['src/', 'app/']);

        $psr4Prefixes = $this->resolver->getPsr4Prefixes();

        $this->assertArrayHasKey('App\\', $psr4Prefixes);
        $this->assertContains('src/', $psr4Prefixes['App\\']);
        $this->assertContains('app/', $psr4Prefixes['App\\']);
    }

    public function testAddPsr4Prefix(): void
    {
        $this->resolver->addPsr4Prefix('Test\\', 'tests/');

        $psr4Prefixes = $this->resolver->getPsr4Prefixes();

        $this->assertArrayHasKey('Test\\', $psr4Prefixes);
        $this->assertContains('tests/', $psr4Prefixes['Test\\']);
    }

    public function testResolvePossiblePaths(): void
    {
        $this->resolver->addPsr4('App\\', 'src/');
        $this->resolver->addPsr0Prefix('Legacy_', 'lib/');
        $this->resolver->addClassmap(['SpecialClass' => 'special.php']);

        $paths = $this->resolver->resolvePossiblePaths('App\Controller\HomeController');
        $this->assertNotEmpty($paths);

        $paths = $this->resolver->resolvePossiblePaths('Legacy_Database');
        $this->assertNotEmpty($paths);

        $paths = $this->resolver->resolvePossiblePaths('SpecialClass');
        $this->assertContains('special.php', $paths);
    }
}
