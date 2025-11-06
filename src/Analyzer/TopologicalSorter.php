<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use PhpPacker\Exception\CircularDependencyException;
use PhpPacker\Storage\StorageInterface;
use Psr\Log\LoggerInterface;

class TopologicalSorter
{
    private StorageInterface $storage;

    private LoggerInterface $logger;

    public function __construct(StorageInterface $storage, LoggerInterface $logger)
    {
        $this->storage = $storage;
        $this->logger = $logger;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLoadOrder(int $entryFileId): array
    {
        $allFiles = $this->storage->getAllRequiredFiles($entryFileId);
        $graph = $this->buildDependencyGraph($allFiles);
        $sorted = $this->topologicalSort($graph);

        $fileMap = [];
        foreach ($allFiles as $file) {
            $fileMap[$file['id']] = $file;
        }

        $result = [];
        foreach ($sorted as $fileId) {
            if (isset($fileMap[$fileId])) {
                $result[] = $fileMap[$fileId];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $files
     * @return array<int, int[]>
     */
    private function buildDependencyGraph(array $files): array
    {
        $graph = [];
        $fileIds = array_column($files, 'id');

        foreach ($fileIds as $fileId) {
            $graph[$fileId] = [];
        }

        $pdo = $this->storage->getPdo();
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));

        $stmt = $pdo->prepare("
            SELECT source_file_id, target_file_id
            FROM dependencies
            WHERE source_file_id IN ({$placeholders})
              AND target_file_id IN ({$placeholders})
              AND is_resolved = 1
        ");
        $stmt->execute(array_merge($fileIds, $fileIds));

        while (($row = $stmt->fetch()) !== false) {
            // 对于加载顺序，被依赖的文件应该先加载
            // 所以图的方向是：target_file 依赖于 source_file
            // 即：source 必须在 target 之前加载
            $graph[$row['target_file_id']][] = $row['source_file_id'];
        }

        return $graph;
    }

    /**
     * @param array<int, int[]> $graph
     * @return int[]
     */
    private function topologicalSort(array $graph): array
    {
        $result = [];
        $visited = [];
        $recursionStack = [];

        foreach (array_keys($graph) as $node) {
            if (!isset($visited[$node])) {
                [$visited, $recursionStack, $result] = $this->topologicalSortUtil($node, $graph, $visited, $recursionStack, $result);
            }
        }

        return array_reverse($result);
    }

    /**
     * @param array<int, int[]> $graph
     * @param array<int, bool> $visited
     * @param array<int, bool> $recursionStack
     * @param int[] $result
     * @return array{array<int, bool>, array<int, bool>, int[]}
     */
    private function topologicalSortUtil(
        int $node,
        array $graph,
        array $visited,
        array $recursionStack,
        array $result,
    ): array {
        $visited[$node] = true;
        $recursionStack[$node] = true;

        foreach ($graph[$node] as $neighbor) {
            if (!isset($visited[$neighbor])) {
                [$visited, $recursionStack, $result] = $this->topologicalSortUtil($neighbor, $graph, $visited, $recursionStack, $result);
            } elseif (isset($recursionStack[$neighbor])) {
                $this->logger->warning('Circular dependency detected', [
                    'from' => $node,
                    'to' => $neighbor,
                ]);
            }
        }

        $result[] = $node;
        unset($recursionStack[$node]);

        return [$visited, $recursionStack, $result];
    }

    /**
     * 对给定的依赖关系执行拓扑排序
     *
     * @param array<string, string[]> $dependencies 依赖关系数组，格式为 ['A' => ['B', 'C'] 表示 A 依赖于 B 和 C
     *
     * @return string[] 排序后的数组，被依赖的项目排在前面
     */
    public function sort(array $dependencies): array
    {
        if (0 === count($dependencies)) {
            return [];
        }

        $allNodes = $this->extractAllNodes($dependencies);
        $graph = $this->buildDependencyGraphFromArray($dependencies, $allNodes);

        return $this->performTopologicalSort($graph, $allNodes);
    }

    /**
     * @param array<string, string[]> $dependencies
     * @return string[]
     */
    private function extractAllNodes(array $dependencies): array
    {
        $allNodes = array_keys($dependencies);
        foreach ($dependencies as $deps) {
            foreach ($deps as $dep) {
                if (!in_array($dep, $allNodes, true)) {
                    $allNodes[] = $dep;
                }
            }
        }

        return $allNodes;
    }

    /**
     * @param array<string, string[]> $dependencies
     * @param string[] $allNodes
     * @return array<string, string[]>
     */
    private function buildDependencyGraphFromArray(array $dependencies, array $allNodes): array
    {
        $graph = [];
        foreach ($allNodes as $node) {
            $graph[$node] = [];
        }

        foreach ($dependencies as $node => $deps) {
            foreach ($deps as $dependency) {
                $graph[$dependency][] = $node;
            }
        }

        return $graph;
    }

    /**
     * @param array<string, string[]> $graph
     * @param string[] $allNodes
     * @return string[]
     */
    private function performTopologicalSort(array $graph, array $allNodes): array
    {
        $result = [];
        $visited = [];
        $recursionStack = [];

        foreach ($allNodes as $node) {
            if (!isset($visited[$node])) {
                [$visited, $recursionStack, $result] = $this->sortUtil($node, $graph, $visited, $recursionStack, $result);
            }
        }

        return array_reverse($result);
    }

    /**
     * 拓扑排序的辅助方法（深度优先搜索）
     * @param array<string, string[]> $graph
     * @param array<string, bool> $visited
     * @param array<string, bool> $recursionStack
     * @param string[] $result
     * @return array{array<string, bool>, array<string, bool>, string[]}
     */
    private function sortUtil(
        string $node,
        array $graph,
        array $visited,
        array $recursionStack,
        array $result,
    ): array {
        $visited[$node] = true;
        $recursionStack[$node] = true;

        // 访问所有依赖项
        if (isset($graph[$node])) {
            foreach ($graph[$node] as $dependency) {
                if (!isset($visited[$dependency])) {
                    [$visited, $recursionStack, $result] = $this->sortUtil($dependency, $graph, $visited, $recursionStack, $result);
                } elseif (isset($recursionStack[$dependency])) {
                    // 检测到循环依赖
                    $this->logger->error('Cyclic dependency detected', [
                        'from' => $node,
                        'to' => $dependency,
                    ]);
                    throw new CircularDependencyException("Cyclic dependency detected: {$node} -> {$dependency}");
                }
            }
        }

        $result[] = $node;
        unset($recursionStack[$node]);

        return [$visited, $recursionStack, $result];
    }
}
