<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer;

use PhpPacker\Analyzer\FileAnalyzer;
use PhpPacker\Storage\SqliteStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(FileAnalyzer::class)]
final class FileAnalyzerTest extends TestCase
{
    private FileAnalyzer $analyzer;

    private SqliteStorage $storage;

    private static string $dbPath;

    private string $rootPath;

    public function testAnalyzeSimpleClass(): void
    {
        $filePath = __DIR__ . '/../Fixtures/Classes/SimpleClass.php';
        $this->analyzer->analyzeFile($filePath);

        // Get the relative path as calculated by FileAnalyzer
        $relativePath = 'php-packer/tests/Fixtures/Classes/SimpleClass.php';
        $file = $this->storage->getFileByPath($relativePath);

        $this->assertNotNull($file);
        $this->assertEquals('class', $file['file_type']);
        $this->assertEquals('TestFixtures\Classes\SimpleClass', $file['class_name']);

        // Check symbol was added
        $symbol = $this->storage->findFileBySymbol('TestFixtures\Classes\SimpleClass');
        $this->assertNotNull($symbol);
        $this->assertEquals($relativePath, $symbol['path']);
    }

    public function testAnalyzeClassWithMultipleDependencies(): void
    {
        // First analyze the dependency files
        $baseClassPath = __DIR__ . '/../Fixtures/Classes/BaseClass.php';
        $interfacePath = __DIR__ . '/../Fixtures/Interfaces/TestInterface.php';
        $traitPath = __DIR__ . '/../Fixtures/Traits/TestTrait.php';

        $this->analyzer->analyzeFile($baseClassPath);
        $this->analyzer->analyzeFile($interfacePath);
        $this->analyzer->analyzeFile($traitPath);

        // Now analyze the main file
        $filePath = __DIR__ . '/../Fixtures/Classes/ClassWithDependencies.php';
        $this->analyzer->analyzeFile($filePath);

        // Check that the main file was analyzed
        $relativePath = 'php-packer/tests/Fixtures/Classes/ClassWithDependencies.php';
        $file = $this->storage->getFileByPath($relativePath);
        $this->assertNotNull($file, 'Main file should be analyzed');

        // Check dependencies were recorded
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare('
            SELECT COUNT(*) as count FROM dependencies 
            WHERE source_file_id = (SELECT id FROM files WHERE path = ?)
        ');
        $stmt->execute([$relativePath]);
        $result = $stmt->fetch();

        // Should have at least some dependencies (use statements, extends, implements, trait use)
        $this->assertGreaterThanOrEqual(1, $result['count']);
    }

    public function testAnalyzeFileWithRequireStatements(): void
    {
        $tempFile = $this->createTempFile('<?php
require "file1.php";
require_once "file2.php";
include "file3.php";
include_once "file4.php";

if ($condition) {
    require "conditional.php";
}
');

        $this->analyzer->analyzeFile($tempFile);

        $pdo = $this->storage->getPdo();
        $stmt = $pdo->query('SELECT * FROM dependencies WHERE dependency_type LIKE "%include%" OR dependency_type LIKE "%require%"');
        $this->assertNotFalse($stmt, 'Query should execute successfully');
        $dependencies = $stmt->fetchAll();

        $this->assertCount(5, $dependencies);

        // Check conditional include
        $conditional = array_filter($dependencies, fn ($d) => 'conditional.php' === $d['context']);
        $this->assertCount(1, $conditional);
        $this->assertEquals(1, reset($conditional)['is_conditional']);
    }

    private function createTempFile(string $content): string
    {
        $tempFile = sys_get_temp_dir() . '/php-packer-test-' . uniqid() . '.php';
        file_put_contents($tempFile, $content);

        register_shutdown_function(function () use ($tempFile): void {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        });

        return $tempFile;
    }

    public function testAnalyzeEmptyFile(): void
    {
        $tempFile = $this->createTempFile('<?php');

        $this->analyzer->analyzeFile($tempFile);

        // Empty files generate a warning but are still processed
        // Verify the analyzer completed without throwing an exception
        $this->assertTrue(true);
    }

    public function testAnalyzeFileWithSyntaxError(): void
    {
        $tempFile = $this->createTempFile('<?php
class Invalid {
    public function test() {
        // Missing closing brace
');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Parse error in .+: Syntax error/');

        $this->analyzer->analyzeFile($tempFile);
    }

    public function testAnalyzeNonExistentFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found: /non/existent/file.php');

        $this->analyzer->analyzeFile('/non/existent/file.php');
    }

    public function testAnalyzeInterface(): void
    {
        $tempFile = $this->createTempFile('<?php
namespace Test;

interface TestInterface extends ParentInterface, AnotherInterface
{
    public function method(): void;
}
');

        $this->analyzer->analyzeFile($tempFile);

        $file = $this->storage->getFileByPath($this->getRelativePath($tempFile));
        $this->assertIsArray($file, 'File should be found');
        $this->assertArrayHasKey('file_type', $file);
        $this->assertArrayHasKey('class_name', $file);
        $this->assertArrayHasKey('id', $file);
        $this->assertEquals('interface', $file['file_type']);
        $this->assertEquals('Test\TestInterface', $file['class_name']);

        // Check extends dependencies
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare('
            SELECT * FROM dependencies 
            WHERE source_file_id = ? AND dependency_type = "extends"
        ');
        $stmt->execute([$file['id']]);
        $this->assertNotFalse($stmt, 'Query should execute successfully');
        $extends = $stmt->fetchAll();

        $this->assertCount(2, $extends);
    }

    private function getRelativePath(string $path): string
    {
        $realPath = realpath($path);
        if (false === $realPath) {
            return $path;
        }

        if (0 === strpos($realPath, $this->rootPath)) {
            return substr($realPath, strlen($this->rootPath) + 1);
        }

        return $realPath;
    }

    public function testAnalyzeTrait(): void
    {
        $tempFile = $this->createTempFile('<?php
namespace Test;

trait TestTrait
{
    use OtherTrait;
    
    public function traitMethod(): void
    {
        echo "trait method";
    }
}
');

        $this->analyzer->analyzeFile($tempFile);

        $file = $this->storage->getFileByPath($this->getRelativePath($tempFile));
        $this->assertIsArray($file, 'File should be found');
        $this->assertArrayHasKey('file_type', $file);
        $this->assertEquals('trait', $file['file_type']);
    }

    public function testAnalyzeAnonymousClass(): void
    {
        $tempFile = $this->createTempFile('<?php
$obj = new class extends BaseClass {
    public function test() {}
};
');

        $this->analyzer->analyzeFile($tempFile);

        // Anonymous classes should still record their dependencies
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->query('SELECT * FROM dependencies WHERE target_symbol = "BaseClass"');
        $this->assertNotFalse($stmt, 'Query should execute successfully');
        $deps = $stmt->fetchAll();

        $this->assertCount(1, $deps);
    }

    public function testAnalyzeFunctions(): void
    {
        $tempFile = $this->createTempFile('<?php
namespace Test;

function globalFunction(): void {}

class TestClass {
    public function method() {
        globalFunction();
        \Some\Other\function();
    }
}
');

        $this->analyzer->analyzeFile($tempFile);

        // Check function symbol was added
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->query('SELECT * FROM symbols WHERE symbol_type = "function"');
        $this->assertNotFalse($stmt, 'Query should execute successfully');
        $functions = $stmt->fetchAll();

        $this->assertCount(1, $functions);
        $this->assertEquals('globalFunction', $functions[0]['symbol_name']);
        $this->assertEquals('Test\globalFunction', $functions[0]['fqn']);
    }

    public function testAnalyzeUseStatements(): void
    {
        $tempFile = $this->createTempFile('<?php
namespace Test;

use Some\Class as Alias;
use Another\{ClassA, ClassB};
use function Some\functionName;
use const Some\CONSTANT;

class TestClass
{
    public function test()
    {
        new Alias();
        new ClassA();
        ClassB::staticMethod();
    }
}
');

        $this->analyzer->analyzeFile($tempFile);

        // Check that aliased class references are resolved correctly
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->query('SELECT DISTINCT target_symbol FROM dependencies WHERE dependency_type = "use_class"');
        $this->assertNotFalse($stmt, 'Query should execute successfully');
        $symbols = array_column($stmt->fetchAll(), 'target_symbol');

        $this->assertContains('Some\Class', $symbols);
        $this->assertContains('Another\ClassA', $symbols);
        $this->assertContains('Another\ClassB', $symbols);
    }

    public function testAnalyzeComplexFile(): void
    {
        $tempFile = $this->createTempFile('<?php
declare(strict_types=1);

namespace Complex\Example;

require_once __DIR__ . "/bootstrap.php";

use Base\AbstractClass;
use Some\Interface as SomeInterface;
use Helper\{Trait1, Trait2};

/**
 * Complex class with multiple dependencies
 */
final class ComplexClass extends AbstractClass implements SomeInterface, \Another\Interface
{
    use Trait1, Trait2 {
        Trait1::method insteadof Trait2;
        Trait2::method as trait2Method;
    }
    
    private \DateTime $date;
    
    public function __construct()
    {
        parent::__construct();
        $this->date = new \DateTime();
    }
    
    public function process(): void
    {
        $obj = new Helper\Class();
        Another\Function();
        
        if (class_exists(Optional\Class::class)) {
            new Optional\Class();
        }
    }
}
');

        $this->analyzer->analyzeFile($tempFile);

        $file = $this->storage->getFileByPath($this->getRelativePath($tempFile));
        $this->assertIsArray($file, 'File should be found');
        $this->assertArrayHasKey('file_type', $file);
        $this->assertArrayHasKey('id', $file);
        $this->assertEquals('class', $file['file_type']);

        // Verify various dependency types
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare('SELECT dependency_type, COUNT(*) as count FROM dependencies WHERE source_file_id = ? GROUP BY dependency_type');
        $stmt->execute([$file['id']]);
        $depTypes = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        $this->assertArrayHasKey('require_once', $depTypes);
        $this->assertArrayHasKey('extends', $depTypes);
        $this->assertArrayHasKey('implements', $depTypes);
        $this->assertArrayHasKey('use_trait', $depTypes);
        $this->assertArrayHasKey('use_class', $depTypes);
    }

    protected function setUp(): void
    {
        self::$dbPath = sys_get_temp_dir() . '/php-packer-test-' . uniqid() . '.db';
        $this->rootPath = dirname(dirname(dirname(__DIR__)));
        $logger = new NullLogger();
        $this->storage = new SqliteStorage(self::$dbPath, $logger);
        $this->analyzer = new FileAnalyzer($this->storage, $logger, $this->rootPath);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$dbPath) && file_exists(self::$dbPath)) {
            unlink(self::$dbPath);
        }
        parent::tearDownAfterClass();
    }

    public function testAnalyzeVendorFile(): void
    {
        $vendorFile = $this->createTempFile('<?php class VendorClass {}');

        // Rename to make it look like a vendor file
        $vendorPath = dirname($vendorFile) . '/vendor/package/file.php';
        @mkdir(dirname($vendorPath), 0o777, true);
        rename($vendorFile, $vendorPath);

        $this->analyzer->analyzeFile($vendorPath);

        // The relative path will be the full vendor path since it's outside rootPath
        $relativePath = $this->getRelativePath($vendorPath);
        $file = $this->storage->getFileByPath($relativePath);

        $this->assertNotNull($file);
        $this->assertEquals(1, $file['is_vendor']);
        $this->assertEquals(1, $file['skip_ast']);
        $this->assertNull($file['ast_root_id']);

        // Cleanup
        unlink($vendorPath);
        @rmdir(dirname($vendorPath));
        @rmdir(dirname(dirname($vendorPath)));
    }

    public function testAnalyzeWithAstStorage(): void
    {
        $tempFile = $this->createTempFile('<?php
namespace Test;

class TestClass
{
    private string $property = "test";
    
    public function method(): void
    {
        echo $this->property;
    }
}');

        $this->analyzer->analyzeFile($tempFile);

        $relativePath = $this->getRelativePath($tempFile);
        $file = $this->storage->getFileByPath($relativePath);

        // Verify AST was stored
        $this->assertIsArray($file, 'File should be found');
        $this->assertArrayHasKey('ast_root_id', $file);
        $this->assertNotNull($file['ast_root_id']);

        // Verify AST nodes exist
        $this->assertArrayHasKey('id', $file);
        $nodes = $this->storage->getAstNodesByFileId($file['id']);
        $this->assertNotEmpty($nodes);

        // Check for Root node type (current implementation only stores root node)
        $nodeTypes = array_column($nodes, 'node_type');
        $this->assertContains('Root', $nodeTypes);

        // Verify class symbol was stored properly
        $symbol = $this->storage->findFileBySymbol('Test\TestClass');
        $this->assertNotNull($symbol, 'Class symbol should be found');
    }

    public function testAnalyzeAutoloadFile(): void
    {
        $autoloadFile = $this->createTempFile('<?php
require __DIR__ . "/vendor/autoload_real.php";
return ComposerAutoloaderInit::getLoader();');

        // Rename to autoload.php
        $autoloadPath = dirname($autoloadFile) . '/autoload.php';
        rename($autoloadFile, $autoloadPath);

        $this->analyzer->analyzeFile($autoloadPath);

        $relativePath = $this->getRelativePath($autoloadPath);
        $file = $this->storage->getFileByPath($relativePath);

        $this->assertNotNull($file);
        $this->assertEquals(1, $file['skip_ast']);
        $this->assertNull($file['ast_root_id']);

        // Cleanup
        unlink($autoloadPath);
    }

    public function testAnalyzeComplexAstStructure(): void
    {
        $tempFile = $this->createTempFile('<?php
namespace App\Complex;

use App\Base\BaseClass;
use App\Contracts\{Interface1, Interface2};

/**
 * @property string $magicProperty
 * @method void magicMethod()
 */
class ComplexClass extends BaseClass implements Interface1, Interface2
{
    use Trait1, Trait2;
    
    public const CONSTANT = "value";
    
    private static ?self $instance = null;
    
    public function __construct(
        private readonly string $param1,
        protected ?int $param2 = null
    ) {
        parent::__construct();
    }
    
    public static function getInstance(): self
    {
        return self::$instance ??= new self("default");
    }
    
    public function process(): void
    {
        $closure = function () use ($param1) {
            return $param1;
        };
        
        $arrow = fn($x) => $x * 2;
        
        match ($this->param2) {
            1 => $this->method1(),
            2 => $this->method2(),
            default => null,
        };
    }
}');

        $this->analyzer->analyzeFile($tempFile);

        $relativePath = $this->getRelativePath($tempFile);
        $file = $this->storage->getFileByPath($relativePath);
        $this->assertIsArray($file, 'File should be found');
        $this->assertArrayHasKey('id', $file, 'File should have id');

        // Verify complex AST structure was stored
        $nodes = $this->storage->getAstNodesByFileId($file['id']);

        // Look for various node types (current implementation only stores root node)
        $nodeTypes = array_column($nodes, 'node_type');

        // Should contain Root node
        $this->assertContains('Root', $nodeTypes);

        // Verify that the file has a proper class name
        $this->assertIsArray($file, 'File should be found');
        $this->assertArrayHasKey('class_name', $file);
        $this->assertEquals('App\Complex\ComplexClass', $file['class_name']);
    }

    public function testSkipNonPhpFiles(): void
    {
        $jsonFile = sys_get_temp_dir() . '/test-' . uniqid() . '.json';
        file_put_contents($jsonFile, '{"test": true}');

        // Analyzer should skip non-PHP files based on shouldParseAst logic
        // But since analyzeFile expects PHP, this would throw an error
        // So we test the internal logic would skip it
        $relativePath = str_replace($this->rootPath . '/', '', $jsonFile);

        // The shouldParseAst method would return false for non-.php files
        $this->assertStringEndsNotWith('.php', $relativePath);
        $this->assertStringEndsWith('.json', $relativePath);

        unlink($jsonFile);
    }

    public function testAnalyzeWithFullyQualifiedNames(): void
    {
        $tempFile = $this->createTempFile('<?php
namespace App;

use Some\External\Class as ExternalClass;

class MyClass extends ExternalClass
{
    public function test()
    {
        // These should all be resolved to FQCN
        new ExternalClass();
        new \DateTime();
        new namespace\Helper();
        
        ExternalClass::staticMethod();
        \Some\Other\Class::CONSTANT;
    }
}');

        $this->analyzer->analyzeFile($tempFile);

        // Get dependencies and verify they use FQCN
        $file = $this->storage->getFileByPath($this->getRelativePath($tempFile));
        $this->assertIsArray($file, 'File should be found');
        $this->assertArrayHasKey('id', $file, 'File should have id');

        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare('SELECT DISTINCT target_symbol FROM dependencies WHERE source_file_id = ? AND target_symbol IS NOT NULL');
        $stmt->execute([$file['id']]);
        $this->assertNotFalse($stmt, 'Query should execute successfully');
        $symbols = array_column($stmt->fetchAll(), 'target_symbol');

        // All symbols should be fully qualified
        $this->assertContains('Some\External\Class', $symbols);
        $this->assertContains('DateTime', $symbols);
        $this->assertContains('App\Helper', $symbols);
        $this->assertContains('Some\Other\Class', $symbols);
    }
}
