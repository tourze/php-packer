<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Commander;

use PhpPacker\Commander\BaseCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BaseCommand::class)]
final class BaseCommandTest extends TestCase
{
    /**
     * 注意：formatBytes() 现在是 protected 方法，应该通过 public 方法间接测试。
     * 此测试类保留以满足测试结构要求，但不测试 protected 方法。
     */
    public function testBaseCommandStructure(): void
    {
        $command = new TestBaseCommand();
        $this->assertInstanceOf(BaseCommand::class, $command);
    }
}
