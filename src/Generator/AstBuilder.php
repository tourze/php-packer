<?php

declare(strict_types=1);

namespace PhpPacker\Generator;

use PhpParser\Node;

class AstBuilder
{
    public function __construct()
    {
    }

    /**
     * @return array<int, Node\Stmt>
     */
    public function createAstHeader(): array
    {
        return [
            new Node\Stmt\InlineHTML(''),
            new Node\Stmt\Declare_([
                new Node\Stmt\DeclareDeclare('strict_types', new Node\Scalar\LNumber(1)),
            ]),
        ];
    }

    /**
     * @param array<int, Node> $mergedAst
     * @return array{groups: array<string, array<int, Node\Stmt>>, global: array<int, Node>}
     */
    public function organizeNamespaces(array $mergedAst): array
    {
        $namespaceGroups = [];
        $globalNodes = [];

        foreach ($mergedAst as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $namespaceGroups = $this->processNamespaceNode($node, $namespaceGroups);
            } else {
                $globalNodes[] = $node;
            }
        }

        return [
            'groups' => $namespaceGroups,
            'global' => $globalNodes,
        ];
    }

    /**
     * @param array<string, array<int, Node\Stmt>> $namespaceGroups
     * @return array<string, array<int, Node\Stmt>>
     */
    private function processNamespaceNode(Node\Stmt\Namespace_ $node, array $namespaceGroups): array
    {
        $nsName = null !== $node->name ? $node->name->toString() : '__global__';
        if (!isset($namespaceGroups[$nsName])) {
            $namespaceGroups[$nsName] = [];
        }
        if (null !== $node->stmts && [] !== $node->stmts) {
            $namespaceGroups[$nsName] = array_merge($namespaceGroups[$nsName], $node->stmts);
        }

        return $namespaceGroups;
    }

    /**
     * @param array<int, Node\Stmt> $ast
     * @param array<string, array<int, Node\Stmt>> $namespaceGroups
     * @param array<int, Node> $globalNodes
     * @return array<int, Node\Stmt>
     */
    public function buildNamespaceStructure(array $ast, array $namespaceGroups, array $globalNodes): array
    {
        if (count($namespaceGroups) > 1 || ([] !== $namespaceGroups && [] !== $globalNodes)) {
            return $this->buildMultipleNamespaces($ast, $namespaceGroups, $globalNodes);
        }
        if (1 === count($namespaceGroups)) {
            return $this->buildSingleNamespace($ast, $namespaceGroups);
        }

        // 确保所有元素都是 Node\Stmt 类型
        /** @var array<int, Node\Stmt> $filteredGlobalNodes */
        $filteredGlobalNodes = array_filter($globalNodes, fn ($node) => $node instanceof Node\Stmt);

        return array_merge($ast, $filteredGlobalNodes);
    }

    /**
     * @param array<int, Node\Stmt> $ast
     * @param array<string, array<int, Node\Stmt>> $namespaceGroups
     * @param array<int, Node> $globalNodes
     * @return array<int, Node\Stmt>
     */
    private function buildMultipleNamespaces(array $ast, array $namespaceGroups, array $globalNodes): array
    {
        foreach ($namespaceGroups as $nsName => $stmts) {
            if ('__global__' !== $nsName && [] !== $stmts) {
                $ast[] = new Node\Stmt\Namespace_(
                    new Node\Name(explode('\\', $nsName)),
                    $stmts
                );
            }
        }

        $globalStmts = array_merge(
            $globalNodes,
            $namespaceGroups['__global__'] ?? []
        );

        if ([] !== $globalStmts) {
            // 确保所有元素都是 Node\Stmt 类型
            /** @var array<int, Node\Stmt> $stmts */
            $stmts = array_filter($globalStmts, fn ($node) => $node instanceof Node\Stmt);
            $ast[] = new Node\Stmt\Namespace_(null, $stmts);
        }

        return $ast;
    }

    /**
     * @param array<int, Node\Stmt> $ast
     * @param array<string, array<int, Node\Stmt>> $namespaceGroups
     * @return array<int, Node\Stmt>
     */
    private function buildSingleNamespace(array $ast, array $namespaceGroups): array
    {
        foreach ($namespaceGroups as $nsName => $stmts) {
            if ('__global__' === $nsName) {
                $ast = array_merge($ast, $stmts);
            } else {
                $ast[] = new Node\Stmt\Namespace_(
                    new Node\Name(explode('\\', $nsName))
                );
                $ast = array_merge($ast, $stmts);
            }
        }

        return $ast;
    }

    /**
     * @param array<int, Node\Stmt> $ast
     * @param array<int, Node\Stmt> $executionCode
     * @param array<string, array<int, Node\Stmt>> $namespaceGroups
     * @param array<int, Node> $globalNodes
     * @return array<int, Node\Stmt>
     */
    public function addExecutionCodeToAst(array $ast, array $executionCode, array $namespaceGroups, array $globalNodes): array
    {
        if (count($namespaceGroups) > 1 || ([] !== $namespaceGroups && [] !== $globalNodes)) {
            return $this->addToGlobalNamespace($ast, $executionCode);
        }

        return array_merge($ast, $executionCode);
    }

    /**
     * @param array<int, Node\Stmt> $ast
     * @param array<int, Node\Stmt> $executionCode
     * @return array<int, Node\Stmt>
     */
    private function addToGlobalNamespace(array $ast, array $executionCode): array
    {
        $globalNamespaceFound = false;
        foreach ($ast as $index => $node) {
            if ($node instanceof Node\Stmt\Namespace_ && null === $node->name) {
                $ast[$index] = new Node\Stmt\Namespace_(null, array_merge($node->stmts, $executionCode));
                $globalNamespaceFound = true;
                break;
            }
        }

        if (!$globalNamespaceFound) {
            $ast[] = new Node\Stmt\Namespace_(null, $executionCode);
        }

        return $ast;
    }
}
