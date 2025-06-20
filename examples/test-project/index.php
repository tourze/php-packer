<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

use TestApp\Application;
use TestApp\Controller\HomeController;

try {
    $app = new Application();
    $app->setDebug(true);
    
    $controller = new HomeController($app);
    $response = $controller->index();
    
    echo $response;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}