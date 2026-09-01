<?php
/**
 * migrate_modules.php — V83 ke liye jagah.
 *
 *  1. `reservations.table_id` — booking kis table ki hai (abhi kisi table
 *     se juri hi nahi hoti, is liye POS ko pata nahi chalta)
 *  2. `menu_items.food_cost` — recipe se nikla hua cost, mehfooz
 *  3. POS ki raftaar ke liye do aur index
 *
 * Idempotent. MySQL 8 / MariaDB dono par lowercase alias.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();
$has = function(string $t) use($pdo): bool {
    $q=$pdo->prepare("SELECT COUNT(*) AS n FROM information_schema.tables
                       WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]); return (bool)$q->fetchColumn(); };
$col = function(string $t,string $c) use($pdo): bool {
    $q=$pdo->prepare("SELECT COUNT(*) AS n FROM information_schema.columns
                       WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$t,$c]); return (bool)$q->fetchColumn(); };
$idx = function(string $t,string $i) use($pdo): bool {
    $q=$pdo->prepare("SELECT COUNT(*) AS n FROM information_schema.statistics
                       WHERE table_schema=DATABASE() AND table_name=? AND index_name=?");
    $q->execute([$t,$i]); return (bool)$q->fetchColumn(); };

$added=0;

/* 1. reservation -> table */
foreach (['table_id'=>'CHAR(36) NULL','duration_min'=>'INT NOT NULL DEFAULT 90'] as $c=>$d) {
    if ($has('reservations') && !$col('reservations',$c)) {
        try { $pdo->exec("ALTER TABLE reservations ADD COLUMN `$c` $d");
              echo "  + reservations.$c\n"; $added++; } catch(\Throwable $e){}
    }
}

/* 2. food cost snapshot on menu items */
foreach (['food_cost'=>'DECIMAL(12,4) NOT NULL DEFAULT 0',
          'food_cost_at'=>'DATETIME(6) NULL'] as $c=>$d) {
    if ($has('menu_items') && !$col('menu_items',$c)) {
        try { $pdo->exec("ALTER TABLE menu_items ADD COLUMN `$c` $d");
              echo "  + menu_items.$c\n"; $added++; } catch(\Throwable $e){}
    }
}

/* 3. speed */
foreach ([
    ['reservations','ix_res_when','(site_id, reservation_at)'],
    ['purchase_orders','ix_po_site','(site_id, status, order_date)'],
] as [$t,$n,$c]) {
    if (!$has($t) || $idx($t,$n)) continue;
    try { $pdo->exec("ALTER TABLE `$t` ADD INDEX `$n` $c"); echo "  + index $t.$n\n"; $added++; }
    catch(\Throwable $e){}
}

echo "MODULES_MIGRATION_READY added=$added\n";

// build: V83 build 2026-08-28
