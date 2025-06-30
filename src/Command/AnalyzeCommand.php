<?php

declare(strict_types=1);

namespace PhpPacker\Command;

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

    public function execute(array $args, array $options): int
    {
        if (empty($args)) {
            $this->logger->error('Entry file is required');
            $this->showHelp();
            return 1;
        }

        $entryFile = $args[0];
        $rootPath = $options['root-path'] ?? $options['r'] ?? getcwd();
        $databasePath = $options['database'] ?? $options['d'] ?? './packer.db';
        $composerPath = $options['composer'] ?? $options['c'] ?? $rootPath . '/composer.json';

        // 解析入口文件绝对路径
        if (!str_starts_with($entryFile, '/')) {
            $entryFile = $rootPath . '/' . $entryFile;
        }

        if (!file_exists($entryFile)) {
            $this->logger->error("Entry file not found: $entryFile");
            return 1;
        }

        // 确保数据库路径是绝对路径
        if (!str_starts_with($databasePath, '/')) {
            $databasePath = $rootPath . '/' . $databasePath;
        }

        // 创建数据库目录
        $dbDir = dirname($databasePath);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }

        $this->logger->info("Analyzing entry file: $entryFile");
        $this->logger->info("Root path: $rootPath");
        $this->logger->info("Database: $databasePath");

        try {
            // 初始化存储
            $storage = new SqliteStorage($databasePath, $this->logger);

            // 初始化分析器
            $fileAnalyzer = new FileAnalyzer($storage, $this->logger, $rootPath);
            $autoloadResolver = new AutoloadResolver($storage, $this->logger, $rootPath);
            $dependencyResolver = new DependencyResolver(
                $storage,
                $this->logger,
                $autoloadResolver,
                $fileAnalyzer,
                $rootPath
            );

            // 加载 autoload 规则
            if (file_exists($composerPath)) {
                $this->logger->info("Loading composer autoload from: $composerPath");
                $autoloadResolver->loadComposerAutoload($composerPath);
            }

            // 处理额外的 autoload 配置
            if (isset($options['autoload'])) {
                $this->processAdditionalAutoload($storage, $options['autoload']);
            }

            // 扫描vendor目录和其他可能的依赖文件
            $this->scanVendorDirectory($fileAnalyzer, $rootPath);

            // 分析依赖
            $this->logger->info("Resolving dependencies...");
            $dependencyResolver->resolveAllDependencies($entryFile);

            // 确保入口文件被标记为入口
            $relativePath = $this->getRelativePath($entryFile, $rootPath);
            $existingFile = $storage->getFileByPath($relativePath);
            if ($existingFile !== null) {
                // 更新现有文件，标记为入口文件
                $pdo = $storage->getPdo();
                $stmt = $pdo->prepare('UPDATE files SET is_entry = 1 WHERE id = :id');
                $stmt->execute([':id' => $existingFile['id']]);
                $this->logger->info("Marked entry file: {$relativePath}");
            } else {
                $this->logger->error("Entry file not found in database after analysis: {$relativePath}");
            }

            // 统计信息
            $stats = $storage->getStatistics();
            $this->logger->info("Analysis completed successfully!");
            $this->logger->info("Statistics:");
            $this->logger->info("  Total files: {$stats['total_files']}");
            $this->logger->info("  Classes: {$stats['total_classes']}");
            $this->logger->info("  Dependencies: {$stats['total_dependencies']}");
            $this->logger->info("  Database size: " . $this->formatBytes(filesize($databasePath)));

            return 0;
        } catch (\Exception $e) {
            $this->logger->error("Analysis failed: " . $e->getMessage());
            return 1;
        }
    }

    private function processAdditionalAutoload(SqliteStorage $storage, $autoloadConfig): void
    {
        $configs = is_array($autoloadConfig) ? $autoloadConfig : [$autoloadConfig];

        foreach ($configs as $config) {
            $parts = explode(':', $config, 3);
            if (count($parts) !== 3) {
                $this->logger->warning("Invalid autoload config format: $config");
                continue;
            }

            list($type, $prefix, $path) = $parts;

            if ($type === 'psr4') {
                $storage->addAutoloadRule('psr4', $path, $prefix, 200);
                $this->logger->info("Added PSR-4 autoload: $prefix => $path");
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
                $vendorPath = realpath($path);
                break;
            }
        }
        
        if (!$vendorPath) {
            $this->logger->info("Vendor directory not found, skipping vendor scan");
            return;
        }

        $this->logger->info("Scanning vendor directory for dependencies...", ['vendor_path' => $vendorPath]);
        
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
                    $count++;
                    
                    if ($count % 10 === 0) {
                        $this->logger->info("Scanned $count files...");
                    }
                } catch (\Exception $e) {
                    $this->logger->warning("Failed to analyze file: " . $file->getPathname(), [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        $this->logger->info("Scanned $count files from $directory");
    }

    private function getRelativePath(string $path, string $rootPath): string
    {
        $path = realpath($path);
        $rootPath = realpath($rootPath);

        if (strpos($path, $rootPath) === 0) {
            return substr($path, strlen($rootPath) + 1);
        }

        return $path;
    }
}
