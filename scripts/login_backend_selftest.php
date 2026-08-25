<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\Auth;
use Aio\DB;

try {
    $pdo = DB::pdo();

    $q = $pdo->prepare(
        "SELECT id,email,status,is_tenant_admin,password_hash
           FROM users
          WHERE tenant_id=?
            AND LOWER(email)=LOWER('admin@urbanspoon.local')
          LIMIT 1"
    );
    $q->execute([tenant_id()]);
    $u = $q->fetch();

    if (!$u) {
        throw new RuntimeException('Default administrator record does not exist.');
    }
    if ($u['status'] !== 'ACTIVE') {
        throw new RuntimeException('Default administrator is not ACTIVE.');
    }
    if (!password_verify('Admin@123', $u['password_hash'])) {
        throw new RuntimeException('Default administrator password hash does not verify.');
    }
    if (!Auth::login('admin@urbanspoon.local', 'Admin@123')) {
        throw new RuntimeException('Auth::login returned false.');
    }

    $current = Auth::user();
    if (!$current || ($current['id'] ?? '') !== $u['id']) {
        throw new RuntimeException('Authenticated session user was not created.');
    }

    echo "LOGIN_BACKEND_READY_V11\n";
} catch (Throwable $e) {
    fwrite(STDERR, "LOGIN_BACKEND_FAILED_V11\n".$e->getMessage()."\n");
    exit(1);
}

// build: V17.1 build 2026-08-25
