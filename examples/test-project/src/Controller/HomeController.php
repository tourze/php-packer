<?php

declare(strict_types=1);

namespace TestApp\Controller;

use TestApp\Application;

class HomeController extends BaseController
{
    public function __construct(Application $app)
    {
        parent::__construct($app);
    }
    
    public function index(): string
    {
        $appName = $this->app->getConfig('name');
        $appVersion = $this->app->getConfig('version');
        $isDebug = $this->app->isDebug() ? 'Yes' : 'No';
        
        return sprintf(
            "Welcome to %s v%s!\nDebug mode: %s\n",
            $appName,
            $appVersion,
            $isDebug
        );
    }
}