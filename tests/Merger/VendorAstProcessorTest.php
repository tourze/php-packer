<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\VendorAstProcessor;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(VendorAstProcessor::class)]
final class VendorAstProcessorTest extends TestCase
{
    private VendorAstProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new VendorAstProcessor();
    }

    public function testParseFile(): void
    {
        $content = '<?php
namespace Vendor\Library;

class Client {
    public function connect(): void {}
}
';

        $ast = $this->processor->parseFile($content);

        $this->assertIsArray($ast);
        $this->assertNotEmpty($ast);
        $this->assertInstanceOf(Node\Stmt\Namespace_::class, $ast[0]);
    }

    public function testParseFileInvalid(): void
    {
        $content = '<?php
class Invalid {
    // Missing closing brace - this will cause a syntax error
    public function test()
'; // Intentionally incomplete to cause parse error

        $ast = $this->processor->parseFile($content);

        $this->assertNull($ast);
    }

    public function testTransformAst(): void
    {
        $content = '<?php
namespace Vendor\Library;

use Exception;

class Client {
    public function method(): Exception {}
}
';

        $ast = $this->processor->parseFile($content);
        if (null === $ast) {
            self::fail('Failed to parse code');
        }
        $transformedAst = $this->processor->transformAst($ast);

        $this->assertIsArray($transformedAst);
        $this->assertNotEmpty($transformedAst);
    }

    public function testFilterNodes(): void
    {
        $content = '<?php
declare(strict_types=1);

namespace Vendor\Library;

class Client {}
';

        $ast = $this->processor->parseFile($content);
        if (null === $ast) {
            self::fail('Failed to parse code');
        }
        /** @var array<Node> $astNodes */
        $astNodes = $ast;
        $filtered = $this->processor->filterNodes($astNodes);

        $this->assertIsArray($filtered);
        $this->assertGreaterThanOrEqual(1, count($filtered));

        foreach ($filtered as $node) {
            $this->assertNotInstanceOf(Node\Stmt\Declare_::class, $node);
        }
    }

    public function testAddFileComment(): void
    {
        $content = '<?php
namespace Vendor\Library;

class Client {}
';

        $ast = $this->processor->parseFile($content);
        if (null === $ast) {
            self::fail('Failed to parse code');
        }
        $this->processor->addFileComment($ast, '/vendor/library/Client.php');

        $this->assertNotEmpty($ast);
        $comments = $ast[0]->getAttribute('comments', []);
        $this->assertNotEmpty($comments);
        $this->assertStringContainsString('Vendor file: /vendor/library/Client.php', $comments[0]->getText());
    }

    public function testAddFileCommentEmptyAst(): void
    {
        $ast = [];
        $this->processor->addFileComment($ast, '/vendor/library/Empty.php');

        $this->assertSame([], $ast);
    }

    public function testExtractNamespace(): void
    {
        $content = '<?php
namespace Vendor\Library\Sub;

class Client {}
';

        $ast = $this->processor->parseFile($content);
        if (null === $ast) {
            self::fail('Failed to parse code');
        }
        $namespace = $this->processor->extractNamespace($ast);

        $this->assertEquals('Vendor\Library\Sub', $namespace);
    }

    public function testExtractNamespaceGlobal(): void
    {
        $content = '<?php

class GlobalClass {}
';

        $ast = $this->processor->parseFile($content);
        if (null === $ast) {
            self::fail('Failed to parse code');
        }
        $namespace = $this->processor->extractNamespace($ast);

        $this->assertEquals('', $namespace);
    }

    public function testExtractClasses(): void
    {
        $content = '<?php
namespace Vendor\Library;

class Client {}
class Server {}
interface Connection {}
';

        $ast = $this->processor->parseFile($content);
        if (null === $ast) {
            self::fail('Failed to parse code');
        }
        $transformedAst = $this->processor->transformAst($ast);
        $classes = $this->processor->extractClasses($transformedAst);

        $this->assertIsArray($classes);
        $this->assertContains('Vendor\Library\Client', $classes);
        $this->assertContains('Vendor\Library\Server', $classes);
        $this->assertNotContains('Vendor\Library\Connection', $classes);
    }

    public function testExtractClassesGlobal(): void
    {
        $content = '<?php
class GlobalClient {}
class GlobalServer {}
';

        $ast = $this->processor->parseFile($content);
        if (null === $ast) {
            self::fail('Failed to parse code');
        }
        $classes = $this->processor->extractClasses($ast);

        $this->assertIsArray($classes);
        $this->assertContains('GlobalClient', $classes);
        $this->assertContains('GlobalServer', $classes);
    }

    public function testStripComments(): void
    {
        $content = '<?php
/**
 * File comment
 */
namespace Vendor\Library;

/**
 * Class comment
 */
class Client {
    /**
     * Method comment
     */
    public function connect(): void {
        // Inline comment
    }
}
';

        $ast = $this->processor->parseFile($content);
        if (null === $ast) {
            self::fail('Failed to parse code');
        }
        $stripped = $this->processor->stripComments($ast);

        $this->assertIsArray($stripped);

        foreach ($stripped as $node) {
            $this->assertEmpty($node->getAttribute('comments', []));
        }
    }

    public function testTransformAstWithComplexStructure(): void
    {
        $content = '<?php
namespace Vendor\Library;

use InvalidArgumentException;

class Client {
    public function method(string $param): Exception {
        throw new InvalidArgumentException();
    }
}
';

        $ast = $this->processor->parseFile($content);
        $this->assertNotNull($ast);

        $transformedAst = $this->processor->transformAst($ast);
        $this->assertIsArray($transformedAst);
        $this->assertNotEmpty($transformedAst);
    }

    public function testFilterNodesKeepsImportantStatements(): void
    {
        $content = '<?php
declare(strict_types=1);

namespace Vendor\Library;

class Client {
    public function method(): void {}
}
';

        $ast = $this->processor->parseFile($content);
        if (null === $ast) {
            self::fail('Failed to parse code');
        }
        /** @var array<Node> $astNodes */
        $astNodes = $ast;
        $filtered = $this->processor->filterNodes($astNodes);

        $this->assertIsArray($filtered);

        $hasNamespace = false;
        $hasClass = false;

        foreach ($filtered as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $hasNamespace = true;
            }
            if ($node instanceof Node\Stmt\Class_) {
                $hasClass = true;
            }
        }

        $this->assertTrue($hasNamespace || $hasClass);
    }
}
