<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Exception;

use PhpPacker\Exception\CodeGenerationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(CodeGenerationException::class)]
final class CodeGenerationExceptionTest extends AbstractExceptionTestCase
{
    public function testIsInstanceOfException(): void
    {
        $exception = new CodeGenerationException('Test message');
        $this->assertInstanceOf(\Exception::class, $exception);
    }

    public function testMessage(): void
    {
        $message = 'Code generation error';
        $exception = new CodeGenerationException($message);
        $this->assertSame($message, $exception->getMessage());
    }
}
