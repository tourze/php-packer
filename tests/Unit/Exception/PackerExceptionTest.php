<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Exception;

use PhpPacker\Exception\PackerException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PackerExceptionTest extends TestCase
{
    public function testIsInstanceOfRuntimeException(): void
    {
        $exception = new PackerException('Test message');
        $this->assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testMessage(): void
    {
        $message = 'Packer error';
        $exception = new PackerException($message);
        $this->assertSame($message, $exception->getMessage());
    }
}