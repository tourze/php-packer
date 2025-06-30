<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use PhpPacker\Storage\SqliteStorage;
use Psr\Log\LoggerInterface;

class DependencyResolver
{
    private SqliteStorage $storage;
    private LoggerInterface $logger;
    private AutoloadResolver $autoloadResolver;
    private FileAnalyzer $fileAnalyzer;
    private string $rootPath;
    // Removed unused property: resolvedFiles
    private array $processingFiles = [];
    private array $warnedDependencies = [];

    public function __construct(
        SqliteStorage $storage,
        LoggerInterface $logger,
        AutoloadResolver $autoloadResolver,
        FileAnalyzer $fileAnalyzer,
        ?string $rootPath = null
    ) {
        $this->storage = $storage;
        $this->logger = $logger;
        $this->autoloadResolver = $autoloadResolver;
        $this->fileAnalyzer = $fileAnalyzer;
        $this->rootPath = $rootPath ?? getcwd();
    }

    public function resolveAllDependencies(string $entryFile): void
    {
        $this->logger->info('Starting dependency resolution', ['entry' => $entryFile]);
        
        // 先分析入口文件
        try {
            $this->fileAnalyzer->analyzeFile($entryFile);
        } catch (\Exception $e) {
            $this->logger->error('Failed to analyze entry file', [
                'file' => $entryFile,
                'error' => $e->getMessage()
            ]);
            // 不重新抛出异常，让后续处理继续
        }
        
        // 循环处理所有待分析的文件
        while (($file = $this->storage->getNextFileToAnalyze()) !== null) {
            try {
                $this->analyzeFileDependencies($file);
                $this->storage->markFileAnalyzed($file['id']);
            } catch (\Exception $e) {
                $this->logger->error('Failed to analyze file dependencies', [
                    'file' => $file['path'],
                    'error' => $e->getMessage(),
                ]);
                $this->storage->markFileAnalysisFailed($file['id']);
            }
        }

        $this->resolveUnresolvedDependencies();
        $this->logger->info('Dependency resolution completed');
    }

    private function analyzeFileDependencies(array $file): void
    {
        if (isset($this->processingFiles[$file['path']])) {
            $this->logger->warning('Circular dependency detected', ['file' => $file['path']]);
            return;
        }

        $this->processingFiles[$file['path']] = true;

        try {
            // 从已存储的 AST 中分析依赖
            $this->resolveDependenciesFromAst($file['id']);
        } finally {
            unset($this->processingFiles[$file['path']]);
        }
    }

    private function getRelativePath(string $path): string
    {
        $realPath = realpath($path);
        if (!$realPath) {
            return $path; // 如果无法解析，返回原路径
        }
        
        $rootPath = realpath($this->rootPath);
        if (!$rootPath) {
            return $path;
        }
        
        // 从根路径计算相对路径
        if (strpos($realPath, $rootPath) === 0) {
            return substr($realPath, strlen($rootPath) + 1);
        }

        return $realPath;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);

        $parts = explode('/', $path);
        $absolute = [];

        foreach ($parts as $part) {
            if ($part === '.') {
                continue;
            } elseif ($part === '..') {
                array_pop($absolute);
            } else {
                $absolute[] = $part;
            }
        }

