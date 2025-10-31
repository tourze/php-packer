<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Exception;

use PhpPacker\Exception\GeneralPackerException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(GeneralPackerException::class)]
final class GeneralPackerExceptionTest extends AbstractExceptionTestCase
{
    protected function getExceptionClass(): string
    {
        return GeneralPackerException::class;
    }
}
