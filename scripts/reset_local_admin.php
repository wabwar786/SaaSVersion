<?php
/**
 * reset_local_admin.php — branch computer par login ka rasta.
 *
 * MASLA: offline version install ho gaya aur password kisi ko yaad nahi.
 * Online se reset karna kaafi nahi tha (aur wo bug alag se V89 mein
 * theek hua) — magar asal baat yeh hai ke branch par INTERNET HO HI NA,
 * to cloud se koi madad nahi milti.
 *
 * Yeh script usi computer par chalti hai, database ko seedha chhoti hai,
 * aur internet ki koi zaroorat nahi.
 *
 *   php scripts/reset_local_admin.php                       # users dikhao
 *   php scripts/reset_local_admin.php --user=owner --password=Naya@123
 *   php scripts/reset_local_admin.php --user=owner --password=X --make-admin
 *
 * HIFAZAT: yeh sirf usi ke haath mein hai jis ke paas us computer ka
 * access hai. Jis ke paas computer hai, us ke paas data bhi hai — is
 * liye yahan koi aur taala lagana sirf malik ko takleef deta.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$a = cli_args();
$p = DB::pdo();

$user = trim((string)($a['user'] ?? ''));
$pass = (string)($a['password'] ?? '');

/* ---- koi flag na ho: users ki fehrist ---- */
if ($user === '' || $pass === '') {
    echo "\n  SmartPOS — local sign-in recovery\n";
    echo "  ".str_repeat('-', 56)."\n\n";
    try {
        $q = $p->prepare(
            "SELECT u.username, u.email, u.full_name, u.status, u.is_tenant_admin,
                    COALESCE(r.name,'-') AS role
               FROM users u
               LEFT JOIN user_roles ur ON ur.user_id = u.id
               LEFT JOIN roles r       ON r.id = ur.role_id
              WHERE u.tenant_id = ? AND u.deleted_at IS NULL
              GROUP BY u.id ORDER BY u.is_tenant_admin DESC, u.full_name");
        $q->execute([tenant_id()]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        echo "  Could not read the users table: ".$e->getMessage()."\n";
        echo "  Is the local database running? Try START_RESTAURANT.bat first.\n\n";
        return;
    }

    if (!$rows) { echo "  No users found in this database.\n\n"; return; }

    printf("  %-20s %-26s %-14s %s\n", 'SIGN IN WITH', 'NAME', 'ROLE', 'STATUS');
    foreach ($rows as $r) {
        printf("  %-20s %-26s %-14s %s%s\n",
            (string)($r['username'] ?: $r['email']),
            substr((string)$r['full_name'], 0, 25),
            substr((string)$r['role'], 0, 13),
            (string)$r['status'],
            ((int)$r['is_tenant_admin'] === 1 ? '  (owner)' : ''));
    }
    echo "\n  To set a new password:\n";
    echo "     php scripts/reset_local_admin.php --user=NAME --password=NewPass123\n\n";
    return;
}

if (strlen($pass) < 4) { echo "  Password must be at least 4 characters.\n"; return; }

/* ---- user dhoondo: username ya email ---- */
$q = $p->prepare("SELECT id, full_name, username, email, is_tenant_admin FROM users
                   WHERE tenant_id=? AND deleted_at IS NULL
                     AND (LOWER(username)=LOWER(?) OR LOWER(email)=LOWER(?)) LIMIT 1");
$q->execute([tenant_id(), $user, $user]);
$u = $q->fetch(PDO::FETCH_ASSOC);

if (!$u) {
    echo "  No user called \"$user\" in this database.\n";
    echo "  Run without any options to see the list.\n";
    return;
}

$hash = password_hash($pass, PASSWORD_DEFAULT);

/* `row_version` bhi barhao — warna agli sync par cloud ka purana
   password wapas aa kar isay mita dega. */
$p->prepare("UPDATE users SET password_hash=?, password_algo='BCRYPT', status='ACTIVE',
                 must_change_password=0, row_version=row_version+1, updated_at=NOW(6)
              WHERE id=?")->execute([$hash, $u['id']]);

if (!empty($a['make-admin'])) {
    $p->prepare("UPDATE users SET is_tenant_admin=1 WHERE id=?")->execute([$u['id']]);
    echo "  Also made this user the owner/admin.\n";
}

echo "\n  Password changed for: ".$u['full_name']."\n";
echo "  Sign in with        : ".($u['username'] ?: $u['email'])."\n";
echo "  New password        : $pass\n\n";
echo "  Open the software and sign in. Change it again from Users & Access.\n\n";

try {
    \Aio\Services\Audit::log('PASSWORD_RESET_LOCAL', 'users',
        ['id' => (string)$u['id'], 'label' => (string)$u['full_name'],
         'desc' => 'reset from the local recovery tool']);
} catch (\Throwable $e) {}

// build: V89 build 2026-09-01
