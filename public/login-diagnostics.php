<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = DB::pdo();
    $q = $pdo->prepare(
        "SELECT email,status,is_tenant_admin,password_hash,last_login_at
           FROM users
          WHERE tenant_id=?
            AND LOWER(email)=LOWER('admin@urbanspoon.local')
          LIMIT 1"
    );
    $q->execute([tenant_id()]);
    $u = $q->fetch();

    echo json_encode([
        'ok' => true,
        'database' => 'connected',
        'admin_exists' => (bool)$u,
        'admin_status' => $u['status'] ?? null,
        'admin_is_tenant_admin' => isset($u['is_tenant_admin']) ? (bool)$u['is_tenant_admin'] : null,
        'password_verifies' => $u ? password_verify('Admin@123', $u['password_hash']) : false,
        'session_active' => session_status() === PHP_SESSION_ACTIVE
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_PRETTY_PRINT);
}

// build: V17.1 build 2026-08-25
