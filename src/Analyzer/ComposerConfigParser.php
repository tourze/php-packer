<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use PhpPacker\Util\PathNormalizer;
use Psr\Log\LoggerInterface;

class ComposerConfigParser
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /** @return array<string, mixed> */
    public function parseComposerConfig(string $composerJsonPath): array
    {
        if (!file_exists($composerJsonPath)) {
            $this->logger->warning('Composer.json not found', ['path' => $composerJsonPath]);

            return [];
        }

        $content = file_get_contents($composerJsonPath);
        if (false === $content) {
            $this->logger->error('Failed to read composer.json', ['path' => $composerJsonPath]);

            return [];
        }

        $composerData = json_decode($content, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            $this->logger->error('Invalid composer.json', ['path' => $composerJsonPath]);

            return [];
        }

        return $this->ensureStringKeys($composerData);
    }

    /**
     * @param mixed $data
     * @return array<string, mixed>
     */
    private function ensureStringKeys($data): array
    {
        if (null === $data || [] === $data || !is_array($data)) {
            return [];
        }

        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @return array<array<string, mixed>> */
    public function loadVendorPackages(string $basePath): array
    {
        $vendorDir = $basePath . '/vendor';
        if (!is_dir($vendorDir)) {
            return [];
        }

        $installedData = $this->loadInstalledData($vendorDir);
        if ([] === $installedData) {
            return [];
        }

        $packages = $this->extractPackagesFromInstalledData($installedData);

        return $this->processPackageList($packages, $vendorDir);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadInstalledData(string $vendorDir): array
    {
        $installedJsonPath = $vendorDir . '/composer/installed.json';
        if (!file_exists($installedJsonPath)) {
            return [];
        }

        $content = file_get_contents($installedJsonPath);
        if (false === $content) {
            return [];
        }

        $installedData = json_decode($content, true);

        return $this->ensureStringKeys($installedData);
    }

    /**
     * @param array<string, mixed> $installedData
     * @return array<mixed>
     */
    private function extractPackagesFromInstalledData(array $installedData): array
    {
        return isset($installedData['packages']) && is_array($installedData['packages'])
            ? $installedData['packages']
            : $installedData;
    }

    /**
     * @param array<mixed> $packages
     * @return array<array<string, mixed>>
     */
    private function processPackageList(array $packages, string $vendorDir): array
    {
        $validPackages = [];

        foreach ($packages as $package) {
            $processedPackage = $this->processPackage($package, $vendorDir);
            if (null !== $processedPackage) {
                $validPackages[] = $processedPackage;
            }
        }

        return $validPackages;
    }

    /**
     * @param mixed $package
     * @return array<string, mixed>|null
     */
    private function processPackage($package, string $vendorDir): ?array
    {
        if (!$this->isValidPackage($package)) {
            return null;
        }
        if (!is_array($package)) {
            return null;
        }
        if (!isset($package['name']) || !is_string($package['name'])) {
            return null;
        }
        $package['path'] = $vendorDir . '/' . $package['name'];

        return $package;
    }

    /**
     * @param mixed $package
     */
    private function isValidPackage($package): bool
    {
        return is_array($package)
            && isset($package['name'], $package['autoload'])
            && is_string($package['name']);
    }

    public function normalizePath(string $path): string
    {
        return PathNormalizer::normalize($path);
    }

    /**
     * 从composer配置中提取所有自动加载路径
     */
    /**
     * @param array<string, mixed> $composerConfig
     * @return array<string>
     */
    public function getAutoloadPaths(array $composerConfig): array
    {
        $typedAutoload = $this->extractTypedAutoload($composerConfig);
        if ([] === $typedAutoload) {
            return [];
        }

        $paths = [];
        $paths = array_merge($paths, $this->extractPsr4Paths($typedAutoload));
        $paths = array_merge($paths, $this->extractPsr0Paths($typedAutoload));
        $paths = array_merge($paths, $this->extractClassmapPaths($typedAutoload));
        $paths = array_merge($paths, $this->extractFiles($typedAutoload));

        return $this->normalizePaths($paths);
    }

    /**
     * @param array<string, mixed> $autoload
     * @return array<string>
     */
    private function extractPsr4Paths(array $autoload): array
    {
        return $this->extractPathsFromSection($autoload, 'psr-4');
    }

    /**
     * @param array<string, mixed> $autoload
     * @return array<string>
     */
    private function extractPsr0Paths(array $autoload): array
    {
        return $this->extractPathsFromSection($autoload, 'psr-0');
    }

    /**
     * @param array<string, mixed> $autoload
     * @return array<string>
     */
    private function extractClassmapPaths(array $autoload): array
    {
        $paths = [];
        if (isset($autoload['classmap']) && is_array($autoload['classmap'])) {
            foreach ($autoload['classmap'] as $path) {
                if (!is_string($path)) {
                    continue;
                }
                $paths[] = rtrim($path, '/') . '/';
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $autoload
     * @return array<string>
     */
    private function extractFiles(array $autoload): array
    {
        if (!isset($autoload['files']) || !is_array($autoload['files'])) {
            return [];
        }

        $files = [];
        foreach ($autoload['files'] as $file) {
            if (is_string($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $autoload
     * @return array<string>
     */
    private function extractPathsFromSection(array $autoload, string $section): array
    {
        if (!isset($autoload[$section]) || !is_array($autoload[$section])) {
            return [];
        }

        $paths = [];
        foreach ($autoload[$section] as $pathList) {
            $normalizedPaths = $this->extractValidPathsFromList($pathList);
            $paths = array_merge($paths, $normalizedPaths);
        }

        return $paths;
    }

    /**
     * @param array<string> $paths
     * @return array<string>
     */
    private function normalizePaths(array $paths): array
    {
        $paths = array_unique($paths);
        sort($paths);

        return $paths;
    }

    /**
     * 从composer配置中提取命名空间到路径的映射
     */
    /**
     * @param array<string, mixed> $composerConfig
     * @return array<string, array<string>>
     */
    public function getNamespaceMapping(array $composerConfig): array
    {
        $typedAutoload = $this->extractTypedAutoload($composerConfig);
        if ([] === $typedAutoload) {
            return [];
        }

        $mapping = [];
        $mapping = array_merge($mapping, $this->extractPsr4Mapping($typedAutoload));

        return array_merge($mapping, $this->extractPsr0Mapping($typedAutoload));
    }

    /**
     * 从composer配置中提取类型安全的autoload段
     * @param array<string, mixed> $composerConfig
     * @return array<string, mixed>
     */
    private function extractTypedAutoload(array $composerConfig): array
    {
        $autoload = $composerConfig['autoload'] ?? [];

        return $this->ensureStringKeys($autoload);
    }

    /**
     * @param array<string, mixed> $autoload
     * @return array<string, array<string>>
     */
    private function extractPsr4Mapping(array $autoload): array
    {
        return $this->extractMappingFromSection($autoload, 'psr-4');
    }

    /**
     * @param array<string, mixed> $autoload
     * @return array<string, array<string>>
     */
    private function extractPsr0Mapping(array $autoload): array
    {
        return $this->extractMappingFromSection($autoload, 'psr-0');
    }

    /**
     * @param array<string, mixed> $autoload
     * @return array<string, array<string>>
     */
    private function extractMappingFromSection(array $autoload, string $section): array
    {
        if (!isset($autoload[$section]) || !is_array($autoload[$section])) {
            return [];
        }

        $mapping = [];
        foreach ($autoload[$section] as $namespace => $pathList) {
            if (!is_string($namespace)) {
                continue;
            }
            $mapping[$namespace] = $this->extractValidPathsFromList($pathList);
        }

        return $mapping;
    }

    /**
     * @param mixed $pathList
     * @return array<string>
     */
    private function extractValidPathsFromList($pathList): array
    {
        $pathList = is_array($pathList) ? $pathList : [$pathList];
        $validPaths = [];

        foreach ($pathList as $path) {
            if (is_string($path)) {
                $validPaths[] = rtrim($path, '/') . '/';
            }
        }

        return $validPaths;
    }
}
