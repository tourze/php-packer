<?php

declare(strict_types=1);

namespace PhpPacker\Command;

use PhpPacker\Storage\SqliteStorage;

class FilesCommand extends BaseCommand
{
    public function getName(): string
    {
        return 'files';
    }
    
    public function getDescription(): string
    {
        return 'List all files in the database with their dependencies';
    }
    
    public function getUsage(): string
    {
        return 'php-packer files [options]
        
Options:
  --database, -d     Database file path (default: ./packer.db)
  --root-path, -r    Project root path (default: current directory)
  --type, -t         Filter by file type (class, trait, interface, script)
  --stats            Show statistics only
  --entry            Show entry file(s) only
  --sort             Sort by: name, type, size, dependencies (default: name)
  --help, -h         Show this help message';
    }
    
    public function execute(array $args, array $options): int
    {
        $rootPath = $options['root-path'] ?? $options['r'] ?? getcwd();
        $databasePath = $options['database'] ?? $options['d'] ?? './packer.db';
        $typeFilter = $options['type'] ?? $options['t'] ?? null;
        $statsOnly = isset($options['stats']);
        $entryOnly = isset($options['entry']);
        $sortBy = $options['sort'] ?? 'name';
        
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
            
            if ($statsOnly) {
                $this->showStatistics($storage);
                return 0;
            }
            
            $files = $this->getFiles($storage, $typeFilter, $entryOnly, $sortBy);
            
            if (empty($files)) {
                $this->logger->info("No files found");
                return 0;
            }
            
            $this->displayFiles($storage, $files);
            
            return 0;
        } catch (\Exception $e) {
            $this->logger->error("Failed to list files: " . $e->getMessage());
            return 1;
        }
    }
    
    private function showStatistics(SqliteStorage $storage): void
    {
        $stats = $storage->getStatistics();
        
        $this->logger->info("Database Statistics:");
        $this->logger->info("  Total files: {$stats['total_files']}");
        $this->logger->info("  Classes: {$stats['total_classes']}");
        $this->logger->info("  Dependencies: {$stats['total_dependencies']}");
        $this->logger->info("  Autoload rules: {$stats['total_autoload_rules']}");
        
        // 按类型统计
        $pdo = $storage->getPdo();
        $stmt = $pdo->query('
            SELECT file_type, COUNT(*) as count 
            FROM files 
            GROUP BY file_type 
            ORDER BY count DESC
        ');
        
        $this->logger->info("\nFiles by type:");
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $this->logger->info("  {$row['file_type']}: {$row['count']}");
        }
    }
    
    private function getFiles(SqliteStorage $storage, ?string $typeFilter, bool $entryOnly, string $sortBy): array
    {
        $pdo = $storage->getPdo();
        
        $sql = 'SELECT * FROM files WHERE 1=1';
        $params = [];
        
        if ($typeFilter) {
            $sql .= ' AND file_type = :type';
            $params[':type'] = $typeFilter;
        }
        
        if ($entryOnly) {
            $sql .= ' AND is_entry = 1';
        }
        
        // 排序
        switch ($sortBy) {
            case 'type':
                $sql .= ' ORDER BY file_type, path';
                break;
            case 'size':
                $sql .= ' ORDER BY LENGTH(content) DESC';
                break;
            case 'dependencies':
                // 需要左连接来计算依赖数
                $sql = str_replace('SELECT *', 'SELECT f.*, COUNT(d.id) as dep_count', $sql);
                $sql = str_replace('FROM files', 'FROM files f LEFT JOIN dependencies d ON f.id = d.source_file_id', $sql);
                $sql .= ' GROUP BY f.id ORDER BY dep_count DESC';
                break;
            case 'name':
            default:
                $sql .= ' ORDER BY path';
                break;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    private function displayFiles(SqliteStorage $storage, array $files): void
    {
        $pdo = $storage->getPdo();
        $totalSize = 0;
        
        $this->logger->info("Files in database (" . count($files) . "):\n");
        
        foreach ($files as $file) {
            $size = strlen($file['content']);
            $totalSize += $size;
            
            // 获取依赖数
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM dependencies WHERE source_file_id = :id');
            $stmt->execute([':id' => $file['id']]);
            $depCount = $stmt->fetchColumn();
            
            // 获取被依赖数
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM dependencies WHERE target_file_id = :id');
            $stmt->execute([':id' => $file['id']]);
            $usedByCount = $stmt->fetchColumn();
            
            $line = sprintf(
                "  %-60s [%s] %s deps:%d used:%d",
                $file['path'],
                $file['file_type'] ?? 'unknown',
                $this->formatBytes($size),
                $depCount,
                $usedByCount
            );
            
            if ($file['is_entry']) {
                $line .= " [ENTRY]";
            }
            
            $this->logger->info($line);
            
            if ($file['class_name']) {
                $this->logger->info("    Class: {$file['class_name']}");
            }
        }
        
        $this->logger->info("\nTotal size: " . $this->formatBytes($totalSize));
    }
}