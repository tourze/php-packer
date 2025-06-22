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
    private array $resolvedFiles = [];
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
        
        $this->storage->addToAnalysisQueue($entryFile, 1000);
        
        while ($queueItem = $this->storage->getNextFromQueue()) {
            try {
                $this->processQueueItem($queueItem);
                $this->storage->markQueueItemCompleted($queueItem['id']);
            } catch (\Exception $e) {
                $this->logger->error('Failed to process file', [
                    'file' => $queueItem['file_path'],
                    'error' => $e->getMessage(),
                ]);
                $this->storage->markQueueItemFailed($queueItem['id']);
            }
        }

        $this->resolveUnresolvedDependencies();
        $this->logger->info('Dependency resolution completed');
    }

    private function processQueueItem(array $queueItem): void
    {
        $filePath = $queueItem['file_path'];
        
        if (isset($this->processingFiles[$filePath])) {
            $this->logger->warning('Circular dependency detected', ['file' => $filePath]);
            return;
        }

        $this->processingFiles[$filePath] = true;

        try {
            $this->fileAnalyzer->analyzeFile($filePath);
            
            $fileData = $this->storage->getFileByPath($this->getRelativePath($filePath));
            if (!$fileData) {
                throw new \RuntimeException("File not found in storage after analysis: $filePath");
            }

            $this->resolveDependenciesForFile($fileData['id']);
            $this->resolvedFiles[$filePath] = true;
        } finally {
            unset($this->processingFiles[$filePath]);
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

    private function resolveDependenciesForFile(int $fileId): void
    {
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare('
            SELECT * FROM dependencies 
            WHERE source_file_id = :file_id AND is_resolved = 0
        ');
        $stmt->execute([':file_id' => $fileId]);

        while ($dependency = $stmt->fetch()) {
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

        if ($targetFile) {
            $targetFileData = $this->storage->getFileByPath($this->getRelativePath($targetFile));

            if (!$targetFileData) {
                $this->storage->addToAnalysisQueue($targetFile, 100);
                $this->logger->debug('Added file to analysis queue', ['file' => $targetFile]);
            } else {
                $this->storage->resolveDependency($dependency['id'], $targetFileData['id']);
            }
        }
    }

    private function resolveIncludePath(array $dependency): ?string
    {
        $context = $dependency['context'];

        if (!$context || $context === 'dynamic' || $context === 'complex') {
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
        if (!$sourceFile) {
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
        return $result ?: null;
    }

    private function resolveClassDependency(array $dependency): ?string
    {
        $symbol = $dependency['target_symbol'];

        if (!$symbol) {
            return null;
        }

        $existingFile = $this->storage->findFileBySymbol($symbol);
        if ($existingFile) {
            return $existingFile['path'];
        }

        $resolvedPath = $this->autoloadResolver->resolveClass($symbol);
        if ($resolvedPath) {
            return $resolvedPath;
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

            $resolved = 0;
            foreach ($unresolvedDeps as $dependency) {
                $this->resolveSingleDependency($dependency);
                $resolved++;
            }

            if ($resolved === 0) {
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

        while ($row = $stmt->fetch()) {
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