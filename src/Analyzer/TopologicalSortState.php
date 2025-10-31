<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

/**
 * 拓扑排序操作的状态保持器
 */
class TopologicalSortState
{
    /** @var array<int|string, bool> */
    public array $visited = [];

    /** @var array<int|string, bool> */
    public array $recursionStack = [];

    /** @var int[]|string[] */
    public array $result = [];

    /**
     * @param int|string $node
     */
    public function markVisited($node): void
    {
        $this->visited[$node] = true;
    }

    /**
     * @param int|string $node
     */
    public function markInRecursionStack($node): void
    {
        $this->recursionStack[$node] = true;
    }

    /**
     * @param int|string $node
     */
    public function removeFromRecursionStack($node): void
    {
        unset($this->recursionStack[$node]);
    }

    /**
     * @param int|string $node
     */
    public function addToResult($node): void
    {
        $this->result[] = $node;
    }

    /**
     * @param int|string $node
     */
    public function isVisited($node): bool
    {
        return isset($this->visited[$node]);
    }

    /**
     * @param int|string $node
     */
    public function isInRecursionStack($node): bool
    {
        return isset($this->recursionStack[$node]);
    }
}
