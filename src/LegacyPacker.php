<?php

declare(strict_types=1);

namespace PhpPacker;

use PhpPacker\Adapter\ConfigurationAdapter;
use PhpPacker\Command\AnalyzeCommand;
use PhpPacker\Command\PackCommand;
use Psr\Log\LoggerInterface;

/**
 * Legacy Packer class for backward compatibility with tests
 */
class LegacyPacker
{
    private ConfigurationAdapter $config;
    // Removed unused property: logger
    
    /**
     * @phpstan-ignore-next-line constructor.unusedParameter
     */
    public function __construct(ConfigurationAdapter $config, LoggerInterface $logger)
    {
        $this->config = $config;
        // Removed: logger assignment
    }
    
    public function pack(): void
    {
        // Get configuration values
        $entry = $this->config->get('entry');
        $output = $this->config->get('output', 'packed.php');
        $database = $this->config->get('database', 'build/packer.db');
        $rootPath = $this->config->getRootPath();
        
        // Prepare absolute paths
        if (!str_starts_with($entry, '/')) {
            $entry = $rootPath . '/' . $entry;
        }
        
        if (!str_starts_with($output, '/')) {
            $output = $rootPath . '/' . $output;
        }
        
        if (!str_starts_with($database, '/')) {
            $database = $rootPath . '/' . $database;
        }
        
        // Create database directory
        $dbDir = dirname($database);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        
        // Step 1: Analyze
        $analyzeCommand = new AnalyzeCommand();
        $analyzeArgs = [$entry];
        $analyzeOptions = [
            'database' => $database,
            'root-path' => $rootPath,
        ];
        
        // Add custom autoload if present
        $autoload = $this->config->get('autoload', []);
        if (!empty($autoload) && isset($autoload['psr-4'])) {
            $autoloadConfigs = [];
            foreach ($autoload['psr-4'] as $prefix => $paths) {
                foreach ((array) $paths as $path) {
                    $autoloadConfigs[] = "psr4:$prefix:$path";
                }
            }
            if (!empty($autoloadConfigs)) {
                $analyzeOptions['autoload'] = $autoloadConfigs;
            }
        }
        
        $result = $analyzeCommand->execute($analyzeArgs, $analyzeOptions);
        if ($result !== 0) {
            throw new \RuntimeException('Analysis failed');
        }
        
        // Step 2: Pack
        $packCommand = new PackCommand();
        $packOptions = [
            'database' => $database,
            'root-path' => $rootPath,
            'output' => $output,
        ];
        
        // Add optimization options
        $optimization = $this->config->get('optimization', []);
        if (!empty($optimization)) {
            if ($optimization['remove_comments'] ?? false) {
                $packOptions['strip-comments'] = true;
            }
            if ($optimization['remove_whitespace'] ?? false) {
                $packOptions['optimize'] = true;
            }
        }
        
        $result = $packCommand->execute([], $packOptions);
        if ($result !== 0) {
            throw new \RuntimeException('Packing failed');
        }
    }
}