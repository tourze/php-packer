<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\ProjectDependencyDetector;
use PhpPacker\Merger\ProjectFileProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ProjectDependencyDetector::class)]
final class ProjectDependencyDetectorTest extends TestCase
{
    private ProjectDependencyDetector $detector;

    private static string $tempDir;

    protected function setUp(): void
    {
        // 创建 mock 依赖
        $processor = $this->createMock(ProjectFileProcessor::class);
        $this->detector = new ProjectDependencyDetector($processor);
        self::$tempDir = sys_get_temp_dir() . '/dependency-detector-test-' . uniqid();
        mkdir(self::$tempDir, 0o777, true);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$tempDir)) {
            self::removeDirectory(self::$tempDir);
        }
    }

    public function testDetectCircularDependencies(): void
    {
        $file1 = $this->createFile('Class1.php', '<?php
namespace App;
use App\Class2;
class Class1 {
    private Class2 $class2;
}
');

        $file2 = $this->createFile('Class2.php', '<?php
namespace App;
use App\Class1;
class Class2 {
    private Class1 $class1;
}
');

        // 配置 mock 返回包含循环依赖的数据
        $processor = $this->createMock(ProjectFileProcessor::class);
        $processor->method('processFile')->willReturnCallback(function ($filePath) use ($file1, $file2) {
            if ($filePath === $file1) {
                return [
                    'ast' => [],
                    'dependencies' => ['App\Class2'], // 依赖 Class2
                    'symbols' => ['classes' => ['App\Class1'], 'functions' => [], 'constants' => []],
                    'metadata' => [],
                    'path' => $file1,
                ];
            }
            if ($filePath === $file2) {
                return [
                    'ast' => [],
                    'dependencies' => ['App\Class1'], // 依赖 Class1，形成循环
                    'symbols' => ['classes' => ['App\Class2'], 'functions' => [], 'constants' => []],
                    'metadata' => [],
                    'path' => $file2,
                ];
            }

            return [
                'ast' => [],
                'dependencies' => [],
                'symbols' => ['classes' => [], 'functions' => [], 'constants' => []],
                'metadata' => [],
                'path' => $filePath,
            ];
        });

        $detector = new ProjectDependencyDetector($processor);
        $circularDeps = $detector->detectCircularDependencies([$file1, $file2]);

        $this->assertIsArray($circularDeps);
        $this->assertNotEmpty($circularDeps);
        $this->assertCount(2, $circularDeps);
        $this->assertContains('App\Class1', $circularDeps);
        $this->assertContains('App\Class2', $circularDeps);
    }

    public function testDetectCircularDependenciesNone(): void
    {
        $file1 = $this->createFile('BaseClass.php', '<?php
namespace App;
class BaseClass {}
');

        $file2 = $this->createFile('ChildClass.php', '<?php
namespace App;
use App\BaseClass;
class ChildClass extends BaseClass {}
');

        // 配置 mock 返回无循环依赖的数据
        $processor = $this->createMock(ProjectFileProcessor::class);
        $processor->method('processFile')->willReturnCallback(function ($filePath) use ($file1, $file2) {
            if ($filePath === $file1) {
                return [
                    'ast' => [],
                    'dependencies' => [], // BaseClass 没有依赖
                    'symbols' => ['classes' => ['App\BaseClass'], 'functions' => [], 'constants' => []],
                    'metadata' => [],
                    'path' => $file1,
                ];
            }
            if ($filePath === $file2) {
                return [
                    'ast' => [],
                    'dependencies' => ['App\BaseClass'], // ChildClass 依赖 BaseClass，但不是循环
                    'symbols' => ['classes' => ['App\ChildClass'], 'functions' => [], 'constants' => []],
                    'metadata' => [],
                    'path' => $file2,
                ];
            }

            return [
                'ast' => [],
                'dependencies' => [],
                'symbols' => ['classes' => [], 'functions' => [], 'constants' => []],
                'metadata' => [],
                'path' => $filePath,
            ];
        });

        $detector = new ProjectDependencyDetector($processor);
        $circularDeps = $detector->detectCircularDependencies([$file1, $file2]);

        $this->assertIsArray($circularDeps);
        $this->assertEmpty($circularDeps);
    }

    public function testFilterProjectDependencies(): void
    {
        $dependencies = [
            'App\Service\UserService',
            'App\Entity\User',
            'Vendor\External\Library',
            'DateTime',
            'stdClass',
            'My\Project\Helper',
        ];

        $projectNamespaces = ['App\\', 'My\Project\\'];
        $filtered = $this->detector->filterProjectDependencies($dependencies, $projectNamespaces);

        $this->assertContains('App\Service\UserService', $filtered);
        $this->assertContains('App\Entity\User', $filtered);
        $this->assertContains('My\Project\Helper', $filtered);
        $this->assertNotContains('Vendor\External\Library', $filtered);
        $this->assertNotContains('DateTime', $filtered);
        $this->assertNotContains('stdClass', $filtered);
    }

    public function testFilterProjectDependenciesEmpty(): void
    {
        $dependencies = [
            'DateTime',
            'stdClass',
            'Exception',
        ];

        $projectNamespaces = ['App\\'];
        $filtered = $this->detector->filterProjectDependencies($dependencies, $projectNamespaces);

        $this->assertEmpty($filtered);
    }

    public function testFilterProjectDependenciesEmptyNamespaces(): void
    {
        $dependencies = [
            'App\Service\UserService',
            'App\Entity\User',
        ];

        $projectNamespaces = [];
        $filtered = $this->detector->filterProjectDependencies($dependencies, $projectNamespaces);

        $this->assertEmpty($filtered);
    }

    public function testDetectComplexCircularDependencies(): void
    {
        $file1 = $this->createFile('A.php', '<?php
namespace App;
use App\B;
class A {
    private B $b;
}
');

        $file2 = $this->createFile('B.php', '<?php
namespace App;
use App\C;
class B {
    private C $c;
}
');

        $file3 = $this->createFile('C.php', '<?php
namespace App;
use App\A;
class C {
    private A $a;
}
');

        // 配置 mock 返回包含复杂循环依赖的数据
        $processor = $this->createMock(ProjectFileProcessor::class);
        $processor->method('processFile')->willReturnCallback(function ($filePath) use ($file1, $file2, $file3) {
            if ($filePath === $file1) {
                return [
                    'ast' => [],
                    'dependencies' => ['App\B'], // A -> B
                    'symbols' => ['classes' => ['App\A'], 'functions' => [], 'constants' => []],
                    'metadata' => [],
                    'path' => $file1,
                ];
            }
            if ($filePath === $file2) {
                return [
                    'ast' => [],
                    'dependencies' => ['App\C'], // B -> C
                    'symbols' => ['classes' => ['App\B'], 'functions' => [], 'constants' => []],
                    'metadata' => [],
                    'path' => $file2,
                ];
            }
            if ($filePath === $file3) {
                return [
                    'ast' => [],
                    'dependencies' => ['App\A'], // C -> A，形成 A -> B -> C -> A 循环
                    'symbols' => ['classes' => ['App\C'], 'functions' => [], 'constants' => []],
                    'metadata' => [],
                    'path' => $file3,
                ];
            }

            return [
                'ast' => [],
                'dependencies' => [],
                'symbols' => ['classes' => [], 'functions' => [], 'constants' => []],
                'metadata' => [],
                'path' => $filePath,
            ];
        });

        $detector = new ProjectDependencyDetector($processor);
        $circularDeps = $detector->detectCircularDependencies([$file1, $file2, $file3]);

        $this->assertIsArray($circularDeps);
        $this->assertNotEmpty($circularDeps);
        $this->assertCount(3, $circularDeps);
    }

    public function testDetectSelfDependency(): void
    {
        $file = $this->createFile('SelfDependent.php', '<?php
namespace App;
use App\SelfDependent;
class SelfDependent {
    private SelfDependent $self;
}
');

        // 配置 mock 返回包含自依赖的数据
        $processor = $this->createMock(ProjectFileProcessor::class);
        $processor->method('processFile')->willReturn([
            'ast' => [],
            'dependencies' => ['App\SelfDependent'], // 自依赖
            'symbols' => ['classes' => ['App\SelfDependent'], 'functions' => [], 'constants' => []],
            'metadata' => [],
            'path' => $file,
        ]);

        $detector = new ProjectDependencyDetector($processor);
        $circularDeps = $detector->detectCircularDependencies([$file]);

        $this->assertIsArray($circularDeps);
        $this->assertNotEmpty($circularDeps);
        $this->assertSame(['App\SelfDependent'], $circularDeps);
    }

    private function createFile(string $path, string $content): string
    {
        $fullPath = self::$tempDir . '/' . $path;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($fullPath, $content);

        return $fullPath;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }
}
