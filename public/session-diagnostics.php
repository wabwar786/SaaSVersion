<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\Auth;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$u = Auth::user();

echo json_encode([
    'ok' => true,
    'session_name' => session_name(),
    'session_id_present' => session_id() !== '',
    'authenticated' => (bool)$u,
    'user_email' => $u['email'] ?? null,
    'is_admin' => isset($u['is_tenant_admin']) ? (bool)$u['is_tenant_admin'] : false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// build: V17.1 build 2026-08-25
