<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer;

use PhpPacker\Analyzer\ComposerConfigParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(ComposerConfigParser::class)]
final class ComposerConfigParserTest extends TestCase
{
    private ComposerConfigParser $parser;

    private string $tempDir;

    protected function setUp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->parser = new ComposerConfigParser($logger);
        $this->tempDir = sys_get_temp_dir() . '/composer-parser-test-' . uniqid();
        mkdir($this->tempDir, 0o777, true);
    }

    public function testParseValidComposerJson(): void
    {
        $composerJson = [
            'name' => 'test/package',
            'autoload' => [
                'psr-4' => [
                    'Test\\' => 'src/',
                ],
            ],
            'require' => [
                'php' => '>=8.1',
            ],
        ];

        $path = $this->createComposerJson($composerJson);
        $config = $this->parser->parseComposerConfig($path);

        $this->assertEquals('test/package', $config['name']);
        $this->assertArrayHasKey('autoload', $config);
        $this->assertArrayHasKey('psr-4', $config['autoload']);
    }

    public function testParseComposerJsonWithClassmap(): void
    {
        $composerJson = [
            'name' => 'test/package',
            'autoload' => [
                'classmap' => ['lib/', 'src/legacy.php'],
            ],
        ];

        $path = $this->createComposerJson($composerJson);
        $config = $this->parser->parseComposerConfig($path);

        $this->assertArrayHasKey('classmap', $config['autoload']);
        $this->assertCount(2, $config['autoload']['classmap']);
    }

    public function testParseComposerJsonWithFiles(): void
    {
        $composerJson = [
            'name' => 'test/package',
            'autoload' => [
                'files' => ['bootstrap.php', 'helpers.php'],
            ],
        ];

        $path = $this->createComposerJson($composerJson);
        $config = $this->parser->parseComposerConfig($path);

        $this->assertArrayHasKey('files', $config['autoload']);
        $this->assertCount(2, $config['autoload']['files']);
    }

    public function testParseInvalidJson(): void
    {
        $path = $this->tempDir . '/composer.json';
        file_put_contents($path, 'invalid json');

        $result = $this->parser->parseComposerConfig($path);
        $this->assertEquals([], $result);
    }

    public function testParseNonExistentFile(): void
    {
        $result = $this->parser->parseComposerConfig($this->tempDir . '/non-existent.json');
        $this->assertEquals([], $result);
    }

    public function testGetAutoloadPaths(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'Tests\\' => ['tests/unit/', 'tests/integration/'],
                ],
                'psr-0' => [
                    'Legacy_' => 'lib/',
                ],
            ],
        ];

        $path = $this->createComposerJson($composerJson);
        $config = $this->parser->parseComposerConfig($path);
        $paths = $this->parser->getAutoloadPaths($config);

        $this->assertContains('src/', $paths);
        $this->assertContains('tests/unit/', $paths);
        $this->assertContains('tests/integration/', $paths);
        $this->assertContains('lib/', $paths);
    }

    public function testGetNamespaceMapping(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'App\Tests\\' => 'tests/',
                ],
            ],
        ];

        $path = $this->createComposerJson($composerJson);
        $config = $this->parser->parseComposerConfig($path);
        $mapping = $this->parser->getNamespaceMapping($config);

        $this->assertArrayHasKey('App\\', $mapping);
        $this->assertArrayHasKey('App\Tests\\', $mapping);
        $this->assertEquals(['src/'], $mapping['App\\']);
        $this->assertEquals(['tests/'], $mapping['App\Tests\\']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createComposerJson(array $data): string
    {
        $path = $this->tempDir . '/composer.json';
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));

        return $path;
    }

    public function testLoadVendorPackages(): void
    {
        $vendorDir = $this->tempDir . '/vendor';
        mkdir($vendorDir);
        mkdir($vendorDir . '/composer');

        $installedJson = [
            'packages' => [
                [
                    'name' => 'vendor/package',
                    'autoload' => [
                        'psr-4' => ['Vendor\Package\\' => 'src/'],
                    ],
                ],
            ],
        ];

        file_put_contents(
            $vendorDir . '/composer/installed.json',
            json_encode($installedJson, JSON_PRETTY_PRINT)
        );

        $packages = $this->parser->loadVendorPackages($this->tempDir);

        $this->assertCount(1, $packages);
        $this->assertEquals('vendor/package', $packages[0]['name']);
        $this->assertArrayHasKey('path', $packages[0]);
    }

    public function testNormalizePath(): void
    {
        $normalized = $this->parser->normalizePath('/foo//bar/../baz');
        $this->assertEquals('/foo/baz', $normalized);
    }

    public function testParseComposerConfig(): void
    {
        $composerJson = [
            'name' => 'test/package',
            'version' => '1.0.0',
        ];

        $path = $this->createComposerJson($composerJson);
        $config = $this->parser->parseComposerConfig($path);

        $this->assertEquals('test/package', $config['name']);
        $this->assertEquals('1.0.0', $config['version']);
    }
}
