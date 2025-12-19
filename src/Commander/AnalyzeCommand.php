<?php

declare(strict_types=1);

namespace PhpPacker\Commander;

use PhpPacker\Analyzer\AutoloadResolver;
use PhpPacker\Analyzer\DependencyResolver;
use PhpPacker\Analyzer\FileAnalyzer;
use PhpPacker\Storage\SqliteStorage;

class AnalyzeCommand extends BaseCommand
{
    public function getName(): string
    {
        return 'analyze';
    }

    public function getDescription(): string
    {
        return 'Analyze PHP entry file and generate dependency database';
    }

    public function getUsage(): string
    {
        return 'php-packer analyze <entry-file> [options]
        
Options:
  --database, -d     Database file path (default: ./packer.db)
  --root-path, -r    Project root path (default: current directory)
  --composer, -c     Composer.json path (default: <root>/composer.json)
  --autoload         Additional autoload config in format "psr4:prefix:path"
  --help, -h         Show this help message';
    }

    /**
     * @param array<int, string> $args
     * @param array<string, mixed> $options
     */
    public function execute(array $args, array $options): int
    {
        if (!$this->validateInput($args)) {
            return 1;
        }

        $config = $this->parseConfiguration($args[0], $options);

        if (!$this->validateEntryFile($config['entryFile'])) {
            return 1;
        }

        $this->prepareDatabaseDirectory($config['databasePath']);
        $this->logConfiguration($config);

        try {
            $this->runAnalysis($config);

            return 0;
        } catch (\Exception $e) {
            $this->logger->error('Analysis failed: ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * @param string[] $args
     */
    private function validateInput(array $args): bool
    {
        if ([] === $args) {
            $this->logger->error('Entry file is required');
            $this->showHelp();

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function parseConfiguration(string $entryFile, array $options): array
    {
        $rootPath = $options['root-path'] ?? $options['r'] ?? getcwd();
        $databasePath = $options['database'] ?? $options['d'] ?? './packer.db';
        $composerPath = $options['composer'] ?? $options['c'] ?? $rootPath . '/composer.json';

        // 解析路径为绝对路径
        $entryFile = $this->makeAbsolutePath($entryFile, $rootPath);
        $databasePath = $this->makeAbsolutePath($databasePath, $rootPath);

        return compact('entryFile', 'rootPath', 'databasePath', 'composerPath', 'options');
    }

    private function makeAbsolutePath(string $path, string $rootPath): string
    {
        return str_starts_with($path, '/') ? $path : $rootPath . '/' . $path;
    }

    private function validateEntryFile(string $entryFile): bool
    {
        if (!file_exists($entryFile)) {
            $this->logger->error("Entry file not found: {$entryFile}");

            return false;
        }

        return true;
    }

    private function prepareDatabaseDirectory(string $databasePath): void
    {
        $dbDir = dirname($databasePath);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0o755, true);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function logConfiguration(array $config): void
    {
        $this->logger->info("Analyzing entry file: {$config['entryFile']}");
        $this->logger->info("Root path: {$config['rootPath']}");
        $this->logger->info("Database: {$config['databasePath']}");
    }

    /**
     * @param array<string, mixed> $config
     */
    private function runAnalysis(array $config): void
    {
        $storage = new SqliteStorage($config['databasePath'], $this->logger);
        $services = $this->initializeServices($storage, $config['rootPath']);

        $this->loadAutoloaders($storage, $services['autoloadResolver'], $config);
        $this->scanVendorDirectory($services['fileAnalyzer'], $config['rootPath']);

        $this->logger->info('Resolving dependencies...');
        $services['dependencyResolver']->resolveAllDependencies($config['entryFile']);

        $this->markEntryFile($storage, $config['entryFile'], $config['rootPath']);
        $this->logStatistics($storage, $config['databasePath']);
    }

    /**
     * @return array<string, mixed>
     */
    private function initializeServices(SqliteStorage $storage, string $rootPath): array
    {
        $fileAnalyzer = new FileAnalyzer($storage, $this->logger, $rootPath);
        $autoloadResolver = new AutoloadResolver($storage, $this->logger);
        $dependencyResolver = new DependencyResolver(
            $storage,
            $this->logger,
            $autoloadResolver,
            $fileAnalyzer,
            $rootPath
        );

        return compact('fileAnalyzer', 'autoloadResolver', 'dependencyResolver');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function loadAutoloaders(SqliteStorage $storage, AutoloadResolver $autoloadResolver, array $config): void
    {
        if (file_exists($config['composerPath'])) {
            $this->logger->info("Loading composer autoload from: {$config['composerPath']}");
            $autoloadResolver->loadComposerAutoload($config['composerPath']);
        }

        if (isset($config['options']['autoload'])) {
            $this->processAdditionalAutoload($storage, $config['options']['autoload']);
        }
    }

    private function markEntryFile(SqliteStorage $storage, string $entryFile, string $rootPath): void
    {
        $relativePath = $this->getRelativePath($entryFile, $rootPath);
        $existingFile = $storage->getFileByPath($relativePath);

        if (null !== $existingFile) {
            $pdo = $storage->getPdo();
            $stmt = $pdo->prepare('UPDATE files SET is_entry = 1 WHERE id = :id');
            $stmt->execute([':id' => $existingFile['id']]);
            $this->logger->info("Marked entry file: {$relativePath}");
        } else {
            $this->logger->error("Entry file not found in database after analysis: {$relativePath}");
        }
    }

    private function logStatistics(SqliteStorage $storage, string $databasePath): void
    {
        $stats = $storage->getStatistics();
        $this->logger->info('Analysis completed successfully!');
        $this->logger->info('Statistics:');
        $this->logger->info("  Total files: {$stats['total_files']}");
        $this->logger->info("  Classes: {$stats['total_classes']}");
        $this->logger->info("  Dependencies: {$stats['total_dependencies']}");
        $fileSize = filesize($databasePath);
        $this->logger->info('  Database size: ' . $this->formatBytes(false !== $fileSize ? $fileSize : 0));
    }

    /**
     * @param string|string[] $autoloadConfig
     */
    private function processAdditionalAutoload(SqliteStorage $storage, $autoloadConfig): void
    {
        $configs = is_array($autoloadConfig) ? $autoloadConfig : [$autoloadConfig];

        foreach ($configs as $config) {
            if (!is_string($config)) {
                $this->logger->warning('Invalid autoload config: not a string');
                continue;
            }
            $parts = explode(':', $config, 3);
            if (3 !== count($parts)) {
                $this->logger->warning("Invalid autoload config format: {$config}");
                continue;
            }

            [$type, $prefix, $path] = $parts;

            if ('psr4' === $type) {
                $storage->addAutoloadRule('psr4', $path, $prefix, 200);
                $this->logger->info("Added PSR-4 autoload: {$prefix} => {$path}");
            }
        }
    }

    private function scanVendorDirectory(FileAnalyzer $fileAnalyzer, string $rootPath): void
    {
        // 尝试多个可能的vendor目录位置
        $possibleVendorPaths = [
            $rootPath . '/vendor',
            $rootPath . '/../vendor',
            $rootPath . '/../../vendor',
            dirname(dirname(dirname($rootPath))) . '/vendor',
        ];

        $vendorPath = null;
        foreach ($possibleVendorPaths as $path) {
            if (is_dir($path)) {
                $realPath = realpath($path);
                if (false !== $realPath) {
                    $vendorPath = $realPath;
                    break;
                }
            }
        }

        if (null === $vendorPath) {
            $this->logger->info('Vendor directory not found, skipping vendor scan');

            return;
        }

        $this->logger->info('Scanning vendor directory for dependencies...', ['vendor_path' => $vendorPath]);

        // 专注于workerman相关的文件
        $workermanPath = $vendorPath . '/workerman/workerman/src';
        if (is_dir($workermanPath)) {
            $this->scanDirectoryRecursively($fileAnalyzer, $workermanPath, '*.php');
        }
    }

    private function scanDirectoryRecursively(FileAnalyzer $fileAnalyzer, string $directory, string $pattern): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
                try {
                    $fileAnalyzer->analyzeFile($file->getPathname());
                    ++$count;

                    if (0 === $count % 10) {
                        $this->logger->info("Scanned {$count} files...");
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to analyze file: ' . $file->getPathname(), [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->logger->info("Scanned {$count} files from {$directory}");
    }

    private function getRelativePath(string $path, string $rootPath): string
    {
        $realPath = realpath($path);
        $realRootPath = realpath($rootPath);

        if (false === $realPath || false === $realRootPath) {
            return $path;
        }

        if (0 === strpos($realPath, $realRootPath)) {
            return substr($realPath, strlen($realRootPath) + 1);
        }

        return $realPath;
    }
}
