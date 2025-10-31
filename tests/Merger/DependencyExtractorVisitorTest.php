<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\DependencyExtractorVisitor;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DependencyExtractorVisitor::class)]
final class DependencyExtractorVisitorTest extends TestCase
{
    public function testExtractDependenciesFromTypeHints(): void
    {
        $code = '<?php
        namespace Test;
        use Some\Dependency;
        class TestClass {
            public function method(Dependency $dep): void {}
        }';

        $visitor = new DependencyExtractorVisitor();

        $this->traverseCode($code, $visitor);

        $this->assertContains('Some\Dependency', $visitor->getDependencies());
    }

    public function testExtractDependenciesFromNewInstances(): void
    {
        $code = '<?php
        namespace Test;
        use Some\Service;
        class TestClass {
            public function method() {
                $service = new Service();
            }
        }';

        $visitor = new DependencyExtractorVisitor();

        $this->traverseCode($code, $visitor);

        $this->assertContains('Some\Service', $visitor->getDependencies());
    }

    public function testExtractDependenciesFromStaticCalls(): void
    {
        $code = '<?php
        namespace Test;
        use Some\Helper;
        class TestClass {
            public function method() {
                Helper::staticMethod();
            }
        }';

        $visitor = new DependencyExtractorVisitor();

        $this->traverseCode($code, $visitor);

        $this->assertContains('Some\Helper', $visitor->getDependencies());
    }

    public function testExtractDependenciesFromInstanceof(): void
    {
        $code = '<?php
        namespace Test;
        use Some\TestInterface;
        class TestClass {
            public function method($obj) {
                if ($obj instanceof TestInterface) {}
            }
        }';

        $visitor = new DependencyExtractorVisitor();

        $this->traverseCode($code, $visitor);

        $this->assertContains('Some\TestInterface', $visitor->getDependencies());
    }

    public function testHandleNamespaceContextCorrectly(): void
    {
        $code = '<?php
        namespace Test;
        class TestClass {
            public function method(LocalClass $local): void {}
        }';

        $visitor = new DependencyExtractorVisitor();

        $this->traverseCode($code, $visitor);

        $this->assertContains('Test\LocalClass', $visitor->getDependencies());
    }

    public function testEnterNodeReturnsNull(): void
    {
        $visitor = new DependencyExtractorVisitor();

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse('<?php class TestClass {}');
        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $classNode = $ast[0];

        $result = $visitor->enterNode($classNode);

        $this->assertNull($result);
    }

    private function traverseCode(string $code, DependencyExtractorVisitor $visitor): void
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code);
        $this->assertNotNull($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }
}
