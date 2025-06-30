<?php

declare(strict_types=1);

namespace PhpPacker\Storage;

use PDO;
use Psr\Log\LoggerInterface;

class SqliteStorage
{
    private PDO $pdo;
    private LoggerInterface $logger;

    public function __construct(string $dbPath, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->initDatabase($dbPath);
    }

    private function initDatabase(string $dbPath): void
    {
        $this->pdo = new PDO("sqlite:$dbPath");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->createTables();
        $this->logger->info('SQLite database initialized', ['path' => $dbPath]);
    }

    private function createTables(): void
    {
        // 文件表
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                path TEXT NOT NULL UNIQUE,
                content TEXT,
                file_type TEXT,
                is_vendor INTEGER DEFAULT 0,
                skip_ast INTEGER DEFAULT 0,
                is_entry INTEGER DEFAULT 0,
                analysis_status TEXT DEFAULT "pending",
                analyzed_dependencies INTEGER DEFAULT 0,
                ast_root_id INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // 符号表（类、接口、trait、函数等）
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS symbols (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id INTEGER NOT NULL,
                symbol_type TEXT NOT NULL,
                symbol_name TEXT NOT NULL,
                fqn TEXT NOT NULL,
                namespace TEXT,
                visibility TEXT,
                is_abstract INTEGER DEFAULT 0,
                is_final INTEGER DEFAULT 0,
                FOREIGN KEY (file_id) REFERENCES files(id),
                UNIQUE(fqn)
            )
        ');

        // 依赖表
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS dependencies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_file_id INTEGER NOT NULL,
                target_file_id INTEGER,
                dependency_type TEXT NOT NULL,
                target_symbol TEXT,
                line_number INTEGER,
                is_conditional INTEGER DEFAULT 0,
                is_resolved INTEGER DEFAULT 0,
                context TEXT,
                FOREIGN KEY (source_file_id) REFERENCES files(id),
                FOREIGN KEY (target_file_id) REFERENCES files(id)
            )
        ');

