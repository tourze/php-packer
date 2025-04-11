<?php

namespace PhpPacker\Adapter;

use PhpPacker\Config\Configuration as ConfigPackageConfiguration;
use Psr\Log\LoggerInterface;

/**
 * 配置适配器，将新包中的Configuration适配到旧版用法
 */
class ConfigurationAdapter
{
    /**
     * 底层使用的配置
     */
    private ConfigPackageConfiguration $config;

    /**
     * @param string $configFile 配置文件路径
     * @param LoggerInterface $logger 日志记录器
     */
    public function __construct(string $configFile, LoggerInterface $logger)
    {
        // 创建底层配置实例
        $this->config = new ConfigPackageConfiguration($configFile, $logger);
    }

    /**
     * 获取入口文件路径
     */
    public function getEntryFile(): string
    {
        return $this->config->getEntryFile();
    }

    /**
     * 获取输出文件路径
     */
    public function getOutputFile(): string
    {
        return $this->config->getOutputFile();
    }

    /**
     * 获取排除的文件模式
     */
    public function getExclude(): array
    {
        return $this->config->getExclude();
    }

    /**
     * 获取资源文件映射
     */
    public function getAssets(): array
    {
        return $this->config->getAssets();
    }

    /**
     * 是否启用代码压缩
     */
    public function shouldMinify(): bool
    {
        return $this->config->shouldMinify();
    }

    /**
     * 是否保留注释
     */
    public function shouldKeepComments(): bool
    {
        return $this->config->shouldKeepComments();
    }

    /**
     * 是否为调试模式
     */
    public function isDebug(): bool
    {
        return $this->config->isDebug();
    }

    /**
     * 获取源代码路径列表
     */
    public function getSourcePaths(): array
    {
        return $this->config->getSourcePaths();
    }

    /**
     * 获取资源文件路径列表
     */
    public function getResourcePaths(): array
    {
        return $this->config->getResourcePaths();
    }

    /**
     * 获取输出目录
     */
    public function getOutputDirectory(): string
    {
        return $this->config->getOutputDirectory();
    }

    /**
     * 获取原始配置数组
     */
    public function getRaw(): array
    {
        return $this->config->getRaw();
    }

    /**
     * 是否清理输出目录
     */
    public function shouldCleanOutput(): bool
    {
        return $this->config->shouldCleanOutput();
    }

    /**
     * 是否移除命名空间
     */
    public function shouldRemoveNamespace(): bool
    {
        return $this->config->shouldRemoveNamespace();
    }

    /**
     * 是否为KPHP生成代码
     */
    public function forKphp(): bool
    {
        return $this->config->forKphp();
    }

    /**
     * 获取配置文件路径
     */
    public function getConfigFile(): string
    {
        return $this->config->getConfigFile();
    }

    /**
     * 获取底层配置实例
     */
    public function getConfiguration(): ConfigPackageConfiguration
    {
        return $this->config;
    }
}
