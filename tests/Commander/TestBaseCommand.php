<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Commander;

use PhpPacker\Commander\BaseCommand;

/**
 * 测试用的 BaseCommand 实现
 *
 * @internal
 */
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

    /**
     * @param array<mixed> $args
     * @param array<mixed> $options
     */
    public function execute(array $args, array $options): int
    {
        return 0;
    }

    protected function formatBytes(int $bytes): string
    {
        return parent::formatBytes($bytes);
    }
}
