<?php
/**
 * LOGIN / MODULE DIAGNOSTIC
 *
 * Jab koi kahe "login nahi ho raha" ya "modules gayab hain", to andaza
 * lagane ke bajaye yeh chala kar dekhein. Kuch badalta nahi — sirf
 * parhta hai aur batata hai.
 *
 *   php scripts/diagnose_login.php
 *   php scripts/diagnose_login.php <slug>          # ek business
 *   php scripts/diagnose_login.php <slug> <email>  # ek user
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;

$pdo  = DB::pdo();
$slug = $argv[1] ?? '';
$mail = $argv[2] ?? '';

function line(string $s = ''): void { echo $s . "\n"; }
function ok(string $s): void { line('  [ok]   ' . $s); }
function bad(string $s): void { line('  [!!]   ' . $s); }
function inf(string $s): void { line('  .      ' . $s); }

line();
line('=== 1. SCHEMA ===');
foreach ([['tenants','region_profile'], ['platform_modules','industry_code'], ['units','conversion_factor']] as [$t,$c]) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                         WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$t, $c]);
    (int)$q->fetchColumn() ? ok("$t.$c present") : bad("$t.$c GHAIB — `php scripts/migrate_retail.php` run");
}
$q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='rtl_products'");
$q->execute();
(int)$q->fetchColumn() ? ok('rtl_* tables present') : bad('rtl_* tables GHAIB — `php scripts/migrate_retail.php`');

/* ============================================================
   1b. SUPER ADMIN (platform console)
   `php scripts/diagnose_login.php super <password>` se password bhi
   check hota hai.
   ============================================================ */
line();
line('=== 1b. SUPER ADMIN ===');
try {
    $pu = $pdo->query("SELECT id,email,role,status,LENGTH(password_hash) len,created_at
                         FROM platform_users ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC);
    if (!$pu) {
        bad('platform_users is EMPTY — there is no super admin account at all.');
        inf('      To create one: php scripts/reset_super_admin.php --email=you@x.com --password=\'Pass@123\' --create');
    } else {
        foreach ($pu as $x) {
            $f = [];
            if ($x['status'] !== 'ACTIVE') $f[] = 'status=' . $x['status'];
            if ((int)$x['len'] !== 60)     $f[] = 'hash length=' . $x['len'] . ' (bcrypt 60 hona chahiye)';
            $msg = sprintf('%-30s %-6s %s', $x['email'], $x['role'], $f ? '<< ' . implode(', ', $f) : '');
            $f ? bad($msg) : ok($msg);
        }
        $nSuper = 0;
        foreach ($pu as $x) if ($x['role'] === 'SUPER' && $x['status'] === 'ACTIVE') $nSuper++;
        if (!$nSuper) bad("No ACTIVE role='SUPER' account — signing into the console is impossible.");
    }

    /* password check: diagnose_login.php super <password> */
    if (($argv[1] ?? '') === 'super' && ($argv[2] ?? '') !== '') {
        $try = $argv[2];
        line();
        $hit = false;
        $vq = $pdo->query("SELECT email,password_hash,status FROM platform_users");
        foreach ($vq->fetchAll(PDO::FETCH_ASSOC) as $x) {
            if (\password_verify($try, (string)$x['password_hash'])) {
                ok('The password matches this account: ' . $x['email'] .
                   ($x['status'] === 'ACTIVE' ? '' : '  (but status ' . $x['status'] . ')'));
                $hit = true;
            }
        }
        if (!$hit) {
            bad('This password does not match any platform account.');
            inf('      php scripts/reset_super_admin.php --email=<email> --password=\'NayaPass@123\'');
        }
        line();
        line('  NOTE: if SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD are set in Railway Variables');
        line('  then the password is re-applied on every deploy — anything changed via SQL');
        line('  is lost at the next deploy.');
        exit(0);
    }
} catch (\Throwable $e) {
    bad('Could not read platform_users: ' . $e->getMessage());
    inf('      `php scripts/migrate_platform.php` run.');
}

