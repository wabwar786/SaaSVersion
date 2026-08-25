<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = DB::pdo();
    $q = $pdo->prepare(
        "SELECT email, username, status, is_tenant_admin, deleted_at,
                CHAR_LENGTH(password_hash) AS hash_length
         FROM users
         WHERE tenant_id=? AND email='admin@urbanspoon.local'
         LIMIT 1"
    );
    $q->execute([tenant_id()]);
    $u = $q->fetch();

    echo json_encode([
        'ok' => true,
        'database' => 'connected',
        'default_admin_exists' => (bool)$u,
        'default_admin_active' => $u ? ($u['status'] === 'ACTIVE' && $u['deleted_at'] === null) : false,
        'default_admin_is_admin' => $u ? (bool)$u['is_tenant_admin'] : false,
        'password_hash_present' => $u ? ((int)$u['hash_length'] > 20) : false,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'database' => 'error',
        'message' => $e->getMessage()
    ]);
}
