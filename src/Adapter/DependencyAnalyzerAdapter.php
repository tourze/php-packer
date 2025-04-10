<?php

namespace PhpPacker\Adapter;

use PhpPacker\Analysis\Dependency\DependencyAnalyzer as AnalysisDependencyAnalyzer;
use PhpPacker\Ast\AstManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * 依赖分析器适配器，将新包中的DependencyAnalyzer适配到旧版用法
 */
class DependencyAnalyzerAdapter
{
    /**
     * 底层使用的依赖分析器
     */
    private AnalysisDependencyAnalyzer $analyzer;

    /**
     * @param AstManagerInterface $astManager AST管理器
     * @param ReflectionServiceAdapter $reflectionService 反射服务
     * @param LoggerInterface $logger 日志记录器
     */
    public function __construct(
        private readonly AstManagerInterface $astManager,
        ReflectionServiceAdapter $reflectionService,
        private readonly LoggerInterface $logger
    ) {
        // 创建底层依赖分析器
        $this->analyzer = new AnalysisDependencyAnalyzer($astManager, $reflectionService->getReflectionService(), $logger);
    }

    /**
     * 获取优化的文件顺序
     */
    public function getOptimizedFileOrder(string $entryFile): array
    {
        return $this->analyzer->getOptimizedFileOrder($entryFile);
    }

    /**
     * 传入AST数组，获取所有依赖的文件列表
     */
    public function findDepFiles(string $fileName, array $stmts): \Traversable
    {
        return $this->analyzer->findDependencies($fileName, $stmts);
    }

    /**
     * 查找使用的资源
     */
    public function findUsedResources(string $fileName, array $stmts): \Traversable
    {
        return $this->analyzer->findUsedResources($fileName, $stmts);
    }
} 