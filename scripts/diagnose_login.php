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
    (int)$q->fetchColumn() ? ok("$t.$c maujood") : bad("$t.$c GHAIB — `php scripts/migrate_retail.php` chalayein");
}
$q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='rtl_products'");
$q->execute();
(int)$q->fetchColumn() ? ok('rtl_* tables maujood') : bad('rtl_* tables GHAIB — `php scripts/migrate_retail.php`');

line();
line('=== 2. MODULE CATALOG ===');
$rows = $pdo->query("SELECT COALESCE(NULLIF(industry_code,''),'(khali)') ic, COUNT(*) c
                       FROM platform_modules WHERE is_active=1 GROUP BY ic ORDER BY ic")->fetchAll();
$byInd = [];
foreach ($rows as $r) { $byInd[$r['ic']] = (int)$r['c']; inf(sprintf('%-12s %d modules', $r['ic'], $r['c'])); }

if (!isset($byInd['COMMON'])) {
    bad('COMMON bucket hai hi nahi — `php scripts/seed_industry_modules.php` chalayein.');
    bad('Iske baghair har user ko sirf apne vertical ke modules milte hain, settings/reports tak nahi.');
} else {
    ok('COMMON bucket theek hai');
}
$rest = (int)$pdo->query("SELECT COUNT(*) FROM platform_modules
                           WHERE is_active=1 AND industry_code IN('RESTAURANT','COMMON')")->fetchColumn();
$ret  = (int)$pdo->query("SELECT COUNT(*) FROM platform_modules
                           WHERE is_active=1 AND industry_code IN('RETAIL','COMMON')")->fetchColumn();
inf("restaurant tenant ko dikhne chahiye: $rest modules");
inf("retail tenant ko dikhne chahiye:     $ret modules");
if ($rest < 30) bad('Restaurant ke modules bahut kam hain — seed dobara chalayein.');

line();
line('=== 3. BUSINESSES ===');
$sql = "SELECT id,name,slug,COALESCE(NULLIF(industry_code,''),'(khali)') ic,
               COALESCE(NULLIF(region_profile,''),'-') rp, status
          FROM tenants" . ($slug ? " WHERE slug=?" : "") . " ORDER BY created_at DESC LIMIT 20";
$q = $pdo->prepare($sql);
$slug ? $q->execute([$slug]) : $q->execute();
$tenants = $q->fetchAll();
if (!$tenants) { bad('Koi business nahi mila' . ($slug ? " (slug: $slug)" : '')); exit(1); }

foreach ($tenants as $t) {
    line();
    line(sprintf('  %s  [%s / %s]  %s', $t['name'], $t['ic'], $t['rp'], $t['status']));
    inf('slug: ' . $t['slug'] . '   login: /login.html?b=' . $t['slug']);

    if ($t['ic'] === '(khali)') {
        bad('industry_code KHALI hai — system isay RESTAURANT maanega. Theek karne ke liye:');
        inf("      UPDATE tenants SET industry_code='RESTAURANT' WHERE id='" . $t['id'] . "';");
    } elseif (!in_array($t['ic'], ['RESTAURANT','RETAIL'], true)) {
        bad('industry_code na-maloom: ' . $t['ic'] . ' — is tenant ko sirf COMMON modules milenge.');
    }
    if ($t['status'] !== 'ACTIVE') bad('Status ACTIVE nahi — login isi wajah se ruk sakta hai.');

    /* users */
    $uq = $pdo->prepare("SELECT id,email,username,full_name,status,is_tenant_admin,
                                (password_hash IS NULL OR password_hash='') AS nopass, deleted_at
                           FROM users WHERE tenant_id=?" . ($mail ? " AND (email=? OR username=?)" : "") . " LIMIT 10");
    $mail ? $uq->execute([$t['id'], $mail, $mail]) : $uq->execute([$t['id']]);
    $users = $uq->fetchAll();
    if (!$users) { bad('Is business ka koi user nahi — login mumkin hi nahi.'); continue; }

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
            $mq->execute([strtoupper($t['ic'] === '(khali)' ? 'RESTAURANT' : $t['ic'])]);
            $n = (int)$mq->fetchColumn();
        } else {
            $mq = $pdo->prepare(
                "SELECT COUNT(DISTINCT pm.module_key)
                   FROM platform_modules pm
                   JOIN role_modules rm ON rm.module_id=pm.id
                   JOIN user_roles ur ON ur.role_id=rm.role_id AND ur.user_id=?
                  WHERE pm.is_active=1 AND pm.industry_code IN (?, 'COMMON')");
            $mq->execute([$u['id'], strtoupper($t['ic'] === '(khali)' ? 'RESTAURANT' : $t['ic'])]);
            $n = (int)$mq->fetchColumn();
        }
        inf('   modules: ' . $n . ($n === 0 ? '  << ZERO — login to hoga magar sidebar khali aur har page 403' : ''));
    }
}

line();
line('=== 4. AAM WAJUHAT ===');
inf('a) Browser mein pehle kisi DOOSRE business se login tha?');
inf('   Purane build mein retail session ke baad login.html khud 404 deta tha.');
inf('   Test: incognito window mein kholein. Chal jaye to yehi wajah hai — naya build deploy karein.');
inf('b) Migration na chali ho -> upar section 1 dekh lein.');
inf('c) seed_industry_modules.php na chali ho -> section 2 mein COMMON bucket dekh lein.');
line();
