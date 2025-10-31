<?php

declare(strict_types=1);

namespace TestApp\Traits;

trait ConfigurableTrait
{
    private array $config = [];

    public function loadConfiguration(): void
    {
        $this->config = [
            'name' => APP_NAME,
            'version' => APP_VERSION,
            'env' => APP_ENV,
        ];
    }

    public function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function setConfig(string $key, $value): void
    {
        $this->config[$key] = $value;
    }
}
