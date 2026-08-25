<?php
declare(strict_types=1);

$sessionDir = dirname(__DIR__) . '/storage/sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0775, true);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('AIO_RESTAURANT_V14_SESSION');
    session_save_path($sessionDir);
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'cookie_path' => '/',
    ]);
}

// AIO_CONFIG env var can point to an alternate config (e.g. cloud deployment).
// Falls back to the standard local branch config.
$envConfig = getenv('AIO_CONFIG');
if ($envConfig && !preg_match('#^(/|[A-Za-z]:)#', $envConfig)) {
    $envConfig = dirname(__DIR__) . '/' . ltrim($envConfig, '/');
}
$configFile = ($envConfig && is_file($envConfig)) ? $envConfig : (dirname(__DIR__) . '/config/local.php');
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Missing config/local.php');
}
$GLOBALS['config'] = require $configFile;
date_default_timezone_set($GLOBALS['config']['app']['timezone'] ?? 'UTC');

spl_autoload_register(function(string $class): void {
    $prefix = 'Aio\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require $file;
});

require_once __DIR__ . '/helpers.php';

// build: V17.1 build 2026-08-25
