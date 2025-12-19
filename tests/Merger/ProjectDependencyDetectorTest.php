<?php

declare(strict_types=1);

namespace PhpPacker\Tests\Merger;

use PhpPacker\Merger\NodeDeduplicator;
use PhpPacker\Merger\ProjectDependencyDetector;
use PhpPacker\Merger\ProjectFileProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(ProjectDependencyDetector::class)]
final class ProjectDependencyDetectorTest extends TestCase
{
    private ProjectDependencyDetector $detector;

    private ProjectFileProcessor $processor;

    private static string $tempDir;

    protected function setUp(): void
    {
        // 使用真实实现替代 Mock
        $logger = new NullLogger();
        $deduplicator = new NodeDeduplicator($logger);
        $this->processor = new ProjectFileProcessor($logger, $deduplicator);
        $this->detector = new ProjectDependencyDetector($this->processor);
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

        // 使用真实的 processor 处理文件
        $circularDeps = $this->detector->detectCircularDependencies([$file1, $file2]);

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

        // 使用真实的 processor 处理文件
        $circularDeps = $this->detector->detectCircularDependencies([$file1, $file2]);

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

        // 使用真实的 processor 处理文件
        $circularDeps = $this->detector->detectCircularDependencies([$file1, $file2, $file3]);

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

        // 使用真实的 processor 处理文件
        $circularDeps = $this->detector->detectCircularDependencies([$file]);

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
