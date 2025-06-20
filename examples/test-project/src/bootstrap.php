<?php

declare(strict_types=1);

// Bootstrap file
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Include configuration
require_once __DIR__ . '/config.php';

// Simple autoloader for demonstration
spl_autoload_register(function ($class) {
    $prefix = 'TestApp\\';
    $baseDir = __DIR__ . '/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});