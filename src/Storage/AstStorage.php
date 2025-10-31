<?php

declare(strict_types=1);

namespace PhpPacker\Storage;

use PhpParser\Node;
use Psr\Log\LoggerInterface;

class AstStorage
{
    private SqliteStorage $storage;

    private LoggerInterface $logger;

    private NodeDataExtractor $nodeExtractor;

    public function __construct(SqliteStorage $storage, LoggerInterface $logger)
    {
        $this->storage = $storage;
        $this->logger = $logger;

        // 创建助手类实例
        $typeConverter = new TypeConverter();
        $this->nodeExtractor = new NodeDataExtractor($typeConverter);
    }

    /**
     * 存储 AST 到数据库
     *
     * @param array<Node> $ast    AST 节点数组
     * @param int         $fileId 文件 ID
     *
     * @return int 返回根节点 ID
     */
    public function storeAst(array $ast, int $fileId): int
    {
        $this->logger->debug('Storing AST for file', ['file_id' => $fileId]);

        // 创建虚拟根节点
        $rootId = $this->storage->addAstNode(
            $fileId,
            0,
            'Root',
            '',
            0
        );

        // 递归存储所有节点
        foreach ($ast as $index => $node) {
            $this->storeNode($node, $fileId, $rootId, $index, null);
        }

        // 更新文件的 AST 根节点引用
        $this->storage->updateFileAstRoot($fileId, $rootId);

        return $rootId;
    }

    /**
     * 递归存储单个节点
     */
    private function storeNode(Node $node, int $fileId, int $parentId, int $position, ?Node\Stmt\Namespace_ $currentNamespace = null): int
    {
        // 如果当前节点是命名空间，更新命名空间上下文
        if ($node instanceof Node\Stmt\Namespace_) {
            $currentNamespace = $node;
        }

        $nodeData = $this->nodeExtractor->extractNodeData($node, $fileId, $parentId, $position, $currentNamespace);
        $nodeId = $this->storage->addAstNodeWithData($nodeData);

        // 存储子节点，传递命名空间上下文
        $this->storeChildNodes($node, $fileId, $nodeId, $currentNamespace);

        return $nodeId;
    }

    private function storeChildNodes(Node $node, int $fileId, int $nodeId, ?Node\Stmt\Namespace_ $currentNamespace = null): void
    {
        $childPosition = 0;
        foreach ($node->getSubNodeNames() as $subNodeName) {
            /** @phpstan-ignore-next-line property.dynamicName */
            $subNode = $node->{$subNodeName};
            $childPosition = $this->processSubNode($subNode, $fileId, $nodeId, $childPosition, $currentNamespace);
        }
    }

    /**
     * @param mixed $subNode
     * @param int $fileId
     * @param int $nodeId
     * @param int $childPosition
     * @param ?Node\Stmt\Namespace_ $currentNamespace
     * @return int
     */
    private function processSubNode(mixed $subNode, int $fileId, int $nodeId, int $childPosition, ?Node\Stmt\Namespace_ $currentNamespace = null): int
    {
        if ($subNode instanceof Node) {
            $this->storeNode($subNode, $fileId, $nodeId, $childPosition, $currentNamespace);

            return $childPosition + 1;
        }
        if (is_array($subNode)) {
            return $this->processSubNodeArray($subNode, $fileId, $nodeId, $childPosition, $currentNamespace);
        }

        return $childPosition;
    }

    /**
     * @param array<mixed> $subNodeArray
     * @param int $fileId
     * @param int $nodeId
     * @param int $childPosition
     * @param ?Node\Stmt\Namespace_ $currentNamespace
     * @return int
     */
    private function processSubNodeArray(array $subNodeArray, int $fileId, int $nodeId, int $childPosition, ?Node\Stmt\Namespace_ $currentNamespace = null): int
    {
        foreach ($subNodeArray as $item) {
            if ($item instanceof Node) {
                $this->storeNode($item, $fileId, $nodeId, $childPosition, $currentNamespace);
                ++$childPosition;
            }
        }

        return $childPosition;
    }

    /**
     * 从数据库加载 AST
     *
     * @param int $fileId 文件 ID
     *
     * @return array<Node>|null 返回 AST 节点数组或 null
     */
    public function loadAst(int $fileId): ?array
    {
        $nodes = $this->storage->getAstNodesByFileId($fileId);
        if (0 === count($nodes)) {
            return null;
        }

        // Check if we have a single root node with serialized AST data (SqliteStorage format)
        $firstNode = $nodes[0];
        if (1 === count($nodes) && is_array($firstNode)
            && isset($firstNode['node_type']) && 'Root' === $firstNode['node_type']
            && isset($firstNode['node_data']) && '' !== $firstNode['node_data']) {
            return $this->loadSerializedAst($firstNode);
        }

        // Handle AstStorage format (decomposed nodes)
        return $this->loadDecomposedAst($nodes);
    }

    /**
     * @param array<string, mixed> $rootNode
     * @return array<Node>|null
     */
    private function loadSerializedAst(array $rootNode): ?array
    {
        if (!isset($rootNode['node_data']) || !is_string($rootNode['node_data'])) {
            return null;
        }

        // 使用错误抑制来避免损坏数据的警告
        $ast = @unserialize($rootNode['node_data']);

        if (false === $ast) {
            $this->logger->warning('Failed to unserialize AST data', [
                'node_data_length' => strlen($rootNode['node_data']),
                'node_data_preview' => substr($rootNode['node_data'], 0, 50),
            ]);

            return null;
        }

        return is_array($ast) ? $ast : null;
    }

    /**
     * @param array<array<string, mixed>> $nodes
     * @return array<mixed>
     */
    private function loadDecomposedAst(array $nodes): array
    {
        $astNodes = [];
        foreach ($nodes as $nodeData) {
            if (is_array($nodeData) && $this->isRealAstNode($nodeData, $nodes)) {
                $astNodes[] = $nodeData;
            }
        }

        return $astNodes;
    }

    /**
     * @param array<string, mixed> $nodeData
     * @param array<array<string, mixed>> $allNodes
     */
    private function isRealAstNode(array $nodeData, array $allNodes): bool
    {
        if (!isset($nodeData['node_type']) || !isset($nodeData['parent_id'])) {
            return false;
        }

        if ('Root' === $nodeData['node_type'] || !isset($nodeData['parent_id'])) {
            return false;
        }

        if (!is_int($nodeData['parent_id'])) {
            return false;
        }

        return $this->hasVirtualRootParent($nodeData['parent_id'], $allNodes);
    }

    /** @param array<array<string, mixed>> $allNodes */
    private function hasVirtualRootParent(int $parentId, array $allNodes): bool
    {
        foreach ($allNodes as $parentNode) {
            if (is_array($parentNode)
                && isset($parentNode['id']) && $parentNode['id'] === $parentId
                && isset($parentNode['node_type']) && 'Root' === $parentNode['node_type']) {
                return true;
            }
        }

        return false;
    }

    /**
     * 查找特定类型的所有节点
     *
     * @return array<array<string, mixed>>
     */
    public function findNodesByType(int $fileId, string $nodeType): array
    {
        $allNodes = $this->storage->getAstNodesByFileId($fileId);

        return array_filter($allNodes, function ($node) use ($nodeType): bool {
            return isset($node['node_type']) && $node['node_type'] === $nodeType;
        });
    }

    /**
     * 查找使用特定 FQCN 的所有位置
     *
     * @return array<array<string, mixed>>
     */
    public function findUsages(string $fqcn): array
    {
        return $this->storage->findAstNodeUsages($fqcn);
    }
}
