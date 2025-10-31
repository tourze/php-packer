<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer\Processor;

use PhpPacker\Analyzer\ClassFinder;
use PhpPacker\Analyzer\PathResolver;
use PhpPacker\Storage\SqliteStorage;
use Psr\Log\LoggerInterface;

class DependencyTargetResolver
{
    private SqliteStorage $storage;

    private LoggerInterface $logger;

    private ClassFinder $classFinder;

    private PathResolver $pathResolver;

    /** @var array<string, bool> */
    private array $warnedDependencies = [];

    public function __construct(
        SqliteStorage $storage,
        LoggerInterface $logger,
        ClassFinder $classFinder,
        PathResolver $pathResolver,
    ) {
        $this->storage = $storage;
        $this->logger = $logger;
        $this->classFinder = $classFinder;
        $this->pathResolver = $pathResolver;
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public function resolveDependencyTarget(array $dependency): ?string
    {
        return match ($dependency['dependency_type']) {
            'require', 'require_once', 'include', 'include_once' => $this->resolveIncludePath($dependency),
            'extends', 'implements', 'use_trait', 'use_class' => $this->resolveClassDependency($dependency),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function resolveIncludePath(array $dependency): ?string
    {
        $context = $dependency['context'];

        if ('' === $context || null === $context || 'dynamic' === $context || 'complex' === $context) {
            $contextStr = is_string($context) ? $context : 'unknown';
            $this->logDynamicIncludeWarning($dependency, $contextStr);

            return null;
        }

        $sourceFile = $this->getFileById($dependency['source_file_id']);
        if ([] === $sourceFile || null === $sourceFile) {
            return null;
        }

        $resolvedPath = $this->pathResolver->resolveIncludePath($dependency, $sourceFile);

        $this->logger->info('Path resolution result', [
            'context' => $context,
            'source' => $sourceFile['path'],
            'resolved' => null !== $resolvedPath ? 'success' : 'failed',
            'path' => $resolvedPath,
        ]);

        // 如果直接解析失败，尝试查找是否有匹配的文件在数据库中
        if (null === $resolvedPath) {
            $resolvedPath = $this->findFileInDatabase($context);
        }

        return $resolvedPath;
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function logDynamicIncludeWarning(array $dependency, string $context): void
    {
        $id = $dependency['id'] ?? 'unknown';
        $warningKey = 'include_' . $id;
        if (!isset($this->warnedDependencies[$warningKey])) {
            $this->logger->warning('Cannot resolve dynamic include', [
                'source' => $dependency['source_file_id'] ?? 'unknown',
                'context' => $context,
            ]);
            $this->warnedDependencies[$warningKey] = true;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getFileById(int $fileId): ?array
    {
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare('SELECT * FROM files WHERE id = :id');
        $stmt->execute([':id' => $fileId]);

        $result = $stmt->fetch();

        return false !== $result ? $result : null;
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function resolveClassDependency(array $dependency): ?string
    {
        $symbol = $dependency['target_symbol'] ?? null;

        if ('' === $symbol || null === $symbol || !is_string($symbol)) {
            return null;
        }

        $result = $this->classFinder->findClassFile($symbol);
        if (null !== $result) {
            return $result;
        }

        $this->handleClassNotFound($dependency, $symbol);

        return null;
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function handleClassNotFound(array $dependency, string $symbol): void
    {
        if ($this->classFinder->isBuiltinOrExternal($symbol)) {
            return;
        }

        $id = $dependency['id'] ?? 'unknown';
        $warningKey = 'class_' . $id;
        if (!isset($this->warnedDependencies[$warningKey])) {
            $this->logger->warning('Class not found', [
                'class' => $symbol,
                'source' => $dependency['source_file_id'] ?? 'unknown',
            ]);
            $this->warnedDependencies[$warningKey] = true;
        }
    }

    private function findFileInDatabase(string $context): ?string
    {
        $pdo = $this->storage->getPdo();

        // 尝试精确匹配文件名
        $stmt = $pdo->prepare('SELECT path FROM files WHERE path = :context');
        $stmt->execute([':context' => $context]);
        $result = $stmt->fetch();

        if (false !== $result) {
            $path = $this->pathResolver->makeAbsolutePath($result['path']);
            $this->logger->info('Found file in database by exact match', [
                'context' => $context,
                'path' => $path,
            ]);

            return $path;
        }

        // 尝试匹配文件名（basename）
        $stmt = $pdo->prepare('SELECT path FROM files WHERE path LIKE :pattern');
        $stmt->execute([':pattern' => '%' . $context]);

        while (($row = $stmt->fetch()) !== false) {
            if (basename($row['path']) === $context) {
                $path = $this->pathResolver->makeAbsolutePath($row['path']);
                $this->logger->info('Found file in database by basename match', [
                    'context' => $context,
                    'path' => $path,
                ]);

                return $path;
            }
        }

        $this->logger->warning('File not found in database', [
            'context' => $context,
        ]);

        return null;
    }
}
