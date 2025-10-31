<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Commander;

use PhpPacker\Commander\PackCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PackCommand::class)]
final class PackCommandTest extends TestCase
{
    public function testGetName(): void
    {
        $command = $this->createCommand();
        $this->assertSame('pack', $command->getName());
    }

    public function testGetDescription(): void
    {
        $command = $this->createCommand();
        $this->assertSame('Pack analyzed files from database into a single PHP file', $command->getDescription());
    }

    public function testGetUsage(): void
    {
        $command = $this->createCommand();
        $usage = $command->getUsage();
        $this->assertStringContainsString('php-packer pack', $usage);
        $this->assertStringContainsString('--output', $usage);
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

    private function createCommand(): PackCommand
    {
        return new PackCommand();
    }
}