line();
line('=== 2. MODULE CATALOG ===');
$rows = $pdo->query("SELECT COALESCE(NULLIF(industry_code,''),'(empty)') ic, COUNT(*) c
                       FROM platform_modules WHERE is_active=1 GROUP BY ic ORDER BY ic")->fetchAll();
$byInd = [];
foreach ($rows as $r) { $byInd[$r['ic']] = (int)$r['c']; inf(sprintf('%-12s %d modules', $r['ic'], $r['c'])); }

if (!isset($byInd['COMMON'])) {
    bad('There is no COMMON bucket — run `php scripts/seed_industry_modules.php`.');
    bad('Without it users only get their own vertical modules — not even settings or reports.');
} else {
    ok('The COMMON bucket is fine');
}
$rest = (int)$pdo->query("SELECT COUNT(*) FROM platform_modules
                           WHERE is_active=1 AND industry_code IN('RESTAURANT','COMMON')")->fetchColumn();
$ret  = (int)$pdo->query("SELECT COUNT(*) FROM platform_modules
                           WHERE is_active=1 AND industry_code IN('RETAIL','COMMON')")->fetchColumn();
inf("restaurant tenant ko dikhne chahiye: $rest modules");
inf("retail tenant ko dikhne chahiye:     $ret modules");
if ($rest < 30) bad('Far too few restaurant modules — run the seed again.');

line();
line('=== 3. BUSINESSES ===');
$sql = "SELECT id,name,slug,COALESCE(NULLIF(industry_code,''),'(empty)') ic,
               COALESCE(NULLIF(region_profile,''),'-') rp, status
          FROM tenants" . ($slug ? " WHERE slug=?" : "") . " ORDER BY created_at DESC LIMIT 20";
$q = $pdo->prepare($sql);
$slug ? $q->execute([$slug]) : $q->execute();
$tenants = $q->fetchAll();
if (!$tenants) { bad('No business found' . ($slug ? " (slug: $slug)" : '')); exit(1); }

foreach ($tenants as $t) {
    line();
    line(sprintf('  %s  [%s / %s]  %s', $t['name'], $t['ic'], $t['rp'], $t['status']));
    inf('slug: ' . $t['slug'] . '   login: /login.html?b=' . $t['slug']);

    if ($t['ic'] === '(empty)') {
        bad('industry_code is EMPTY — the system will treat it as RESTAURANT. To fix:');
        inf("      UPDATE tenants SET industry_code='RESTAURANT' WHERE id='" . $t['id'] . "';");
    } elseif (!in_array($t['ic'], ['RESTAURANT','RETAIL'], true)) {
        bad('industry_code na-maloom: ' . $t['ic'] . ' — is tenant ko only COMMON modules milenge.');
    }
    if ($t['status'] !== 'ACTIVE') bad('Status is not ACTIVE — that alone can block sign-in.');

    /* users */
    $uq = $pdo->prepare("SELECT id,email,username,full_name,status,is_tenant_admin,
                                (password_hash IS NULL OR password_hash='') AS nopass, deleted_at
                           FROM users WHERE tenant_id=?" . ($mail ? " AND (email=? OR username=?)" : "") . " LIMIT 10");
    $mail ? $uq->execute([$t['id'], $mail, $mail]) : $uq->execute([$t['id']]);
    $users = $uq->fetchAll();
    if (!$users) { bad('This business has no users — sign-in is impossible.'); continue; }

    foreach ($users as $u) {
        $flags = [];
        if ($u['status'] !== 'ACTIVE') $flags[] = 'status=' . $u['status'];
        if ($u['deleted_at'])          $flags[] = 'DELETED';
        if ($u['nopass'])              $flags[] = 'PASSWORD NAHI';
        $tag = $u['is_tenant_admin'] ? 'admin' : 'user';
        $msg = sprintf('%-28s %-6s %s', $u['email'] ?: $u['username'], $tag, $flags ? '<< ' . implode(', ', $flags) : '');
        $flags ? bad($msg) : ok($msg);

        /* is user ko kitne modules milenge */
        if ($u['is_tenant_admin']) {
            $mq = $pdo->prepare("SELECT COUNT(*) FROM platform_modules
                                  WHERE is_active=1 AND industry_code IN (?, 'COMMON')");
            $mq->execute([strtoupper($t['ic'] === '(empty)' ? 'RESTAURANT' : $t['ic'])]);
            $n = (int)$mq->fetchColumn();
        } else {
            $mq = $pdo->prepare(
                "SELECT COUNT(DISTINCT pm.module_key)
                   FROM platform_modules pm
                   JOIN role_modules rm ON rm.module_id=pm.id
                   JOIN user_roles ur ON ur.role_id=rm.role_id AND ur.user_id=?
                  WHERE pm.is_active=1 AND pm.industry_code IN (?, 'COMMON')");
            $mq->execute([$u['id'], strtoupper($t['ic'] === '(empty)' ? 'RESTAURANT' : $t['ic'])]);
            $n = (int)$mq->fetchColumn();
        }
        inf('   modules: ' . $n . ($n === 0 ? '  << ZERO — sign-in works but the sidebar is empty and every page returns 403' : ''));
    }
}

/* ============================================================
   4. ASAL LOGIN — qadam ba qadam
   `php scripts/diagnose_login.php <slug> <email> <password>`
   Yeh wahi qadam chalata hai jo Auth::login chalata hai, aur batata hai
   ke kaunsa qadam nakaam hua — "Invalid login" jaisa mubham jawab nahi.
   ============================================================ */
$pass = $argv[3] ?? '';
if ($slug !== '' && $mail !== '' && $pass !== '') {
    line();
    line('=== 4. LOGIN SIMULATION ===');
    $tq = $pdo->prepare("SELECT * FROM tenants WHERE slug=? LIMIT 1");
    $tq->execute([$slug]);
    $t = $tq->fetch(PDO::FETCH_ASSOC);
    if (!$t) { bad("Slug '$slug' — no such business. Use `list` to find the right slug."); exit(1); }
    ok('business mila: ' . $t['name'] . ' [' . $t['industry_code'] . ']');

    if (($t['status'] ?? '') !== 'ACTIVE') {
        bad('Business status ' . $t['status'] . ' — sign-in stops right here.');
        inf("      UPDATE tenants SET status='ACTIVE' WHERE id='" . $t['id'] . "';");
    }

    /* Auth::login ki asal query — hu-ba-hu */
    $uq = $pdo->prepare(
        "SELECT * FROM users
          WHERE tenant_id=? AND status='ACTIVE' AND deleted_at IS NULL
            AND (LOWER(email)=LOWER(?) OR LOWER(username)=LOWER(?)) LIMIT 1");
    $uq->execute([$t['id'], $mail, $mail]);
    $u = $uq->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        bad("This business has no '$mail' — no ACTIVE user with that name.");
        $any = $pdo->prepare("SELECT email,username,status,deleted_at FROM users
                               WHERE LOWER(email)=LOWER(?) OR LOWER(username)=LOWER(?)");
        $any->execute([$mail, $mail]);
        foreach ($any->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $ot = $pdo->prepare("SELECT name,slug FROM tenants WHERE id=?");
            $ot->execute([$o['tenant_id'] ?? '']);
            inf('      this email exists elsewhere: status=' . $o['status'] .
                ($o['deleted_at'] ? ' (deleted)' : ''));
        }
        inf('      Users in this business:');
        $lu = $pdo->prepare("SELECT email,username,status FROM users WHERE tenant_id=? LIMIT 10");
        $lu->execute([$t['id']]);
        foreach ($lu->fetchAll(PDO::FETCH_ASSOC) as $o) inf('        ' . ($o['email'] ?: $o['username']) . '  ' . $o['status']);
        exit(1);
    }
    ok('user mila: ' . ($u['email'] ?: $u['username']));

    if (empty($u['password_hash'])) {
        bad('The password hash is empty — this user has no password set.');
        exit(1);
    }
    if (\password_verify($pass, (string)$u['password_hash'])) {
        ok('PASSWORD IS CORRECT — sign-in should work.');
    } else {
        bad('PASSWORD IS WRONG (hash did not match).');
        inf('      The demo password is shown ONCE at creation; only the hash is stored.');
        inf('      To set a new one:');
        inf("        php -r \"require 'src/bootstrap.php'; Aio\\DB::pdo()->prepare('UPDATE users SET password_hash=? WHERE id=?')");
        inf("          ->execute([password_hash('NayaPass@123',PASSWORD_DEFAULT),'" . $u['id'] . "']);\"");
        exit(1);
    }

    $ind = \strtoupper((string)($t['industry_code'] ?: 'RESTAURANT'));
    if ((int)$u['is_tenant_admin'] === 1) {
        $mq = $pdo->prepare("SELECT COUNT(*) FROM platform_modules WHERE is_active=1 AND industry_code IN (?, 'COMMON')");
        $mq->execute([$ind]);
    } else {
        $mq = $pdo->prepare(
            "SELECT COUNT(DISTINCT pm.module_key) FROM platform_modules pm
               JOIN role_modules rm ON rm.module_id=pm.id
               JOIN user_roles ur ON ur.role_id=rm.role_id AND ur.user_id=?
              WHERE pm.is_active=1 AND pm.industry_code IN (?, 'COMMON')");
        $mq->execute([$u['id'], $ind]);
    }
    $n = (int)$mq->fetchColumn();
    $n > 0 ? ok("login ke after $n modules milenge")
           : bad('ZERO modules — sign-in works but every page returns 403');

    line();
    line('  Result: on the server side sign-in for this user is FINE.');
    line('  If it still fails in the browser the cause is on the client side —');
    line('  an old session cookie or an old build. Try an incognito window.');
}

line();
line('=== 5. AAM WAJUHAT ===');
inf('a) Was a DIFFERENT business signed in first in this browser?');
inf('   In the old build, login.html itself returned 404 after a retail session.');
inf('   Test: open it in an incognito window. If it works, that is the cause — deploy the new build.');
inf('b) A migration may not have run -> see section 1 above.');
inf('c) seed_industry_modules.php may not have run -> check the COMMON bucket in section 2.');
line();
