<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Commander;

use PhpPacker\Commander\AnalyzeCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AnalyzeCommand::class)]
final class AnalyzeCommandTest extends TestCase
{
    public function testGetName(): void
    {
        $command = $this->createCommand();
        $this->assertSame('analyze', $command->getName());
    }

    public function testGetDescription(): void
    {
        $command = $this->createCommand();
        $this->assertSame('Analyze PHP entry file and generate dependency database', $command->getDescription());
    }

    public function testExecuteWithoutArgs(): void
    {
        $command = $this->createCommand();

        // 捕获标准输出，避免测试时输出错误信息
        ob_start();
        $result = $command->run([]);
        $output = ob_get_clean();

        $this->assertSame(1, $result);
        // 验证帮助信息被输出 (通过echo输出到标准输出)
        $this->assertIsString($output);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('analyze <entry-file>', $output);
        $this->assertStringContainsString('--database', $output);
        $this->assertStringContainsString('--root-path', $output);
    }

    public function testGetUsage(): void
    {
        $command = $this->createCommand();
        $usage = $command->getUsage();
        $this->assertStringContainsString('php-packer analyze', $usage);
        $this->assertStringContainsString('<entry-file>', $usage);
        $this->assertStringContainsString('--database', $usage);
    }

    private function createCommand(): AnalyzeCommand
    {
        return new AnalyzeCommand();
    }
}
