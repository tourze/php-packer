<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Exception;

use PhpPacker\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ConfigurationExceptionTest extends TestCase
{
    public function testIsInstanceOfRuntimeException(): void
    {
        $exception = new ConfigurationException('Test message');
        $this->assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testMessage(): void
    {
        $message = 'Configuration error';
        $exception = new ConfigurationException($message);
        $this->assertSame($message, $exception->getMessage());
    }
}