<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\ConditionalNodeBuilder;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ConditionalNodeBuilder::class)]
final class ConditionalNodeBuilderTest extends TestCase
{
    private ConditionalNodeBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ConditionalNodeBuilder();
    }

    public function testCreateConditionalNode(): void
    {
        $functions = [
            new Node\Stmt\Function_('test1'),
            new Node\Stmt\Function_('test2'),
        ];

        $result = $this->builder->createConditionalNode($functions);

        $this->assertInstanceOf(Node::class, $result);
    }

    public function testCreateConditionalNodeWithEmptyArray(): void
    {
        $result = $this->builder->createConditionalNode([]);

        $this->assertNull($result);
    }
}
