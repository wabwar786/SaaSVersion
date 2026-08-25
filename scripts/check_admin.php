<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';
use Aio\DB;
try {
    $pdo = DB::pdo();
    $q = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tenant_id=? AND is_tenant_admin=1 AND status='ACTIVE'");
    $q->execute([tenant_id()]);
    echo ((int)$q->fetchColumn() > 0) ? "1" : "0";
} catch (Throwable $e) {
    echo "0";
    exit(1);
}

// build: V17.1 build 2026-08-25
