<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;
use Aio\Services\UserService;

$force = in_array('--force', $argv ?? [], true);
$marker = dirname(__DIR__) . '/storage/.approved_v6_admin_repaired';

if (!$force && is_file($marker)) {
    echo "ADMIN_LOGIN_ALREADY_REPAIRED\n";
    exit(0);
}

$pdo = DB::pdo();
$email = 'admin@urbanspoon.local';
$username = 'admin';
$password = 'Admin@123';

$pdo->beginTransaction();
try {
    // Owner/Admin role must exist.
    $q = $pdo->prepare("SELECT id FROM roles WHERE tenant_id=? AND name='Owner / Admin' LIMIT 1");
    $q->execute([tenant_id()]);
    $roleId = $q->fetchColumn();

    if (!$roleId) {
        $roleId = uuid();
        $pdo->prepare(
            "INSERT INTO roles(id,tenant_id,name,description,is_system,is_active)
             VALUES(?,?,'Owner / Admin','Full restaurant administration access',1,1)"
        )->execute([$roleId, tenant_id()]);
    }

    // Exact account shown on the approved login screen.
    $q = $pdo->prepare(
        "SELECT * FROM users
         WHERE tenant_id=? AND (email=? OR username=?)
         ORDER BY is_tenant_admin DESC, created_at ASC
         LIMIT 1"
    );
    $q->execute([tenant_id(), $email, $username]);
    $user = $q->fetch();

    [$hash, $algo] = UserService::passwordHash($password);

    if (!$user) {
        $userId = uuid();
        $pdo->prepare(
            "INSERT INTO users
             (id,tenant_id,username,email,phone,full_name,password_hash,password_algo,status,
              is_tenant_admin,must_change_password,created_at,updated_at,deleted_at)
             VALUES(?,?,?,?,NULL,?,?,?,?,1,0,NOW(6),NOW(6),NULL)"
        )->execute([
            $userId, tenant_id(), $username, $email,
            'System Administrator', $hash, $algo, 'ACTIVE'
        ]);
        echo "DEFAULT_ADMIN_CREATED\n";
    } else {
        $userId = $user['id'];

        // Repair the account ONCE for this V6 package so the credentials
        // visibly printed on the approved login UI are guaranteed to work.
        $pdo->prepare(
            "UPDATE users
             SET username=?, email=?, full_name=COALESCE(NULLIF(full_name,''),'System Administrator'),
                 password_hash=?, password_algo=?, status='ACTIVE',
                 is_tenant_admin=1, must_change_password=0,
                 deleted_at=NULL, updated_at=NOW(6)
             WHERE id=?"
        )->execute([$username, $email, $hash, $algo, $userId]);

        echo "DEFAULT_ADMIN_REPAIRED\n";
    }

    // Ensure Owner/Admin role assignment for the configured site.
    $q = $pdo->prepare(
        "SELECT id FROM user_roles
         WHERE user_id=? AND role_id=? AND (site_id=? OR site_id IS NULL)
         LIMIT 1"
    );
    $q->execute([$userId, $roleId, site_id()]);
    if (!$q->fetchColumn()) {
        $pdo->prepare(
            "INSERT INTO user_roles(id,user_id,role_id,site_id,assigned_by,assigned_at)
             VALUES(?,?,?,?,NULL,NOW(6))"
        )->execute([uuid(), $userId, $roleId, site_id()]);
    }

    // Owner/Admin role gets all active modules.
    $mods = $pdo->query("SELECT id FROM platform_modules WHERE is_active=1")->fetchAll();
    foreach ($mods as $m) {
        $moduleId = $m['id'];

        $q = $pdo->prepare(
            "SELECT id FROM role_modules WHERE role_id=? AND module_id=? LIMIT 1"
        );
        $q->execute([$roleId, $moduleId]);
        if (!$q->fetchColumn()) {
            $pdo->prepare(
                "INSERT INTO role_modules(id,role_id,module_id,is_allowed)
                 VALUES(?,?,?,1)"
            )->execute([uuid(), $roleId, $moduleId]);
        }

        $q = $pdo->prepare(
            "SELECT id FROM user_module_access
             WHERE user_id=? AND module_id=? AND (site_id=? OR site_id IS NULL)
             LIMIT 1"
        );
        $q->execute([$userId, $moduleId, site_id()]);
        $existing = $q->fetchColumn();
        if ($existing) {
            $pdo->prepare(
                "UPDATE user_module_access SET access_mode='ALLOW' WHERE id=?"
            )->execute([$existing]);
        } else {
            $pdo->prepare(
                "INSERT INTO user_module_access
                 (id,user_id,site_id,module_id,access_mode)
                 VALUES(?,?,?,?, 'ALLOW')"
            )->execute([uuid(), $userId, site_id(), $moduleId]);
        }
    }

    $pdo->commit();

    if (!is_dir(dirname($marker))) {
        mkdir(dirname($marker), 0775, true);
    }
    file_put_contents(
        $marker,
        "Approved UI V6 admin account repaired at " . date('c') . PHP_EOL
    );

    echo "LOGIN_READY\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "ADMIN_REPAIR_FAILED\n" . $e->getMessage() . "\n");
    exit(1);
}

// build: V17.1 build 2026-08-25
