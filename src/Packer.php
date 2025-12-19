<?php

declare(strict_types=1);

namespace PhpPacker;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Analyzer\AutoloadResolver;
use PhpPacker\Analyzer\DependencyResolver;
use PhpPacker\Analyzer\FileAnalyzer;
use PhpPacker\Exception\GeneralPackerException;
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
        if (null === $databasePath) {
            $databasePath = 'build/packer.db';
        }
        if (!str_starts_with($databasePath, '/')) {
            $databasePath = $this->config->getRootPath() . '/' . $databasePath;
        }

        // Ensure the database directory exists
        $databaseDir = dirname($databasePath);
        if (!is_dir($databaseDir)) {
            mkdir($databaseDir, 0o755, true);
        }

        $this->storage = new SqliteStorage($databasePath, $this->logger);

        $rootPath = $this->config->getRootPath();
        $this->fileAnalyzer = new FileAnalyzer($this->storage, $this->logger, $rootPath);

        $this->autoloadResolver = new AutoloadResolver($this->storage, $this->logger);

        $this->dependencyResolver = new DependencyResolver(
            $this->storage,
            $this->logger,
            $this->autoloadResolver,
            $this->fileAnalyzer,
            $rootPath
        );

        $this->codeGenerator = new AstCodeGenerator(
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

        return round($bytes, 2) . ' ' . $units[(int) $pow];
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
            $outSize = filesize($outputPath);
            $outputSize = is_int($outSize) ? $outSize : 0;
            $this->logger->info('Packing completed successfully', [
                'duration' => $event->getDuration() . 'ms',
                'memory' => $this->formatBytes($event->getMemory()),
                'files' => count($files),
                'output' => $outputPath,
                'size' => $this->formatBytes($outputSize),
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
        if (count($customAutoload) > 0) {
            $this->processCustomAutoload($customAutoload);
        }

        $event = $this->stopwatch->stop('autoload');
        $this->logger->info('Autoload rules loaded', [
            'duration' => $event->getDuration() . 'ms',
        ]);
    }

    /**
     * @param array{psr-4?: array<string, string|array<int, string>>} $autoload
     */
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
        if (null === $entry || false === $entry || '' === $entry) {
            throw new GeneralPackerException('Entry file not specified in configuration');
        }

        $entryPath = $this->config->getRootPath() . '/' . $entry;
        if (!file_exists($entryPath)) {
            throw new GeneralPackerException("Entry file not found: {$entryPath}");
        }

        $real = realpath($entryPath);
        if (false === $real) {
            throw new GeneralPackerException("Failed to resolve real path: {$entryPath}");
        }

        return $real;
    }

    private function getRelativePath(string $path): string
    {
        $rootPath = realpath($this->config->getRootPath());
        $absPath = realpath($path);

        if (false !== $rootPath && false !== $absPath && 0 === strpos($absPath, $rootPath)) {
            return substr($absPath, strlen($rootPath) + 1);
        }

        return false !== $absPath ? $absPath : $path;
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
        if (0 === count($includePatterns)) {
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
            'rootPath' => $rootPath,
        ]);

        $basePath = $this->resolveBasePath($rootPath, $pattern);
        $adjustedPattern = $this->adjustPattern($pattern);

        if (str_contains($adjustedPattern, '**')) {
            $this->scanWithDoubleWildcard($basePath, $adjustedPattern);
        } else {
            $this->scanWithGlob($basePath, $adjustedPattern);
        }
    }

    private function resolveBasePath(string $rootPath, string $pattern): string
    {
        if (str_starts_with($pattern, '../')) {
            return dirname($rootPath);
        }

        return $rootPath;
    }

    private function adjustPattern(string $pattern): string
    {
        if (str_starts_with($pattern, '../')) {
            return substr($pattern, 3);
        }

        return $pattern;
    }

    private function scanWithDoubleWildcard(string $basePath, string $pattern): void
    {
        $parts = explode('**', $pattern);
        $baseDir = $basePath . '/' . rtrim($parts[0], '/');

        $this->logger->debug('Scanning directory for ** pattern', [
            'baseDir' => $baseDir,
            'exists' => is_dir($baseDir),
        ]);

        if (is_dir($baseDir)) {
            $this->scanDirectory($baseDir);
        } else {
            $this->logger->warning('Directory not found', ['dir' => $baseDir]);
        }
    }

    private function scanWithGlob(string $basePath, string $pattern): void
    {
        $fullPattern = $basePath . '/' . $pattern;
        $this->logger->debug('Using glob pattern', ['fullPattern' => $fullPattern]);

        $files = glob($fullPattern, GLOB_BRACE);
        if (false === $files) {
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
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $this->scanFile($file->getPathname());
                ++$count;
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
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array<mixed>
     */
    private function getFilesInLoadOrder(string $entryFile): array
    {
        // Convert absolute path to relative for lookup
        $relativePath = $this->getRelativePath($entryFile);
        $entryData = $this->storage->getFileByPath($relativePath);

        if (null === $entryData || [] === $entryData) {
            // Try to find by absolute path in case it was stored that way
            $entryData = $this->storage->getFileByPath($entryFile);
        }

        if (null === $entryData || [] === $entryData) {
            throw new GeneralPackerException('Entry file not found in storage: ' . $relativePath);
        }

        return $this->dependencyResolver->getLoadOrder($entryData['id']);
    }
}
