<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\ProjectAstAnalyzer;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ProjectAstAnalyzer::class)]
final class ProjectAstAnalyzerTest extends TestCase
{
    private ProjectAstAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ProjectAstAnalyzer();
    }

    public function testExtractDependencies(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
namespace App\Service;

use App\Entity\User;
use Vendor\Library\Client;

class UserService {
    public function process(User $user): void {
        $client = new Client();
    }
}
');
        $this->assertNotNull($ast);

        $dependencies = $this->analyzer->extractDependencies($ast);

        $this->assertIsArray($dependencies);
        $this->assertContains('App\Entity\User', $dependencies);
        $this->assertContains('Vendor\Library\Client', $dependencies);
    }

    public function testExtractSymbols(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
namespace App\Service;

class UserService {
    public function getUser(): void {}
}

function helperFunction(): void {}

const MY_CONSTANT = "value";
');
        $this->assertNotNull($ast);

        $symbols = $this->analyzer->extractSymbols($ast);

        $this->assertIsArray($symbols);
        $this->assertArrayHasKey('classes', $symbols);
        $this->assertArrayHasKey('functions', $symbols);
        $this->assertArrayHasKey('constants', $symbols);
        $this->assertContains('App\Service\UserService', $symbols['classes']);
        $this->assertContains('App\Service\helperFunction', $symbols['functions']);
        $this->assertContains('MY_CONSTANT', $symbols['constants']);
    }

    public function testExtractMetadata(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $content = '<?php
/**
 * Service file
 */
namespace App\Service;

/**
 * User service class
 */
class UserService {
    public function getUser(): void {}
}
';
        $ast = $parser->parse($content);
        $this->assertNotNull($ast);

        $metadata = $this->analyzer->extractMetadata($ast, $content);

        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('namespace', $metadata);
        $this->assertArrayHasKey('file_doc', $metadata);
        $this->assertArrayHasKey('classes', $metadata);
        $this->assertEquals('App\Service', $metadata['namespace']);
        $this->assertNotNull($metadata['file_doc']);
        $this->assertStringContainsString('Service file', $metadata['file_doc']);
        $this->assertArrayHasKey('UserService', $metadata['classes']);
    }

    public function testExtractNamespace(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
namespace App\Service;

class UserService {}
');
        $this->assertNotNull($ast);

        $namespace = $this->analyzer->extractNamespace($ast);

        $this->assertEquals('App\Service', $namespace);
    }

    public function testExtractNamespaceEmpty(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php

class GlobalClass {}
');
        $this->assertNotNull($ast);

        $namespace = $this->analyzer->extractNamespace($ast);

        $this->assertEquals('', $namespace);
    }

    public function testExtractDependenciesEmpty(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
class SimpleClass {
    public function simpleMethod(): void {}
}
');
        $this->assertNotNull($ast);

        $dependencies = $this->analyzer->extractDependencies($ast);

        $this->assertIsArray($dependencies);
        $this->assertEmpty($dependencies);
    }

    public function testExtractSymbolsMultipleClasses(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
namespace App;

class Class1 {}
class Class2 {}

function func1(): void {}
function func2(): void {}
');
        $this->assertNotNull($ast);

        $symbols = $this->analyzer->extractSymbols($ast);

        $this->assertCount(2, $symbols['classes']);
        $this->assertCount(2, $symbols['functions']);
        $this->assertContains('App\Class1', $symbols['classes']);
        $this->assertContains('App\Class2', $symbols['classes']);
        $this->assertContains('App\func1', $symbols['functions']);
        $this->assertContains('App\func2', $symbols['functions']);
    }

    public function testExtractMetadataWithoutDocComment(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $content = '<?php
namespace App\Service;

class UserService {
    public function getUser(): void {}
}
';
        $ast = $parser->parse($content);
        $this->assertNotNull($ast);

        $metadata = $this->analyzer->extractMetadata($ast, $content);

        $this->assertNull($metadata['file_doc']);
        $this->assertEquals('App\Service', $metadata['namespace']);
        $this->assertArrayHasKey('UserService', $metadata['classes']);
    }
}
