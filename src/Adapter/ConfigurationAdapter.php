<?php

declare(strict_types=1);

namespace PhpPacker\Adapter;

use PhpPacker\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;

class ConfigurationAdapter
{
    private array $config;
    private string $configPath;
    private string $rootPath;
    private LoggerInterface $logger;

    public function __construct(string $configPath, LoggerInterface $logger)
    {
        $this->configPath = $configPath;
        $this->logger = $logger;
        $this->loadConfiguration();
        $this->rootPath = dirname($this->configPath);
    }

    private function loadConfiguration(): void
    {
        if (!file_exists($this->configPath)) {
            throw new ConfigurationException("Configuration file not found: {$this->configPath}");
        }

        $content = file_get_contents($this->configPath);
        if ($content === false) {
            throw new ConfigurationException("Failed to read configuration file: {$this->configPath}");
        }

        $extension = pathinfo($this->configPath, PATHINFO_EXTENSION);
        
        if ($extension !== 'json') {
            throw new ConfigurationException("Only JSON configuration files are supported. Got: $extension");
        }

        $this->config = $this->parseJson($content);
        $this->validateConfiguration();
        $this->logger->info('Configuration loaded successfully', ['path' => $this->configPath]);
    }

    private function parseJson(string $content): array
    {
        $config = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ConfigurationException('Invalid JSON configuration: ' . json_last_error_msg());
        }
        
        return $config;
    }

    private function validateConfiguration(): void
    {
        $required = ['entry', 'output'];
        
        foreach ($required as $field) {
            if (!isset($this->config[$field])) {
                throw new ConfigurationException("Required configuration field missing: $field");
            }
        }

        if (!isset($this->config['optimization'])) {
            $this->config['optimization'] = [
                'remove_comments' => false,
                'remove_whitespace' => false,
                'inline_includes' => true,
            ];
        }

        if (!isset($this->config['runtime'])) {
            $this->config['runtime'] = [
                'error_reporting' => 'E_ALL',
                'memory_limit' => '256M',
                'timezone' => 'UTC',
            ];
        }
    }

    public function set(string $key, $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $config[$k] = $value;
            } else {
                if (!isset($config[$k]) || !is_array($config[$k])) {
                    $config[$k] = [];
                }
                $config = &$config[$k];
            }
        }
    }

    public function all(): array
    {
        return $this->config;
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    public function getIncludePaths(): array
    {
        $paths = $this->get('include_paths', ['./']);

        return array_map(function ($path) {
            return $this->rootPath . '/' . ltrim($path, '/');
        }, $paths);
    }
    
    public function getIncludePatterns(): array
    {
        return $this->get('include', []);
    }

    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function shouldExclude(string $path): bool
    {
        foreach ($this->getExcludePatterns() as $pattern) {
            if ($this->matchPattern($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    public function getExcludePatterns(): array
    {
        // Use 'exclude' from config, not 'exclude_patterns' with defaults that exclude vendor
        return $this->get('exclude', []);
    }

    private function matchPattern(string $pattern, string $path): bool
    {
        // 标准化路径，统一使用正斜杠
        $path = str_replace('\\', '/', $path);
        
        // 转换 glob 模式到正则表达式
        $regex = $this->globToRegex($pattern);
        
        return preg_match($regex, $path) === 1;
    }
    
    private function globToRegex(string $pattern): string
    {
        $pattern = str_replace('\\', '/', $pattern);
        
        // 转义正则表达式特殊字符
        $pattern = preg_quote($pattern, '/');
        
        // 转换 glob 通配符到正则表达式
        // 注意顺序很重要，先处理更具体的模式
        $pattern = str_replace([
            '\*\*\/\*\*',  // **/** -> .*
            '\*\*\/',      // **/ -> .*
            '\/\*\*',      // /** -> \/.*
            '\*\*',        // ** -> .*
            '\*',          // * -> [^/]*
            '\?',          // ? -> .
        ], [
            '.*',
            '.*',
            '\/.*',
            '.*',
            '[^\/]*',
            '.',
        ], $pattern);
        
        return '/^' . $pattern . '$/';
    }
}