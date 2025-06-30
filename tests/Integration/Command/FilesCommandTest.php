<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Integration\Command;

use PhpPacker\Command\FilesCommand;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class FilesCommandTest extends TestCase
{
    public function testGetName(): void
    {
        $command = new FilesCommand();
        $this->assertSame('files', $command->getName());
    }

    public function testGetDescription(): void
    {
        $command = new FilesCommand();
        $this->assertSame('List all files in the database with their dependencies', $command->getDescription());
    }
}