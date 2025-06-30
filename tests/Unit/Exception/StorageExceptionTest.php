<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Exception;

use PhpPacker\Exception\StorageException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StorageExceptionTest extends TestCase
{
    public function testIsInstanceOfRuntimeException(): void
    {
        $exception = new StorageException('Test message');
        $this->assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testMessage(): void
    {
        $message = 'Storage error';
        $exception = new StorageException($message);
        $this->assertSame($message, $exception->getMessage());
    }
}