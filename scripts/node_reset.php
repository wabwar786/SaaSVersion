<?php
/**
 * node_reset.php — BRANCH COMPUTER ko cloud ke barabar lane ka tool.
 *
 * Masla jo is script ne hal kiya:
 *   Cloud par Platform Console se reset/purge kar dene ke baad bhi branch
 *   computer par purana data zinda rehta tha, aur node par usay saaf karne
 *   ka koi tareeqa hi nahi tha (Platform Console sirf cloud par hai).
 *
 * V62 ke baad cloud ka reset khud tombstone bhej deta hai, is liye aam
 * halat mein yeh script chalane ki zaroorat NahI. Yeh un cases ke liye hai:
 *   • node purane build par tha jab cloud par reset hua (tombstone nahi mila)
 *   • pure-offline installation jahan cloud hai hi nahi
 *   • node ka data cloud se aage nikal gaya aur usay dobara seed karna hai
 *
 * Chalane ka tareeqa (node ki root directory se):
 *   php scripts/node_reset.php --what=txn   --confirm="Royal Grill"
 *   php scripts/node_reset.php --what=all   --confirm="Royal Grill"
 *   php scripts/node_reset.php --what=txn   --before=2026-01-01 --confirm="Royal Grill"
 *
 *   --what=txn  : sirf transactions (menu/items/users/settings mehfooz)
 *   --what=all  : transactions + master data (admin login bacha rehta hai)
 *   --dry-run   : sirf ginti dikhata hai, kuch delete nahi karta
 *
 * DHYAN: yeh sirf LOCAL node par chalta hai. Cloud par galti se chalne se
 * rokne ke liye app.role check lagi hai.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use Aio\DB;
use Aio\Services\AdminData;

/* ---------------- args ---------------- */
/* V84 — sealed build mein $argv maujood nahi hota. */
$args = cli_args();
$what    = strtolower((string)($args['what'] ?? 'txn'));
$confirm = (string)($args['confirm'] ?? '');
$before  = (string)($args['before'] ?? '');
$dry     = isset($args['dry-run']);

if (!in_array($what, ['txn', 'all'], true)) {
    exit("ERROR: --what=txn ya --what=all\n");
}

/* ---------------- safety ---------------- */
if ((string)cfg('app.role') === 'cloud') {
    exit("ERROR: yeh script sirf branch computer (offline node) par chalti hai.\n"
       . "Cloud par Platform Console -> Backup & Reset use karein.\n");
}

$pdo = DB::pdo();
$tid = tenant_id();

$q = $pdo->prepare("SELECT name FROM tenants WHERE id=? LIMIT 1");
$q->execute([$tid]);
$name = (string)($q->fetchColumn() ?: '');
if ($name === '') exit("ERROR: is node par koi business configured nahi hai.\n");

if (!$dry && $confirm !== $name) {
    exit("ERROR: confirm match nahi hua.\n"
       . "Poori command yeh honi chahiye:\n\n"
       . "  php scripts/node_reset.php --what=$what --confirm=\"$name\"\n\n");
}

/* ---------------- tables ---------------- */
$master = AdminData::MASTER_TABLES;
$keepAlways = [
    'users', 'user_roles', 'roles', 'role_modules', 'user_form_permissions',
    'user_module_access', 'user_site_access', 'employee_profiles',
    'site_settings', 'site_modules', 'tax_profiles', 'fiscal_settings',
    'document_sequences', 'modifier_groups', 'modifier_options', 'accounts',
];

$tables = [];
foreach (AdminData::wipeableTables() as $t) {
    $n = $t['name'];
    if (in_array($n, ['sync_tombstones', 'sync_tombstones_applied', 'deletion_log'], true)) continue;
    if ($what === 'txn' && in_array($n, $master, true)) continue;
    if ($what === 'txn' && in_array($n, $keepAlways, true)) continue;
    if ($what === 'all' && in_array($n, $keepAlways, true)) continue;
    $tables[] = $t;
}

echo "Node reset — business: $name\n";
echo "Mode: $what".($before !== '' ? "  (before $before)" : '').($dry ? '   [DRY RUN]' : '')."\n";
echo str_repeat('-', 52)."\n";

$siteIds = [];
try {
    $s = $pdo->prepare("SELECT id FROM sites WHERE tenant_id=?");
    $s->execute([$tid]);
    $siteIds = array_column($s->fetchAll(), 'id');
} catch (\Throwable $e) {}

$total = 0;
if (!$dry) $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

foreach ($tables as $t) {
    $n = $t['name'];
    $cols = AdminData::cols($n);
    if (!$cols) continue;

    $where = ''; $args2 = [];
    if (in_array('tenant_id', $cols, true)) { $where = 'tenant_id = ?'; $args2[] = $tid; }
    elseif (in_array('site_id', $cols, true) && $siteIds) {
        $where = 'site_id IN ('.implode(',', array_fill(0, count($siteIds), '?')).')';
        $args2 = $siteIds;
    } else { continue; }

    if ($before !== '') {
        $dc = null;
        foreach (['business_date', 'created_at', 'opened_at', 'paid_at'] as $c) {
            if (in_array($c, $cols, true)) { $dc = $c; break; }
        }
        if ($dc === null) continue;
        $where .= " AND `$dc` < ?"; $args2[] = $before;
    }

    try {
        if ($dry) {
            $c = $pdo->prepare("SELECT COUNT(*) FROM `$n` WHERE $where");
            $c->execute($args2);
            $cnt = (int)$c->fetchColumn();
        } else {
            $d = $pdo->prepare("DELETE FROM `$n` WHERE $where");
            $d->execute($args2);
            $cnt = $d->rowCount();
        }
        if ($cnt > 0) { printf("  %-34s %7d\n", $n, $cnt); $total += $cnt; }
    } catch (\Throwable $e) {
        printf("  %-34s   ! %s\n", $n, substr($e->getMessage(), 0, 60));
    }
}

if (!$dry) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    /* Watermarks reset — warna node cloud se dobara data laata hi nahi
       (uska pull watermark aage hota hai) aur khali baitha rehta hai. */
    try { $pdo->exec("DELETE FROM sync_state WHERE scope LIKE 'push:%' OR scope LIKE 'pull:%'"); }
    catch (\Throwable $e) {}
    echo str_repeat('-', 52)."\n";
    echo "NODE_RESET_DONE rows=$total\n";
    echo "Ab dashboard par 'Sync now' dabayein — cloud se master data wapas aa jayega.\n";
} else {
    echo str_repeat('-', 52)."\n";
    echo "DRY RUN — kuch delete nahi hua. rows_that_would_go=$total\n";
}

// build: V62 build 2026-08-26
