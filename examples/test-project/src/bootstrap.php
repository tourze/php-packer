<?php

declare(strict_types=1);

// Bootstrap file
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Include configuration
require_once __DIR__ . '/config.php';

// Simple autoloader for demonstration
spl_autoload_register(function ($class): void {
    $prefix = 'TestApp\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (0 !== strncmp($prefix, $class, $len)) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
