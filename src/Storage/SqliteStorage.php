<?php

declare(strict_types=1);

namespace PhpPacker\Storage;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SqliteStorage
{
    private PDO $pdo;
    private LoggerInterface $logger;
    private string $databasePath;

    public function __construct(string $databasePath, LoggerInterface $logger)
    {
        $this->databasePath = $databasePath;
        $this->logger = $logger;
        $this->initialize();
    }

    private function initialize(): void
    {
        try {
            $dir = dirname($this->databasePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $this->pdo = new PDO('sqlite:' . $this->databasePath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $this->createTables();
            $this->logger->info('SQLite database initialized', ['path' => $this->databasePath]);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to initialize SQLite database: ' . $e->getMessage(), 0, $e);
        }
    }

    private function createTables(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                path TEXT UNIQUE NOT NULL,
                content TEXT,
                hash TEXT,
                analyzed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                file_type TEXT,
                class_name TEXT,
                namespace TEXT,
                is_entry BOOLEAN DEFAULT 0
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS symbols (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id INTEGER NOT NULL,
                symbol_type TEXT NOT NULL,
                symbol_name TEXT NOT NULL,
                fqn TEXT NOT NULL,
                namespace TEXT,
                visibility TEXT,
                FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
            )
        ');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_symbols_fqn ON symbols(fqn)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_symbols_file ON symbols(file_id)');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS dependencies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_file_id INTEGER NOT NULL,
                target_file_id INTEGER,
                dependency_type TEXT NOT NULL,
                target_symbol TEXT,
                line_number INTEGER,
                is_conditional BOOLEAN DEFAULT 0,
                is_resolved BOOLEAN DEFAULT 0,
                context TEXT,
                FOREIGN KEY (source_file_id) REFERENCES files(id) ON DELETE CASCADE,
                FOREIGN KEY (target_file_id) REFERENCES files(id) ON DELETE CASCADE
            )
        ');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_deps_source ON dependencies(source_file_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_deps_target ON dependencies(target_file_id)');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS autoload_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                prefix TEXT,
                path TEXT NOT NULL,
                priority INTEGER DEFAULT 0
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS analysis_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_path TEXT UNIQUE NOT NULL,
                priority INTEGER DEFAULT 0,
                added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                status TEXT DEFAULT "pending"
            )
        ');
    }

    public function addFile(string $path, string $content, ?string $fileType = null, ?string $className = null, bool $isEntry = false): int
    {
        $hash = hash('sha256', $content);

        // 检查是否已存在文件且为入口文件
        $existingEntry = $this->getFileByPath($path);
        $preserveEntryFlag = $existingEntry && $existingEntry['is_entry'];
        $finalIsEntry = $isEntry || $preserveEntryFlag;

        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO files (path, content, hash, file_type, class_name, is_entry)
            VALUES (:path, :content, :hash, :file_type, :class_name, :is_entry)
        ');

        $stmt->execute([
            ':path' => $path,
            ':content' => $content,
            ':hash' => $hash,
            ':file_type' => $fileType,
            ':class_name' => $className,
            ':is_entry' => $finalIsEntry ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function getFileByPath(string $path): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM files WHERE path = :path');
        $stmt->execute([':path' => $path]);

        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    public function getFileById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM files WHERE id = :id');
        $stmt->execute([':id' => $id]);
        
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function addSymbol(int $fileId, string $type, string $name, string $fqn, ?string $namespace = null, ?string $visibility = null): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO symbols (file_id, symbol_type, symbol_name, fqn, namespace, visibility)
            VALUES (:file_id, :type, :name, :fqn, :namespace, :visibility)
        ');

        $stmt->execute([
            ':file_id' => $fileId,
            ':type' => $type,
            ':name' => $name,
            ':fqn' => $fqn,
            ':namespace' => $namespace,
            ':visibility' => $visibility,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addDependency(array $dependency): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO dependencies (
                source_file_id, target_file_id, dependency_type, target_symbol,
                line_number, is_conditional, is_resolved, context
            ) VALUES (
                :source_file_id, :target_file_id, :dependency_type, :target_symbol,
                :line_number, :is_conditional, :is_resolved, :context
            )
        ');

        $stmt->execute([
            ':source_file_id' => $dependency['source_file_id'],
            ':target_file_id' => $dependency['target_file_id'] ?? null,
            ':dependency_type' => $dependency['dependency_type'],
            ':target_symbol' => $dependency['target_symbol'] ?? null,
            ':line_number' => $dependency['line_number'] ?? null,
            ':is_conditional' => ($dependency['is_conditional'] ?? false) ? 1 : 0,
            ':is_resolved' => ($dependency['is_resolved'] ?? false) ? 1 : 0,
            ':context' => $dependency['context'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addToAnalysisQueue(string $filePath, int $priority = 0): void
    {
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO analysis_queue (file_path, priority)
            VALUES (:file_path, :priority)
        ');

        $stmt->execute([
            ':file_path' => $filePath,
            ':priority' => $priority,
        ]);
    }

    public function getNextFromQueue(): ?array
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('
                SELECT * FROM analysis_queue
                WHERE status = "pending"
                ORDER BY priority DESC, added_at ASC
                LIMIT 1
            ');
            $stmt->execute();
            $result = $stmt->fetch();

            if ($result) {
                $updateStmt = $this->pdo->prepare('
                    UPDATE analysis_queue SET status = "analyzing" WHERE id = :id
                ');
                $updateStmt->execute([':id' => $result['id']]);
            }

            $this->pdo->commit();
            return $result ?: null;
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    public function markQueueItemCompleted(int $id): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE analysis_queue SET status = "completed" WHERE id = :id
        ');
        $stmt->execute([':id' => $id]);
    }

    public function markQueueItemFailed(int $id): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE analysis_queue SET status = "failed" WHERE id = :id
        ');
        $stmt->execute([':id' => $id]);
    }

    public function findFileBySymbol(string $fqn): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT f.* FROM files f
            JOIN symbols s ON f.id = s.file_id
            WHERE s.fqn = :fqn
            LIMIT 1
        ');
        $stmt->execute([':fqn' => $fqn]);
        
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function addAutoloadRule(string $type, string $path, ?string $prefix = null, int $priority = 0): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO autoload_rules (type, prefix, path, priority)
            VALUES (:type, :prefix, :path, :priority)
        ');
        
        $stmt->execute([
            ':type' => $type,
            ':prefix' => $prefix,
            ':path' => $path,
            ':priority' => $priority,
        ]);
    }

    public function getAutoloadRules(): array
    {
        $stmt = $this->pdo->query('
            SELECT * FROM autoload_rules
            ORDER BY priority DESC, id ASC
        ');
        
        return $stmt->fetchAll();
    }

    public function getAllRequiredFiles(int $entryFileId): array
    {
        $sql = '
            WITH RECURSIVE dep_tree AS (
                SELECT :entry_id as file_id, 0 as depth
                
                UNION
                
                SELECT d.target_file_id, dt.depth + 1
                FROM dependencies d
                JOIN dep_tree dt ON d.source_file_id = dt.file_id
                WHERE d.target_file_id IS NOT NULL
                  AND d.is_resolved = 1
                  AND dt.depth < 100
            )
            SELECT DISTINCT f.*
            FROM files f
            JOIN dep_tree dt ON f.id = dt.file_id
            ORDER BY dt.depth DESC
        ';
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':entry_id' => $entryFileId]);
        
        return $stmt->fetchAll();
    }

    public function getUnresolvedDependencies(): array
    {
        $stmt = $this->pdo->query('
            SELECT d.*, f.path as source_path
            FROM dependencies d
            JOIN files f ON d.source_file_id = f.id
            WHERE d.is_resolved = 0
        ');
        
        return $stmt->fetchAll();
    }

    public function resolveDependency(int $dependencyId, int $targetFileId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE dependencies
            SET target_file_id = :target_file_id, is_resolved = 1
            WHERE id = :id
        ');
        
        $stmt->execute([
            ':target_file_id' => $targetFileId,
            ':id' => $dependencyId,
        ]);
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }
    
    public function getStatistics(): array
    {
        $stats = [];
        
        // 总文件数
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM files');
        $stats['total_files'] = $stmt->fetchColumn();
        
        // 类文件数
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM files WHERE file_type = "class"');
        $stats['total_classes'] = $stmt->fetchColumn();
        
        // 依赖关系数
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM dependencies');
        $stats['total_dependencies'] = $stmt->fetchColumn();
        
        // autoload 规则数
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM autoload_rules');
        $stats['total_autoload_rules'] = $stmt->fetchColumn();
        
        return $stats;
    }
}