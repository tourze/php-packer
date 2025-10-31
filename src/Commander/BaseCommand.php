<?php

declare(strict_types=1);

namespace PhpPacker\Commander;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

abstract class BaseCommand
{
    protected LoggerInterface $logger;

    /** @var array<int, string> */
    protected array $args = [];

    /** @var array<string, mixed> */
    protected array $options = [];

    public function __construct()
    {
        $this->logger = $this->createLogger();
    }

    protected function createLogger(): LoggerInterface
    {
        $logger = new Logger('php-packer');

        $handler = new StreamHandler('php://stdout', Level::Debug);
        $formatter = new LineFormatter(
            format: "[%datetime%] %level_name%: %message%\n",
            dateFormat: 'H:i:s',
            allowInlineLineBreaks: true
        );
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);

        return $logger;
    }

    /**
     * @param array<int, string> $argv
     */
    public function run(array $argv): int
    {
        try {
            [$this->args, $this->options] = $this->parseArguments($argv);

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

    /**
     * @param array<int, string> $argv
     * @return array{0: array<int, string>, 1: array<string, mixed>}
     */
    protected function parseArguments(array $argv): array
    {
        $args = [];
        $options = [];

        for ($i = 0; $i < count($argv); ++$i) {
            $result = $this->processArgument($argv, $i);

            if ('option' === $result['type']) {
                /** @var array{type: 'option', key: string, value: string|bool, consumed_next: bool} $result */
                $options[$result['key']] = $result['value'];
                if ($result['consumed_next']) {
                    ++$i;
                }
            } elseif ('arg' === $result['type']) {
                /** @var array{type: 'arg', value: string, consumed_next: false} $result */
                $args[] = $result['value'];
            }
        }

        return [$args, $options];
    }

    /**
     * @param array<int, string> $argv
     * @return array{type: 'option', key: string, value: string|bool, consumed_next: bool}|array{type: 'arg', value: string, consumed_next: false}
     */
    private function processArgument(array $argv, int $index): array
    {
        $arg = $argv[$index];

        if (str_starts_with($arg, '--')) {
            return $this->processLongOption($arg, $argv, $index);
        }

        if (str_starts_with($arg, '-')) {
            return $this->processShortOption($arg, $argv, $index);
        }

        return ['type' => 'arg', 'value' => $arg, 'consumed_next' => false];
    }

    /**
     * @param array<int, string> $argv
     * @return array{type: 'option', key: string, value: string|bool, consumed_next: bool}
     */
    private function processLongOption(string $arg, array $argv, int $index): array
    {
        $key = substr($arg, 2);

        if (str_contains($key, '=')) {
            [$key, $value] = explode('=', $key, 2);

            return ['type' => 'option', 'key' => $key, 'value' => $value, 'consumed_next' => false];
        }

        return $this->processOptionValue($key, $argv, $index);
    }

    /**
     * @param array<int, string> $argv
     * @return array{type: 'option', key: string, value: string|bool, consumed_next: bool}
     */
    private function processShortOption(string $arg, array $argv, int $index): array
    {
        $key = substr($arg, 1);

        return $this->processOptionValue($key, $argv, $index);
    }

    /**
     * @param array<int, string> $argv
     * @return array{type: 'option', key: string, value: string|bool, consumed_next: bool}
     */
    private function processOptionValue(string $key, array $argv, int $index): array
    {
        $nextIndex = $index + 1;

        if ($this->hasNextValue($argv, $nextIndex)) {
            return ['type' => 'option', 'key' => $key, 'value' => $argv[$nextIndex], 'consumed_next' => true];
        }

        return ['type' => 'option', 'key' => $key, 'value' => true, 'consumed_next' => false];
    }

    /**
     * @param array<int, string> $argv
     */
    private function hasNextValue(array $argv, int $nextIndex): bool
    {
        return isset($argv[$nextIndex]) && !str_starts_with($argv[$nextIndex], '-');
    }

    protected function showHelp(): void
    {
        echo $this->getName() . ' - ' . $this->getDescription() . "\n\n";
        echo "Usage:\n";
        echo '  ' . $this->getUsage() . "\n";
    }

    abstract public function getName(): string;

    abstract public function getDescription(): string;

    abstract public function getUsage(): string;

    /**
     * @param array<int, string> $args
     * @param array<string, mixed> $options
     */
    abstract public function execute(array $args, array $options): int;

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes > 0 ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[(int) $pow];
    }
}
