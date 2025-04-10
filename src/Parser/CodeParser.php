<?php

namespace PhpPacker\Parser;

use PhpPacker\Adapter\DependencyAnalyzerAdapter;
use PhpPacker\Ast\AstManagerInterface;
use PhpPacker\Ast\CodeParser as AstCodeParser;
use PhpPacker\Ast\ParserFactory as AstParserFactory;
use PhpPacker\Config\Configuration;
use PhpPacker\Exception\ResourceException;
use PhpParser\Parser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Stopwatch\Stopwatch;

class CodeParser
{
    private Configuration $config;
    private LoggerInterface $logger;
    private array $processedFiles = [];
    private array $dependencies = [];
    private array $psr4Map = [];
    private Parser $parser;
    private AstCodeParser $astCodeParser;

    public function __construct(
        Configuration $config,
        LoggerInterface $logger,
        private readonly DependencyAnalyzerAdapter $dependencyAnalyzer,
        private readonly AstManagerInterface $astManager,
    )
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->loadPsr4Map();

        $this->parser = AstParserFactory::createPhp81Parser();
        $this->astCodeParser = new AstCodeParser($this->astManager, $this->parser, $this->logger);
    }
    
    private function loadPsr4Map(): void
    {
        $vendorPath = dirname($this->config->getEntryFile()) . '/vendor/';
        $autoloadFile = $vendorPath . 'composer/autoload_psr4.php';
        
        if (file_exists($autoloadFile)) {
            $this->psr4Map = require $autoloadFile;
            $this->logger->debug('Loaded PSR-4 autoload map', [
                'namespaces' => array_keys($this->psr4Map)
            ]);
        } else {
            $this->logger->warning('PSR-4 autoload map not found');
        }
    }
    
    public function parse(string $file): void
    {
        if ($this->isFileProcessed($file)) {
            return;
        }

        $stopwatch = new Stopwatch();
        $stopwatch->start('parse');

        $this->logger->debug('Parsing file', ['file' => $file]);

        // 使用新的AST解析器解析文件
        $ast = $this->astCodeParser->parseFile($file);
        $this->processedFiles[] = $file;

        // 分析文件中的依赖
        $this->dependencies[$file] = iterator_to_array($this->dependencyAnalyzer->findDepFiles($file, $ast));

        // 递归分析依赖文件
        foreach ($this->dependencies[$file] ?? [] as $dependencyFile) {
            $this->parse($dependencyFile);
        }

        $event = $stopwatch->stop('parse');
        $this->logger->debug('File parsed successfully', [
            'file' => $file,
            'stopwatch' => strval($event),
        ]);
    }

    private function parseCode(string $fileName): array
    {
        $code = @file_get_contents($fileName);
        if ($code === false) {
            throw new ResourceException("Failed to read file: $fileName");
        }

        // 使用AST解析器解析代码
        return $this->astCodeParser->parseCode($code, $fileName);
    }
    
    private function isFileProcessed(string $file): bool
    {
        return in_array($file, $this->processedFiles);
    }
    
    public function getAstManager(): AstManagerInterface
    {
        return $this->astManager;
    }
    
    public function getProcessedFiles(): array
    {
        return $this->processedFiles;
    }
    
    public function getDependencies(): array
    {
        return $this->dependencies;
    }
}
