<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Util;

use PhpPacker\Util\PathNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PathNormalizer::class)]
final class PathNormalizerTest extends TestCase
{
    public function testNormalizeSeparators(): void
    {
        $this->assertSame('a/b/c', PathNormalizer::normalize('a\b////c'));
    }

    public function testNormalizeDots(): void
    {
        $this->assertSame('a/c', PathNormalizer::normalize('a/./b/../c'));
    }

    public function testNormalizeLeading(): void
    {
        $this->assertSame('/var/www/html', PathNormalizer::normalize('/var//www////html'));
    }
}
