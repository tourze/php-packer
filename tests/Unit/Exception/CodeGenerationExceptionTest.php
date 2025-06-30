<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Exception;

use Exception;
use PhpPacker\Exception\CodeGenerationException;
use PHPUnit\Framework\TestCase;

class CodeGenerationExceptionTest extends TestCase
{
    public function testIsInstanceOfException(): void
    {
        $exception = new CodeGenerationException('Test message');
        $this->assertInstanceOf(Exception::class, $exception);
    }

    public function testMessage(): void
    {
        $message = 'Code generation error';
        $exception = new CodeGenerationException($message);
        $this->assertSame($message, $exception->getMessage());
    }
}