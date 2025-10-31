<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use Psr\Log\LoggerInterface;

class PsrResolver
{
    private LoggerInterface $logger;

    private ComposerConfigParser $configParser;

    /** @var array<string, string[]> */
    private array $psr4Prefixes = [];

    /** @var array<string, string[]> */
    private array $psr0Prefixes = [];

    /** @var array<string, string> */
    private array $classmapFiles = [];

    /** @var string[] */
    private array $fallbackDirs = [];

    private string $baseDir = '';

    public function __construct(LoggerInterface $logger, ComposerConfigParser $configParser)
    {
        $this->logger = $logger;
        $this->configParser = $configParser;
    }

    public function addPsr4Prefix(string $prefix, string $path): void
    {
        if (!isset($this->psr4Prefixes[$prefix])) {
            $this->psr4Prefixes[$prefix] = [];
        }
        $this->psr4Prefixes[$prefix][] = $path;
        $this->logger->debug('Added PSR-4 prefix', [
            'prefix' => $prefix,
            'path' => $path,
        ]);
    }

    public function addPsr0Prefix(string $prefix, string $path): void
    {
        if (!isset($this->psr0Prefixes[$prefix])) {
            $this->psr0Prefixes[$prefix] = [];
        }
        $this->psr0Prefixes[$prefix][] = $path;
        $this->logger->debug('Added PSR-0 prefix', [
            'prefix' => $prefix,
            'path' => $path,
        ]);
    }

