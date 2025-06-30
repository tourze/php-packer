<?php

declare(strict_types=1);

namespace PhpPacker\Storage;

use PhpParser\Node;
use Psr\Log\LoggerInterface;

class AstStorage
{
    private SqliteStorage $storage;
    private LoggerInterface $logger;

    public function __construct(SqliteStorage $storage, LoggerInterface $logger)
    {
        $this->storage = $storage;
        $this->logger = $logger;
    }

    /**
     * 存储 AST 到数据库
     *
     * @param array $ast AST 节点数组
     * @param int $fileId 文件 ID
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
            $this->storeNode($node, $fileId, $rootId, $index);
        }

        // 更新文件的 AST 根节点引用
        $this->storage->updateFileAstRoot($fileId, $rootId);

        return $rootId;
    }

    /**
     * 递归存储单个节点
     */
    private function storeNode(Node $node, int $fileId, int $parentId, int $position): int
    {
        $nodeData = $this->extractNodeData($node, $fileId, $parentId, $position);
        $nodeId = $this->storage->addAstNode(
            $nodeData['file_id'],
            $nodeData['parent_id'],
            $nodeData['node_type'],
            $nodeData['fqcn'] ?? '',
            $position
        );

        // 存储子节点
        $childPosition = 0;
        foreach ($node->getSubNodeNames() as $subNodeName) {
            /** @phpstan-ignore-next-line property.dynamicName */
            $subNode = $node->$subNodeName;
            
            if ($subNode instanceof Node) {
                $this->storeNode($subNode, $fileId, $nodeId, $childPosition++);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node) {
                        $this->storeNode($item, $fileId, $nodeId, $childPosition++);
                    }
                }
            }
        }

        return $nodeId;
    }

    /**
     * 提取节点数据
     */
    private function extractNodeData(Node $node, int $fileId, int $parentId, int $position): array
    {
        $nodeType = $node->getType();
        $nodeName = null;
        $fqcn = null;
        $attributes = [];

        // 提取节点名称和 FQCN
        if ($node instanceof Node\Stmt\Class_) {
            $nodeName = $node->name !== null ? $node->name->toString() : null;
            $fqcn = $this->extractFqcn($node);
            $attributes['flags'] = $node->flags;
            $attributes['extends'] = $node->extends !== null ? $node->extends->toString() : null;
            $attributes['implements'] = array_map(fn($i) => $i->toString(), $node->implements);
        } elseif ($node instanceof Node\Stmt\Interface_) {
            $nodeName = $node->name->toString();
            $fqcn = $this->extractFqcn($node);
            $attributes['extends'] = array_map(fn($e) => $e->toString(), $node->extends);
        } elseif ($node instanceof Node\Stmt\Trait_) {
            $nodeName = $node->name->toString();
            $fqcn = $this->extractFqcn($node);
        } elseif ($node instanceof Node\Stmt\Function_) {
            $nodeName = $node->name->toString();
            $fqcn = $this->extractFqcn($node);
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $nodeName = $node->name->toString();
            $attributes['flags'] = $node->flags;
            $attributes['returnType'] = $node->returnType !== null ? $this->typeToString($node->returnType) : null;
        } elseif ($node instanceof Node\Stmt\Property) {
            $attributes['flags'] = $node->flags;
            $attributes['type'] = $node->type !== null ? $this->typeToString($node->type) : null;
            $nodeName = $node->props[0]->name->toString();
        } elseif ($node instanceof Node\Name) {
            $fqcn = $node->toString();
            $nodeName = $node->getLast();
        } elseif ($node instanceof Node\Stmt\Use_) {
            $attributes['type'] = $node->type;
        } elseif ($node instanceof Node\Stmt\Namespace_) {
            $nodeName = $node->name !== null ? $node->name->toString() : null;
        }

        // 存储其他重要属性
        if ($node instanceof Node\Expr\New_) {
            if ($node->class instanceof Node\Name) {
                $attributes['class'] = $node->class->toString();
            }
        } elseif ($node instanceof Node\Expr\StaticCall) {
            if ($node->class instanceof Node\Name) {
                $attributes['class'] = $node->class->toString();
            }
            $attributes['method'] = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
        } elseif ($node instanceof Node\Expr\ClassConstFetch) {
            if ($node->class instanceof Node\Name) {
                $attributes['class'] = $node->class->toString();
            }
            $attributes['const'] = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
        }

        return [
            'file_id' => $fileId,
            'parent_id' => $parentId,
            'node_type' => $nodeType,
            'node_name' => $nodeName,
            'fqcn' => $fqcn,
            'position' => $position,
            'start_line' => $node->getStartLine(),
            'end_line' => $node->getEndLine(),
            'attributes' => !empty($attributes) ? json_encode($attributes) : null,
        ];
    }

    /**
     * 提取节点的完全限定类名
     */
    private function extractFqcn(Node $node): ?string
    {
        // 如果节点已经有 namespacedName 属性（由 NameResolver 设置）
        if (property_exists($node, 'namespacedName') && isset($node->namespacedName) && $node->namespacedName instanceof Node\Name) {
            return $node->namespacedName->toString();
        }

        // 对于有名字的节点，尝试获取其名称
        if (property_exists($node, 'name') && $node->name !== null) {
            if ($node->name instanceof Node\Name) {
                return $node->name->toString();
            } elseif ($node->name instanceof Node\Identifier) {
                return $node->name->toString();
            }
        }

        return null;
    }

    /**
     * 将类型节点转换为字符串
     */
    private function typeToString($type): string
    {
        if ($type instanceof Node\Name) {
            return $type->toString();
        } elseif ($type instanceof Node\Identifier) {
            return $type->toString();
        } elseif ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn($t) => $this->typeToString($t), $type->types));
        } elseif ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(fn($t) => $this->typeToString($t), $type->types));
        } elseif ($type instanceof Node\NullableType) {
            return '?' . $this->typeToString($type->type);
        }
        
        return 'mixed';
    }

    /**
     * 从数据库加载 AST
     *
     * @param int $fileId 文件 ID
     * @return array|null 返回 AST 节点数组或 null
     */
    public function loadAst(int $fileId): ?array
    {
        $nodes = $this->storage->getAstNodesByFileId($fileId);
        if (empty($nodes)) {
            return null;
        }

        // 构建节点树
        $nodeMap = [];
        $rootNodes = [];

        foreach ($nodes as $nodeData) {
            $nodeMap[$nodeData['id']] = $nodeData;
            
            if ($nodeData['parent_id'] === null) {
                $rootNodes[] = $nodeData;
            }
        }

        // 递归构建子节点关系
        foreach ($nodeMap as &$node) {
            if ($node['parent_id'] !== null && isset($nodeMap[$node['parent_id']])) {
                $nodeMap[$node['parent_id']]['children'][] = &$node;
            }
        }

        return $rootNodes;
    }

    /**
     * 查找特定类型的所有节点
     */
    public function findNodesByType(int $fileId, string $nodeType): array
    {
        $allNodes = $this->storage->getAstNodesByFileId($fileId);
        
        return array_filter($allNodes, function ($node) use ($nodeType) {
            return $node['node_type'] === $nodeType;
        });
    }

    /**
     * 查找使用特定 FQCN 的所有位置
     */
    public function findUsages(string $fqcn): array
    {
        return $this->storage->findAstNodeUsages($fqcn);
    }
}