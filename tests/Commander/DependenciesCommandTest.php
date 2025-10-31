<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Commander;

use PhpPacker\Commander\DependenciesCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DependenciesCommand::class)]
final class DependenciesCommandTest extends TestCase
{
    public function testGetName(): void
    {
        $command = $this->createCommand();
        $this->assertSame('dependencies', $command->getName());
    }

    public function testGetDescription(): void
    {
        $command = $this->createCommand();
        $this->assertSame('Query and display file dependencies from database', $command->getDescription());
    }

    public function testGetUsage(): void
    {
        $command = $this->createCommand();
        $usage = $command->getUsage();
        $this->assertStringContainsString('php-packer dependencies', $usage);
        $this->assertStringContainsString('--database', $usage);
    }

    public function testExecuteWithoutArgs(): void
    {
        $command = $this->createCommand();
        $result = $command->execute([], []);
        $this->assertSame(1, $result);
    }

    public function testExecuteWithNonExistentDatabase(): void
    {
        $command = $this->createCommand();
        $result = $command->execute(
            ['test.php'],
            ['database' => '/non/existent/database.db']
        );
        $this->assertSame(1, $result);
    }

    private function createCommand(): DependenciesCommand
    {
        return new DependenciesCommand();
    }
}
