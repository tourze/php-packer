<?php

declare(strict_types=1);

namespace TestApp\Interfaces;

interface ApplicationInterface
{
    public function setDebug(bool $debug): void;
    
    public function isDebug(): bool;
    
    public function registerService(string $name, $service): void;
    
    public function getService(string $name);
}