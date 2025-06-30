<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Integration\Command;

use PhpPacker\Command\BaseCommand;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class BaseCommandTest extends TestCase
{
    public function testFormatBytes(): void
    {
        $command = new TestBaseCommand();
        $this->assertStringContainsString('KB', $command->formatBytes(1024));
    }
}

class TestBaseCommand extends BaseCommand
{
    public function getName(): string
    {
        return 'test';
    }

    public function getDescription(): string
    {
        return 'Test command';
    }

    public function getUsage(): string
    {
        return 'test';
    }

    public function execute(array $args, array $options): int
    {
        return 0;
    }

    public function formatBytes(int $bytes): string
    {
        return parent::formatBytes($bytes);
    }
}