<?php

namespace PhpPacker;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Adapter\DependencyAnalyzerAdapter;
use PhpPacker\Adapter\ReflectionServiceAdapter;
use PhpPacker\Adapter\ResourceManagerAdapter;
use PhpPacker\Ast\AstManager;
use PhpPacker\Ast\AstManagerInterface;
use PhpPacker\Generator\CodeGenerator;
use PhpPacker\Generator\CodeGeneratorInterface;
use PhpPacker\Generator\Config\GeneratorConfig;
use PhpPacker\Parser\CodeParser;
use PhpPacker\Resource\Exception\ResourceException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Stopwatch\Stopwatch;

class Packer
{
    private LoggerInterface $logger;
    private AstManagerInterface $astManager;
    private CodeParser $parser;
    private CodeGeneratorInterface $generator;
    private DependencyAnalyzerAdapter $analyzer;
    private ReflectionServiceAdapter $reflectionService;
    private ResourceManagerAdapter $resourceManager;

    public function __construct(private readonly ConfigurationAdapter $config, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->reflectionService = new ReflectionServiceAdapter($this->config, $logger);
        $this->astManager = new AstManager($logger);
        $this->analyzer = new DependencyAnalyzerAdapter($this->astManager, $this->reflectionService, $logger);
        $this->parser = new CodeParser($this->analyzer, $this->astManager, null, null, $logger);
        $this->resourceManager = new ResourceManagerAdapter($this->config, $logger);

        // 创建生成器配置
        $generatorConfig = new GeneratorConfig();
        $generatorConfig->setPreserveComments($this->config->shouldKeepComments());
        $generatorConfig->setRemoveNamespace($this->config->shouldRemoveNamespace());

        // 创建代码生成器
        $this->generator = new CodeGenerator($generatorConfig, $this->astManager, $logger);
    }

    public function pack(): void
    {
        $stopwatch = new Stopwatch();

        $this->logger->info('Starting packing process');

        // 解析入口文件
        $stopwatch->start('parse');
        $this->logger->debug('Parsing code files');
        $entryFile = $this->config->getEntryFile();
        $this->parser->parse($entryFile);
        $event = $stopwatch->stop('parse');
        $this->logger->debug('解析文件完成', [
            'stopwatch' => strval($event),
        ]);

        // 优化文件顺序
        $this->logger->debug('Optimizing file order');
        $phpFiles = $this->analyzer->getOptimizedFileOrder($entryFile);
        //dd($files);

        // 额外加载资源文件
        $resources = [];
        foreach ($phpFiles as $phpFile) {
            $used_resources = $this->analyzer->findUsedResources($phpFile, $this->astManager->getAst($phpFile));
            foreach ($used_resources as $resource) {
                $resources[] = $resource;
            }
        }
        $resources = array_unique($resources);

        // 生成代码
        $code = $this->generator->generate($this->astManager, $phpFiles, $resources);

        // 写入输出
        $this->logger->debug('Writing output');
        $this->writeOutput($code);

        // 复制资源文件
        $this->logger->debug('Copying resources');
        $this->resourceManager->copyResources();

        //$this->logger->debug('Generating self-execute file');
        //$this->generateSelfExecuteFile();

        $this->logger->info('Packing completed successfully');
    }

    private function writeOutput(string $code): void
    {
        $outputFile = $this->config->getOutputFile();
        $this->logger->info('Writing output file');

        $dir = dirname($outputFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        if (file_put_contents($outputFile, $code) === false) {
            throw new ResourceException("Failed to write output file: $outputFile");
        }

        $this->logger->debug('Output file written successfully');
    }

//    private function generateSelfExecuteFile(): void
//    {
//        $targetDir = dirname($this->config->getOutputFile());
//        $targetName = basename($this->config->getOutputFile(), '.php');
//
//        $macosSfxFile = __DIR__ . '/../../bin/php-8.4.1-micro-macos-aarch64-micro.sfx';
//        system("cat $macosSfxFile {$this->config->getOutputFile()} > $targetDir/$targetName-macos-aarch64");
//        system("chmod +x $targetDir/$targetName-macos-aarch64");
//
//        $linuxSfxFile = __DIR__ . '/../../bin/php-8.4.1-micro-linux-x86_64-micro.sfx';
//        system("cat $linuxSfxFile {$this->config->getOutputFile()} > $targetDir/$targetName-linux-x86_64");
//        system("chmod +x $targetDir/$targetName-linux-x86_64");
//    }
}
