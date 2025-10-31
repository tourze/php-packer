<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpParser\Node;
use Psr\Log\LoggerInterface;

class NodeDeduplicator
{
    private LoggerInterface $logger;

    private NodeSymbolExtractor $symbolExtractor;

    private DuplicationHandler $duplicationHandler;

    private ConditionalNodeBuilder $conditionalBuilder;

    /** @var array<string, mixed> */
    private array $stats = [
        'total_nodes' => 0,
        'unique_nodes' => 0,
        'duplicates_removed' => 0,
        'duplicate_types' => [],
    ];

    private ?\Closure $customComparator = null;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->symbolExtractor = new NodeSymbolExtractor();
        $this->duplicationHandler = new DuplicationHandler($logger, $this->symbolExtractor);
        $this->conditionalBuilder = new ConditionalNodeBuilder();
    }

    /**
     * 去除重复的节点（基于符号名称）
     */
    /**
     * @param array<int, Node> $nodes
     * @return array<int, Node>
     */
    public function deduplicateNodes(array $nodes): array
    {
        $state = $this->initializeDeduplicationState();

        foreach ($nodes as $node) {
            $state = $this->processNodeForDeduplication($node, $state);
        }

        return $this->finalizeDeduplication($state);
    }

    /**
     * @return array<string, mixed>
     */
    private function initializeDeduplicationState(): array
    {
        return [
            'uniqueNodes' => [],
            'seenSymbols' => [],
            'conditionalFunctions' => [],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function processNodeForDeduplication(Node $node, array $state): array
    {
        if (null !== $this->customComparator) {
            return $this->processNodeWithCustomComparator($node, $state);
        }

        $symbol = $this->symbolExtractor->getNodeSymbol($node);

        if (null === $symbol) {
            $state['uniqueNodes'][] = $node;

            return $state;
        }

        if (!isset($state['seenSymbols'][$symbol])) {
            return $this->addNewSymbol($node, $symbol, $state);
        }

        return $this->duplicationHandler->handleDuplicateSymbol($node, $symbol, $state);
    }

    /**
     * 使用自定义比较器处理节点
     */
    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function processNodeWithCustomComparator(Node $node, array $state): array
    {
        $isDuplicate = false;

        foreach ($state['uniqueNodes'] as $existingNode) {
            if (null !== $this->customComparator && ($this->customComparator)($node, $existingNode)) {
                $isDuplicate = true;
                break;
            }
        }

        if (!$isDuplicate) {
            $state['uniqueNodes'][] = $node;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function addNewSymbol(Node $node, string $symbol, array $state): array
    {
        $state['seenSymbols'][$symbol] = true;
        $state['uniqueNodes'][] = $node;

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<int, Node>
     */
    private function finalizeDeduplication(array $state): array
    {
        if (!isset($state['uniqueNodes'])) {
            $this->logger->warning('Missing uniqueNodes in deduplication state');

            return [];
        }

        if (!isset($state['conditionalFunctions'])) {
            $this->logger->debug('No conditional functions found in deduplication state');

            return array_values($state['uniqueNodes']);
        }

        foreach ($state['conditionalFunctions'] as $symbol => $functions) {
            if (!is_array($functions) || 0 === count($functions)) {
                $this->logger->warning('Invalid conditional functions for symbol', ['symbol' => $symbol]);
                continue;
            }

            $conditionalNode = $this->conditionalBuilder->createConditionalNode($functions);
            if (null !== $conditionalNode) {
                $state['uniqueNodes'][] = $conditionalNode;
            } else {
                $this->logger->warning('Failed to create conditional node for symbol', ['symbol' => $symbol]);
            }
        }

        return array_values($state['uniqueNodes']);
    }

    /**
     * 去除重复节点并更新统计信息
     */
    /**
     * @param array<int, Node> $nodes
     * @return array<int, Node>
     */
    public function deduplicate(array $nodes): array
    {
        $this->stats['total_nodes'] = count($nodes);
        $this->logger->debug('开始去重处理', ['total_nodes' => $this->stats['total_nodes']]);

        $deduplicated = $this->deduplicateNodes($nodes);
        $this->stats['unique_nodes'] = count($deduplicated);
        $this->stats['duplicates_removed'] = $this->stats['total_nodes'] - $this->stats['unique_nodes'];

        // 计算重复类型统计
        $this->calculateDuplicateTypes($nodes);

        $this->logger->info('节点去重完成', $this->stats);

        return $deduplicated;
    }

    /**
     * 获取重复统计信息
     */
    /**
     * @return array<string, mixed>
     */
    public function getDuplicateStats(): array
    {
        return $this->stats;
    }

    /**
     * 设置自定义比较器
     */
    public function setComparator(\Closure $comparator): void
    {
        $this->customComparator = $comparator;
    }

    /**
     * 重置统计信息
     */
    public function reset(): void
    {
        $this->stats = [
            'total_nodes' => 0,
            'unique_nodes' => 0,
            'duplicates_removed' => 0,
            'duplicate_types' => [],
        ];
        $this->customComparator = null;
    }

    /**
     * 计算重复类型统计
     */
    /**
     * @param array<int, Node> $nodes
     */
    private function calculateDuplicateTypes(array $nodes): void
    {
        $typeCount = [];
        $seenSymbols = [];

        foreach ($nodes as $node) {
            $symbol = $this->symbolExtractor->getNodeSymbol($node);
            if (null === $symbol) {
                continue;
            }

            $type = $this->symbolExtractor->getNodeType($node);

            if (isset($seenSymbols[$symbol])) {
                if (!isset($typeCount[$type])) {
                    $typeCount[$type] = 0;
                }
                ++$typeCount[$type];
            } else {
                $seenSymbols[$symbol] = true;
            }
        }

        $this->stats['duplicate_types'] = $typeCount;
    }
}
