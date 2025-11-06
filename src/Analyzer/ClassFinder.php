<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use PhpPacker\Storage\StorageInterface;
use Psr\Log\LoggerInterface;

class ClassFinder
{
    private StorageInterface $storage;

    private LoggerInterface $logger;

    private AutoloadResolver $autoloadResolver;

    private PathResolver $pathResolver;

    private FileVerifier $fileVerifier;

    public function __construct(
        StorageInterface $storage,
        LoggerInterface $logger,
        AutoloadResolver $autoloadResolver,
        PathResolver $pathResolver,
        FileVerifier $fileVerifier,
    ) {
        $this->storage = $storage;
        $this->logger = $logger;
        $this->autoloadResolver = $autoloadResolver;
        $this->pathResolver = $pathResolver;
        $this->fileVerifier = $fileVerifier;
    }

    public function findClassFile(string $symbol): ?string
    {
        // 尝试各种解析策略
        $strategies = [
            fn (string $s) => $this->findExistingFileBySymbol($s),
            fn (string $s) => $this->findClassInAstNodes($s),
            fn (string $s) => $this->resolveViaAutoload($s),
            fn (string $s) => $this->findInVendorFiles($s),
            fn (string $s) => $this->scanFileSystemForClass($s),
        ];

        foreach ($strategies as $strategy) {
            $result = $strategy($symbol);
            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }

    public function isBuiltinOrExternal(string $fqcn): bool
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

    private function findExistingFileBySymbol(string $symbol): ?string
    {
        $existingFile = $this->storage->findFileBySymbol($symbol);
        if (null !== $existingFile && isset($existingFile['path']) && is_string($existingFile['path'])) {
            return $this->pathResolver->makeAbsolutePath($existingFile['path']);
        }

        return null;
    }

    private function findClassInAstNodes(string $symbol): ?string
    {
        $astNodes = $this->storage->getAstNodesByFqcn($symbol);
        if ([] === $astNodes) {
            return null;
        }

        foreach ($astNodes as $node) {
            $filePath = $this->extractFilePathFromNode($node);
            if (null !== $filePath) {
                return $filePath;
            }
        }

        return null;
    }

    private function extractFilePathFromNode(mixed $node): ?string
    {
        if (!is_array($node) || !isset($node['node_type'], $node['file_id'])) {
            return null;
        }

        if (!is_int($node['file_id'])) {
            return null;
        }

        if (!in_array($node['node_type'], ['Stmt_Class', 'Stmt_Interface', 'Stmt_Trait'], true)) {
            return null;
        }

        $fileData = $this->storage->getFileById($node['file_id']);
        if (null === $fileData || !isset($fileData['path']) || !is_string($fileData['path'])) {
            return null;
        }

        return $this->pathResolver->makeAbsolutePath($fileData['path']);
    }

    private function resolveViaAutoload(string $symbol): ?string
    {
        return $this->autoloadResolver->resolveClass($symbol);
    }

    private function findInVendorFiles(string $symbol): ?string
    {
        // 规范化 FQCN
        $fqcn = ltrim($symbol, '\\');

        // 从 FQCN 推断可能的文件路径
        $className = basename(str_replace('\\', '/', $fqcn));

        $this->logger->debug('Searching for class in vendor files', [
            'fqcn' => $fqcn,
            'className' => $className,
        ]);

        // 查询vendor文件
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare('
            SELECT * FROM files 
            WHERE is_vendor = 1 
            AND path LIKE :pattern
        ');

        $pattern = '%' . $className . '.php';
        $stmt->execute([':pattern' => $pattern]);

        while (($file = $stmt->fetch()) !== false) {
            if (!is_array($file) || !isset($file['path']) || !is_string($file['path'])) {
                continue;
            }

            // 检查路径是否已经是绝对路径
            $fullPath = $file['path'];
            if (!str_starts_with($fullPath, '/')) {
                $fullPath = $this->pathResolver->getRootPath() . '/' . $fullPath;
            }

            // 验证文件确实包含该类
            if ($this->fileVerifier->verifyClassInFile($fullPath, $className, $fqcn)) {
                $this->logger->debug('Found class in vendor file', [
                    'class' => $fqcn,
                    'file' => $file['path'],
                ]);

                return $fullPath;
            }
        }

        $this->logger->debug('Class not found in vendor files', ['fqcn' => $fqcn]);

        return null;
    }

    private function scanFileSystemForClass(string $symbol): ?string
    {
        // 移除命名空间前缀的反斜杠
        $fqcn = ltrim($symbol, '\\');

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

        $rootPath = $this->pathResolver->getRootPath();
        foreach ($possiblePaths as $path) {
            $fullPath = $rootPath . '/' . $path;
            if (file_exists($fullPath)) {
                // 验证文件确实包含该类
                if ($this->fileVerifier->verifyClassInFile($fullPath, $className, $fqcn)) {
                    $this->logger->debug('Found class via file system scan', [
                        'class' => $fqcn,
                        'file' => $fullPath,
                    ]);

                    return $fullPath;
                }
            }
        }

        $this->logger->debug('Class not found via file system scan', ['fqcn' => $fqcn]);

        return null;
    }
}
