<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer;

use PhpPacker\Analyzer\FileVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(FileVerifier::class)]
final class FileVerifierTest extends TestCase
{
    private FileVerifier $verifier;

    private string $tempDir;

    protected function setUp(): void
    {
        $logger = new NullLogger();
        $this->verifier = new FileVerifier($logger);
        $this->tempDir = sys_get_temp_dir() . '/file-verifier-test-' . uniqid();
        mkdir($this->tempDir, 0o777, true);
    }

    public function testVerifyClassInFile(): void
    {
        $file = $this->tempDir . '/TestClass.php';
        file_put_contents($file, "<?php\nnamespace App\\Test;\n\nclass TestClass {}");

        $this->assertTrue($this->verifier->verifyClassInFile($file, 'TestClass', 'App\Test\TestClass'));
        $this->assertFalse($this->verifier->verifyClassInFile($file, 'WrongClass', 'App\Test\WrongClass'));
        $this->assertFalse($this->verifier->verifyClassInFile($file, 'TestClass', 'Wrong\Namespace\TestClass'));
    }

    public function testVerifyClassInFileWithInterface(): void
    {
        $file = $this->tempDir . '/TestInterface.php';
        file_put_contents($file, "<?php\nnamespace App\\Test;\n\ninterface TestInterface {}");

        $this->assertTrue($this->verifier->verifyClassInFile($file, 'TestInterface', 'App\Test\TestInterface'));
    }

    public function testVerifyClassInFileWithTrait(): void
    {
        $file = $this->tempDir . '/TestTrait.php';
        file_put_contents($file, "<?php\nnamespace App\\Test;\n\ntrait TestTrait {}");

        $this->assertTrue($this->verifier->verifyClassInFile($file, 'TestTrait', 'App\Test\TestTrait'));
    }

    public function testShouldExcludeFromAnalysis(): void
    {
        $this->assertTrue($this->verifier->shouldExcludeFromAnalysis('vendor/autoload.php'));
        $this->assertTrue($this->verifier->shouldExcludeFromAnalysis('path/to/vendor/composer/autoload_real.php'));
        $this->assertTrue($this->verifier->shouldExcludeFromAnalysis('vendor/composer/ClassLoader.php'));

        $this->assertFalse($this->verifier->shouldExcludeFromAnalysis('src/MyClass.php'));
        $this->assertFalse($this->verifier->shouldExcludeFromAnalysis('vendor/package/src/Class.php'));
    }

    public function testVerifyClassInFileWithModifiers(): void
    {
        $file = $this->tempDir . '/AbstractClass.php';
        file_put_contents($file, "<?php\nnamespace App\\Test;\n\nabstract class AbstractClass {}");

        $this->assertTrue($this->verifier->verifyClassInFile($file, 'AbstractClass', 'App\Test\AbstractClass'));

        $file2 = $this->tempDir . '/FinalClass.php';
        file_put_contents($file2, "<?php\nnamespace App\\Test;\n\nfinal class FinalClass {}");

        $this->assertTrue($this->verifier->verifyClassInFile($file2, 'FinalClass', 'App\Test\FinalClass'));
    }

    public function testVerifyFile(): void
    {
        $validPhpFile = $this->tempDir . '/valid.php';
        file_put_contents($validPhpFile, '<?php class Test {}');

        $invalidPhpFile = $this->tempDir . '/invalid.php';
        file_put_contents($invalidPhpFile, '<?php class Test {');

        $nonPhpFile = $this->tempDir . '/test.txt';
        file_put_contents($nonPhpFile, 'Not a PHP file');

        $this->assertTrue($this->verifier->verifyFile($validPhpFile));
        $this->assertFalse($this->verifier->verifyFile($invalidPhpFile));
        $this->assertFalse($this->verifier->verifyFile($nonPhpFile));
        $this->assertFalse($this->verifier->verifyFile($this->tempDir . '/non-existent.php'));
    }
}
