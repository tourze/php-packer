<?php

declare(strict_types=1);

namespace PhpPacker;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Analyzer\AutoloadResolver;
use PhpPacker\Analyzer\DependencyResolver;
use PhpPacker\Analyzer\FileAnalyzer;
use PhpPacker\Dumper\BootstrapGenerator;
use PhpPacker\Dumper\CodeDumper;
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
    private BootstrapGenerator $bootstrapGenerator;
    private CodeDumper $codeDumper;
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
        if (!str_starts_with($databasePath, '/')) {
            $databasePath = $this->config->getRootPath() . '/' . $databasePath;
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
        
        $this->bootstrapGenerator = new BootstrapGenerator(
            $this->storage,
            $this->logger,
            $this->config->all()
        );
        
        $this->codeDumper = new CodeDumper(
            $this->storage,
            $this->logger,
            $this->bootstrapGenerator,
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
            $this->markEntryFile($entryFile);

            $this->analyzeAndResolveDependencies($entryFile);

            $files = $this->getFilesInLoadOrder($entryFile);

            $outputPath = $this->config->get('output', 'packed.php');
            if (!str_starts_with($outputPath, '/')) {
                $outputPath = $this->config->getRootPath() . '/' . $outputPath;
            }
            $this->codeDumper->dump($files, $entryFile, $outputPath);

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
                foreach ((array) $paths as $path) {
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
            throw new \RuntimeException('Entry file not specified in configuration');
        }

        $entryPath = $this->config->getRootPath() . '/' . $entry;
        if (!file_exists($entryPath)) {
            throw new \RuntimeException("Entry file not found: $entryPath");
        }

        return realpath($entryPath);
    }

    private function markEntryFile(string $entryFile): void
    {
        $content = file_get_contents($entryFile);
        if ($content === false) {
            throw new \RuntimeException("Failed to read entry file: $entryFile");
        }

        $relativePath = $this->getRelativePath($entryFile);
        $this->storage->addFile($relativePath, $content, 'script', null, true);
    }

    private function getRelativePath(string $path): string
    {
        $rootPath = $this->config->getRootPath();
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

    private function getFilesInLoadOrder(string $entryFile): array
    {
        $entryData = $this->storage->getFileByPath($this->getRelativePath($entryFile));
        if (empty($entryData)) {
            throw new \RuntimeException('Entry file not found in storage');
        }

        return $this->dependencyResolver->getLoadOrder($entryData['id']);
    }
}