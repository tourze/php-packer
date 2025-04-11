<?php

namespace PhpPacker\Adapter;

use PhpPacker\Resource\ResourceFinder;
use PhpPacker\Resource\ResourceManager;
use Psr\Log\LoggerInterface;

/**
 * 资源管理器适配器，将新包中的ResourceManager适配到旧版用法
 */
class ResourceManagerAdapter
{
    /**
     * 底层使用的资源管理器
     */
    private ResourceManager $resourceManager;
    
    /**
     * 资源查找器
     */
    private ResourceFinder $resourceFinder;
    
    /**
     * @param ConfigurationAdapter $config 配置适配器
     * @param LoggerInterface $logger 日志记录器
     */
    public function __construct(ConfigurationAdapter $config, LoggerInterface $logger)
    {
        // 创建底层资源管理器实例
        $this->resourceManager = new ResourceManager($config->getConfiguration(), $logger);
        
        // 创建资源查找器
        $this->resourceFinder = new ResourceFinder($this->resourceManager);
    }
    
    /**
     * 复制所有配置的资源文件
     */
    public function copyResources(): void
    {
        $this->resourceManager->copyResources();
    }
    
    /**
     * 复制单个资源文件
     * 
     * @param string $source 源文件路径
     * @param string $target 目标文件路径
     */
    public function copyResource(string $source, string $target): void
    {
        $this->resourceManager->copyResource($source, $target);
    }
    
    /**
     * 清理输出目录
     */
    public function cleanOutputDirectory(): void
    {
        $this->resourceManager->cleanOutputDirectory();
    }
    
    /**
     * 检查文件是否为资源文件
     * 
     * @param string $file 文件路径
     * @return bool 是否为资源文件
     */
    public function isResourceFile(string $file): bool
    {
        return $this->resourceManager->isResourceFile($file);
    }
    
    /**
     * 验证资源文件是否存在
     */
    public function validateResources(): void
    {
        $this->resourceManager->validateResources();
    }
    
    /**
     * 查找AST中使用的资源
     * 
     * @param string $fileName 当前文件名
     * @param array $stmts AST语法树
     * @return string[] 找到的资源文件列表
     */
    public function findResources(string $fileName, array $stmts): array
    {
        return $this->resourceFinder->findResources($fileName, $stmts);
    }
    
    /**
     * 获取底层资源管理器实例
     */
    public function getResourceManager(): ResourceManager
    {
        return $this->resourceManager;
    }
    
    /**
     * 获取资源查找器实例
     */
    public function getResourceFinder(): ResourceFinder
    {
        return $this->resourceFinder;
    }
}
