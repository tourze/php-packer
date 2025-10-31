<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer\Processor;

use PhpPacker\Analyzer\FileAnalyzer;
use PhpPacker\Analyzer\FileVerifier;
use PhpPacker\Analyzer\PathResolver;
use PhpPacker\Storage\SqliteStorage;
use Psr\Log\LoggerInterface;

class DependencyFileProcessor
{
    private SqliteStorage $storage;

    private LoggerInterface $logger;

    private FileAnalyzer $fileAnalyzer;

    private PathResolver $pathResolver;

    private FileVerifier $fileVerifier;

    public function __construct(
        SqliteStorage $storage,
        LoggerInterface $logger,
        FileAnalyzer $fileAnalyzer,
        PathResolver $pathResolver,
        FileVerifier $fileVerifier,
    ) {
        $this->storage = $storage;
        $this->logger = $logger;
        $this->fileAnalyzer = $fileAnalyzer;
        $this->pathResolver = $pathResolver;
        $this->fileVerifier = $fileVerifier;
    }

    /** @param array<string, mixed> $dependency */
    public function processDependencyFile(array $dependency, string $targetFile): void
    {
        $targetFileData = $this->getOrCreateFileData($targetFile);

        if ([] !== $targetFileData) {
            $this->storage->resolveDependency($dependency['id'], $targetFileData['id']);
            $this->handleVendorSymbols($dependency, $targetFileData);
        }
    }

    /** @return array<string, mixed> */
    private function getOrCreateFileData(string $targetFile): array
    {
        $targetFileData = $this->storage->getFileByPath($this->pathResolver->getRelativePath($targetFile));

        if (null === $targetFileData) {
            $this->analyzeAndQueueFile($targetFile);
            $targetFileData = $this->storage->getFileByPath($this->pathResolver->getRelativePath($targetFile));
        }

        return $targetFileData ?? [];
    }

    private function analyzeAndQueueFile(string $filePath): void
    {
        if (!str_starts_with($filePath, '/')) {
            $filePath = $this->pathResolver->getRootPath() . '/' . $filePath;
        }

        if ($this->fileVerifier->shouldExcludeFromAnalysis($filePath)) {
            return;
        }

        $relativePath = $this->pathResolver->getRelativePath($filePath);
        $existingFile = $this->storage->getFileByPath($relativePath);

        if (null === $existingFile) {
            $existingFile = $this->storage->getFileByPath($filePath);
        }

        if (null === $existingFile) {
            try {
                $this->fileAnalyzer->analyzeFile($filePath);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to analyze dependency file', [
                    'file' => $filePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $targetFileData
     */
    private function handleVendorSymbols(array $dependency, array $targetFileData): void
    {
        if (!(bool) $targetFileData['is_vendor'] || !in_array($dependency['dependency_type'], ['extends', 'implements', 'use_trait', 'use_class'], true)) {
            return;
        }

        $symbol = $dependency['target_symbol'];
        if ('' === $symbol || !is_string($symbol)) {
            return;
        }

        $className = basename(str_replace('\\', '/', $symbol));
        $this->storage->addSymbol(
            (int) $targetFileData['id'],
            'class',
            $className,
            $symbol,
            null,
            'public'
        );
    }

    /**
     * 处理文件并返回处理结果
     */
    /** @return array<string, mixed> */
    public function processFile(string $filePath): array
    {
        try {
            $this->analyzeAndQueueFile($filePath);
            $relativePath = $this->pathResolver->getRelativePath($filePath);
            $fileData = $this->storage->getFileByPath($relativePath);

            if (null === $fileData) {
                return [
                    'status' => 'error',
                    'error' => 'File not found after analysis',
                    'path' => $filePath,
                ];
            }

            // 获取文件的依赖关系
            $dependencies = $this->storage->getDependenciesByFile($fileData['id']);

            return [
                'status' => 'success',
                'file_id' => $fileData['id'],
                'path' => $filePath,
                'relative_path' => $relativePath,
                'dependencies' => $dependencies,
                'is_vendor' => $fileData['is_vendor'],
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to process file', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'path' => $filePath,
            ];
        }
    }

    /**
     * 获取依赖关系映射
     */
    /** @return array<string, mixed> */
    public function getDependencyMap(): array
    {
        return $this->storage->getAllDependencies();
    }
}
