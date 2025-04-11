<?php

namespace PhpPacker\Parser;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Adapter\DependencyAnalyzerAdapter;
use PhpPacker\Ast\AstManagerInterface;
use PhpPacker\Parser\Config\ParserConfig as ExternalParserConfig;
use Psr\Log\LoggerInterface;

/**
 * 解析器适配器，使用新包中的解析器
 */
class CodeParser
{
    /**
     * 底层使用的解析器
     *
     * @var \PhpPacker\Parser\CodeParserInterface
     */
    private $externalParser;

    /**
     * 配置对象
     */
    private ConfigurationAdapter $config;

    /**
     * 日志记录器
     */
    private LoggerInterface $logger;

    /**
     * @param ConfigurationAdapter $config 配置对象
     * @param LoggerInterface $logger 日志记录器
     * @param DependencyAnalyzerAdapter $dependencyAnalyzer 依赖分析器适配器
     * @param AstManagerInterface $astManager AST管理器
     */
    public function __construct(
        ConfigurationAdapter $config,
        LoggerInterface $logger,
        private readonly DependencyAnalyzerAdapter $dependencyAnalyzer,
        private readonly AstManagerInterface $astManager,
    )
    {
        $this->config = $config;
        $this->logger = $logger;
        
        // 创建外部解析器配置
        $parserConfig = new ExternalParserConfig();
        $parserConfig->setEnableStopwatch(true);
        
        // 创建并配置外部解析器
        $entryFile = $this->config->getEntryFile();
        $excludePatterns = $this->config->getExclude();
        
        // 使用ParserFactory创建解析器实例
        $this->externalParser = \PhpPacker\Parser\ParserFactory::create(
            $entryFile,
            $excludePatterns,
            $parserConfig,
            $logger
        );
    }
    
    /**
     * 解析文件
     */
    public function parse(string $file): void
    {
        $this->logger->debug('Using external parser to parse file', ['file' => $file]);
        $this->externalParser->parse($file);
    }
    
    /**
     * 检查文件是否已处理
     */
    public function isFileProcessed(string $file): bool
    {
        return $this->externalParser->isFileProcessed($file);
    }

    /**
     * 获取AST管理器
     */
    public function getAstManager(): AstManagerInterface
    {
        return $this->astManager;
    }

    /**
     * 获取已处理的文件列表
     */
    public function getProcessedFiles(): array
    {
        return $this->externalParser->getProcessedFiles();
    }

    /**
     * 获取依赖关系映射
     */
    public function getDependencies(): array
    {
        return $this->externalParser->getDependencies();
    }
}
