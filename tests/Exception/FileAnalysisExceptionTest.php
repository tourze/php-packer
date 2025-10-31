<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Exception;

use PhpPacker\Exception\FileAnalysisException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(FileAnalysisException::class)]
final class FileAnalysisExceptionTest extends AbstractExceptionTestCase
{
    public function testIsInstanceOfRuntimeException(): void
    {
        $exception = new FileAnalysisException('Test message');
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testMessage(): void
    {
        $message = 'File analysis error';
        $exception = new FileAnalysisException($message);
        $this->assertSame($message, $exception->getMessage());
    }
}
