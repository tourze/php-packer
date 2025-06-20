<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Unit\Analyzer;

use PhpPacker\Analyzer\FileAnalyzer;
use PhpPacker\Storage\SqliteStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class FileAnalyzerTest extends TestCase
{
    private FileAnalyzer $analyzer;
    private SqliteStorage $storage;
    private LoggerInterface $logger;
    private string $dbPath;
    private string $rootPath;

    public function testAnalyzeSimpleClass(): void
    {
        $filePath = __DIR__ . '/../../Fixtures/Classes/SimpleClass.php';
        $this->analyzer->analyzeFile($filePath);

        $relativePath = 'tests/Fixtures/Classes/SimpleClass.php';
        $file = $this->storage->getFileByPath($relativePath);

        $this->assertNotNull($file);
        $this->assertEquals('class', $file['file_type']);
        $this->assertEquals('TestFixtures\\Classes', $file['namespace']);

        // Check symbol was added
        $symbol = $this->storage->findFileBySymbol('TestFixtures\\Classes\\SimpleClass');
        $this->assertNotNull($symbol);
        $this->assertEquals($relativePath, $symbol['path']);
    }

    public function testAnalyzeClassWithMultipleDependencies(): void
    {
        // First create the dependencies
        $this->createDependencyFixtures();

        $filePath = __DIR__ . '/../../Fixtures/Classes/ClassWithDependencies.php';
        $this->analyzer->analyzeFile($filePath);

        // Check dependencies were recorded
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare('
            SELECT COUNT(*) as count FROM dependencies 
            WHERE source_file_id = (SELECT id FROM files WHERE path = ?)
        ');
        $stmt->execute(['tests/Fixtures/Classes/ClassWithDependencies.php']);
        $result = $stmt->fetch();

        // Should have: 2 use statements, 1 extends, 1 implements, 1 trait use
        $this->assertGreaterThanOrEqual(5, $result['count']);
    }

    private function createDependencyFixtures(): void
    {
        // Create base class
        $this->storage->addFile('tests/Fixtures/Classes/BaseClass.php', '<?php
namespace TestFixtures\Classes;
abstract class BaseClass {
    protected function parentMethod() {}
}');
        $this->storage->addSymbol(1, 'class', 'BaseClass', 'TestFixtures\\Classes\\BaseClass', 'TestFixtures\\Classes', 'abstract');

        // Create interface
        $this->storage->addFile('tests/Fixtures/Interfaces/TestInterface.php', '<?php
namespace TestFixtures\Interfaces;
interface TestInterface {
    public function interfaceMethod(): string;
}');
        $this->storage->addSymbol(2, 'interface', 'TestInterface', 'TestFixtures\\Interfaces\\TestInterface', 'TestFixtures\\Interfaces');

        // Create trait
        $this->storage->addFile('tests/Fixtures/Traits/TestTrait.php', '<?php
namespace TestFixtures\Traits;
trait TestTrait {
    protected function traitMethod() {}
}');
        $this->storage->addSymbol(3, 'trait', 'TestTrait', 'TestFixtures\\Traits\\TestTrait', 'TestFixtures\\Traits');
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
        $dependencies = $stmt->fetchAll();

        $this->assertCount(5, $dependencies);

        // Check conditional include
        $conditional = array_filter($dependencies, fn($d) => $d['context'] === 'conditional.php');
        $this->assertCount(1, $conditional);
        $this->assertEquals(1, reset($conditional)['is_conditional']);
    }

    private function createTempFile(string $content): string
    {
        $tempFile = sys_get_temp_dir() . '/php-packer-test-' . uniqid() . '.php';
        file_put_contents($tempFile, $content);

        register_shutdown_function(function() use ($tempFile) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        });

        return $tempFile;
    }

    public function testAnalyzeEmptyFile(): void
    {
        $tempFile = $this->createTempFile('<?php');
        
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Empty AST for file', ['file' => $tempFile]);
        
        $this->analyzer->analyzeFile($tempFile);
    }

    public function testAnalyzeFileWithSyntaxError(): void
    {
        $tempFile = $this->createTempFile('<?php
class Invalid {
    public function test() {
        // Missing closing brace
');
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Parse error in .+: Syntax error/');
        
        $this->analyzer->analyzeFile($tempFile);
    }

    public function testAnalyzeNonExistentFile(): void
    {
        $this->expectException(RuntimeException::class);
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
        $this->assertEquals('interface', $file['file_type']);
        $this->assertEquals('Test', $file['namespace']);
        
        // Check extends dependencies
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare('
            SELECT * FROM dependencies 
            WHERE source_file_id = ? AND dependency_type = "extends"
        ');
        $stmt->execute([$file['id']]);
        $extends = $stmt->fetchAll();
        
        $this->assertCount(2, $extends);
    }

    private function getRelativePath(string $path): string
    {
        $path = realpath($path);
        if (strpos($path, $this->rootPath) === 0) {
            return substr($path, strlen($this->rootPath) + 1);
        }
        return $path;
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
        $functions = $stmt->fetchAll();

        $this->assertCount(1, $functions);
        $this->assertEquals('globalFunction', $functions[0]['symbol_name']);
        $this->assertEquals('Test\\globalFunction', $functions[0]['fqn']);
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
        $symbols = array_column($stmt->fetchAll(), 'target_symbol');

        $this->assertContains('Some\\Class', $symbols);
        $this->assertContains('Another\\ClassA', $symbols);
        $this->assertContains('Another\\ClassB', $symbols);
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
        $this->dbPath = sys_get_temp_dir() . '/php-packer-test-' . uniqid() . '.db';
        $this->rootPath = dirname(dirname(dirname(__DIR__)));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->storage = new SqliteStorage($this->dbPath, $this->logger);
        $this->analyzer = new FileAnalyzer($this->storage, $this->logger, $this->rootPath);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }
}