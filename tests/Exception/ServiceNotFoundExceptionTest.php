<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Exception;

use PhpPacker\Exception\PackerException;
use PhpPacker\Exception\ServiceNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(ServiceNotFoundException::class)]
final class ServiceNotFoundExceptionTest extends AbstractExceptionTestCase
{
    protected function setUp(): void
    {
        // No setup needed for this test
    }

    public function testIsPackerException(): void
    {
        $exception = new ServiceNotFoundException();
        $this->assertInstanceOf(PackerException::class, $exception);
    }

    public function testWithMessage(): void
    {
        $message = 'Service "Logger" not found';
        $exception = new ServiceNotFoundException($message);

        $this->assertEquals($message, $exception->getMessage());
    }

    public function testWithPreviousException(): void
    {
        $previous = new \RuntimeException('Previous error');
        $exception = new ServiceNotFoundException('Test message', 404, $previous);

        $this->assertSame($previous, $exception->getPrevious());
        $this->assertEquals(404, $exception->getCode());
    }
}
