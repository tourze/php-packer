<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use PhpPacker\Storage\SqliteStorage;
use Psr\Log\LoggerInterface;

class AutoloadResolver
{
    private SqliteStorage $storage;

    private LoggerInterface $logger;

    private ComposerConfigParser $configParser;

    private ClassScanner $classScanner;

    private PsrResolver $psrResolver;

    /** @var array<string, string> */
    private array $classMap = [];

    /** @var array<string> */
    private array $files = [];

    /** @var array<array{type: string, prefix: string|null, path: string, priority: int}> */
    private array $autoloadRules = [];

    /**
     * @phpstan-ignore-next-line constructor.unusedParameter
     */
    public function __construct(SqliteStorage $storage, LoggerInterface $logger, string $rootPath)
    {
        $this->storage = $storage;
        $this->logger = $logger;
        // $rootPath parameter kept for backward compatibility

        // 创建助手类实例
        $this->configParser = new ComposerConfigParser($logger);
        $this->classScanner = new ClassScanner();
        $this->psrResolver = new PsrResolver($logger, $this->configParser);
    }

    public function loadComposerAutoload(string $composerJsonPath): void
    {
        $composerData = $this->configParser->parseComposerConfig($composerJsonPath);
        if ([] === $composerData) {
            return;
        }

        $basePath = dirname($composerJsonPath);

        if (isset($composerData['autoload']) && is_array($composerData['autoload'])) {
            $autoload = $this->ensureStringKeys($composerData['autoload']);
            $this->processAutoloadSection($autoload, $basePath, 100);
        }

        if (isset($composerData['autoload-dev']) && is_array($composerData['autoload-dev'])) {
            $autoloadDev = $this->ensureStringKeys($composerData['autoload-dev']);
            $this->processAutoloadSection($autoloadDev, $basePath, 50);
        }

        $this->loadVendorAutoload($basePath);
        $this->saveAutoloadRulesToStorage();
    }

    /** @param array<string, mixed> $autoload */
    private function processAutoloadSection(array $autoload, string $basePath, int $priority): void
    {
        $this->processPsr4Autoload($autoload, $basePath, $priority);
        $this->processPsr0Autoload($autoload, $basePath, $priority);
        $this->processClassmapAutoload($autoload, $basePath);
        $this->processFilesAutoload($autoload, $basePath, $priority);
    }

    /** @param array<string, mixed> $autoload */
    private function processPsr4Autoload(array $autoload, string $basePath, int $priority): void
    {
        if (!isset($autoload['psr-4']) || !is_array($autoload['psr-4'])) {
            return;
        }

        /** @var array<string, mixed> $psr4Config */
        $psr4Config = $autoload['psr-4'];
        foreach ($psr4Config as $prefix => $paths) {
            if (!is_string($prefix)) {
                continue;
            }
            $paths = (array) $paths;
            foreach ($paths as $path) {
                if (!is_string($path)) {
                    continue;
                }
                $absolutePath = $this->configParser->normalizePath($basePath . '/' . $path);
                $this->psrResolver->addPsr4Prefix($prefix, $absolutePath);
                $this->autoloadRules[] = [
                    'type' => 'psr4',
                    'prefix' => $prefix,
                    'path' => $absolutePath,
                    'priority' => $priority,
                ];
            }
        }
    }

    /** @param array<string, mixed> $autoload */
    private function processPsr0Autoload(array $autoload, string $basePath, int $priority): void
    {
        if (!isset($autoload['psr-0']) || !is_array($autoload['psr-0'])) {
            return;
        }

        /** @var array<string, mixed> $psr0Config */
        $psr0Config = $autoload['psr-0'];
        foreach ($psr0Config as $prefix => $paths) {
            if (!is_string($prefix)) {
                continue;
            }
            $paths = (array) $paths;
            foreach ($paths as $path) {
                if (!is_string($path)) {
                    continue;
                }
                $absolutePath = $this->configParser->normalizePath($basePath . '/' . $path);
                $this->psrResolver->addPsr0Prefix($prefix, $absolutePath);
                $this->autoloadRules[] = [
                    'type' => 'psr0',
                    'prefix' => $prefix,
                    'path' => $absolutePath,
                    'priority' => $priority - 10, // PSR-0 优先级稍低
                ];
            }
        }
    }

