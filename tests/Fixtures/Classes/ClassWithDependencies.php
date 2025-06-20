<?php

declare(strict_types=1);

namespace TestFixtures\Classes;

use TestFixtures\Interfaces\TestInterface;
use TestFixtures\Traits\TestTrait;

class ClassWithDependencies extends BaseClass implements TestInterface
{
    use TestTrait;
    
    public function doSomething(): void
    {
        $this->traitMethod();
        parent::parentMethod();
    }
    
    public function interfaceMethod(): string
    {
        return 'implemented';
    }
}