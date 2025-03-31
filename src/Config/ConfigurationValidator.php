<?php

namespace PhpPacker\Config;

use PhpPacker\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;

class ConfigurationValidator
{
    private LoggerInterface $logger;
    
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
    
    public function validate(array $config): void
    {
        $this->validateRequired($config);
        $this->validatePaths($config);
        $this->validatePatterns($config);
        $this->validateOptions($config);
    }
    
    private function validateRequired(array $config): void
    {
        $required = ['entry', 'output'];
        foreach ($required as $key) {
            if (!isset($config[$key])) {
                $this->logger->error('Missing required config', ['key' => $key]);
                throw new ConfigurationException("Missing required config: $key");
            }
        }
    }
    
    private function validatePaths(array $config): void
    {
        if (!file_exists($config['entry'])) {
            $this->logger->error('Entry file not found', ['file' => $config['entry']]);
            throw new ConfigurationException("Entry file not found: {$config['entry']}");
        }
        
        $outputDir = dirname($config['output']);
        if (!is_dir($outputDir)) {
            $this->logger->info('Creating output directory', ['dir' => $outputDir]);
            if (!mkdir($outputDir, 0755, true)) {
                throw new ConfigurationException("Failed to create output directory: $outputDir");
            }
        }
    }
    
    private function validatePatterns(array $config): void
    {
        if (isset($config['exclude']) && !is_array($config['exclude'])) {
            throw new ConfigurationException('Exclude patterns must be an array');
        }
        
        if (isset($config['assets']) && !is_array($config['assets'])) {
            throw new ConfigurationException('Asset patterns must be an array');
        }
    }
    
    private function validateOptions(array $config): void
    {
        $booleanOptions = ['minify', 'comments', 'debug'];
        foreach ($booleanOptions as $option) {
            if (isset($config[$option]) && !is_bool($config[$option])) {
                throw new ConfigurationException("Option '$option' must be a boolean");
            }
        }
    }
}
