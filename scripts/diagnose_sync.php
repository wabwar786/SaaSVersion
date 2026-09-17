<?php
/**
 * WHY DID THIS TABLE NOT COME DOWN FROM THE CLOUD?
 *
 * Run this ON THE BRANCH COMPUTER (the offline node).
 *
 *   php scripts/diagnose_sync.php
 *   php scripts/diagnose_sync.php menu_items     (one table, in detail)
 *
 * Sync writes the result of every table into `sync_state`. So when one
 * table arrives and another does not, the node already knows why — this
 * just prints it. Read-only; changes nothing.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;

$only = '';
foreach (array_slice($argv, 1) as $a) { if (!str_starts_with($a, '--')) { $only = $a; break; } }

$pdo  = DB::pdo();
$role = (string)cfg('app.role');

echo "\n=== This computer ===\n";
echo "  role      : {$role}" . ($role === 'cloud' ? '   (run this on the BRANCH, not the cloud)' : '') . "\n";
echo "  database  : " . (string)cfg('db.database') . "\n";
echo "  tenant_id : " . (string)cfg('app.tenant_id') . "\n";
echo "  site_id   : " . (string)cfg('app.site_id') . "\n";

/* Node ka site_id cloud ke site se milta hai? Yehi wo cheez hai jo sab
   se zyada chupke se kaam kharab karti hai: cloud site ke hisaab se rows
   deta hai, aur agar node kisi aur site ka id bhej raha ho to jawab
   khali aata hai — koi error nahi, bas kuch nahi. */
try {
    $s = $pdo->prepare("SELECT id, name FROM sites WHERE tenant_id=?");
    $s->execute([tenant_id()]);
    $sites = $s->fetchAll(\PDO::FETCH_ASSOC);
    echo "  sites here: " . count($sites) . "\n";
    foreach ($sites as $x) {
        $mark = ((string)$x['id'] === (string)site_id()) ? '  <= configured' : '';
        echo "     " . substr((string)$x['id'], 0, 8) . "  " . $x['name'] . $mark . "\n";
    }
    if ($sites && !in_array((string)site_id(), array_column($sites, 'id'), true)) {
        echo "  WARNING: the configured site_id is not one of these. Sync will\n";
        echo "           return nothing for site-scoped tables (menu_items is one).\n";
    }
} catch (\Throwable $e) { echo "  sites: " . $e->getMessage() . "\n"; }

/* ---------- har pull table ka haal ---------- */
$tables = (array)(cfg('sync.pull_tables') ?: []);
if ($only !== '') $tables = [$only];
if (!$tables) { echo "\nNo pull tables configured.\n"; return; }

echo "\n=== What sync did, table by table ===\n";
printf("  %-26s %-8s %-9s %-20s %s\n", 'table', 'rows here', 'status', 'last watermark', 'last error');

$bad = [];
foreach ($tables as $t) {
    $cnt = '-';
    try {
        $q = $pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '', $t) . "`");
        $cnt = (string)$q->fetchColumn();
    } catch (\Throwable $e) { $cnt = 'no table'; }

    $st = ['value' => '', 'status' => '', 'note' => '', 'rows' => ''];
    try {
        $w = $pdo->prepare("SELECT watermark AS value, last_status AS status,
                                   last_error AS note, rows_synced, last_run_at
                              FROM sync_state WHERE scope=?");
        $w->execute(["pull:$t"]);
        $r = $w->fetch(\PDO::FETCH_ASSOC);
        if ($r) $st = $r + $st;
    } catch (\Throwable $e) { }

    $status = (string)($st['status'] ?: '-');
    printf("  %-26s %-9s %-9s %-20s %s\n",
        $t, $cnt, $status,
        substr((string)($st['value'] ?: '-'), 0, 19),
        substr((string)($st['note'] ?? ''), 0, 60));

    if ($status === 'ERROR' || $cnt === 'no table') $bad[] = $t;
}

/* ---------- saaf nateeja ---------- */
echo "\n=== Reading ===\n";
if ($bad) {
    echo "  These tables failed: " . implode(', ', $bad) . "\n";
    echo "  The error text above is the reason. A missing column on this\n";
    echo "  computer is the usual one — run the migrations and sync again:\n";
    echo "     php scripts/install_schema.php\n";
} else {
    echo "  No table reported an error.\n";
    echo "  If a table is still empty here but full on the cloud, then the\n";
    echo "  cloud returned nothing for it. That means one of:\n";
    echo "    - the site_id above does not match the site the data lives in\n";
    echo "    - the watermark is already ahead (rows changed before this node\n";
    echo "      was set up, so the cloud sees nothing 'new' to send)\n";
    echo "\n";
    echo "  For the second one, clear that table's watermark and pull again:\n";
    echo "     php scripts/diagnose_sync.php --reset=menu_items\n";
}

/* ---------- watermark reset (jaan boojh kar alag flag ke peeche) ---------- */
foreach (array_slice($argv, 1) as $a) {
    if (!str_starts_with($a, '--reset=')) continue;
    $t = substr($a, 8);
    /* Watermark ko sifar par le jao: agli pull us table ka POORA data
       dobara maangegi. Data mitta nahi — sirf "mujhe sab kuch phir se
       bhejo" kehne ka tareeqa hai. */
    $pdo->prepare("UPDATE sync_state SET watermark='1970-01-01 00:00:00', last_status='RESET'
                    WHERE scope=?")->execute(["pull:$t"]);
    echo "\n  Watermark for {$t} cleared. Run a sync now:\n";
    echo "     php scripts/sync_worker.php\n";
}
echo "\n";
