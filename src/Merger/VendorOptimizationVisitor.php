<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class VendorOptimizationVisitor extends NodeVisitorAbstract
{
    /** @var array{total_nodes: int, removed_nodes: int, optimized_files: int, removed_methods: int, removed_properties: int} */
    private array $stats = [
        'total_nodes' => 0,
        'removed_nodes' => 0,
        'optimized_files' => 0,
        'removed_methods' => 0,
        'removed_properties' => 0,
    ];

    /**
     * @param array{total_nodes?: int, removed_nodes?: int, optimized_files?: int, removed_methods?: int, removed_properties?: int}|null $initialStats
     */
    public function __construct(?array $initialStats = null)
    {
        if (null !== $initialStats) {
            $this->stats = array_merge($this->stats, $initialStats);
        }
    }

    /**
     * @return array{total_nodes: int, removed_nodes: int, optimized_files: int, removed_methods: int, removed_properties: int}
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    public function leaveNode(Node $node): Node
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->optimizeClass($node);
        }

        return $node;
    }

    private function optimizeClass(Node\Stmt\Class_ $class): void
    {
        $usageAnalysis = $this->analyzeClassUsage($class);
        $class->stmts = $this->filterUnusedMembers($class->stmts, $usageAnalysis);
    }

    /**
     * @return array{methods: array<int, string>, properties: array<int, string>}
     */
    private function analyzeClassUsage(Node\Stmt\Class_ $class): array
    {
        $allUsedMethods = [];
        $allUsedProperties = [];

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->isPublic()) {
                $usage = $this->analyzeMethodUsage($stmt);
                $allUsedMethods = array_merge($allUsedMethods, $usage['methods']);
                $allUsedProperties = array_merge($allUsedProperties, $usage['properties']);
            }
        }

        return [
            'methods' => array_unique($allUsedMethods),
            'properties' => array_unique($allUsedProperties),
        ];
    }

    /**
     * @return array{methods: array<int, string>, properties: array<int, string>}
     */
    private function analyzeMethodUsage(Node\Stmt\ClassMethod $method): array
    {
        if (null === $method->stmts) {
            return ['methods' => [], 'properties' => []];
        }

        $visitor = new VendorUsageAnalysisVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($method->stmts);

        return $visitor->getUsageData();
    }

    /**
     * @param array<int, Node\Stmt> $statements
     * @param array{methods: array<int, string>, properties: array<int, string>} $usageAnalysis
     * @return array<int, Node\Stmt>
     */
    private function filterUnusedMembers(array $statements, array $usageAnalysis): array
    {
        $result = [];
        foreach ($statements as $stmt) {
            if (!$this->shouldRemoveStatement($stmt, $usageAnalysis)) {
                $result[] = $stmt;
            }
        }

        return $result;
    }

    /**
     * @param array{methods: array<int, string>, properties: array<int, string>} $usageAnalysis
     */
    private function shouldRemoveStatement(Node $stmt, array $usageAnalysis): bool
    {
        if ($this->isUnusedPrivateMethod($stmt, $usageAnalysis['methods'])) {
            ++$this->stats['removed_methods'];

            return true;
        }

        if ($this->isUnusedPrivateProperty($stmt, $usageAnalysis['properties'])) {
            ++$this->stats['removed_properties'];

            return true;
        }

        return false;
    }

    /**
     * @param array<int, string> $usedMethods
     */
    private function isUnusedPrivateMethod(Node $stmt, array $usedMethods): bool
    {
        return $stmt instanceof Node\Stmt\ClassMethod
            && $stmt->isPrivate()
            && !in_array($stmt->name->toString(), $usedMethods, true);
    }

    /**
     * @param array<int, string> $usedProperties
     */
    private function isUnusedPrivateProperty(Node $stmt, array $usedProperties): bool
    {
        if (!($stmt instanceof Node\Stmt\Property) || !$stmt->isPrivate()) {
            return false;
        }

        foreach ($stmt->props as $prop) {
            if (!in_array($prop->name->toString(), $usedProperties, true)) {
                return true;
            }
        }

        return false;
    }
}
