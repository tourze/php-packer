<?php

declare(strict_types=1);

namespace TestApp;

use TestApp\Interfaces\ApplicationInterface;
use TestApp\Traits\ConfigurableTrait;

class Application implements ApplicationInterface
{
    use ConfigurableTrait;
    
    private bool $debug = false;
    private array $services = [];
    
    public function __construct()
    {
        $this->initialize();
    }
    
    private function initialize(): void
    {
        $this->loadConfiguration();
        $this->registerServices();
    }
    
    private function registerServices(): void
    {
        // Register default services
        $this->registerService('logger', new \stdClass());
    }
    
    public function registerService(string $name, $service): void
    {
        $this->services[$name] = $service;
    }
    
    public function isDebug(): bool
    {
        return $this->debug;
    }
    
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }
    
    public function getService(string $name)
    {
        return $this->services[$name] ?? null;
    }
}