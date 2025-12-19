<?php

declare(strict_types=1);

namespace PhpPacker\Tests;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Exception\ConfigurationException;
use PhpPacker\Exception\GeneralPackerException;
use PhpPacker\Packer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(Packer::class)]
final class PackerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/packer_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->cleanupTempDir();
    }

    private function cleanupTempDir(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }

        rmdir($this->tempDir);
    }

    private function createConfigFile(array $config): string
    {
        $configPath = $this->tempDir . '/packer.json';
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $configPath;
    }

    public function testConstructor(): void
    {
        $configPath = $this->createConfigFile([
            'entry' => 'entry.php',
            'output' => 'output.php',
            'database' => 'build/packer.db',
        ]);

        $config = new ConfigurationAdapter($configPath, new NullLogger());
        $packer = new Packer($config, new NullLogger());

        $this->assertInstanceOf(Packer::class, $packer);
    }

    public function testPackWithMissingEntry(): void
    {
        // Create config without 'entry' field - will fail validation
        $configPath = $this->tempDir . '/packer.json';
        file_put_contents($configPath, json_encode([
            'output' => 'output.php',
        ], JSON_PRETTY_PRINT));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Required configuration field missing: entry');

        new ConfigurationAdapter($configPath, new NullLogger());
    }

    public function testPackWithNonExistentEntry(): void
    {
        $configPath = $this->createConfigFile([
            'entry' => 'non-existent-file.php',
            'output' => 'packed.php',
            'database' => 'build/test.db',
        ]);

        $config = new ConfigurationAdapter($configPath, new NullLogger());
        $packer = new Packer($config, new NullLogger());

        $this->expectException(GeneralPackerException::class);
        $this->expectExceptionMessage('Entry file not found');

        $packer->pack();
    }

    public function testPackSuccessfulExecution(): void
    {
        // Create entry file
        $entryFile = $this->tempDir . '/entry.php';
        file_put_contents($entryFile, '<?php echo "test";');

        // Create configuration
        $configPath = $this->createConfigFile([
            'entry' => 'entry.php',
            'output' => 'packed.php',
            'database' => 'build/test.db',
            'autoload' => [],
            'include' => [],
            'exclude' => [],
        ]);

        $config = new ConfigurationAdapter($configPath, new NullLogger());
        $packer = new Packer($config, new NullLogger());

        // Should not throw any exception
        $packer->pack();

        // Verify output file was created
        $outputFile = $this->tempDir . '/packed.php';
        $this->assertFileExists($outputFile);
    }
}
