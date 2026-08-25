<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'ok' => true,
    'php' => PHP_VERSION,
    'session_name' => session_name(),
    'bootstrap_loaded' => true
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
