<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Exception;

use PhpPacker\Exception\CircularDependencyException;
use PhpPacker\Exception\PackerException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(CircularDependencyException::class)]
final class CircularDependencyExceptionTest extends AbstractExceptionTestCase
{
    public function testIsPackerException(): void
    {
        $exception = new CircularDependencyException();
        $this->assertInstanceOf(PackerException::class, $exception);
    }

    public function testWithMessage(): void
    {
        $message = 'Circular dependency detected between A and B';
        $exception = new CircularDependencyException($message);

        $this->assertEquals($message, $exception->getMessage());
    }

    public function testWithPreviousException(): void
    {
        $previous = new \RuntimeException('Previous error');
        $exception = new CircularDependencyException('Test message', 500, $previous);

        $this->assertSame($previous, $exception->getPrevious());
        $this->assertEquals(500, $exception->getCode());
    }
}
