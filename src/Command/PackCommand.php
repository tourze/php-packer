<?php

declare(strict_types=1);

namespace PhpPacker\Command;

use PhpPacker\Dumper\BootstrapGenerator;
use PhpPacker\Dumper\CodeDumper;
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
  --root-path, -r    Project root path (default: current directory)
  --output, -o       Output file path (default: ./packed.php)
  --compression      Enable output compression (gzip)
  --strip-comments   Remove comments from packed code
  --optimize         Enable code optimization
  --help, -h         Show this help message';
    }
    
    public function execute(array $args, array $options): int
    {
        $rootPath = $options['root-path'] ?? $options['r'] ?? getcwd();
        $databasePath = $options['database'] ?? $options['d'] ?? './packer.db';
        $outputPath = $options['output'] ?? $options['o'] ?? './packed.php';
        $compression = isset($options['compression']);
        $stripComments = isset($options['strip-comments']);
        $optimize = isset($options['optimize']);
        
        // 确保数据库路径是绝对路径
        if (!str_starts_with($databasePath, '/')) {
            $databasePath = $rootPath . '/' . $databasePath;
        }
        
        if (!file_exists($databasePath)) {
            $this->logger->error("Database not found: $databasePath");
            $this->logger->info("Please run 'php-packer analyze' first to create the database");
            return 1;
        }
        
        // 确保输出路径是绝对路径
        if (!str_starts_with($outputPath, '/')) {
            $outputPath = $rootPath . '/' . $outputPath;
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
                'strip_comments' => $stripComments,
                'optimize' => $optimize,
                'root_path' => $rootPath,
            ];
            
            // 创建 dumper
            $bootstrapGenerator = new BootstrapGenerator($storage, $this->logger, $config);
            $codeDumper = new CodeDumper($storage, $this->logger, $bootstrapGenerator, $config);
            
            // 执行打包
            $codeDumper->dump($files, $entryFile['path'], $outputPath);
            
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
        return $result ?: null;
    }
    
    private function getFilesInLoadOrder(SqliteStorage $storage, int $entryFileId): array
    {
        $pdo = $storage->getPdo();
        
        // 获取所有依赖关系
        $stmt = $pdo->query('SELECT * FROM dependencies ORDER BY id');
        $dependencies = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // 构建依赖图
        $graph = [];
        $inDegree = [];
        $fileIds = [];
        
        foreach ($dependencies as $dep) {
            $from = $dep['source_file_id'];
            $to = $dep['target_file_id'];
            
            if (!$to) continue; // 跳过未解析的依赖
            
            if (!isset($graph[$from])) {
                $graph[$from] = [];
            }
            $graph[$from][] = $to;
            
            if (!isset($inDegree[$to])) {
                $inDegree[$to] = 0;
            }
            $inDegree[$to]++;
            
            $fileIds[$from] = true;
            $fileIds[$to] = true;
        }
        
        // 确保入口文件在图中
        $fileIds[$entryFileId] = true;
        
        // 初始化所有节点的入度
        foreach ($fileIds as $id => $v) {
            if (!isset($inDegree[$id])) {
                $inDegree[$id] = 0;
            }
        }
        
        // 拓扑排序
        $queue = [];
        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $queue[] = $id;
            }
        }
        
        $loadOrder = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            $loadOrder[] = $current;
            
            if (isset($graph[$current])) {
                foreach ($graph[$current] as $neighbor) {
                    $inDegree[$neighbor]--;
                    if ($inDegree[$neighbor] === 0) {
                        $queue[] = $neighbor;
                    }
                }
            }
        }
        
        // 返回文件信息（反向顺序，依赖项先加载）
        $files = [];
        foreach (array_reverse($loadOrder) as $fileId) {
            $stmt = $pdo->prepare('SELECT * FROM files WHERE id = :id');
            $stmt->execute([':id' => $fileId]);
            $file = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!empty($file)) {
                $files[] = $file;
            }
        }
        
        return $files;
    }
}