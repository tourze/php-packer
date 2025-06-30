<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Integration\Command;

use PhpPacker\Command\DependenciesCommand;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class DependenciesCommandTest extends TestCase
{
    public function testGetName(): void
    {
        $command = new DependenciesCommand();
        $this->assertSame('dependencies', $command->getName());
    }

    public function testGetDescription(): void
    {
        $command = new DependenciesCommand();
        $this->assertSame('Query and display file dependencies from database', $command->getDescription());
    }
}