        // 自动加载规则表
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS autoload_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                path TEXT NOT NULL,
                prefix TEXT,
                priority INTEGER DEFAULT 100
            )
        ');

        // AST 节点表
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS ast_nodes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id INTEGER NOT NULL,
                parent_id INTEGER,
                node_type TEXT NOT NULL,
                node_data TEXT,
                start_line INTEGER,
                end_line INTEGER,
                position INTEGER,
                fqcn TEXT,
                FOREIGN KEY (file_id) REFERENCES files(id),
                FOREIGN KEY (parent_id) REFERENCES ast_nodes(id)
            )
        ');

        // 创建索引
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_files_path ON files(path)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_files_ast_root ON files(ast_root_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_files_analysis_status ON files(analysis_status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_symbols_fqn ON symbols(fqn)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_symbols_file ON symbols(file_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_dependencies_source ON dependencies(source_file_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_dependencies_target ON dependencies(target_file_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_dependencies_unresolved ON dependencies(is_resolved, target_symbol)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_ast_nodes_file ON ast_nodes(file_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_ast_nodes_parent ON ast_nodes(parent_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_ast_nodes_fqcn ON ast_nodes(fqcn)');
    }

    public function addFile(string $path, string $content, ?string $fileType = null, ?bool $isEntry = null, ?bool $shouldSkipAst = null): int
    {
        // 判断是否是 vendor 文件
        $isVendor = str_contains($path, 'vendor/') ? 1 : 0;
        
        // vendor文件默认跳过AST，除非明确指定不跳过
        if ($shouldSkipAst !== null) {
            $skipAst = $shouldSkipAst ? 1 : 0;
        } else {
            $skipAst = $isVendor; // vendor文件默认跳过AST
        }
        $isEntry = $isEntry ?? false;

        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO files (path, content, file_type, is_vendor, skip_ast, is_entry, analysis_status)
            VALUES (:path, :content, :file_type, :is_vendor, :skip_ast, :is_entry, :analysis_status)
        ');

        $stmt->execute([
            ':path' => $path,
            ':content' => $content,
            ':file_type' => $fileType,
            ':is_vendor' => $isVendor,
            ':skip_ast' => $skipAst,
            ':is_entry' => $isEntry ? 1 : 0,
            ':analysis_status' => 'pending'
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function addSymbol(
        int $fileId,
        string $type,
        string $name,
        string $fqn,
        ?string $namespace = null,
        ?string $visibility = null,
        bool $isAbstract = false,
        bool $isFinal = false
    ): int {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO symbols (
                file_id, symbol_type, symbol_name, fqn, namespace, 
                visibility, is_abstract, is_final
            ) VALUES (
                :file_id, :symbol_type, :symbol_name, :fqn, :namespace,
                :visibility, :is_abstract, :is_final
            )
        ');

        $stmt->execute([
            ':file_id' => $fileId,
            ':symbol_type' => $type,
            ':symbol_name' => $name,
            ':fqn' => $fqn,
            ':namespace' => $namespace,
            ':visibility' => $visibility,
            ':is_abstract' => $isAbstract ? 1 : 0,
            ':is_final' => $isFinal ? 1 : 0,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function addDependency(
        int $sourceFileId,
        string $type,
        ?string $targetSymbol,
        ?int $lineNumber = null,
        bool $isConditional = false,
        ?string $context = null
    ): int {
        $stmt = $this->pdo->prepare('
            INSERT INTO dependencies (
                source_file_id, dependency_type, target_symbol, 
                line_number, is_conditional, context
            ) VALUES (
                :source_file_id, :dependency_type, :target_symbol,
                :line_number, :is_conditional, :context
            )
        ');

        $stmt->execute([
            ':source_file_id' => $sourceFileId,
            ':dependency_type' => $type,
            ':target_symbol' => $targetSymbol,
            ':line_number' => $lineNumber,
            ':is_conditional' => $isConditional ? 1 : 0,
            ':context' => $context,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function addAutoloadRule(string $type, string $path, ?string $prefix = null, int $priority = 100): void
    {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO autoload_rules (type, path, prefix, priority)
            VALUES (:type, :path, :prefix, :priority)
        ');

        $stmt->execute([
            ':type' => $type,
            ':path' => $path,
            ':prefix' => $prefix,
            ':priority' => $priority,
        ]);
    }

    public function getFileByPath(string $path): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM files WHERE path = :path');
        $stmt->execute([':path' => $path]);
        
        $result = $stmt->fetch();
        return $result !== false ? $result : null;
    }

    public function getFileById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM files WHERE id = :id');
        $stmt->execute([':id' => $id]);
        
        $result = $stmt->fetch();
        return $result !== false ? $result : null;
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
        return $result !== false ? $result : null;
    }

    public function getAutoloadRules(): array
    {
        $stmt = $this->pdo->query('
            SELECT * FROM autoload_rules 
            ORDER BY priority DESC, id ASC
        ');
        
        return $stmt->fetchAll();
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getStatistics(): array
    {
        $stats = [];
        
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM files');
        $stats['total_files'] = $stmt->fetchColumn();
        
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM symbols WHERE symbol_type = "class"');
        $stats['total_classes'] = $stmt->fetchColumn();
        
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM dependencies');
        $stats['total_dependencies'] = $stmt->fetchColumn();
        
        return $stats;
    }

    public function storeAst(int $fileId, array $ast): void
    {
        // 删除旧的 AST 节点
        $stmt = $this->pdo->prepare('DELETE FROM ast_nodes WHERE file_id = :file_id');
        $stmt->execute([':file_id' => $fileId]);
        
        if (empty($ast)) {
            return;
        }
        
        // 存储新的 AST 节点
        $rootId = $this->storeAstNode($fileId, null, 'Root', serialize($ast), 0, 0, 0);
        
        // 更新文件的 ast_root_id
        $stmt = $this->pdo->prepare('UPDATE files SET ast_root_id = :root_id WHERE id = :id');
        $stmt->execute([':root_id' => $rootId, ':id' => $fileId]);
    }

    private function storeAstNode(
        int $fileId,
        ?int $parentId,
        string $nodeType,
        string $nodeData,
        int $startLine,
        int $endLine,
        int $position,
        ?string $fqcn = null
    ): int {
        $stmt = $this->pdo->prepare('
            INSERT INTO ast_nodes (
                file_id, parent_id, node_type, node_data, 
                start_line, end_line, position, fqcn
            ) VALUES (
                :file_id, :parent_id, :node_type, :node_data,
                :start_line, :end_line, :position, :fqcn
            )
        ');
        
        $stmt->execute([
            ':file_id' => $fileId,
            ':parent_id' => $parentId,
            ':node_type' => $nodeType,
            ':node_data' => $nodeData,
            ':start_line' => $startLine,
            ':end_line' => $endLine,
            ':position' => $position,
            ':fqcn' => $fqcn
        ]);
        
        return (int)$this->pdo->lastInsertId();
    }

    public function getAstNodesByFileId(int $fileId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM ast_nodes 
            WHERE file_id = :file_id 
            ORDER BY position
        ');
        $stmt->execute([':file_id' => $fileId]);
        
        return $stmt->fetchAll();
    }

    public function getAstNodesByFqcn(string $fqcn): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM ast_nodes 
            WHERE fqcn = :fqcn
        ');
        $stmt->execute([':fqcn' => $fqcn]);
        
        return $stmt->fetchAll();
    }

    /**
     * 获取所有需要打包的文件（改进的算法）
     * 包括：
     * 1. 入口文件
     * 2. 通过require/include直接依赖的文件
     * 3. 通过类/接口/trait符号间接依赖的文件
     */
    public function getAllRequiredFiles(int $entryFileId): array
    {
        // 使用更复杂的递归查询，包括符号依赖
        $sql = '
            WITH RECURSIVE 
            -- 第一步：收集所有文件的符号依赖
            symbol_deps AS (
                SELECT 
                    d.source_file_id,
                    d.target_symbol,
                    s.file_id as target_file_id
                FROM dependencies d
                LEFT JOIN symbols s ON d.target_symbol = s.fqn
                WHERE d.target_symbol IS NOT NULL
                  AND d.dependency_type IN ("extends", "implements", "use_trait", "use_class")
            ),
            -- 第二步：递归收集所有依赖的文件
            dep_tree AS (
                -- 1. 入口文件
                SELECT :entry_id as file_id, 0 as depth
                
                UNION
                
                -- 2. 直接文件依赖（require/include）
                SELECT d.target_file_id, dt.depth + 1
                FROM dependencies d
                JOIN dep_tree dt ON d.source_file_id = dt.file_id
                WHERE d.target_file_id IS NOT NULL
                  AND d.is_resolved = 1
                  AND dt.depth < 100
                
                UNION
                
                -- 3. 符号依赖（类、接口、trait）
                SELECT sd.target_file_id, dt.depth + 1
                FROM symbol_deps sd
                JOIN dep_tree dt ON sd.source_file_id = dt.file_id
                WHERE sd.target_file_id IS NOT NULL
                  AND dt.depth < 100
            )
            SELECT DISTINCT f.*
            FROM files f
            JOIN dep_tree dt ON f.id = dt.file_id
            ORDER BY dt.depth DESC, f.path
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

    public function markFileAnalyzed(int $fileId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE files 
            SET analysis_status = "completed", analyzed_dependencies = 1
            WHERE id = :id
        ');
        $stmt->execute([':id' => $fileId]);
    }

    public function markFileAnalysisFailed(int $fileId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE files 
            SET analysis_status = "failed"
            WHERE id = :id
        ');
        $stmt->execute([':id' => $fileId]);
    }

    public function getNextFileToAnalyze(): ?array
    {
        $stmt = $this->pdo->query('
            SELECT * FROM files 
            WHERE analysis_status = "pending" 
              AND analyzed_dependencies = 0
            ORDER BY is_entry DESC, id ASC
            LIMIT 1
        ');
        
        $result = $stmt->fetch();
        return $result !== false ? $result : null;
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

    public function addAstNode(int $fileId, int $parentId, string $nodeType, string $fqcn, int $position): int
    {
        return $this->storeAstNode($fileId, $parentId, $nodeType, '', 0, 0, $position, $fqcn);
    }

    public function updateFileAstRoot(int $fileId, int $astRootId): void
    {
        $stmt = $this->pdo->prepare('UPDATE files SET ast_root_id = :ast_root_id WHERE id = :id');
        $stmt->execute([':ast_root_id' => $astRootId, ':id' => $fileId]);
    }

    public function findAstNodeUsages(string $fqcn): array
    {
        return $this->getAstNodesByFqcn($fqcn);
    }

    public function shouldSkipAst(string $relativePath): bool
    {
        $excludePatterns = [
            'vendor/autoload.php',
            'vendor/composer/autoload_real.php',
            'vendor/composer/autoload_static.php',
            'vendor/composer/autoload_classmap.php',
            'vendor/composer/autoload_files.php',
            'vendor/composer/autoload_namespaces.php',
            'vendor/composer/autoload_psr4.php',
            'vendor/composer/ClassLoader.php',
        ];
        
        foreach ($excludePatterns as $pattern) {
            if (str_ends_with($relativePath, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
}