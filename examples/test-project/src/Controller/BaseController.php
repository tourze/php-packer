<?php

declare(strict_types=1);

namespace TestApp\Controller;

use TestApp\Application;

abstract class BaseController
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }
}
