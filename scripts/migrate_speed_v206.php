<?php
/**
 * V206 — SALE POINT KI RAFTAR: 2 saal ke data ke baad bhi.
 *
 * Test (3 lakh bill, shop PC jaisa server): bill 44 ms se 425 ms ho gaya,
 * aur account preview / dashboard khulte hi counters 3.7 second ruk jate.
 * Wajah: account aur "aaj" ki queries ko closed_at / paid_at par index
 * nahi milta tha, MySQL poori history parhta tha.
 *
 * Yeh chaar index bas. InnoDB par ALGORITHM=INPLACE, LOCK=NONE — bante waqt
 * bill rukte nahi. Cloud par 'background' stage mein (Apache ke baad),
 * taake bari table par healthcheck timeout na ho. Idempotent.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();
$has = function (string $t, string $c) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$t, $c]); return (bool)$q->fetchColumn();
};
$idx = function (string $t, string $i) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?");
    $q->execute([$t, $i]); return (bool)$q->fetchColumn();
};
$added = 0; $failed = 0;
foreach ([
    ['orders',   'ix_ord_site_closed', ['site_id', 'closed_at']],
    ['payments', 'ix_pay_site_paid',   ['site_id', 'paid_at']],
    /* khule bill (har bill par ginti, tablet ki tables, dashboard) */
    ['orders',   'ix_ord_site_open',   ['site_id', 'order_status']],
    /* kitchen / bill parchi ka nishan (tablet ki tables, KOT "counter ne chhap di") */
    ['printer_jobs', 'ix_pj_ref',      ['reference_id', 'job_type']],
] as [$t, $name, $cols]) {
    if ($idx($t, $name)) { echo "  = index $t.$name\n"; continue; }
    $ok = true; foreach ($cols as $c) if (!$has($t, $c)) $ok = false;
    if (!$ok) continue;
    $list = '(`' . implode('`,`', $cols) . '`)';
    try {
        try { $pdo->exec("ALTER TABLE `$t` ADD INDEX `$name` $list, ALGORITHM=INPLACE, LOCK=NONE"); }
        catch (\Throwable $e) { $pdo->exec("ALTER TABLE `$t` ADD INDEX `$name` $list"); }
        echo "  + index $t.$name\n"; $added++;
    } catch (\Throwable $e) { echo "  ! index $t.$name: " . $e->getMessage() . "\n"; $failed++; }
}
echo "migrate_speed_v206: added=$added failed=$failed\n";
exit($failed ? 1 : 0);

// build: V206 build 2026-11-18
