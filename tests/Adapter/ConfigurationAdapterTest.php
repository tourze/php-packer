<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Adapter;

use PhpPacker\Adapter\ConfigurationAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(ConfigurationAdapter::class)]
final class ConfigurationAdapterTest extends TestCase
{
    private NullLogger $logger;

    private string $tempDir;

    public function testLoadValidJsonConfig(): void
    {
        $config = [
            'entry' => 'src/index.php',
            'output' => 'dist/packed.php',
            'database' => 'build/packer.db',
            'include_paths' => ['src/', 'lib/'],
            'exclude_patterns' => ['**/tests/**'],
            'optimization' => [
                'remove_comments' => true,
            ],
        ];

        $configPath = $this->createJsonConfig($config);

        $adapter = new ConfigurationAdapter($configPath, $this->logger);

        $this->assertEquals('src/index.php', $adapter->get('entry'));
        $this->assertEquals('dist/packed.php', $adapter->get('output'));
        $this->assertEquals('build/packer.db', $adapter->get('database'));
        $this->assertTrue($adapter->get('optimization.remove_comments'));
        $this->assertEquals($this->tempDir, $adapter->getRootPath());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createJsonConfig(array $config): string
    {
        $path = $this->tempDir . '/config.json';
        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT));

        return $path;
    }

    public function testRequiredFields(): void
    {
        $config = [
            'entry' => 'index.php',
            // Missing 'output'
        ];

        $configPath = $this->createJsonConfig($config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Required configuration field missing: output');

        new ConfigurationAdapter($configPath, $this->logger);
    }

    public function testDefaultValues(): void
    {
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
        ];

        $configPath = $this->createJsonConfig($config);
        $adapter = new ConfigurationAdapter($configPath, $this->logger);

        // Check default optimization settings
        $this->assertFalse($adapter->get('optimization.remove_comments'));
        $this->assertFalse($adapter->get('optimization.remove_whitespace'));
        $this->assertTrue($adapter->get('optimization.inline_includes'));

        // Check default runtime settings
        $this->assertEquals('E_ALL', $adapter->get('runtime.error_reporting'));
        $this->assertEquals('256M', $adapter->get('runtime.memory_limit'));
        $this->assertEquals('UTC', $adapter->get('runtime.timezone'));
    }

    public function testGetWithDefault(): void
    {
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
        ];

        $configPath = $this->createJsonConfig($config);
        $adapter = new ConfigurationAdapter($configPath, $this->logger);

        $this->assertEquals('default_value', $adapter->get('non.existent.key', 'default_value'));
        $this->assertNull($adapter->get('another.missing.key'));
    }

    public function testSetValue(): void
    {
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
        ];

        $configPath = $this->createJsonConfig($config);
        $adapter = new ConfigurationAdapter($configPath, $this->logger);

        $adapter->set('new.nested.value', 'test');
        $this->assertEquals('test', $adapter->get('new.nested.value'));

        $adapter->set('entry', 'new-entry.php');
        $this->assertEquals('new-entry.php', $adapter->get('entry'));
    }

    public function testAll(): void
    {
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
            'custom' => 'value',
        ];

        $configPath = $this->createJsonConfig($config);
        $adapter = new ConfigurationAdapter($configPath, $this->logger);

        $all = $adapter->all();

        $this->assertArrayHasKey('entry', $all);
        $this->assertArrayHasKey('output', $all);
        $this->assertArrayHasKey('custom', $all);
        $this->assertArrayHasKey('optimization', $all);
        $this->assertArrayHasKey('runtime', $all);
    }

    public function testInvalidJsonFile(): void
    {
        $configPath = $this->tempDir . '/invalid.json';
        file_put_contents($configPath, 'invalid json content');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON configuration');

        new ConfigurationAdapter($configPath, $this->logger);
    }

    public function testNonExistentFile(): void
    {
        $configPath = $this->tempDir . '/non-existent.json';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuration file not found');

        new ConfigurationAdapter($configPath, $this->logger);
    }

    public function testUnsupportedFileFormat(): void
    {
        $configPath = $this->tempDir . '/config.xml';
        file_put_contents($configPath, '<config></config>');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only JSON configuration files are supported. Got: xml');

        new ConfigurationAdapter($configPath, $this->logger);
    }

    public function testGetIncludePaths(): void
    {
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
            'include_paths' => ['src/', '/absolute/path', '../relative/path'],
        ];

        $configPath = $this->createJsonConfig($config);
        $adapter = new ConfigurationAdapter($configPath, $this->logger);

        $paths = $adapter->getIncludePaths();

        $this->assertCount(3, $paths);
        $this->assertEquals($this->tempDir . '/src/', $paths[0]);
        $this->assertEquals($this->tempDir . '/absolute/path', $paths[1]);
        $this->assertEquals($this->tempDir . '/../relative/path', $paths[2]);
    }

    public function testGetDefaultIncludePaths(): void
    {
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
        ];

        $configPath = $this->createJsonConfig($config);
        $adapter = new ConfigurationAdapter($configPath, $this->logger);

        $paths = $adapter->getIncludePaths();

        $this->assertCount(1, $paths);
        $this->assertEquals($this->tempDir . '/./', $paths[0]);
    }

    public function testGetExcludePatterns(): void
    {
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
            'exclude_patterns' => ['custom/**', '*.test.php'],
        ];

        $configPath = $this->createJsonConfig($config);
        $adapter = new ConfigurationAdapter($configPath, $this->logger);

        $patterns = $adapter->getExcludePatterns();

        $this->assertContains('custom/**', $patterns);
        $this->assertContains('*.test.php', $patterns);
    }

    public function testGetDefaultExcludePatterns(): void
    {
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
        ];

        $configPath = $this->createJsonConfig($config);
        $adapter = new ConfigurationAdapter($configPath, $this->logger);

        $patterns = $adapter->getExcludePatterns();

        $this->assertContains('**/tests/**', $patterns);
        $this->assertContains('**/Tests/**', $patterns);
        $this->assertContains('**/*Test.php', $patterns);
        $this->assertContains('**/vendor/**', $patterns);
    }

    public function testShouldExclude(): void
    {
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
            'exclude_patterns' => [
                '**/tests/**',
                '*.test.php',
                'config/*.local.php',
            ],
        ];

        $configPath = $this->createJsonConfig($config);
        $adapter = new ConfigurationAdapter($configPath, $this->logger);

        // Should exclude
        $this->assertTrue($adapter->shouldExclude('src/tests/TestCase.php'));
        $this->assertTrue($adapter->shouldExclude('unit.test.php'));
        $this->assertTrue($adapter->shouldExclude('config/database.local.php'));

        // Should not exclude
        $this->assertFalse($adapter->shouldExclude('src/Service.php'));
        $this->assertFalse($adapter->shouldExclude('config/app.php'));
        $this->assertFalse($adapter->shouldExclude('testing.php'));
    }

    public function testComplexNestedConfig(): void
    {
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
            'deeply' => [
                'nested' => [
                    'config' => [
                        'value' => 42,
                        'array' => [1, 2, 3],
                    ],
                ],
            ],
        ];

        $configPath = $this->createJsonConfig($config);
        $adapter = new ConfigurationAdapter($configPath, $this->logger);

        $this->assertEquals(42, $adapter->get('deeply.nested.config.value'));
        $this->assertEquals([1, 2, 3], $adapter->get('deeply.nested.config.array'));
        $this->assertNull($adapter->get('deeply.nested.missing'));
    }

    public function testLoggerCalls(): void
    {
        $config = [
            'entry' => 'index.php',
            'output' => 'packed.php',
        ];

        $configPath = $this->createJsonConfig($config);

        // Use NullLogger which accepts all log calls without errors
        $logger = new NullLogger();

        // Verify that ConfigurationAdapter can be instantiated with logger
        $adapter = new ConfigurationAdapter($configPath, $logger);

        // Verify configuration loaded successfully
        $this->assertEquals('index.php', $adapter->get('entry'));
        $this->assertEquals('packed.php', $adapter->get('output'));
    }

    protected function setUp(): void
    {
        $this->logger = new NullLogger();
        $this->tempDir = sys_get_temp_dir() . '/php-packer-config-test-' . uniqid();
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($this->tempDir);
        }
    }
}
