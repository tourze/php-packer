<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Exception;

use PhpPacker\Exception\GeneralPackerException;
use PhpPacker\Exception\PackerException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(PackerException::class)]
final class PackerExceptionTest extends AbstractExceptionTestCase
{
    public function testIsInstanceOfRuntimeException(): void
    {
        // Test through concrete subclass since PackerException is abstract
        $exception = new GeneralPackerException('Test message');
        $this->assertInstanceOf(PackerException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testMessage(): void
    {
        // Test through concrete subclass since PackerException is abstract
        $message = 'Packer error';
        $exception = new GeneralPackerException($message);
        $this->assertInstanceOf(PackerException::class, $exception);
        $this->assertSame($message, $exception->getMessage());
    }
}