        return implode('/', $absolute);
    }

    private function resolveDependenciesFromAst(int $fileId): void
    {
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare('
            SELECT * FROM dependencies 
            WHERE source_file_id = :file_id AND is_resolved = 0
        ');
        $stmt->execute([':file_id' => $fileId]);

        while (($dependency = $stmt->fetch()) !== false) {
            $this->resolveSingleDependency($dependency);
        }
    }

    private function resolveSingleDependency(array $dependency): void
    {
        $targetFile = null;

        switch ($dependency['dependency_type']) {
            case 'require':
            case 'require_once':
            case 'include':
            case 'include_once':
                $targetFile = $this->resolveIncludePath($dependency);
                break;

            case 'extends':
            case 'implements':
            case 'use_trait':
            case 'use_class':
                $targetFile = $this->resolveClassDependency($dependency);
                break;
        }

        if ($targetFile !== null) {
            $targetFileData = $this->storage->getFileByPath($this->getRelativePath($targetFile));

            if (empty($targetFileData)) {
                // File not in database yet - analyze it first
                $this->analyzeAndQueueFile($targetFile);
                // Try to get it again after analysis
                $targetFileData = $this->storage->getFileByPath($this->getRelativePath($targetFile));
            }
            
            if (!empty($targetFileData)) {
                $this->storage->resolveDependency($dependency['id'], $targetFileData['id']);
                
                // For class dependencies on vendor files, add the symbol
                if ($targetFileData['is_vendor'] && in_array($dependency['dependency_type'], ['extends', 'implements', 'use_trait', 'use_class'])) {
                    $symbol = $dependency['target_symbol'];
                    if ($symbol) {
                        // addSymbol(fileId, type, name, fqn, namespace, visibility)
                        $className = basename(str_replace('\\', '/', $symbol));
                        $this->storage->addSymbol(
                            (int)$targetFileData['id'], 
                            'class', 
                            $className, 
                            $symbol, 
                            null, 
                            'public'
                        );
                    }
                }
            }
        }
    }

    private function resolveIncludePath(array $dependency): ?string
    {
        $context = $dependency['context'];

        if (empty($context) || $context === 'dynamic' || $context === 'complex') {
            $warningKey = 'include_' . $dependency['id'];
            if (!isset($this->warnedDependencies[$warningKey])) {
                $this->logger->warning('Cannot resolve dynamic include', [
                    'source' => $dependency['source_file_id'],
                    'context' => $context,
                ]);
                $this->warnedDependencies[$warningKey] = true;
            }
            return null;
        }

        $sourceFile = $this->getFileById($dependency['source_file_id']);
        if (empty($sourceFile)) {
            return null;
        }

        $sourceDir = dirname($sourceFile['path']);
        
        // 处理 __DIR__ 路径
        if (str_contains($context, '__DIR__')) {
            $sourceRealDir = $this->rootPath . '/' . $sourceDir;
            $resolvedContext = str_replace('__DIR__', $sourceRealDir, $context);
            $normalizedPath = $this->normalizePath($resolvedContext);
            if (file_exists($normalizedPath)) {
                return realpath($normalizedPath);
            }
            return null;
        }
        
        // 如果是绝对路径，直接使用
        if (str_starts_with($context, '/')) {
            if (file_exists($context)) {
                return realpath($context);
            }
            return null;
        }
        
        // 尝试不同的相对路径解析
        $possiblePaths = [
            // 相对于源文件目录
            $this->rootPath . '/' . $sourceDir . '/' . $context,
            // 相对于根目录
            $this->rootPath . '/' . $context,
            // 直接在当前工作目录
            $context,
            // 相对于源文件的完整路径
            dirname($this->rootPath . '/' . $sourceFile['path']) . '/' . $context,
        ];

        foreach ($possiblePaths as $path) {
            $normalizedPath = $this->normalizePath($path);
            if (file_exists($normalizedPath)) {
                return realpath($normalizedPath);
            }
        }

        $this->logger->warning('Include path not found', [
            'path' => $context,
            'source' => $sourceFile['path'],
        ]);

        return null;
    }

    private function getFileById(int $fileId): ?array
    {
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare('SELECT * FROM files WHERE id = :id');
        $stmt->execute([':id' => $fileId]);

        $result = $stmt->fetch();
        return $result !== false ? $result : null;
    }

    private function resolveClassDependency(array $dependency): ?string
    {
        $symbol = $dependency['target_symbol'];

        if (!$symbol) {
            return null;
        }

        // 由于现在所有符号都是 FQCN，直接查找
        $existingFile = $this->storage->findFileBySymbol($symbol);
        if (!empty($existingFile)) {
            $path = $existingFile['path'];
            // 如果路径已经是绝对路径，直接返回
            if (str_starts_with($path, '/')) {
                return $path;
            }
            // 否则拼接根路径
            return $this->rootPath . '/' . $path;
        }

        // 使用 FQCN 查找 AST 节点，只查找类定义节点
        $astNodes = $this->storage->getAstNodesByFqcn($symbol);
        if (!empty($astNodes)) {
            // 只有当找到的是类定义节点时才返回
            foreach ($astNodes as $node) {
                if (in_array($node['node_type'], ['Stmt_Class', 'Stmt_Interface', 'Stmt_Trait'], true)) {
                    $fileData = $this->storage->getFileById($node['file_id']);
                    if (!empty($fileData)) {
                        $path = $fileData['path'];
                        // 如果路径已经是绝对路径，直接返回
                        if (str_starts_with($path, '/')) {
                            return $path;
                        }
                        // 否则拼接根路径
                        return $this->rootPath . '/' . $path;
                    }
                }
            }
        }

        // 尝试通过 autoload 解析
        $resolvedPath = $this->autoloadResolver->resolveClass($symbol);
        if ($resolvedPath !== null) {
            return $resolvedPath;
        }

        // 尝试在已扫描的vendor文件中查找
        $vendorFile = $this->findClassInVendorFiles($symbol);
        if ($vendorFile !== null) {
            return $vendorFile;
        }

        // 如果 autoload 解析失败，尝试通过文件系统扫描查找
        $resolvedPath = $this->scanForClass($symbol);
        if ($resolvedPath !== null) {
            // 找到文件后，分析它并将其加入处理队列
            $this->analyzeAndQueueFile($resolvedPath);
            return $resolvedPath;
        }

        // 对于某些内置类或第三方库，可能不需要警告
        if ($this->isBuiltinOrExternal($symbol)) {
            return null;
        }

        $warningKey = 'class_' . $dependency['id'];
        if (!isset($this->warnedDependencies[$warningKey])) {
            $this->logger->warning('Class not found', [
                'class' => $symbol,
                'source' => $dependency['source_file_id'],
            ]);
            $this->warnedDependencies[$warningKey] = true;
        }

        return null;
    }

    /**
     * 在已扫描的vendor文件中查找类
     */
    private function findClassInVendorFiles(string $fqcn): ?string
    {
        // 规范化 FQCN
        $fqcn = ltrim($fqcn, '\\');
        
        // 从 FQCN 推断可能的文件路径
        // 例如 Workerman\Worker -> Worker.php
        $className = basename(str_replace('\\', '/', $fqcn));
        
        $this->logger->debug('Searching for class in vendor files', [
            'fqcn' => $fqcn,
            'className' => $className
        ]);
        
        // 查询vendor文件
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare('
            SELECT * FROM files 
            WHERE is_vendor = 1 
            AND path LIKE :pattern
        ');
        
        // 搜索包含类名的文件
        // 同时考虑命名空间路径，例如 Http\Request -> Http/Request.php
        $namespaceParts = explode('\\', $fqcn);
        $possibleFilename = implode('/', array_slice($namespaceParts, -2)) . '.php';
        
        $pattern = '%' . $className . '.php';
        $this->logger->debug('Using pattern', ['pattern' => $pattern]);
        
        $stmt->execute([':pattern' => $pattern]);
        
        $foundFiles = [];
        while (($file = $stmt->fetch()) !== false) {
            $foundFiles[] = $file['path'];
        }
        
        $this->logger->debug('Found files matching pattern', [
            'count' => count($foundFiles),
            'files' => $foundFiles
        ]);
        
        // Reset to check each file
        $stmt->execute([':pattern' => $pattern]);
        
        while (($file = $stmt->fetch()) !== false) {
            // 检查路径是否已经是绝对路径
            $fullPath = $file['path'];
            if (!str_starts_with($fullPath, '/')) {
                $fullPath = $this->rootPath . '/' . $fullPath;
            }
            
            // 验证文件确实包含该类
            if ($this->verifyClassInFile($fullPath, $className, $fqcn)) {
                $this->logger->debug('Found class in vendor file', [
                    'class' => $fqcn,
                    'file' => $file['path']
                ]);
                return $fullPath;
            }
        }
        
        $this->logger->debug('Class not found in vendor files', ['fqcn' => $fqcn]);
        return null;
    }

    /**
     * 通过文件系统扫描查找类文件
     */
    private function scanForClass(string $fqcn): ?string
    {
        // 移除命名空间前缀的反斜杠
        $fqcn = ltrim($fqcn, '\\');
        
        
        // 将 App\Application 转换为 Application.php 和 App/Application.php 等可能的路径
        $className = basename(str_replace('\\', '/', $fqcn));
        $namespacePath = str_replace('\\', '/', dirname(str_replace('\\', '/', $fqcn)));
        
        // 可能的文件路径
        $possiblePaths = [
            $className . '.php',  // Application.php
            $namespacePath . '/' . $className . '.php', // App/Application.php
            'src/' . $className . '.php', // src/Application.php  
            'src/' . $namespacePath . '/' . $className . '.php', // src/App/Application.php
            strtolower($namespacePath) . '/' . $className . '.php', // app/Application.php
            'src/' . strtolower($namespacePath) . '/' . $className . '.php', // src/app/Application.php
        ];
        
        foreach ($possiblePaths as $path) {
            $fullPath = $this->rootPath . '/' . $path;
            if (file_exists($fullPath)) {
                // 验证文件确实包含该类
                if ($this->verifyClassInFile($fullPath, $className, $fqcn)) {
                    $this->logger->debug('Found class via file system scan', [
                        'class' => $fqcn,
                        'file' => $fullPath
                    ]);
                    return $fullPath;
                }
            }
        }
        
        $this->logger->debug('Class not found via file system scan', ['fqcn' => $fqcn]);
        return null;
    }
    
    /**
     * 验证文件是否包含指定的类
     */
    private function verifyClassInFile(string $filePath, string $className, string $fqcn): bool
    {
        try {
            $content = file_get_contents($filePath);
            if ($content === false) {
                return false;
            }
            
            // 更完善的类名匹配检查，支持各种修饰符和扩展
            // 匹配: class ClassName
            //      abstract class ClassName
            //      final class ClassName
            //      class ClassName extends
            //      class ClassName implements
            $pattern = '/(^|\s)(abstract\s+|final\s+)?(class|interface|trait)\s+' . preg_quote($className, '/') . '(\s|{|$)/m';
            
            if (preg_match($pattern, $content)) {
                // 进一步验证命名空间是否匹配
                $namespaceParts = explode('\\', $fqcn);
                array_pop($namespaceParts); // Remove class name
                $expectedNamespace = implode('\\', $namespaceParts);
                
                if ($expectedNamespace === '') {
                    // 全局命名空间
                    return true;
                }
                
                // 检查命名空间声明
                $namespacePattern = '/namespace\s+' . preg_quote($expectedNamespace, '/') . '\s*[;{]/m';
                if (preg_match($namespacePattern, $content)) {
                    return true;
                }
                
                $this->logger->debug('Namespace mismatch', [
                    'expected' => $expectedNamespace,
                    'pattern' => $namespacePattern,
                    'file' => $filePath
                ]);
            }
            
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 判断是否是内置类或外部依赖
     */
    private function analyzeAndQueueFile(string $filePath): void
    {
        // 检查文件路径是否已经是绝对路径
        if (!str_starts_with($filePath, '/')) {
            $filePath = $this->rootPath . '/' . $filePath;
        }
        
        // 排除 Composer 自动生成的 autoload 文件
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
            if (str_ends_with($filePath, $pattern)) {
                $this->logger->debug('Skipping Composer autoload file analysis', ['file' => $filePath]);
                return;
            }
        }
        
        $relativePath = $this->getRelativePath($filePath);
        $existingFile = $this->storage->getFileByPath($relativePath);
        
        if (empty($existingFile)) {
            // 尝试用绝对路径查找
            $existingFile = $this->storage->getFileByPath($filePath);
        }
        
        if (empty($existingFile)) {
            // 文件还未分析，立即分析并加入队列
            try {
                $this->fileAnalyzer->analyzeFile($filePath);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to analyze dependency file', [
                    'file' => $filePath,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function isBuiltinOrExternal(string $fqcn): bool
    {
        // PHP 内置类
        $builtinPrefixes = [
            'Exception',
            'RuntimeException',
            'InvalidArgumentException',
            'LogicException',
            'DateTime',
            'DateTimeInterface',
            'stdClass',
            'ArrayObject',
            'Iterator',
            'Traversable',
            'Countable',
            'JsonSerializable',
        ];

        foreach ($builtinPrefixes as $prefix) {
            if (str_starts_with($fqcn, $prefix) || str_starts_with($fqcn, '\\' . $prefix)) {
                return true;
            }
        }

        // 常见的第三方库命名空间
        $externalPrefixes = [
            'Psr\\',
            'Symfony\\',
            'Doctrine\\',
            'Monolog\\',
            'Composer\\',
            'PhpParser\\',
        ];

        foreach ($externalPrefixes as $prefix) {
            if (str_starts_with($fqcn, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function resolveUnresolvedDependencies(): void
    {
        $maxIterations = 5;
        $iteration = 0;

        while ($iteration < $maxIterations) {
            $unresolvedDeps = $this->storage->getUnresolvedDependencies();

            if (empty($unresolvedDeps)) {
                break;
            }

            $this->logger->info('Resolving unresolved dependencies', [
                'count' => count($unresolvedDeps),
                'iteration' => $iteration + 1,
            ]);

            $previousCount = count($unresolvedDeps);
            foreach ($unresolvedDeps as $dependency) {
                $this->resolveSingleDependency($dependency);
            }
            
            // Check if we made any progress by comparing unresolved count
            $currentUnresolved = $this->storage->getUnresolvedDependencies();
            if (count($currentUnresolved) >= $previousCount) {
                // No progress made, stop trying
                break;
            }

            $iteration++;
        }

        $stillUnresolved = $this->storage->getUnresolvedDependencies();
        if (!empty($stillUnresolved)) {
            $this->logger->warning('Some dependencies remain unresolved', [
                'count' => count($stillUnresolved),
            ]);
        }
    }

    public function getLoadOrder(int $entryFileId): array
    {
        $allFiles = $this->storage->getAllRequiredFiles($entryFileId);

        $graph = $this->buildDependencyGraph($allFiles);

        $sorted = $this->topologicalSort($graph);

        $fileMap = [];
        foreach ($allFiles as $file) {
            $fileMap[$file['id']] = $file;
        }

        $result = [];
        foreach ($sorted as $fileId) {
            if (isset($fileMap[$fileId])) {
                $result[] = $fileMap[$fileId];
            }
        }

        return $result;
    }

    private function buildDependencyGraph(array $files): array
    {
        $graph = [];
        $fileIds = array_column($files, 'id');

        foreach ($fileIds as $fileId) {
            $graph[$fileId] = [];
        }

        $pdo = $this->storage->getPdo();
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));

        $stmt = $pdo->prepare("
            SELECT source_file_id, target_file_id
            FROM dependencies
            WHERE source_file_id IN ($placeholders)
              AND target_file_id IN ($placeholders)
              AND is_resolved = 1
        ");
        $stmt->execute(array_merge($fileIds, $fileIds));

        while (($row = $stmt->fetch()) !== false) {
            // 对于加载顺序，被依赖的文件应该先加载
            // 所以图的方向是：target_file 依赖于 source_file
            // 即：source 必须在 target 之前加载
            $graph[$row['target_file_id']][] = $row['source_file_id'];
        }

        return $graph;
    }

    private function topologicalSort(array $graph): array
    {
        $result = [];
        $visited = [];
        $recursionStack = [];

        foreach (array_keys($graph) as $node) {
            if (!isset($visited[$node])) {
                $this->topologicalSortUtil($node, $graph, $visited, $recursionStack, $result);
            }
        }

        return array_reverse($result);
    }

    private function topologicalSortUtil(
        int $node,
        array &$graph,
        array &$visited,
        array &$recursionStack,
        array &$result
    ): void {
        $visited[$node] = true;
        $recursionStack[$node] = true;

        foreach ($graph[$node] as $neighbor) {
            if (!isset($visited[$neighbor])) {
                $this->topologicalSortUtil($neighbor, $graph, $visited, $recursionStack, $result);
            } elseif (isset($recursionStack[$neighbor])) {
                $this->logger->warning('Circular dependency detected', [
                    'from' => $node,
                    'to' => $neighbor,
                ]);
            }
        }

        $result[] = $node;
        unset($recursionStack[$node]);
    }
}