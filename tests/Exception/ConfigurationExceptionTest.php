<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Exception;

use PhpPacker\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(ConfigurationException::class)]
final class ConfigurationExceptionTest extends AbstractExceptionTestCase
{
    public function testIsInstanceOfRuntimeException(): void
    {
        $exception = new ConfigurationException('Test message');
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testMessage(): void
    {
        $message = 'Configuration error';
        $exception = new ConfigurationException($message);
        $this->assertSame($message, $exception->getMessage());
    }
}
