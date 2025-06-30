<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit;

use PhpPacker\Packer;
use PHPUnit\Framework\TestCase;

class PackerTest extends TestCase
{
    public function testConstructor(): void
    {
        $config = $this->createMock(\PhpPacker\Adapter\ConfigurationAdapter::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $packer = new Packer($config, $logger);
        $this->assertInstanceOf(Packer::class, $packer);
    }
}