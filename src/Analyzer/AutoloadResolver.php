<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use PhpPacker\Storage\SqliteStorage;
use Psr\Log\LoggerInterface;

class AutoloadResolver
{
    private SqliteStorage $storage;
    private LoggerInterface $logger;
    private string $rootPath;
    private array $psr4Prefixes = [];
    private array $psr0Prefixes = [];
    private array $classMap = [];
    private array $files = [];
    private array $autoloadRules = []; // 存储规则和优先级

    public function __construct(SqliteStorage $storage, LoggerInterface $logger, string $rootPath)
    {
        $this->storage = $storage;
        $this->logger = $logger;
        $this->rootPath = rtrim($rootPath, '/');
    }

    public function loadComposerAutoload(string $composerJsonPath): void
    {
        if (!file_exists($composerJsonPath)) {
            $this->logger->warning('Composer.json not found', ['path' => $composerJsonPath]);
            return;
        }

        $composerData = json_decode(file_get_contents($composerJsonPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Invalid composer.json', ['path' => $composerJsonPath]);
            return;
        }
        
        // Handle empty composer.json gracefully
        if (!$composerData) {
            $composerData = [];
        }

        $basePath = dirname($composerJsonPath);

        if (isset($composerData['autoload'])) {
            $this->processAutoloadSection($composerData['autoload'], $basePath, 100);
        }

        if (isset($composerData['autoload-dev'])) {
            $this->processAutoloadSection($composerData['autoload-dev'], $basePath, 50);
        }

        $this->loadVendorAutoload($basePath);
        $this->saveAutoloadRulesToStorage();
    }

    private function processAutoloadSection(array $autoload, string $basePath, int $priority): void
    {
        if (isset($autoload['psr-4'])) {
            foreach ($autoload['psr-4'] as $prefix => $paths) {
                $paths = (array) $paths;
                foreach ($paths as $path) {
                    $absolutePath = $this->normalizePath($basePath . '/' . $path);
                    $this->psr4Prefixes[$prefix][] = $absolutePath;
                    $this->autoloadRules[] = [
                        'type' => 'psr4',
                        'prefix' => $prefix,
                        'path' => $absolutePath,
                        'priority' => $priority
                    ];
                    $this->logger->debug('Added PSR-4 prefix', [
                        'prefix' => $prefix,
                        'path' => $absolutePath,
                    ]);
                }
            }
        }

        if (isset($autoload['psr-0'])) {
            foreach ($autoload['psr-0'] as $prefix => $paths) {
                $paths = (array) $paths;
                foreach ($paths as $path) {
                    $absolutePath = $this->normalizePath($basePath . '/' . $path);
                    $this->psr0Prefixes[$prefix][] = $absolutePath;
                    $this->autoloadRules[] = [
                        'type' => 'psr0',
                        'prefix' => $prefix,
                        'path' => $absolutePath,
                        'priority' => $priority - 10 // PSR-0 优先级稍低
                    ];
                }
            }
        }

        if (isset($autoload['classmap'])) {
            foreach ((array) $autoload['classmap'] as $path) {
                $absolutePath = $this->normalizePath($basePath . '/' . $path);
                $this->scanClassMap($absolutePath);
            }
        }

        if (isset($autoload['files'])) {
            foreach ((array) $autoload['files'] as $file) {
                $absolutePath = $this->normalizePath($basePath . '/' . $file);
                $this->files[] = $absolutePath;
                $this->autoloadRules[] = [
                    'type' => 'files',
                    'prefix' => null,
                    'path' => $absolutePath,
                    'priority' => $priority + 20 // files 优先级最高
                ];
            }
        }
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

    private function scanClassMap(string $path): void
    {
        if (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            $this->scanFileForClasses($path);
        } elseif (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $this->scanFileForClasses($file->getPathname());
                }
            }
        }
    }