    public function resolvePsr4(string $className): ?string
    {
        foreach ($this->psr4Prefixes as $prefix => $paths) {
            if (!str_starts_with($className, $prefix)) {
                continue;
            }

            $file = $this->findPsr4File($className, $prefix, $paths);
            if (null !== $file) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param string[] $paths
     */
    private function findPsr4File(string $className, string $prefix, array $paths): ?string
    {
        $relativeClass = $this->getRelativeClassPath($className, $prefix);

        foreach ($paths as $path) {
            $file = $this->configParser->normalizePath($path . '/' . $relativeClass);
            if (file_exists($file)) {
                return $file;
            }
        }

        return null;
    }

    private function getRelativeClassPath(string $className, string $prefix): string
    {
        $relativeClass = substr($className, strlen($prefix));

        return str_replace('\\', '/', $relativeClass) . '.php';
    }

    public function resolvePsr0(string $className): ?string
    {
        $className = ltrim($className, '\\');

        foreach ($this->psr0Prefixes as $prefix => $paths) {
            if (!$this->matchesPsr0Prefix($className, $prefix)) {
                continue;
            }

            $file = $this->findPsr0File($className, $paths);
            if (null !== $file) {
                return $file;
            }
        }

        return null;
    }

    private function matchesPsr0Prefix(string $className, string $prefix): bool
    {
        return '' === $prefix || str_starts_with($className, $prefix);
    }

    /**
     * @param string[] $paths
     */
    private function findPsr0File(string $className, array $paths): ?string
    {
        $logicalPath = $this->getPsr0LogicalPath($className);

        foreach ($paths as $path) {
            $file = $this->configParser->normalizePath($path . '/' . $logicalPath);
            if (file_exists($file)) {
                return $file;
            }
        }

        return null;
    }

    private function getPsr0LogicalPath(string $className): string
    {
        // PSR-0: 不移除前缀，整个类名都用于路径
        $logicalPath = strtr($className, '\\', DIRECTORY_SEPARATOR);
        $logicalPath = strtr($logicalPath, '_', DIRECTORY_SEPARATOR);

        return $logicalPath . '.php';
    }

    /**
     * @return array<string, string[]>
     */
    public function getPsr4Prefixes(): array
    {
        return $this->psr4Prefixes;
    }

    /**
     * @return array<string, string[]>
     */
    public function getPsr0Prefixes(): array
    {
        return $this->psr0Prefixes;
    }

    /**
     * 添加 PSR-4 自动加载规则（支持字符串或数组路径）
     * @param string|string[] $paths
     */
    public function addPsr4(string $prefix, string|array $paths): void
    {
        if (is_string($paths)) {
            $paths = [$paths];
        }

        foreach ($paths as $path) {
            $this->addPsr4Prefix($prefix, $path);
        }
    }

    /**
     * 添加 classmap 自动加载规则
     * @param array<string, string> $classmap
     */
    public function addClassmap(array $classmap): void
    {
        $this->classmapFiles = array_merge($this->classmapFiles, $classmap);
        $this->logger->debug('Added classmap entries', [
            'count' => count($classmap),
        ]);
    }

    /**
     * 清除所有映射
     */
    public function clearMappings(): void
    {
        $this->psr4Prefixes = [];
        $this->psr0Prefixes = [];
        $this->classmapFiles = [];
        $this->fallbackDirs = [];
        $this->logger->debug('Cleared all mappings');
    }

    /**
     * 解析类名到文件路径
     */
    public function resolve(string $className): ?string
    {
        $file = $this->resolveFromClassmap($className);
        if (null !== $file) {
            return $file;
        }

        $file = $this->resolvePsr4($className);
        if (null !== $file) {
            return $file;
        }

        $file = $this->resolvePsr0($className);
        if (null !== $file) {
            return $file;
        }

        return $this->resolveFromFallback($className);
    }

    private function resolveFromClassmap(string $className): ?string
    {
        if (!isset($this->classmapFiles[$className])) {
            return null;
        }

        $path = $this->classmapFiles[$className];
        if ('' !== $this->baseDir && !$this->isAbsolutePath($path)) {
            $path = $this->baseDir . '/' . $path;
        }

        return file_exists($path) ? $path : null;
    }

    private function resolveFromFallback(string $className): ?string
    {
        foreach ($this->fallbackDirs as $dir) {
            $logicalPath = str_replace('\\', '/', $className) . '.php';
            $file = $this->configParser->normalizePath($dir . '/' . $logicalPath);
            if (file_exists($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * 解析可能的路径（用于不确定的类名解析）
     * @return string[]
     */
    public function resolvePossiblePaths(string $className): array
    {
        $paths = [];

        $paths = array_merge($paths, $this->getClassmapPaths($className));
        $paths = array_merge($paths, $this->getPsr4Paths($className));
        $paths = array_merge($paths, $this->getPsr0Paths($className));
        $paths = array_merge($paths, $this->getFallbackPaths($className));

        return array_unique($paths);
    }

    /**
     * @return string[]
     */
    private function getClassmapPaths(string $className): array
    {
        if (isset($this->classmapFiles[$className])) {
            return [$this->classmapFiles[$className]];
        }

        return [];
    }

    /**
     * @return string[]
     */
    private function getPsr4Paths(string $className): array
    {
        $paths = [];
        foreach ($this->psr4Prefixes as $prefix => $prefixPaths) {
            if (!str_starts_with($className, $prefix)) {
                continue;
            }

            $paths = array_merge($paths, $this->getPsr4PathsForPrefix($className, $prefix, $prefixPaths));
        }

        return $paths;
    }

    /**
     * @param string[] $prefixPaths
     * @return string[]
     */
    private function getPsr4PathsForPrefix(string $className, string $prefix, array $prefixPaths): array
    {
        $paths = [];
        $relativeClass = $this->getRelativeClassPath($className, $prefix);

        foreach ($prefixPaths as $path) {
            $paths[] = $this->buildPsr4Path($path, $relativeClass);
        }

        return $paths;
    }

    private function buildPsr4Path(string $path, string $relativeClass): string
    {
        $fullPath = $this->configParser->normalizePath($path . '/' . $relativeClass);

        if ('' !== $this->baseDir && !$this->isAbsolutePath($path)) {
            $fullPath = $this->configParser->normalizePath($this->baseDir . '/' . $fullPath);
        }

        return $fullPath;
    }

    /**
     * @return string[]
     */
    private function getPsr0Paths(string $className): array
    {
        $paths = [];
        foreach ($this->psr0Prefixes as $prefix => $prefixPaths) {
            if ($this->matchesPsr0Prefix($className, $prefix)) {
                $logicalPath = $this->getPsr0LogicalPath($className);
                foreach ($prefixPaths as $path) {
                    $paths[] = $this->configParser->normalizePath($path . '/' . $logicalPath);
                }
            }
        }

        return $paths;
    }

    /**
     * @return string[]
     */
    private function getFallbackPaths(string $className): array
    {
        $paths = [];
        foreach ($this->fallbackDirs as $dir) {
            $logicalPath = str_replace('\\', '/', $className) . '.php';
            $paths[] = $this->configParser->normalizePath($dir . '/' . $logicalPath);
        }

        return $paths;
    }

    /**
     * 设置 fallback 目录
     * @param string[] $dirs
     */
    public function setFallbackDirs(array $dirs): void
    {
        $this->fallbackDirs = $dirs;
        $this->logger->debug('Set fallback directories', [
            'dirs' => $dirs,
        ]);
    }

    /**
     * 获取所有注册的命名空间
     * @return string[]
     */
    public function getNamespaces(): array
    {
        $namespaces = [];

        // PSR-4 命名空间
        foreach (array_keys($this->psr4Prefixes) as $prefix) {
            $namespaces[] = rtrim($prefix, '\\');
        }

        // PSR-0 命名空间
        foreach (array_keys($this->psr0Prefixes) as $prefix) {
            if ('' !== $prefix) {
                $namespaces[] = rtrim($prefix, '\\');
            }
        }

        return array_unique($namespaces);
    }

    /**
     * 设置基础目录
     */
    public function setBaseDir(string $baseDir): void
    {
        $this->baseDir = rtrim($baseDir, '/');
        $this->logger->debug('Set base directory', [
            'baseDir' => $this->baseDir,
        ]);
    }

    /**
     * 检查路径是否为绝对路径
     */
    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || (strlen($path) > 1 && ':' === $path[1]);
    }
}
