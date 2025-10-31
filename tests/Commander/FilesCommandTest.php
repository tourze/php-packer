<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Commander;

use PhpPacker\Commander\FilesCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FilesCommand::class)]
final class FilesCommandTest extends TestCase
{
    public function testGetName(): void
    {
        $command = $this->createCommand();
        $this->assertSame('files', $command->getName());
    }

    public function testGetDescription(): void
    {
        $command = $this->createCommand();
        $this->assertSame('List all files in the database with their dependencies', $command->getDescription());
    }

    public function testGetUsage(): void
    {
        $command = $this->createCommand();
        $usage = $command->getUsage();
        $this->assertStringContainsString('php-packer files', $usage);
        $this->assertStringContainsString('--database', $usage);
    }

    public function testExecuteWithNonExistentDatabase(): void
    {
        $command = $this->createCommand();
        $result = $command->execute(
            [],
            ['database' => '/non/existent/database.db']
        );
        $this->assertSame(1, $result);
    }

    private function createCommand(): FilesCommand
    {
        return new FilesCommand();
    }
}
