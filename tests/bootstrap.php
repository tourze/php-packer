<?php

declare(strict_types=1);

// Composer autoloader
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

$autoloaderFound = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    die("Autoloader not found. Please run 'composer install' first.\n");
}

// Set up test environment
ini_set('memory_limit', '256M');
error_reporting(E_ALL);

// Clean up test databases
$testDbPattern = sys_get_temp_dir() . '/php-packer-test-*.db';
foreach (glob($testDbPattern) as $file) {
    @unlink($file);
}