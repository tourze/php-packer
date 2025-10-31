<?php

declare(strict_types=1);

namespace PhpPacker\Adapter;

use PhpPacker\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;

class ConfigurationAdapter
{
    /** @var array<string, mixed> */
    private array $config;

    private string $rootPath;

    public function __construct(
        private readonly string $configPath,
        private readonly LoggerInterface $logger,
    ) {
        $this->loadConfiguration();
        $this->rootPath = dirname($this->configPath);
    }

    private function loadConfiguration(): void
    {
        if (!file_exists($this->configPath)) {
            throw new ConfigurationException("Configuration file not found: {$this->configPath}");
        }

        $content = file_get_contents($this->configPath);
        if (false === $content) {
            throw new ConfigurationException("Failed to read configuration file: {$this->configPath}");
        }

        $extension = pathinfo($this->configPath, PATHINFO_EXTENSION);

        if ('json' !== $extension) {
            throw new ConfigurationException("Only JSON configuration files are supported. Got: {$extension}");
        }

        $this->config = $this->parseJson($content);
        $this->validateConfiguration();
        $this->logger->info('Configuration loaded successfully', ['path' => $this->configPath]);
    }

    /** @return array<string, mixed> */
    private function parseJson(string $content): array
    {
        $config = json_decode($content, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new ConfigurationException('Invalid JSON configuration: ' . json_last_error_msg());
        }

        if (!is_array($config)) {
            throw new ConfigurationException('JSON configuration must be an object/array');
        }

        // Ensure all keys are strings (object properties)
        $stringKeyConfig = [];
        foreach ($config as $key => $value) {
            if (is_string($key)) {
                $stringKeyConfig[$key] = $value;
            }
        }

        return $stringKeyConfig;
    }

    private function validateConfiguration(): void
    {
        $required = ['entry', 'output'];

        foreach ($required as $field) {
            if (!isset($this->config[$field])) {
                throw new ConfigurationException("Required configuration field missing: {$field}");
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

    /**
     * @param string $key
     * @param mixed $value
     */
    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $this->setNestedValue($keys, $value);
    }

    /**
     * @param array<int, string> $keys
     * @param mixed $value
     */
    private function setNestedValue(array $keys, mixed $value): void
    {
        if ([] === $keys) {
            return;
        }

        $this->config = $this->updateNestedValue($this->config, $keys, $value);
    }

    /**
     * 更新嵌套值（纯函数，无副作用）
     * @param array<string, mixed> $config
     * @param array<int, string> $keys
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function updateNestedValue(array $config, array $keys, mixed $value): array
    {
        if ([] === $keys) {
            return $config;
        }

        $key = array_shift($keys);
        if (null === $key) {
            return $config;
        }

        if ([] === $keys) {
            // 最后一个key，设置值
            $config[$key] = $value;
        } else {
            // 还有更深层级的key
            if (!isset($config[$key]) || !is_array($config[$key])) {
                $config[$key] = [];
            }
            $config[$key] = $this->updateNestedValue($config[$key], $keys, $value);
        }

        return $config;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->config;
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    /** @return array<int, string> */
    public function getIncludePaths(): array
    {
        $paths = $this->get('include_paths', ['./']);
        assert(is_array($paths));

        // Ensure $paths is array of strings before mapping
        $stringPaths = array_filter($paths, 'is_string');

        return array_values(array_map(
            function (string $path): string {
                return $this->rootPath . '/' . ltrim($path, '/');
            },
            $stringPaths
        ));
    }

    /** @return array<int, string> */
    public function getIncludePatterns(): array
    {
        $patterns = $this->get('include', []);
        assert(is_array($patterns));

        return array_values(array_filter($patterns, 'is_string'));
    }

    public function get(string $key, mixed $default = null): mixed
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

    /** @return array<int, string> */
    public function getExcludePatterns(): array
    {
        // Support both 'exclude' and 'exclude_patterns' for backward compatibility
        $exclude = $this->get('exclude', []);
        $excludePatterns = $this->get('exclude_patterns', []);

        // Ensure both are arrays of strings
        $exclude = is_array($exclude) ? array_filter($exclude, 'is_string') : [];
        $excludePatterns = is_array($excludePatterns) ? array_filter($excludePatterns, 'is_string') : [];

        // Merge both arrays
        $patterns = array_merge($exclude, $excludePatterns);

        // Add default patterns if no patterns are specified
        if ([] === $patterns) {
            $patterns = [
                '**/tests/**',
                '**/Tests/**',
                '**/*Test.php',
                '**/vendor/**',
            ];
        }

        return array_values($patterns);
    }

    private function matchPattern(string $pattern, string $path): bool
    {
        // 标准化路径，统一使用正斜杠
        $path = str_replace('\\', '/', $path);

        // 转换 glob 模式到正则表达式
        $regex = $this->globToRegex($pattern);

        return 1 === preg_match($regex, $path);
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
