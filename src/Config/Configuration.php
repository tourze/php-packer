<?php

namespace PhpPacker\Config;

use PhpPacker\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;

class Configuration
{
    private string $configFile;
    private array $config;
    private LoggerInterface $logger;

    public function __construct(string $configFile, LoggerInterface $logger)
    {
        $this->configFile = $configFile;
        $this->logger = $logger;

        $this->logger->debug('Loading config file');

        if (!file_exists($configFile)) {
            throw new ConfigurationException("Config file not found: $configFile");
        }

        $this->config = require $configFile;

        $this->validate();
    }

    private function validate(): void
    {
        $required = ['entry', 'output'];

        foreach ($required as $key) {
            if (!isset($this->config[$key])) {
                throw new ConfigurationException("Missing required config key: $key");
            }
        }

        if (!file_exists($this->config['entry'])) {
            throw new ConfigurationException("Entry file not found: {$this->config['entry']}");
        }
    }

    public function getEntryFile(): string
    {
        return $this->config['entry'];
    }

    public function getOutputFile(): string
    {
        return $this->config['output'];
    }

    public function getExclude(): array
    {
        return $this->config['exclude'] ?? [];
    }

    public function getAssets(): array
    {
        return $this->config['assets'] ?? [];
    }

    public function shouldMinify(): bool
    {
        return $this->config['minify'] ?? false;
    }

    public function shouldKeepComments(): bool
    {
        return $this->config['comments'] ?? true;
    }

    public function isDebug(): bool
    {
        return $this->config['debug'] ?? false;
    }

    public function getSourcePaths(): array
    {
        return [
            dirname($this->config['entry']) . '/src',
            dirname($this->config['entry']) . '/vendor',
        ];
    }

    public function getResourcePaths(): array
    {
        $basePath = dirname($this->config['entry']);
        return [
            $basePath,
            $basePath . '/resources',
            $basePath . '/views',
            $basePath . '/templates',
            $basePath . '/config',
        ];
    }

    public function getOutputDirectory(): string
    {
        return dirname($this->config['output']);
    }

    public function getRaw(): array
    {
        return $this->config;
    }

    public function shouldCleanOutput(): bool
    {
        return $this->config['clean_output'] ?? false;
    }

    public function shouldRemoveNamespace(): bool
    {
        return $this->config['remove_namespace'] ?? false;
    }

    public function forKphp(): bool
    {
        return $this->config['for_kphp'] ?? false;
    }

    public function getConfigFile(): string
    {
        return $this->configFile;
    }
}
