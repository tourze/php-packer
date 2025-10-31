<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer;

use PhpPacker\Analyzer\PathResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(PathResolver::class)]
final class PathResolverTest extends TestCase
{
    private PathResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PathResolver(new NullLogger(), '/base/path');
    }

    public function testResolveAbsolutePath(): void
    {
        $this->assertEquals('/absolute/path/file.php', $this->resolver->resolve('/absolute/path/file.php'));
    }

    public function testResolveRelativePath(): void
    {
        $this->assertEquals('/base/path/relative/file.php', $this->resolver->resolve('relative/file.php'));
        $this->assertEquals('/base/path/relative/file.php', $this->resolver->resolve('./relative/file.php'));
    }

    public function testResolveParentPath(): void
    {
        $this->assertEquals('/base/file.php', $this->resolver->resolve('../file.php'));
        $this->assertEquals('/file.php', $this->resolver->resolve('../../file.php'));
    }

    public function testNormalizePath(): void
    {
        $this->assertEquals('/path/to/file.php', $this->resolver->normalizePath('/path/to/./file.php'));
        $this->assertEquals('/path/file.php', $this->resolver->normalizePath('/path/to/../file.php'));
        $this->assertEquals('/file.php', $this->resolver->normalizePath('/path/../to/../../file.php'));
        $this->assertEquals('/path/to/file.php', $this->resolver->normalizePath('/path//to///file.php'));
    }

    public function testGetRelativePath(): void
    {
        $this->assertEquals('file.php', $this->resolver->getRelativePath('/base/path/file.php'));
        $this->assertEquals('sub/file.php', $this->resolver->getRelativePath('/base/path/sub/file.php'));
        $this->assertEquals('../other/file.php', $this->resolver->getRelativePath('/base/other/file.php'));
        $this->assertEquals('../../file.php', $this->resolver->getRelativePath('/file.php'));
    }

    public function testIsAbsolutePath(): void
    {
        $this->assertTrue($this->resolver->isAbsolutePath('/absolute/path'));
        $this->assertTrue($this->resolver->isAbsolutePath('C:\Windows\Path')); // Windows
        $this->assertFalse($this->resolver->isAbsolutePath('relative/path'));
        $this->assertFalse($this->resolver->isAbsolutePath('./relative/path'));
        $this->assertFalse($this->resolver->isAbsolutePath('../relative/path'));
    }

    public function testJoinPaths(): void
    {
        $this->assertEquals('/base/sub/file.php', $this->resolver->joinPaths('/base', 'sub', 'file.php'));
        $this->assertEquals('/base/sub/file.php', $this->resolver->joinPaths('/base/', '/sub/', '/file.php'));
        $this->assertEquals('/file.php', $this->resolver->joinPaths('/base', '../', 'file.php'));
    }

    public function testGetDirectory(): void
    {
        $this->assertEquals('/path/to', $this->resolver->getDirectory('/path/to/file.php'));
        $this->assertEquals('/path', $this->resolver->getDirectory('/path/file.php'));
        $this->assertEquals('/', $this->resolver->getDirectory('/file.php'));
    }

    public function testGetFilename(): void
    {
        $this->assertEquals('file.php', $this->resolver->getFilename('/path/to/file.php'));
        $this->assertEquals('file.php', $this->resolver->getFilename('file.php'));
    }

    public function testGetExtension(): void
    {
        $this->assertEquals('php', $this->resolver->getExtension('/path/to/file.php'));
        $this->assertEquals('txt', $this->resolver->getExtension('document.txt'));
        $this->assertEquals('', $this->resolver->getExtension('no-extension'));
        $this->assertEquals('gz', $this->resolver->getExtension('archive.tar.gz'));
    }

    public function testSetBasePath(): void
    {
        $this->resolver->setBasePath('/new/base');
        $this->assertEquals('/new/base/file.php', $this->resolver->resolve('file.php'));
    }

    public function testResolveWithCustomBase(): void
    {
        $this->assertEquals('/custom/base/file.php', $this->resolver->resolve('file.php', '/custom/base'));
    }

    public function testMakeAbsolutePath(): void
    {
        $this->assertEquals('/base/path/file.php', $this->resolver->makeAbsolutePath('file.php'));
        $this->assertEquals('/absolute/file.php', $this->resolver->makeAbsolutePath('/absolute/file.php'));
    }

    public function testResolveIncludePath(): void
    {
        $dependency = ['context' => 'file.php'];
        $sourceFile = ['path' => '/first/path/source.php'];
        $result = $this->resolver->resolveIncludePath($dependency, $sourceFile);
        $this->assertNull($result); // 文件不存在时返回 null
    }
}
