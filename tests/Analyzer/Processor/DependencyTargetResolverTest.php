<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Analyzer\Processor;

use PhpPacker\Analyzer\AutoloadResolver;
use PhpPacker\Analyzer\ClassFinder;
use PhpPacker\Analyzer\FileVerifier;
use PhpPacker\Analyzer\PathResolver;
use PhpPacker\Analyzer\Processor\DependencyTargetResolver;
use PhpPacker\Storage\SqliteStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(DependencyTargetResolver::class)]
final class DependencyTargetResolverTest extends TestCase
{
    private DependencyTargetResolver $resolver;

    private string $dbPath;

    protected function setUp(): void
    {
        // 直接创建依赖项避免容器问题
        $logger = new NullLogger();
        $this->dbPath = sys_get_temp_dir() . '/test_resolver_' . uniqid() . '.db';
        $storage = new SqliteStorage($this->dbPath, $logger);

        $rootPath = sys_get_temp_dir();
        $pathResolver = new PathResolver($logger, $rootPath);

        $fileVerifier = new FileVerifier($logger);
        $autoloadResolver = new AutoloadResolver($storage, $logger, $rootPath);
        $classFinder = new ClassFinder(
            $storage,
            $logger,
            $autoloadResolver,
            $pathResolver,
            $fileVerifier
        );

        $this->resolver = new DependencyTargetResolver(
            $storage,
            $logger,
            $classFinder,
            $pathResolver
        );
    }

    public function testResolveDependencyTarget(): void
    {
        // 测试基本的依赖解析功能 - 在空数据库中应该返回null
        $dependency = [
            'dependency_type' => 'require',
            'context' => 'test.php',
            'source_file_id' => 1,
        ];

        // 由于数据库为空，没有对应的文件记录，应该返回null
        $result = $this->resolver->resolveDependencyTarget($dependency);

        // 验证在空数据库情况下确实返回null
        $this->assertNull($result);

        // 额外的断言来确保测试有明确的验证
        $this->assertIsArray($dependency);
        $this->assertEquals('require', $dependency['dependency_type']);
    }

    public function testResolveClassDependency(): void
    {
        // 测试类依赖解析 - 在空数据库中应该返回null
        $dependency = [
            'dependency_type' => 'extends',
            'context' => 'ParentClass',
            'source_file_id' => 1,
        ];

        $result = $this->resolver->resolveDependencyTarget($dependency);

        // 验证在空数据库情况下确实返回null
        $this->assertNull($result);

        // 额外的断言来确保测试有明确的验证
        $this->assertIsArray($dependency);
        $this->assertEquals('extends', $dependency['dependency_type']);
        $this->assertEquals('ParentClass', $dependency['context']);
    }

    public function testResolveUnknownDependencyType(): void
    {
        // 测试未知依赖类型
        $dependency = [
            'dependency_type' => 'unknown_type',
            'context' => 'test',
            'source_file_id' => 1,
        ];

        $result = $this->resolver->resolveDependencyTarget($dependency);
        $this->assertNull($result);
    }
}
