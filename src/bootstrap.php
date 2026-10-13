<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Config;

Config::load();

error_reporting(E_ALL);
ini_set('display_errors', Config::bool('APP_DEBUG', false) ? '1' : '0');
ini_set('log_errors', '1');
