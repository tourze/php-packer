<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Integration\Command;

use PhpPacker\Command\PackCommand;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class PackCommandTest extends TestCase
{
    public function testGetName(): void
    {
        $command = new PackCommand();
        $this->assertSame('pack', $command->getName());
    }

    public function testGetDescription(): void
    {
        $command = new PackCommand();
        $this->assertSame('Pack analyzed files from database into a single PHP file', $command->getDescription());
    }
}