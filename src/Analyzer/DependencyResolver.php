<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use PhpPacker\Analyzer\Processor\DependencyFileProcessor;
use PhpPacker\Analyzer\Processor\DependencyTargetResolver;
use PhpPacker\Storage\SqliteStorage;
use Psr\Log\LoggerInterface;

class DependencyResolver
{
    private SqliteStorage $storage;

    private LoggerInterface $logger;

    private FileAnalyzer $fileAnalyzer;

    private TopologicalSorter $topologicalSorter;

    private DependencyTargetResolver $targetResolver;

    private DependencyFileProcessor $fileProcessor;

    /** @var array<string, bool> */
    private array $processingFiles = [];

    public function __construct(
        SqliteStorage $storage,
        LoggerInterface $logger,
        AutoloadResolver $autoloadResolver,
        FileAnalyzer $fileAnalyzer,
        ?string $rootPath = null,
    ) {
        $this->storage = $storage;
        $this->logger = $logger;
        $this->fileAnalyzer = $fileAnalyzer;
        $this->topologicalSorter = new TopologicalSorter($storage, $logger);

        $pathResolver = new PathResolver($logger, $rootPath);
        $fileVerifier = new FileVerifier($logger);
        $classFinder = new ClassFinder(
            $storage,
            $logger,
            $autoloadResolver,
            $pathResolver,
            $fileVerifier
        );

        $this->targetResolver = new DependencyTargetResolver(
            $storage,
            $logger,
            $classFinder,
            $pathResolver
        );
        $this->fileProcessor = new DependencyFileProcessor(
            $storage,
            $logger,
            $fileAnalyzer,
            $pathResolver,
            $fileVerifier
        );
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
                'error' => $e->getMessage(),
            ]);
            // 不重新抛出异常，让后续处理继续
        }

        // 循环处理所有待分析的文件
        while (($file = $this->storage->getNextFileToAnalyze()) !== null) {
            try {
                /** @var array{id: int, path: string} $file */
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

    /** @param array{id: int, path: string} $file */
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

    /** @param array{id: int, source_file_id: int, type: string, name: string, is_resolved: int} $dependency */
    private function resolveSingleDependency(array $dependency): void
    {
        $targetFile = $this->targetResolver->resolveDependencyTarget($dependency);

        if (null !== $targetFile) {
            $this->fileProcessor->processDependencyFile($dependency, $targetFile);
        }
    }

    private function resolveUnresolvedDependencies(): void
    {
        $maxIterations = 5;
        $iteration = 0;

        while ($iteration < $maxIterations) {
            $unresolvedDeps = $this->storage->getUnresolvedDependencies();

            if ([] === $unresolvedDeps) {
                break;
            }

            $this->logger->info('Resolving unresolved dependencies', [
                'count' => count($unresolvedDeps),
                'iteration' => $iteration + 1,
            ]);

            $previousCount = count($unresolvedDeps);
            foreach ($unresolvedDeps as $dependency) {
                /** @var array{id: int, source_file_id: int, type: string, name: string, is_resolved: int} $dependency */
                $this->resolveSingleDependency($dependency);
            }

            // Check if we made any progress by comparing unresolved count
            $currentUnresolved = $this->storage->getUnresolvedDependencies();
            if (count($currentUnresolved) >= $previousCount) {
                // No progress made, stop trying
                break;
            }

            ++$iteration;
        }

        $stillUnresolved = $this->storage->getUnresolvedDependencies();
        if ([] !== $stillUnresolved) {
            $this->logger->warning('Some dependencies remain unresolved', [
                'count' => count($stillUnresolved),
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getLoadOrder(int $entryFileId): array
    {
        return $this->topologicalSorter->getLoadOrder($entryFileId);
    }
}