    private function scanFileForClasses(string $filePath): void
    {
        $content = file_get_contents($filePath);
        if (!$content) {
            return;
        }

        $tokens = token_get_all($content);
        $namespace = '';
        $classes = [];

        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i][0] === T_NAMESPACE) {
                $namespace = $this->getNamespaceFromTokens($tokens, $i);
            } elseif (in_array($tokens[$i][0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                $className = $this->getClassNameFromTokens($tokens, $i);
                if ($className) {
                    $fqn = $namespace ? $namespace . '\\' . $className : $className;
                    $this->classMap[$fqn] = $filePath;
                }
            }
        }
    }

    private function getNamespaceFromTokens(array $tokens, int &$index): string
    {
        $namespace = '';
        $index++;
        
        while (isset($tokens[$index])) {
            if ($tokens[$index][0] === T_NAME_QUALIFIED || $tokens[$index][0] === T_STRING) {
                $namespace .= $tokens[$index][1];
            } elseif ($tokens[$index] === '\\') {
                $namespace .= '\\';
            } elseif ($tokens[$index] === ';' || $tokens[$index] === '{') {
                break;
            }
            $index++;
        }
        
        return $namespace;
    }

    private function getClassNameFromTokens(array $tokens, int &$index): ?string
    {
        $index++;
        
        while (isset($tokens[$index])) {
            if ($tokens[$index][0] === T_STRING) {
                return $tokens[$index][1];
            } elseif (!in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                break;
            }
            $index++;
        }
        
        return null;
    }

    private function loadVendorAutoload(string $basePath): void
    {
        $vendorDir = $basePath . '/vendor';
        if (!is_dir($vendorDir)) {
            return;
        }

        $installedJsonPath = $vendorDir . '/composer/installed.json';
        if (!file_exists($installedJsonPath)) {
            return;
        }

        $installedData = json_decode(file_get_contents($installedJsonPath), true);
        if (!$installedData) {
            return;
        }

        $packages = $installedData['packages'] ?? $installedData;

        foreach ($packages as $package) {
            if (!isset($package['name']) || !isset($package['autoload'])) {
                continue;
            }

            $packagePath = $vendorDir . '/' . $package['name'];
            $this->processAutoloadSection($package['autoload'], $packagePath, 10);
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

        if (isset($this->classMap[$className])) {
            return $this->classMap[$className];
        }

        $file = $this->resolvePsr4($className);
        if ($file && file_exists($file)) {
            return $file;
        }

        $file = $this->resolvePsr0($className);
        if ($file && file_exists($file)) {
            return $file;
        }

        $this->logger->debug('Class not found via autoload', ['class' => $className]);
        return null;
    }

    private function resolvePsr4(string $className): ?string
    {
        foreach ($this->psr4Prefixes as $prefix => $paths) {
            if (strpos($className, $prefix) === 0) {
                $relativeClass = substr($className, strlen($prefix));
                $relativeClass = str_replace('\\', '/', $relativeClass) . '.php';

                foreach ($paths as $path) {
                    $file = $this->normalizePath($path . '/' . $relativeClass);
                    if (file_exists($file)) {
                        return $file;
                    }
                }
            }
        }

        return null;
    }

    private function resolvePsr0(string $className): ?string
    {
        $className = ltrim($className, '\\');
        
        foreach ($this->psr0Prefixes as $prefix => $paths) {
            // 检查类名是否匹配前缀
            if ($prefix === '' || strpos($className, $prefix) === 0) {
                // PSR-0: 不移除前缀，整个类名都用于路径
                $logicalPathPsr0 = strtr($className, '\\', DIRECTORY_SEPARATOR);
                $logicalPathPsr0 = strtr($logicalPathPsr0, '_', DIRECTORY_SEPARATOR);
                $logicalPathPsr0 .= '.php';

                foreach ($paths as $path) {
                    $file = $this->normalizePath($path . '/' . $logicalPathPsr0);
                    if (file_exists($file)) {
                        return $file;
                    }
                }
            }
        }

        return null;
    }

    public function getRequiredFiles(): array
    {
        return $this->files;
    }
}