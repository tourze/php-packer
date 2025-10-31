<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Storage;

use PhpPacker\Storage\TestDataExtractor;
use PhpPacker\Storage\TypeConverter;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TestDataExtractor::class)]
final class TestDataExtractorTest extends TestCase
{
    private TestDataExtractor $extractor;

    private TypeConverter $typeConverter;

    protected function setUp(): void
    {
        $this->typeConverter = new TypeConverter();
        $this->extractor = new TestDataExtractor($this->typeConverter);
    }

    public function testExtractTestData(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
class TestClass {
    public function testMethod(): void {}
}
');

        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $classNode = $ast[0];
        $data = $this->extractor->extractTestData($classNode);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('type', $data);
        $this->assertEquals('Stmt_Class', $data['type']);
        $this->assertArrayHasKey('name', $data);
        $this->assertEquals('TestClass', $data['name']);
    }

    public function testExtractTestDataWithPosition(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
class TestClass {
    public function testMethod(): void {}
}
');

        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $classNode = $ast[0];
        $data = $this->extractor->extractTestData($classNode, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('position', $data);
        $this->assertArrayHasKey('start_line', $data['position']);
        $this->assertArrayHasKey('end_line', $data['position']);
    }

    public function testExtractClassData(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
abstract class AbstractTestClass extends BaseClass implements TestInterface {
    public function testMethod(): void {}
}
');

        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $classNode = $ast[0];
        $data = $this->extractor->extractTestData($classNode);

        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('extends', $data);
        $this->assertArrayHasKey('implements', $data);
        $this->assertArrayHasKey('is_abstract', $data);
        $this->assertArrayHasKey('is_final', $data);
        $this->assertEquals('AbstractTestClass', $data['name']);
        $this->assertEquals('BaseClass', $data['extends']);
        $this->assertContains('TestInterface', $data['implements']);
        $this->assertTrue($data['is_abstract']);
        $this->assertFalse($data['is_final']);
    }

    public function testExtractInterfaceData(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
interface TestInterface extends BaseInterface {
    public function testMethod(): void;
}
');

        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $interfaceNode = $ast[0];
        $data = $this->extractor->extractTestData($interfaceNode);

        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('extends', $data);
        $this->assertEquals('TestInterface', $data['name']);
        $this->assertContains('BaseInterface', $data['extends']);
    }

    public function testExtractTraitData(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
trait TestTrait {
    public function testMethod(): void {}
}
');

        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $traitNode = $ast[0];
        $data = $this->extractor->extractTestData($traitNode);

        $this->assertArrayHasKey('name', $data);
        $this->assertEquals('TestTrait', $data['name']);
    }

    public function testExtractFunctionData(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
function testFunction(string $param, int $number = 10): bool {
    return true;
}
');

        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $functionNode = $ast[0];
        $data = $this->extractor->extractTestData($functionNode);

        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('return_type', $data);
        $this->assertArrayHasKey('params', $data);
        $this->assertEquals('testFunction', $data['name']);
        $this->assertEquals('bool', $data['return_type']);
        $this->assertCount(2, $data['params']);
        $this->assertArrayHasKey('name', $data['params'][0]);
        $this->assertArrayHasKey('type', $data['params'][0]);
        $this->assertArrayHasKey('has_default', $data['params'][0]);
        $this->assertArrayHasKey('has_default', $data['params'][1]);
        $this->assertEquals('param', $data['params'][0]['name']);
        $this->assertEquals('string', $data['params'][0]['type']);
        $this->assertFalse($data['params'][0]['has_default']);
        $this->assertTrue($data['params'][1]['has_default']);
    }

    public function testExtractMethodData(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
class TestClass {
    public static function testMethod(array $items): void {}
    private function privateMethod(): string { return ""; }
    protected abstract function abstractMethod(): int;
}
');

        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $classNode = $ast[0];
        $this->assertInstanceOf(Node\Stmt\Class_::class, $classNode);
        $publicMethod = $classNode->stmts[0];
        $privateMethod = $classNode->stmts[1];
        $abstractMethod = $classNode->stmts[2];

        $publicData = $this->extractor->extractTestData($publicMethod);
        $this->assertArrayHasKey('name', $publicData);
        $this->assertArrayHasKey('visibility', $publicData);
        $this->assertArrayHasKey('is_static', $publicData);
        $this->assertArrayHasKey('is_final', $publicData);
        $this->assertArrayHasKey('is_abstract', $publicData);
        $this->assertEquals('testMethod', $publicData['name']);
        $this->assertEquals('public', $publicData['visibility']);
        $this->assertTrue($publicData['is_static']);
        $this->assertFalse($publicData['is_final']);
        $this->assertFalse($publicData['is_abstract']);

        $privateData = $this->extractor->extractTestData($privateMethod);
        $this->assertArrayHasKey('visibility', $privateData);
        $this->assertArrayHasKey('is_static', $privateData);
        $this->assertEquals('private', $privateData['visibility']);
        $this->assertFalse($privateData['is_static']);

        $abstractData = $this->extractor->extractTestData($abstractMethod);
        $this->assertArrayHasKey('visibility', $abstractData);
        $this->assertArrayHasKey('is_abstract', $abstractData);
        $this->assertEquals('protected', $abstractData['visibility']);
        $this->assertTrue($abstractData['is_abstract']);
    }

    public function testExtractPropertyData(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
class TestClass {
    private string $name = "default";
    public static array $items;
    protected readonly int $id;
}
');

        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $classNode = $ast[0];
        $this->assertInstanceOf(Node\Stmt\Class_::class, $classNode);
        $nameProperty = $classNode->stmts[0];
        $itemsProperty = $classNode->stmts[1];
        $idProperty = $classNode->stmts[2];

        $nameData = $this->extractor->extractTestData($nameProperty);
        $this->assertArrayHasKey('name', $nameData);
        $this->assertArrayHasKey('visibility', $nameData);
        $this->assertArrayHasKey('has_default', $nameData);
        $this->assertArrayHasKey('is_static', $nameData);
        $this->assertArrayHasKey('is_readonly', $nameData);
        $this->assertEquals('name', $nameData['name']);
        $this->assertEquals('private', $nameData['visibility']);
        $this->assertTrue($nameData['has_default']);
        $this->assertFalse($nameData['is_static']);
        $this->assertFalse($nameData['is_readonly']);

        $itemsData = $this->extractor->extractTestData($itemsProperty);
        $this->assertArrayHasKey('visibility', $itemsData);
        $this->assertArrayHasKey('is_static', $itemsData);
        $this->assertEquals('public', $itemsData['visibility']);
        $this->assertTrue($itemsData['is_static']);

        $idData = $this->extractor->extractTestData($idProperty);
        $this->assertArrayHasKey('is_readonly', $idData);
        $this->assertTrue($idData['is_readonly']);
    }

    public function testExtractNamespaceData(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
namespace App\Service;

class TestClass {}
');

        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $namespaceNode = $ast[0];
        $data = $this->extractor->extractTestData($namespaceNode);

        $this->assertArrayHasKey('name', $data);
        $this->assertEquals('App\Service', $data['name']);
    }

    public function testExtractUseData(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
use App\Service\UserService;
use App\Entity\User as UserEntity;
use function array_map;
');

        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $this->assertCount(3, $ast);
        $useNode = $ast[0];
        $data = $this->extractor->extractTestData($useNode);

        $this->assertArrayHasKey('uses', $data);
        $this->assertCount(1, $data['uses']);
        $this->assertArrayHasKey('name', $data['uses'][0]);
        $this->assertArrayHasKey('alias', $data['uses'][0]);
        $this->assertEquals('App\Service\UserService', $data['uses'][0]['name']);
        $this->assertNull($data['uses'][0]['alias']);

        $aliasUseNode = $ast[1];
        $aliasData = $this->extractor->extractTestData($aliasUseNode);
        $this->assertArrayHasKey('uses', $aliasData);
        $this->assertArrayHasKey('alias', $aliasData['uses'][0]);
        $this->assertEquals('UserEntity', $aliasData['uses'][0]['alias']);
    }

    public function testExtractNewExprData(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
$obj = new TestClass($param1, $param2);
');

        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $exprStmt = $ast[0];
        $this->assertInstanceOf(Node\Stmt\Expression::class, $exprStmt);
        $assignExpr = $exprStmt->expr;
        $this->assertInstanceOf(Node\Expr\Assign::class, $assignExpr);
        $newExpr = $assignExpr->expr;
        $data = $this->extractor->extractTestData($newExpr);

        $this->assertArrayHasKey('class', $data);
        $this->assertArrayHasKey('args_count', $data);
        $this->assertEquals('TestClass', $data['class']);
        $this->assertEquals(2, $data['args_count']);
    }

    public function testExtractStaticCallData(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
TestClass::staticMethod($arg1, $arg2, $arg3);
');

        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $exprStmt = $ast[0];
        $this->assertInstanceOf(Node\Stmt\Expression::class, $exprStmt);
        $staticCall = $exprStmt->expr;
        $data = $this->extractor->extractTestData($staticCall);

        $this->assertArrayHasKey('class', $data);
        $this->assertArrayHasKey('method', $data);
        $this->assertArrayHasKey('args_count', $data);
        $this->assertEquals('TestClass', $data['class']);
        $this->assertEquals('staticMethod', $data['method']);
        $this->assertEquals(3, $data['args_count']);
    }

    public function testExtractConstData(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
const FIRST_CONST = "value1", SECOND_CONST = 42;
');

        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $constNode = $ast[0];
        $data = $this->extractor->extractTestData($constNode);

        $this->assertArrayHasKey('consts', $data);
        $this->assertCount(2, $data['consts']);
        $this->assertArrayHasKey('name', $data['consts'][0]);
        $this->assertArrayHasKey('name', $data['consts'][1]);
        $this->assertEquals('FIRST_CONST', $data['consts'][0]['name']);
        $this->assertEquals('SECOND_CONST', $data['consts'][1]['name']);
    }

    public function testExtractTraitUseData(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php
class TestClass {
    use TraitA, TraitB {
        TraitA::method insteadof TraitB;
    }
}
');

        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
        $classNode = $ast[0];
        $this->assertInstanceOf(Node\Stmt\Class_::class, $classNode);
        $traitUseNode = $classNode->stmts[0];
        $data = $this->extractor->extractTestData($traitUseNode);

        $this->assertArrayHasKey('traits', $data);
        $this->assertArrayHasKey('adaptations', $data);
        $this->assertContains('TraitA', $data['traits']);
        $this->assertContains('TraitB', $data['traits']);
        $this->assertEquals(1, $data['adaptations']);
    }
}
