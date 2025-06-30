<?php

declare(strict_types=1);

namespace PhpPacker\Command;

use PhpPacker\Generator\AstCodeGenerator;
use PhpPacker\Storage\SqliteStorage;

class PackCommand extends BaseCommand
{
    public function getName(): string
    {
        return 'pack';
    }
    
    public function getDescription(): string
    {
        return 'Pack analyzed files from database into a single PHP file';
    }
    
    public function getUsage(): string
    {
        return 'php-packer pack [options]
        
Options:
  --database, -d     Database file path (default: ./packer.db)
  --output, -o       Output file path (default: ./packed.php)
  --compression      Enable output compression (gzip)
  --strip-comments   Remove comments from packed code
  --optimize         Enable code optimization
  --help, -h         Show this help message';
    }
    
    public function execute(array $args, array $options): int
    {
        $databasePath = $options['database'] ?? $options['d'] ?? './packer.db';
        $outputPath = $options['output'] ?? $options['o'] ?? './packed.php';
        $compression = isset($options['compression']);
        $stripComments = isset($options['strip-comments']);
        $optimize = isset($options['optimize']);
        
        // 数据库路径处理：如果是相对路径，相对于当前工作目录
        if (!str_starts_with($databasePath, '/')) {
            $databasePath = getcwd() . '/' . $databasePath;
        }
        
        if (!file_exists($databasePath)) {
            $this->logger->error("Database not found: $databasePath");
            $this->logger->info("Please run 'php-packer analyze' first to create the database");
            return 1;
        }
        
        // 输出路径处理：如果是相对路径，相对于当前工作目录
        if (!str_starts_with($outputPath, '/')) {
            $outputPath = getcwd() . '/' . $outputPath;
        }
        
        try {
            $storage = new SqliteStorage($databasePath, $this->logger);
            
            // 获取入口文件
            $entryFile = $this->getEntryFile($storage);
            if (empty($entryFile)) {
                $this->logger->error("No entry file found in database");
                $this->logger->info("Please run 'php-packer analyze' with an entry file first");
                return 1;
            }
            
            $this->logger->info("Entry file: {$entryFile['path']}");
            $this->logger->info("Output file: $outputPath");
            
            // 获取加载顺序
            $files = $this->getFilesInLoadOrder($storage, $entryFile['id']);
            $this->logger->info("Files to pack: " . count($files));
            
            // 准备配置
            $config = [
                'compression' => $compression,
                'optimization' => [
                    'enabled' => $optimize,
                    'remove_comments' => $stripComments,
                    'minimize_whitespace' => $optimize,
                ],
            ];
            
            // 创建代码生成器
            $codeGenerator = new AstCodeGenerator($storage, $this->logger, $config);
            
            // 执行打包
            $codeGenerator->generate($files, $entryFile['path'], $outputPath);
            
            // 显示结果
            $outputSize = filesize($outputPath);
            $this->logger->info("\nPacking completed successfully!");
            $this->logger->info("Output file: $outputPath");
            $this->logger->info("Output size: " . $this->formatBytes($outputSize));
            
            if ($compression) {
                $this->logger->info("Compression: enabled");
            }
            
            // 设置可执行权限
            chmod($outputPath, 0755);
            $this->logger->info("Executable permissions set");
            
            return 0;
        } catch (\Exception $e) {
            $this->logger->error("Packing failed: " . $e->getMessage());
            return 1;
        }
    }
    
    private function getEntryFile(SqliteStorage $storage): ?array
    {
        $pdo = $storage->getPdo();
        $stmt = $pdo->query('SELECT * FROM files WHERE is_entry = 1 LIMIT 1');
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }
    
    private function getFilesInLoadOrder(SqliteStorage $storage, int $entryFileId): array
    {
        // 使用 SqliteStorage 的改进算法，包括符号依赖
        return $storage->getAllRequiredFiles($entryFileId);
    }
}