<?php

namespace PhpPacker\Adapter;

use PhpPacker\Analysis\Dependency\DependencyAnalyzer as AnalysisDependencyAnalyzer;
use PhpPacker\Analysis\Dependency\DependencyAnalyzerInterface;
use PhpPacker\Analysis\Visitor\DefaultVisitorFactory;
use PhpPacker\Ast\AstManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * 依赖分析器适配器，将新包中的DependencyAnalyzer适配到旧版用法
 */
class DependencyAnalyzerAdapter implements DependencyAnalyzerInterface
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
        AstManagerInterface $astManager,
        ReflectionServiceAdapter $reflectionService,
        LoggerInterface $logger
    ) {
        // 创建访问者工厂
        $visitorFactory = new DefaultVisitorFactory();
        
        // 创建底层依赖分析器，添加visitorFactory参数
        $this->analyzer = new AnalysisDependencyAnalyzer(
            $astManager, 
            $reflectionService->getReflectionService(), 
            $visitorFactory,
            null,
            null,
            null,
            $logger
        );
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
    public function findDependencies(string $fileName, array $stmts): \Traversable
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
