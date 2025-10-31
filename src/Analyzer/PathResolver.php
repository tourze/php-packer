<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use Psr\Log\LoggerInterface;

class PathResolver
{
    private string $rootPath;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger, ?string $rootPath = null)
    {
        $this->logger = $logger;
        $currentDir = getcwd();
        $this->rootPath = $rootPath ?? (false !== $currentDir ? $currentDir : '/');
    }

    public function getRelativePath(string $path): string
    {
        // 首先标准化路径
        $normalizedPath = $this->normalizePath($path);
        $normalizedRoot = $this->normalizePath($this->rootPath);

        // 如果路径在根目录下，返回相对路径
        if (0 === strpos($normalizedPath, $normalizedRoot)) {
            $relativePath = substr($normalizedPath, strlen($normalizedRoot));

            return ltrim($relativePath, '/');
        }

        // 如果不在根目录下，计算相对路径
        $pathParts = explode('/', trim($normalizedPath, '/'));
        $rootParts = explode('/', trim($normalizedRoot, '/'));

        // 找到共同的前缀
        $commonLength = 0;
        for ($i = 0; $i < min(count($pathParts), count($rootParts)); ++$i) {
            if ($pathParts[$i] === $rootParts[$i]) {
                ++$commonLength;
            } else {
                break;
            }
        }

        // 构建相对路径
        $upLevels = count($rootParts) - $commonLength;
        $downPath = array_slice($pathParts, $commonLength);

        $relativeParts = array_merge(array_fill(0, $upLevels, '..'), $downPath);

        return implode('/', $relativeParts);
    }

    public function makeAbsolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->rootPath . '/' . $path;
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $sourceFile
     */
    public function resolveIncludePath(array $dependency, array $sourceFile): ?string
    {
        $context = $dependency['context'];

        if ($this->isInvalidContext($context)) {
            return null;
        }

        $sourceDir = dirname($sourceFile['path']);

        // 处理 __DIR__ 路径
        if (str_contains($context, '__DIR__')) {
            return $this->resolveDirPath($context, $sourceDir);
        }

        // 如果是绝对路径，直接使用
        if (str_starts_with($context, '/')) {
            return $this->resolveAbsolutePath($context);
        }

        // 尝试不同的相对路径解析
        return $this->resolveRelativePath($context, $sourceFile, $sourceDir);
    }

    private function isInvalidContext(string $context): bool
    {
        return '' === $context || 'dynamic' === $context || 'complex' === $context;
    }

    private function resolveDirPath(string $context, string $sourceDir): ?string
    {
        $sourceRealDir = $this->rootPath . '/' . $sourceDir;
        $resolvedContext = str_replace('__DIR__', $sourceRealDir, $context);
        $normalizedPath = $this->normalizePath($resolvedContext);

        $realPath = file_exists($normalizedPath) ? realpath($normalizedPath) : false;

        return false !== $realPath ? $realPath : null;
    }

    private function resolveAbsolutePath(string $context): ?string
    {
        $realPath = file_exists($context) ? realpath($context) : false;

        return false !== $realPath ? $realPath : null;
    }

    /**
     * @param array<string, mixed> $sourceFile
     */
    private function resolveRelativePath(string $context, array $sourceFile, string $sourceDir): ?string
    {
        $possiblePaths = $this->getPossiblePaths($context, $sourceFile, $sourceDir);

        foreach ($possiblePaths as $path) {
            $normalizedPath = $this->normalizePath($path);
            if (file_exists($normalizedPath)) {
                $realPath = realpath($normalizedPath);

                return false !== $realPath ? $realPath : null;
            }
        }

        $this->logger->warning('Include path not found', [
            'path' => $context,
            'source' => $sourceFile['path'],
        ]);

        return null;
    }

    /**
     * @param array<string, mixed> $sourceFile
     * @return array<string>
     */
    private function getPossiblePaths(string $context, array $sourceFile, string $sourceDir): array
    {
        $sourcePath = $sourceFile['path'];
        $absoluteSourcePath = $this->makeAbsolutePath($sourcePath);
        $actualSourceDir = dirname($absoluteSourcePath);

        return [
            // 相对于源文件的实际目录
            $actualSourceDir . '/' . $context,
            // 相对于源文件目录
            $this->rootPath . '/' . $sourceDir . '/' . $context,
            // 相对于根目录
            $this->rootPath . '/' . $context,
            // 直接在当前工作目录
            $context,
            // 相对于源文件的完整路径
            dirname($this->rootPath . '/' . $sourceFile['path']) . '/' . $context,
        ];
    }

    public function normalizePath(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');

        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);

        if (null === $path) {
            return '';
        }

        $parts = explode('/', $path);
        $absolute = [];

        foreach ($parts as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part) {
                array_pop($absolute);
            } else {
                $absolute[] = $part;
            }
        }

        $result = implode('/', $absolute);

        // 保持原始路径的绝对/相对属性
        return $isAbsolute ? '/' . $result : $result;
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    /**
     * 解析路径（将相对路径转换为绝对路径）
     */
    public function resolve(string $path, ?string $basePath = null): string
    {
        $base = $basePath ?? $this->rootPath;

        // 如果已经是绝对路径，直接返回
        if (str_starts_with($path, '/')) {
            return $path;
        }

        // 标准化路径
        $resolvedPath = $base . '/' . $path;

        return $this->normalizePath($resolvedPath);
    }

    /**
     * 设置基础路径
     */
    public function setBasePath(string $basePath): void
    {
        $this->rootPath = $basePath;
    }

    /**
     * 检查路径是否为绝对路径
     */
    public function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || (strlen($path) > 1 && ':' === $path[1]);
    }

    /**
     * 连接多个路径部分
     */
    public function joinPaths(string ...$paths): string
    {
        $filteredPaths = array_filter($paths, static fn ($path) => '' !== $path);

        if ([] === $filteredPaths) {
            return '';
        }

        $firstPath = array_shift($filteredPaths);
        $isAbsolute = $this->isAbsolutePath($firstPath);

        $result = rtrim($firstPath, '/');

        foreach ($filteredPaths as $path) {
            $result .= '/' . ltrim($path, '/');
        }

        return $this->normalizePath($result);
    }

    /**
     * 获取路径的目录部分
     */
    public function getDirectory(string $path): string
    {
        return dirname($path);
    }

    /**
     * 获取路径的文件名部分
     */
    public function getFilename(string $path): string
    {
        return basename($path);
    }

    /**
     * 获取文件的扩展名
     */
    public function getExtension(string $path): string
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }
}
