<?php

declare(strict_types=1);

namespace PhpPacker\Command;

use PhpPacker\Storage\SqliteStorage;

class DependenciesCommand extends BaseCommand
{
    public function getName(): string
    {
        return 'dependencies';
    }
    
    public function getDescription(): string
    {
        return 'Query and display file dependencies from database';
    }
    
    public function getUsage(): string
    {
        return 'php-packer dependencies <file-path> [options]
        
Options:
  --database, -d     Database file path (default: ./packer.db)
  --root-path, -r    Project root path (default: current directory)
  --reverse          Show files that depend on this file
  --tree             Display dependencies as tree
  --help, -h         Show this help message';
    }
    
    public function execute(array $args, array $options): int
    {
        if (empty($args)) {
            $this->logger->error('File path is required');
            $this->showHelp();
            return 1;
        }
        
        $filePath = $args[0];
        $rootPath = $options['root-path'] ?? $options['r'] ?? getcwd();
        $databasePath = $options['database'] ?? $options['d'] ?? './packer.db';
        $reverse = isset($options['reverse']);
        $tree = isset($options['tree']);
        
        // 确保数据库路径是绝对路径
        if (!str_starts_with($databasePath, '/')) {
            $databasePath = $rootPath . '/' . $databasePath;
        }
        
        if (!file_exists($databasePath)) {
            $this->logger->error("Database not found: $databasePath");
            $this->logger->info("Please run 'php-packer analyze' first to create the database");
            return 1;
        }
        
        try {
            $storage = new SqliteStorage($databasePath, $this->logger);
            
            // 获取文件信息
            $fileData = $storage->getFileByPath($filePath);
            if (empty($fileData)) {
                $this->logger->error("File not found in database: $filePath");
                return 1;
            }
            
            $this->logger->info("File: {$fileData['path']} (ID: {$fileData['id']})");
            $this->logger->info("Type: {$fileData['file_type']}");
            if ($fileData['class_name']) {
                $this->logger->info("Class: {$fileData['class_name']}");
            }
            
            if ($reverse) {
                $this->showReverseDependencies($storage, $fileData['id'], $tree);
            } else {
                $this->showDependencies($storage, $fileData['id'], $tree);
            }
            
            return 0;
        } catch (\Exception $e) {
            $this->logger->error("Failed to query dependencies: " . $e->getMessage());
            return 1;
        }
    }
    
    private function showReverseDependencies(SqliteStorage $storage, int $fileId, bool $tree): void
    {
        $dependencies = $this->getReverseDependencies($storage, $fileId);

        if (empty($dependencies)) {
            $this->logger->info("\nNo files depend on this file");
            return;
        }

        $this->logger->info("\nFiles that depend on this (" . count($dependencies) . "):");

        if ($tree) {
            $this->showReverseDependencyTree($storage, $fileId, 0, []);
        } else {
            foreach ($dependencies as $dep) {
                $sourceFile = $storage->getFileById($dep['source_file_id']);
                if (!empty($sourceFile)) {
                    $this->logger->info("  ← {$sourceFile['path']} [{$dep['dependency_type']}]");
                }
            }
        }
    }
    
    private function getReverseDependencies(SqliteStorage $storage, int $fileId): array
    {
        $pdo = $storage->getPdo();
        $stmt = $pdo->prepare('
            SELECT * FROM dependencies 
            WHERE target_file_id = :file_id
            ORDER BY dependency_type, source_file_id
        ');
        $stmt->execute([':file_id' => $fileId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    private function showReverseDependencyTree(SqliteStorage $storage, int $fileId, int $level, array $visited): void
    {
        if (in_array($fileId, $visited)) {
            return;
        }

        $visited[] = $fileId;
        $dependencies = $this->getReverseDependencies($storage, $fileId);

        foreach ($dependencies as $dep) {
            $sourceFile = $storage->getFileById($dep['source_file_id']);
            if (!empty($sourceFile)) {
                $indent = str_repeat('  ', $level + 1);
                $this->logger->info("{$indent}← {$sourceFile['path']} [{$dep['dependency_type']}]");

                // 递归显示父依赖
                $this->showReverseDependencyTree($storage, $dep['source_file_id'], $level + 1, $visited);
            }
        }
    }
    
    private function showDependencies(SqliteStorage $storage, int $fileId, bool $tree): void
    {
        $dependencies = $this->getDependencies($storage, $fileId);

        if (empty($dependencies)) {
            $this->logger->info("\nNo dependencies found");
            return;
        }

        $this->logger->info("\nDependencies (" . count($dependencies) . "):");

        if ($tree) {
            $this->showDependencyTree($storage, $fileId, 0, []);
        } else {
            foreach ($dependencies as $dep) {
                $targetFile = $storage->getFileById($dep['target_file_id']);
                if (!empty($targetFile)) {
                    $this->logger->info("  → {$targetFile['path']} [{$dep['dependency_type']}]");
                }
            }
        }
    }
    
    private function getDependencies(SqliteStorage $storage, int $fileId): array
    {
        $pdo = $storage->getPdo();
        $stmt = $pdo->prepare('
            SELECT * FROM dependencies 
            WHERE source_file_id = :file_id
            ORDER BY dependency_type, target_file_id
        ');
        $stmt->execute([':file_id' => $fileId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    private function showDependencyTree(SqliteStorage $storage, int $fileId, int $level, array $visited): void
    {
        if (in_array($fileId, $visited)) {
            return;
        }

        $visited[] = $fileId;
        $dependencies = $this->getDependencies($storage, $fileId);

        foreach ($dependencies as $dep) {
            $targetFile = $storage->getFileById($dep['target_file_id']);
            if (!empty($targetFile)) {
                $indent = str_repeat('  ', $level + 1);
                $this->logger->info("{$indent}→ {$targetFile['path']} [{$dep['dependency_type']}]");

                // 递归显示子依赖
                $this->showDependencyTree($storage, $dep['target_file_id'], $level + 1, $visited);
            }
        }
    }
}