    /** @param array<string, mixed> $autoload */
    private function processClassmapAutoload(array $autoload, string $basePath): void
    {
        if (!isset($autoload['classmap'])) {
            return;
        }

        $classmapPaths = (array) $autoload['classmap'];
        foreach ($classmapPaths as $path) {
            if (!is_string($path)) {
                continue;
            }
            $absolutePath = $this->configParser->normalizePath($basePath . '/' . $path);
            $classMap = $this->classScanner->scanClassMap($absolutePath);
            $this->classMap = array_merge($this->classMap, $classMap);
        }
    }

    /** @param array<string, mixed> $autoload */
    private function processFilesAutoload(array $autoload, string $basePath, int $priority): void
    {
        if (!isset($autoload['files'])) {
            return;
        }

        $filesList = (array) $autoload['files'];
        foreach ($filesList as $file) {
            if (!is_string($file)) {
                continue;
            }
            $absolutePath = $this->configParser->normalizePath($basePath . '/' . $file);
            $this->files[] = $absolutePath;
            $this->autoloadRules[] = [
                'type' => 'files',
                'prefix' => null,
                'path' => $absolutePath,
                'priority' => $priority + 20, // files 优先级最高
            ];
        }
    }

    private function loadVendorAutoload(string $basePath): void
    {
        $packages = $this->configParser->loadVendorPackages($basePath);

        foreach ($packages as $package) {
            if (!is_array($package) || !isset($package['autoload'], $package['path'])) {
                continue;
            }
            if (!is_array($package['autoload']) || !is_string($package['path'])) {
                continue;
            }
            $this->processAutoloadSection($package['autoload'], $package['path'], 10);
        }
    }

    private function saveAutoloadRulesToStorage(): void
    {
        // 使用收集的规则和它们的优先级
        foreach ($this->autoloadRules as $rule) {
            $this->storage->addAutoloadRule(
                $rule['type'],
                $rule['path'],
                $rule['prefix'],
                $rule['priority']
            );
        }

        // 添加 classmap 规则
        foreach ($this->classMap as $class => $path) {
            $this->storage->addAutoloadRule('classmap', $path, $class, 110);
        }
    }

    public function resolveClass(string $className): ?string
    {
        $className = ltrim($className, '\\');

        $this->logger->debug('Resolving class via autoloader', [
            'class' => $className,
            'classMap' => count($this->classMap),
            'psr4' => count($this->psrResolver->getPsr4Prefixes()),
            'psr0' => count($this->psrResolver->getPsr0Prefixes()),
        ]);

        if (isset($this->classMap[$className])) {
            $this->logger->debug('Found in class map', ['class' => $className, 'file' => $this->classMap[$className]]);

            return $this->classMap[$className];
        }

        $file = $this->psrResolver->resolvePsr4($className);
        if (null !== $file && file_exists($file)) {
            $this->logger->debug('Found via PSR-4', ['class' => $className, 'file' => $file]);

            return $file;
        }

        $file = $this->psrResolver->resolvePsr0($className);
        if (null !== $file && file_exists($file)) {
            $this->logger->debug('Found via PSR-0', ['class' => $className, 'file' => $file]);

            return $file;
        }

        $this->logger->debug('Class not found via autoload', ['class' => $className]);

        return null;
    }

    /** @return array<string> */
    public function getRequiredFiles(): array
    {
        return $this->files;
    }

    /**
     * @param array<mixed> $array
     * @return array<string, mixed>
     */
    private function ensureStringKeys(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
