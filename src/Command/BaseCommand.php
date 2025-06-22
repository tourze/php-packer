<?php

declare(strict_types=1);

namespace PhpPacker\Command;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

abstract class BaseCommand
{
    protected LoggerInterface $logger;
    protected array $args = [];
    protected array $options = [];
    
    public function __construct()
    {
        $this->logger = $this->createLogger();
    }
    
    protected function createLogger(): LoggerInterface
    {
        $logger = new Logger('php-packer');

        $handler = new StreamHandler('php://stdout', Logger::DEBUG);
        $formatter = new LineFormatter(
            format: "[%datetime%] %level_name%: %message%\n",
            dateFormat: 'H:i:s',
            allowInlineLineBreaks: true
        );
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);

        return $logger;
    }

    public function run(array $argv): int
    {
        try {
            list($this->args, $this->options) = $this->parseArguments($argv);

            if (isset($this->options['help']) || isset($this->options['h'])) {
                $this->showHelp();
                return 0;
            }

            return $this->execute($this->args, $this->options);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return 1;
        }
    }

    protected function parseArguments(array $argv): array
    {
        $args = [];
        $options = [];

        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--')) {
                $key = substr($arg, 2);
                if (str_contains($key, '=')) {
                    list($key, $value) = explode('=', $key, 2);
                    $options[$key] = $value;
                } else {
                    $options[$key] = isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')
                        ? $argv[++$i]
                        : true;
                }
            } elseif (str_starts_with($arg, '-')) {
                $key = substr($arg, 1);
                $options[$key] = isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')
                    ? $argv[++$i]
                    : true;
            } else {
                $args[] = $arg;
            }
        }

        return [$args, $options];
    }

    protected function showHelp(): void
    {
        echo $this->getName() . " - " . $this->getDescription() . "\n\n";
        echo "Usage:\n";
        echo "  " . $this->getUsage() . "\n";
    }
    
    abstract public function getName(): string;
    
    abstract public function getDescription(): string;
    
    abstract public function getUsage(): string;
    
    abstract public function execute(array $args, array $options): int;
    
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}