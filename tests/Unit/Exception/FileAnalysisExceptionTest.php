<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Exception;

use PhpPacker\Exception\FileAnalysisException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FileAnalysisExceptionTest extends TestCase
{
    public function testIsInstanceOfRuntimeException(): void
    {
        $exception = new FileAnalysisException('Test message');
        $this->assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testMessage(): void
    {
        $message = 'File analysis error';
        $exception = new FileAnalysisException($message);
        $this->assertSame($message, $exception->getMessage());
    }
}