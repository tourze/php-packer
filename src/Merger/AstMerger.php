<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpPacker\Storage\SqliteStorage;
use PhpPacker\Visitor\FqcnTransformVisitor;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use Psr\Log\LoggerInterface;

class AstMerger
{
    private Parser $parser;
    private LoggerInterface $logger;
    private PrettyPrinter\Standard $printer;

    /**
     * @phpstan-ignore-next-line constructor.unusedParameter
     */
    public function __construct(SqliteStorage $storage, LoggerInterface $logger)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->logger = $logger;
        $this->printer = new PrettyPrinter\Standard();
    }

    /**
     * 合并多个文件的 AST
     *
     * @param array $files 文件数据数组，每个元素包含 id, path, content 等
     * @return array 合并后的 AST
     */
    public function mergeFiles(array $files): array
    {
        $this->logger->info('Starting AST merge', ['files' => count($files)]);
        
        // 分离 vendor 文件和项目文件
        $vendorFiles = [];
        $projectFiles = [];
        
        foreach ($files as $file) {
            if ($file['is_vendor'] || $file['skip_ast']) {
                $vendorFiles[] = $file;
            } else {
                $projectFiles[] = $file;
            }
        }
        
        // 构建合并后的 AST 结构
        $mergedAst = [];
        
        // 1. 首先处理 vendor 文件（作为原始内容包含）
        if (!empty($vendorFiles)) {
            $vendorNodes = $this->createVendorNodes($vendorFiles);
            $mergedAst = array_merge($mergedAst, $vendorNodes);
        }
        
        // 2. 处理项目文件的 AST
        $projectNodes = $this->mergeProjectFiles($projectFiles);
        $mergedAst = array_merge($mergedAst, $projectNodes);
        
        $this->logger->info('AST merge completed', [
            'vendor_files' => count($vendorFiles),
            'project_files' => count($projectFiles),
            'total_nodes' => count($mergedAst),
        ]);
        
        return $mergedAst;
    }

    /**
     * 创建 vendor 文件的包含节点
     */
    private function createVendorNodes(array $vendorFiles): array
    {
        $nodes = [];
        
        // 直接解析并包含 vendor 文件的内容
        foreach ($vendorFiles as $file) {
            try {
                // 解析 vendor 文件的 AST
                $ast = $this->parser->parse($file['content']);
                if ($ast !== null) {
                    // 应用 NameResolver 将所有名称解析为 FQCN
                    $traverser = new NodeTraverser();
                    $traverser->addVisitor(new NameResolver());
                    $ast = $traverser->traverse($ast);
                    
                    // 应用 FqcnTransformVisitor 移除use语句
                    $fqcnTraverser = new NodeTraverser();
                    $fqcnTraverser->addVisitor(new FqcnTransformVisitor());
                    $ast = $fqcnTraverser->traverse($ast);
                    
                    // 添加注释说明这是 vendor 文件
                    $comment = new Comment('// Vendor file: ' . $file['path']);
                    
                    // 过滤掉 declare 语句和其他不需要的节点
                    $filteredAst = [];
                    foreach ($ast as $node) {
                        if ($node instanceof Node\Stmt\Declare_) {
                            // 跳过 declare 语句
                            continue;
                        }
                        $filteredAst[] = $node;
                    }
                    
                    if (!empty($filteredAst)) {
                        $filteredAst[0]->setAttribute('comments', [$comment]);
                    }
                    $nodes = array_merge($nodes, $filteredAst);
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to parse vendor file', [
                    'file' => $file['path'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return $nodes;
    }

    /**
     * 合并项目文件的 AST
     */
    private function mergeProjectFiles(array $projectFiles): array
    {
        $mergedNodes = [];
        $namespaceGroups = [];
        
        // 按命名空间分组
        foreach ($projectFiles as $file) {
            // 从文件内容解析 AST
            $ast = $this->loadFileAst($file);
            if (empty($ast)) {
                $this->logger->warning('Failed to parse AST for file', ['file' => $file['path']]);
                continue;
            }
            
            // 提取命名空间
            $namespace = $this->extractNamespace($ast);
            $namespaceKey = $namespace ?? '__global__';
            
            if (!isset($namespaceGroups[$namespaceKey])) {
                $namespaceGroups[$namespaceKey] = [];
            }
            
            // 过滤掉命名空间声明本身，并且只保留定义语句（类、函数等）
            $filteredNodes = $this->filterToDefinitionsOnly($ast);
            $namespaceGroups[$namespaceKey] = array_merge(
                $namespaceGroups[$namespaceKey], 
                $filteredNodes
            );
        }
        
        // 为每个命名空间创建一个命名空间节点
        foreach ($namespaceGroups as $namespace => $nodes) {
            if ($namespace === '__global__') {
                // 全局命名空间的内容直接添加
                $mergedNodes = array_merge($mergedNodes, $this->deduplicateNodes($nodes));
            } else {
                // 创建命名空间节点
                $namespaceParts = explode('\\', $namespace);
                $namespaceNode = new Node\Stmt\Namespace_(
                    new Node\Name($namespaceParts),
                    $this->deduplicateNodes($nodes)
                );
                $mergedNodes[] = $namespaceNode;
            }
        }
        
        return $mergedNodes;
    }

    /**
     * 从文件加载 AST
     */
    private function loadFileAst(array $file): ?array
    {
        try {
            // 直接解析文件内容
            if (!empty($file['content'])) {
                $ast = $this->parser->parse($file['content']);
                if ($ast === null) {
                    return null;
                }
                
                // 应用 NameResolver 将所有名称解析为 FQCN
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new NameResolver());
                $ast = $traverser->traverse($ast);
                
                // 应用 FqcnTransformVisitor 移除use语句
                $fqcnTraverser = new NodeTraverser();
                $fqcnTraverser->addVisitor(new FqcnTransformVisitor());
                $ast = $fqcnTraverser->traverse($ast);
                
                return $ast;
            }
            
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to load AST', [
                'file' => $file['path'],
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 提取命名空间
     */
    private function extractNamespace(array $ast): ?string
    {
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                return $node->name !== null ? $node->name->toString() : null;
            }
        }
        return null;
    }

    /**
     * 只保留定义语句（类、接口、trait、函数），过滤掉执行语句
     */
    private function filterToDefinitionsOnly(array $ast): array
    {
        $definitions = [];

        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                // 处理命名空间内的语句
                if (!empty($node->stmts)) {
                    $namespaceDefs = $this->filterToDefinitionsOnly($node->stmts);
                    $definitions = array_merge($definitions, $namespaceDefs);
                }
            } elseif ($node instanceof Node\Stmt\Class_ ||
                      $node instanceof Node\Stmt\Interface_ ||
                      $node instanceof Node\Stmt\Trait_ ||
                      $node instanceof Node\Stmt\Function_ ||
                      $node instanceof Node\Stmt\Const_) {
                // 保留定义语句，但跳过 use 语句因为我们使用 FQCN
                $definitions[] = $node;
            } elseif ($node instanceof Node\Stmt\Declare_) {
                // 跳过 declare 语句，它们会在最终生成时统一处理
                continue;
            }
            // 跳过其他语句（执行语句、表达式等）
        }

        return $definitions;
    }
    
    /**
     * 去除重复的节点（基于符号名称）
     */
    private function deduplicateNodes(array $nodes): array
    {
        $uniqueNodes = [];
        $seenSymbols = [];
        $conditionalFunctions = [];

        foreach ($nodes as $node) {
            $symbol = $this->getNodeSymbol($node);

            if ($symbol === null) {
                // 不是符号定义的节点，直接保留
                $uniqueNodes[] = $node;
            } elseif (!isset($seenSymbols[$symbol])) {
                // 新的符号，保留
                $seenSymbols[$symbol] = true;
                $uniqueNodes[] = $node;
            } else {
                // 对于函数，如果是来自条件包含的文件，创建条件定义
                if ($node instanceof Node\Stmt\Function_) {
                    // 将重复的函数收集起来，稍后创建条件定义
                    if (!isset($conditionalFunctions[$symbol])) {
                        // 找到第一个定义，将其移到条件函数列表
                        foreach ($uniqueNodes as $i => $existingNode) {
                            if ($this->getNodeSymbol($existingNode) === $symbol) {
                                $conditionalFunctions[$symbol][] = $existingNode;
                                unset($uniqueNodes[$i]);
                                break;
                            }
                        }
                    }
                    $conditionalFunctions[$symbol][] = $node;
                } else {
                    // 其他重复的符号，跳过
                    $this->logger->debug('Skipping duplicate symbol', ['symbol' => $symbol]);
                }
            }
        }

        // 为条件函数创建条件定义
        foreach ($conditionalFunctions as $symbol => $functions) {
            if (count($functions) === 2) {
                // 简单情况：两个版本，可能是 PHP 版本相关
                $conditionalDef = $this->createConditionalFunctionDefinition($functions);
                if ($conditionalDef !== null) {
                    $uniqueNodes[] = $conditionalDef;
                } else {
                    // 如果无法创建条件定义，只保留第一个
                    $uniqueNodes[] = $functions[0];
                }
            } else {
                // 多个版本，只保留第一个
                $uniqueNodes[] = $functions[0];
            }
        }

        return array_values($uniqueNodes);
    }
    
    /**
     * 创建条件函数定义
     */
    private function createConditionalFunctionDefinition(array $functions): ?Node\Stmt\If_
    {
        if (count($functions) !== 2) {
            return null;
        }
        
        // 检查函数内容，推断条件
        // 这是一个简化的实现，只处理 PHP 版本检查的情况
        $func1 = $functions[0];
        $func2 = $functions[1];
        
        // 检查函数体中是否包含 PHP 版本相关的字符串
        $code1 = $this->printer->prettyPrint([$func1]);
        $code2 = $this->printer->prettyPrint([$func2]);
        
        $isPhp8InFirst = stripos($code1, 'PHP 8') !== false;
        $isPhp7InSecond = stripos($code2, 'PHP 7') !== false;
        
        if ($isPhp8InFirst && $isPhp7InSecond) {
            // 创建条件定义：if (PHP_VERSION_ID >= 80000) { func1 } else { func2 }
            return new Node\Stmt\If_(
                new Node\Expr\BinaryOp\GreaterOrEqual(
                    new Node\Expr\ConstFetch(new Node\Name('PHP_VERSION_ID')),
                    new Node\Scalar\LNumber(80000)
                ),
                [
                    'stmts' => [$func1],
                    'else' => new Node\Stmt\Else_([$func2])
                ]
            );
        } elseif (!$isPhp8InFirst && !$isPhp7InSecond) {
            // 反过来的情况
            return new Node\Stmt\If_(
                new Node\Expr\BinaryOp\GreaterOrEqual(
                    new Node\Expr\ConstFetch(new Node\Name('PHP_VERSION_ID')),
                    new Node\Scalar\LNumber(80000)
                ),
                [
                    'stmts' => [$func2],
                    'else' => new Node\Stmt\Else_([$func1])
                ]
            );
        }
        
        return null;
    }

    /**
     * 获取节点的符号名称（FQCN）
     */
    private function getNodeSymbol(Node $node): ?string
    {
        if ($node instanceof Node\Stmt\Class_) {
            return isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
        } elseif ($node instanceof Node\Stmt\Interface_) {
            return isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
        } elseif ($node instanceof Node\Stmt\Trait_) {
            return isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
        } elseif ($node instanceof Node\Stmt\Function_) {
            return isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
        } elseif ($node instanceof Node\Stmt\Const_) {
            if (!empty($node->consts)) {
                $const = $node->consts[0];
                return isset($const->namespacedName) ? $const->namespacedName->toString() : $const->name->toString();
            }
        } elseif ($node instanceof Node\Stmt\Use_) {
            // 处理 use 语句，为每个导入的类生成唯一标识符
            $useNames = [];
            foreach ($node->uses as $use) {
                $useNames[] = $use->name->toString() . ($use->alias !== null ? ' as ' . $use->alias->toString() : '');
            }
            return 'use:' . implode(',', $useNames);
        } elseif ($node instanceof Node\Stmt\GroupUse) {
            // 处理 group use 语句
            $prefix = $node->prefix->toString();
            $useNames = [];
            foreach ($node->uses as $use) {
                $useNames[] = $prefix . '\\' . $use->name->toString() . ($use->alias !== null ? ' as ' . $use->alias->toString() : '');
            }
            return 'use:' . implode(',', $useNames);
        }

        return null;
    }

    /**
     * 优化合并后的 AST
     */
    public function optimizeAst(array $ast): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new OptimizationVisitor($this->logger));

        return $traverser->traverse($ast);
    }

    // Removed unused filterNamespaceDeclarations method
}

/**
 * AST 优化访问器
 */
class OptimizationVisitor extends NodeVisitorAbstract
{
    private bool $collectMode = true;

    /**
     * @phpstan-ignore-next-line constructor.unusedParameter
     */
    public function __construct(LoggerInterface $logger)
    {
        // Logger not used in current implementation
    }

    public function beforeTraverse(array $nodes)
    {
        // 第一遍遍历：收集使用的符号
        $this->collectMode = true;
        return null;
    }

    public function enterNode(Node $node)
    {
        if ($this->collectMode) {
            // 收集使用的符号 - 当前实现为空
            // 可以在这里添加符号收集逻辑
        }
        
        return null;
    }

    public function leaveNode(Node $node)
    {
        // 可以在这里实现删除未使用代码的逻辑
        // 但为了安全起见，暂时不删除任何代码
        return null;
    }
}