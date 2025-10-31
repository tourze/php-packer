<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Exception;

use PhpPacker\Exception\StorageException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(StorageException::class)]
final class StorageExceptionTest extends AbstractExceptionTestCase
{
    protected function setUp(): void
    {
        // No setup needed for this test
    }

    public function testIsInstanceOfRuntimeException(): void
    {
        $exception = new StorageException('Test message');
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testMessage(): void
    {
        $message = 'Storage error';
        $exception = new StorageException($message);
        $this->assertSame($message, $exception->getMessage());
    }
}
