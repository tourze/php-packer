<?php

namespace PhpPacker\Adapter;

use PhpPacker\Analysis\ReflectionService as AnalysisReflectionService;
use PhpPacker\Config\Configuration;
use Psr\Log\LoggerInterface;

/**
 * 反射服务适配器，将新包中的ReflectionService适配到旧版用法
 */
class ReflectionServiceAdapter
{
    /**
     * 底层使用的反射服务
     */
    private AnalysisReflectionService $reflectionService;

    /**
     * @param Configuration $config 配置对象
     * @param LoggerInterface|null $logger 日志记录器
     */
    public function __construct(Configuration $config, ?LoggerInterface $logger = null)
    {
        // 创建新的反射服务实例
        $this->reflectionService = new AnalysisReflectionService($config->getExclude(), $logger);
    }

    /**
     * 读取类所在的文件名
     */
    public function getClassFileName(string $className): ?string
    {
        return $this->reflectionService->getClassFileName($className);
    }

    /**
     * 读取函数所在的文件名
     */
    public function getFunctionFileName(string $functionName): ?string
    {
        return $this->reflectionService->getFunctionFileName($functionName);
    }

    /**
     * 获取底层的反射服务实例
     * 
     * @return AnalysisReflectionService 反射服务实例
     */
    public function getReflectionService(): AnalysisReflectionService
    {
        return $this->reflectionService;
    }
} 