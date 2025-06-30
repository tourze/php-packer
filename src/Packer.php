<?php

declare(strict_types=1);

namespace PhpPacker;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Analyzer\AutoloadResolver;
use PhpPacker\Analyzer\DependencyResolver;
use PhpPacker\Analyzer\FileAnalyzer;
use PhpPacker\Exception\PackerException;
use PhpPacker\Generator\AstCodeGenerator;
use PhpPacker\Storage\SqliteStorage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Stopwatch\Stopwatch;

class Packer
{
    private ConfigurationAdapter $config;
    private LoggerInterface $logger;
    private SqliteStorage $storage;
    private FileAnalyzer $fileAnalyzer;
    private AutoloadResolver $autoloadResolver;
    private DependencyResolver $dependencyResolver;
    private AstCodeGenerator $codeGenerator;
    private Stopwatch $stopwatch;

    public function __construct(ConfigurationAdapter $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->stopwatch = new Stopwatch();

        $this->initialize();
    }

    private function initialize(): void
    {
        $this->stopwatch->start('initialization');

        $databasePath = $this->config->get('database', 'build/packer.db');
        if ($databasePath !== null && !str_starts_with($databasePath, '/')) {
            $databasePath = $this->config->getRootPath() . '/' . $databasePath;
        }
        
        // Ensure the database directory exists
        $databaseDir = dirname($databasePath);
        if (!is_dir($databaseDir)) {
            mkdir($databaseDir, 0755, true);
        }
        
        $this->storage = new SqliteStorage($databasePath, $this->logger);

        $rootPath = $this->config->getRootPath();
        $this->fileAnalyzer = new FileAnalyzer($this->storage, $this->logger, $rootPath);

        $this->autoloadResolver = new AutoloadResolver($this->storage, $this->logger, $rootPath);

        $this->dependencyResolver = new DependencyResolver(
            $this->storage,
            $this->logger,
            $this->autoloadResolver,
            $this->fileAnalyzer,
            $rootPath
        );

        $this->codeGenerator = new AstCodeGenerator(
            $this->storage,
            $this->logger,
            $this->config->all()
        );

        $event = $this->stopwatch->stop('initialization');
        $this->logger->info('Initialization completed', [
            'duration' => $event->getDuration() . 'ms',
            'memory' => $this->formatBytes($event->getMemory()),
        ]);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes > 0 ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    public function pack(): void
    {
        $this->stopwatch->start('total');

        try {
            $this->logger->info('Starting packing process');

            $this->loadAutoloadRules();

            $entryFile = $this->resolveEntryFile();
            
            $this->scanIncludeDirectories();

            $this->analyzeAndResolveDependencies($entryFile);

            $files = $this->getFilesInLoadOrder($entryFile);

            $outputPath = $this->config->get('output', 'packed.php');
            if (!str_starts_with($outputPath, '/')) {
                $outputPath = $this->config->getRootPath() . '/' . $outputPath;
            }

            // 使用新的 AST 代码生成器
            $this->codeGenerator->generate($files, $entryFile, $outputPath);

            $event = $this->stopwatch->stop('total');
            $this->logger->info('Packing completed successfully', [
                'duration' => $event->getDuration() . 'ms',
                'memory' => $this->formatBytes($event->getMemory()),
                'files' => count($files),
                'output' => $outputPath,
                'size' => $this->formatBytes(filesize($outputPath)),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Packing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function loadAutoloadRules(): void
    {
        $this->stopwatch->start('autoload');

        $composerPath = $this->config->getRootPath() . '/composer.json';
        if (file_exists($composerPath)) {
            $this->autoloadResolver->loadComposerAutoload($composerPath);
        }

        $customAutoload = $this->config->get('autoload', []);
        if (!empty($customAutoload)) {
            $this->processCustomAutoload($customAutoload);
        }

        $event = $this->stopwatch->stop('autoload');
        $this->logger->info('Autoload rules loaded', [
            'duration' => $event->getDuration() . 'ms',
        ]);
    }

    private function processCustomAutoload(array $autoload): void
    {
        if (isset($autoload['psr-4'])) {
            foreach ($autoload['psr-4'] as $prefix => $paths) {
                foreach ((array)$paths as $path) {
                    $absolutePath = $this->config->getRootPath() . '/' . $path;
                    $this->storage->addAutoloadRule('psr4', $absolutePath, $prefix, 200);
                }
            }
        }
    }

    private function resolveEntryFile(): string
    {
        $entry = $this->config->get('entry');
        if (!$entry) {
            throw new PackerException('Entry file not specified in configuration');
        }

        $entryPath = $this->config->getRootPath() . '/' . $entry;
        if (!file_exists($entryPath)) {
            throw new PackerException("Entry file not found: $entryPath");
        }

        return realpath($entryPath);
    }

    private function getRelativePath(string $path): string
    {
        $rootPath = realpath($this->config->getRootPath());
        $path = realpath($path);

        if (strpos($path, $rootPath) === 0) {
            return substr($path, strlen($rootPath) + 1);
        }

        return $path;
    }

    private function analyzeAndResolveDependencies(string $entryFile): void
    {
        $this->stopwatch->start('analysis');

        $this->dependencyResolver->resolveAllDependencies($entryFile);

        $event = $this->stopwatch->stop('analysis');
        $this->logger->info('Dependency analysis completed', [
            'duration' => $event->getDuration() . 'ms',
            'memory' => $this->formatBytes($event->getMemory()),
        ]);
    }

    private function scanIncludeDirectories(): void
    {
        $includePatterns = $this->config->getIncludePatterns();
        if (empty($includePatterns)) {
            return;
        }
        
        $this->logger->info('Scanning include directories', ['patterns' => count($includePatterns)]);
        
        foreach ($includePatterns as $pattern) {
            $this->scanPattern($pattern);
        }
    }
    
    private function scanPattern(string $pattern): void
    {
        $rootPath = $this->config->getRootPath();
        
        $this->logger->debug('Scanning pattern', [
            'pattern' => $pattern,
            'rootPath' => $rootPath
        ]);
        
        // 将模式转换为实际路径
        $basePath = $rootPath;
        if (str_starts_with($pattern, '../')) {
            $basePath = dirname($rootPath);
            $pattern = substr($pattern, 3);
        }
        
        // 处理 ** 通配符
        if (str_contains($pattern, '**')) {
            // 提取基础目录
            $parts = explode('**', $pattern);
            $baseDir = $basePath . '/' . rtrim($parts[0], '/');
            
            $this->logger->debug('Scanning directory for ** pattern', [
                'baseDir' => $baseDir,
                'exists' => is_dir($baseDir)
            ]);
            
            if (is_dir($baseDir)) {
                $this->scanDirectory($baseDir);
            } else {
                $this->logger->warning('Directory not found', ['dir' => $baseDir]);
            }
        } else {
            // 处理普通 glob 模式
            $fullPattern = $basePath . '/' . $pattern;
            $this->logger->debug('Using glob pattern', ['fullPattern' => $fullPattern]);
            
            $files = glob($fullPattern, GLOB_BRACE);
            if ($files === false) {
                $this->logger->warning('Failed to scan pattern', ['pattern' => $pattern]);
                return;
            }
            
            $this->logger->debug('Glob found files', ['count' => count($files)]);
            
            foreach ($files as $file) {
                if (is_file($file) && str_ends_with($file, '.php')) {
                    $this->scanFile($file);
                }
            }
        }
    }
    
    private function scanDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $this->logger->debug('Scanning directory', ['dir' => $dir]);
        
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->scanFile($file->getPathname());
                $count++;
            }
        }
        
        $this->logger->debug('Scanned directory', ['dir' => $dir, 'files' => $count]);
    }
    
    private function scanFile(string $file): void
    {
        if (!$this->config->shouldExclude($file)) {
            try {
                // 分析文件并存储 AST，但不立即处理依赖
                $this->fileAnalyzer->analyzeFile($file);
                $this->logger->debug('Scanned file', ['file' => $file]);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to scan file', [
                    'file' => $file,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function getFilesInLoadOrder(string $entryFile): array
    {
        // Convert absolute path to relative for lookup
        $relativePath = $this->getRelativePath($entryFile);
        $entryData = $this->storage->getFileByPath($relativePath);

        if (empty($entryData)) {
            // Try to find by absolute path in case it was stored that way
            $entryData = $this->storage->getFileByPath($entryFile);
        }

        if (empty($entryData)) {
            throw new PackerException('Entry file not found in storage: ' . $relativePath);
        }

        return $this->dependencyResolver->getLoadOrder($entryData['id']);
    }
}
