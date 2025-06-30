<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Integration\Command;

use PhpPacker\Command\AnalyzeCommand;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class AnalyzeCommandTest extends TestCase
{
    public function testGetName(): void
    {
        $command = new AnalyzeCommand();
        $this->assertSame('analyze', $command->getName());
    }

    public function testGetDescription(): void
    {
        $command = new AnalyzeCommand();
        $this->assertSame('Analyze PHP entry file and generate dependency database', $command->getDescription());
    }

    public function testExecuteWithoutArgs(): void
    {
        $command = new AnalyzeCommand();
        $result = $command->execute([], []);
        $this->assertSame(1, $result);
    }
}