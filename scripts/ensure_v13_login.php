<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;

$marker = dirname(__DIR__) . '/storage/.v13_workable_ready';
$force = in_array('--force', $argv ?? [], true);

if (!$force && is_file($marker)) {
    echo "V13_LOGIN_ACCOUNT_ALREADY_READY\n";
    exit(0);
}

$pdo = DB::pdo();
$email = 'admin@urbanspoon.local';
$username = 'admin';
$password = 'Admin@123';

$hash = password_hash($password, PASSWORD_BCRYPT);
if (!$hash) {
    fwrite(STDERR, "Unable to create local password hash.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $q = $pdo->prepare(
        "SELECT *
           FROM users
          WHERE tenant_id=?
            AND (LOWER(email)=LOWER(?) OR LOWER(username)=LOWER(?))
          ORDER BY is_tenant_admin DESC, created_at ASC
          LIMIT 1"
    );
    $q->execute([tenant_id(), $email, $username]);
    $u = $q->fetch();

    if ($u) {
        $userId = $u['id'];
        $pdo->prepare(
            "UPDATE users
                SET username=?,
                    email=?,
                    password_hash=?,
                    password_algo='BCRYPT',
                    status='ACTIVE',
                    is_tenant_admin=1,
                    must_change_password=0,
                    deleted_at=NULL,
                    updated_at=NOW(6)
              WHERE id=?"
        )->execute([$username, $email, $hash, $userId]);
    } else {
        $userId = uuid();
        $pdo->prepare(
            "INSERT INTO users
             (id,tenant_id,username,email,phone,full_name,password_hash,password_algo,
              status,is_tenant_admin,must_change_password,created_at,updated_at,deleted_at)
             VALUES
             (?,?,?,?,NULL,'System Administrator',?,'BCRYPT','ACTIVE',1,0,NOW(6),NOW(6),NULL)"
        )->execute([$userId, tenant_id(), $username, $email, $hash]);
    }

    $q = $pdo->prepare(
        "SELECT id FROM roles WHERE tenant_id=? AND name='Owner / Admin' LIMIT 1"
    );
    $q->execute([tenant_id()]);
    $roleId = $q->fetchColumn();

    if (!$roleId) {
        $roleId = uuid();
        $pdo->prepare(
            "INSERT INTO roles(id,tenant_id,name,description,is_system,is_active)
             VALUES(?,?,'Owner / Admin','Full restaurant administration access',1,1)"
        )->execute([$roleId, tenant_id()]);
    }

    $q = $pdo->prepare(
        "SELECT id
           FROM user_roles
          WHERE user_id=?
            AND role_id=?
            AND (site_id=? OR site_id IS NULL)
          LIMIT 1"
    );
    $q->execute([$userId, $roleId, site_id()]);
    if (!$q->fetchColumn()) {
        $pdo->prepare(
            "INSERT INTO user_roles(id,user_id,role_id,site_id,assigned_by,assigned_at)
             VALUES(?,?,?,?,NULL,NOW(6))"
        )->execute([uuid(), $userId, $roleId, site_id()]);
    }

    $pdo->commit();

    file_put_contents($marker, 'V13 login account prepared at '.date('c').PHP_EOL);
    echo "V13_LOGIN_ACCOUNT_READY\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "V13_LOGIN_ACCOUNT_FAILED\n".$e->getMessage()."\n");
    exit(1);
}

// build: V17.1 build 2026-08-25
