<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

class ProjectDependencyDetector
{
    public function __construct(private ProjectFileProcessor $processor)
    {
    }

    /**
     * @param array<int, string> $filePaths
     * @return array<int, string>
     */
    public function detectCircularDependencies(array $filePaths): array
    {
        $dependencyGraph = $this->buildDependencyGraph($filePaths);

        return $this->findCircularDependencies($dependencyGraph['classes'], $dependencyGraph['dependencies']);
    }

    /**
     * @param array<int, string> $dependencies
     * @param array<int, string> $projectNamespaces
     * @return array<int, string>
     */
    public function filterProjectDependencies(array $dependencies, array $projectNamespaces): array
    {
        $filtered = [];

        foreach ($dependencies as $dependency) {
            foreach ($projectNamespaces as $namespace) {
                if (str_starts_with($dependency, $namespace)) {
                    $filtered[] = $dependency;
                    break;
                }
            }
        }

        return $filtered;
    }

    /**
     * @param array<int, string> $filePaths
     * @return array<string, mixed>
     */
    private function buildDependencyGraph(array $filePaths): array
    {
        $dependencies = [];
        $classes = [];

        foreach ($filePaths as $filePath) {
            $processed = $this->processor->processFile($filePath);
            if (isset($processed['symbols']['classes']) && count($processed['symbols']['classes']) > 0) {
                foreach ($processed['symbols']['classes'] as $class) {
                    $classes[$class] = $filePath;
                    $dependencies[$class] = $processed['dependencies'] ?? [];
                }
            }
        }

        return ['classes' => $classes, 'dependencies' => $dependencies];
    }

    /**
     * @param array<string, string> $classes
     * @param array<string, mixed> $dependencies
     * @return array<int, string>
     */
    private function findCircularDependencies(array $classes, array $dependencies): array
    {
        $circular = [];

        foreach ($classes as $class => $file) {
            if ($this->hasCycle($class, $dependencies)) {
                $circular[] = $class;
            }
        }

        return array_unique($circular);
    }

    /**
     * @param array<string, mixed> $dependencies
     * @param array<int, string> $visited
     */
    private function hasCycle(string $class, array $dependencies, array $visited = []): bool
    {
        // 检查是否已经访问过这个类（循环依赖）
        if (in_array($class, $visited, true)) {
            return true;
        }

        $visited[] = $class;

        // 检查当前类的依赖中是否包含自己（自依赖）
        if (isset($dependencies[$class])) {
            foreach ($dependencies[$class] as $dependency) {
                // 自依赖：类依赖自己
                if ($dependency === $class) {
                    return true;
                }

                // 递归检查依赖的依赖
                if ($this->hasCycle($dependency, $dependencies, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }
}
