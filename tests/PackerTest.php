<?php

declare(strict_types=1);

namespace PhpPacker\Tests;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Exception\GeneralPackerException;
use PhpPacker\Packer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(Packer::class)]
final class PackerTest extends TestCase
{
    public function testConstructor(): void
    {
        /*
         * 使用具体类 ConfigurationAdapter 进行 mock 的原因：
         * 1) 为什么必须使用具体类而不是接口：ConfigurationAdapter 没有对应的接口拽象，且 Packer 构造函数直接依赖具体实现
         * 2) 这种使用是否合理和必要：在单元测试中合理，避免真实的配置文件读取，专注测试 Packer 的初始化逻辑
         * 3) 是否有更好的替代方案：为配置适配器定义接口会改善架构，但当前使用 mock 是有效的测试方法
         */
        $config = $this->createMock(ConfigurationAdapter::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Mock the required methods that are called during initialization
        $config->method('get')
            ->willReturnMap([
                ['database', 'build/packer.db', 'build/packer.db'],
            ])
        ;

        $config->method('getRootPath')
            ->willReturn('/tmp')
        ;

        $config->method('all')
            ->willReturn([])
        ;

        $packer = new Packer($config, $logger);
        $this->assertInstanceOf(Packer::class, $packer);
    }

    public function testPackWithMissingEntry(): void
    {
        $config = $this->createMock(ConfigurationAdapter::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Mock basic methods for initialization
        $config->method('get')
            ->willReturnCallback(function ($key, $default = null) {
                return match ($key) {
                    'database' => 'build/test.db',
                    'entry' => null, // No entry file specified
                    default => $default,
                };
            })
        ;

        $config->method('getRootPath')
            ->willReturn(sys_get_temp_dir())
        ;

        $config->method('all')
            ->willReturn([])
        ;

        $packer = new Packer($config, $logger);

        $this->expectException(GeneralPackerException::class);
        $this->expectExceptionMessage('Entry file not specified in configuration');

        $packer->pack();
    }

    public function testPackWithNonExistentEntry(): void
    {
        $config = $this->createMock(ConfigurationAdapter::class);
        $logger = $this->createMock(LoggerInterface::class);

        $tempDir = sys_get_temp_dir();

        // Mock basic methods for initialization
        $config->method('get')
            ->willReturnCallback(function ($key, $default = null) {
                return match ($key) {
                    'database' => 'build/test.db',
                    'entry' => 'non-existent-file.php',
                    default => $default,
                };
            })
        ;

        $config->method('getRootPath')
            ->willReturn($tempDir)
        ;

        $config->method('all')
            ->willReturn([])
        ;

        $packer = new Packer($config, $logger);

        $this->expectException(GeneralPackerException::class);
        $this->expectExceptionMessage('Entry file not found');

        $packer->pack();
    }

    public function testPackSuccessfulExecution(): void
    {
        $config = $this->createMock(ConfigurationAdapter::class);
        $logger = $this->createMock(LoggerInterface::class);

        $tempDir = sys_get_temp_dir() . '/packer_test_' . uniqid();
        mkdir($tempDir, 0o755, true);

        // Create a temporary entry file
        $entryFile = $tempDir . '/entry.php';
        file_put_contents($entryFile, '<?php echo "test";');

        // Mock basic methods for initialization
        $config->method('get')
            ->willReturnCallback(function ($key, $default = null) use ($tempDir) {
                return match ($key) {
                    'database' => $tempDir . '/test.db',
                    'entry' => 'entry.php',
                    'output' => 'packed.php',
                    'autoload' => [],
                    default => $default,
                };
            })
        ;

        $config->method('getRootPath')
            ->willReturn($tempDir)
        ;

        $config->method('all')
            ->willReturn([])
        ;

        $config->method('getIncludePatterns')
            ->willReturn([])
        ;

        $config->method('shouldExclude')
            ->willReturn(false)
        ;

        $packer = new Packer($config, $logger);

        // Should not throw any exception
        $packer->pack();

        // Verify output file was created
        $outputFile = $tempDir . '/packed.php';
        $this->assertFileExists($outputFile);

        // Cleanup
        unlink($entryFile);
        unlink($outputFile);
        unlink($tempDir . '/test.db');
        if (is_dir($tempDir . '/build')) {
            @rmdir($tempDir . '/build');
        }
        rmdir($tempDir);
    }
}
