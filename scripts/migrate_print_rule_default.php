<?php
/**
 * migrate_print_rule_default.php
 *
 * Masla:
 *   "Create business" par ->  SQLSTATE[HY000] 1364
 *                             Field 'print_rule' doesn't have a default value
 *
 * Wajah:
 *   `docs/02_local_mysql_schema.sql` mein yeh column
 *       print_rule VARCHAR(40) NOT NULL DEFAULT 'PENDING_QTY_ONLY'
 *   hai, magar purane databases mein wo BINA default ke bana tha.
 *   `install_schema.php` `CREATE TABLE IF NOT EXISTS` use karta hai, is
 *   liye table pehle se maujood ho to us ka column kabhi update nahi
 *   hota — schema file theek dikhti rehti hai aur live DB purani rehti hai.
 *
 * Yeh migration us column par default laga deti hai. Idempotent.
 * (Code side par har INSERT ab print_rule explicit bhejta hai, is liye
 *  yeh sirf mazeed hifazat ke liye hai — kisi purane build ya kisi aur
 *  raste se INSERT aaye to bhi na toote.)
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

/* MySQL 8 information_schema UPPERCASE deta hai, MariaDB lowercase —
   explicit lowercase alias. */
$q = $pdo->prepare(
    "SELECT column_name AS c, column_default AS d, is_nullable AS n, column_type AS t
       FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name   = 'menu_category_printer_routes'
        AND column_name  = 'print_rule'");
$q->execute();
$col = $q->fetch();

if (!$col) {
    echo "PRINT_RULE_SKIPPED column/table maujood nahi\n";
    return;
}

if ($col['d'] !== null && $col['d'] !== '') {
    echo "PRINT_RULE_ALREADY_OK default='{$col['d']}'\n";
    return;
}

try {
    /* Pehle khali rows bhar do, warna NOT NULL par ALTER atak sakta hai. */
    $pdo->exec("UPDATE menu_category_printer_routes
                   SET print_rule='PENDING_QTY_ONLY'
                 WHERE print_rule IS NULL OR print_rule=''");

    $pdo->exec("ALTER TABLE menu_category_printer_routes
                MODIFY COLUMN print_rule VARCHAR(40) NOT NULL DEFAULT 'PENDING_QTY_ONLY'");

    echo "PRINT_RULE_FIXED default set to 'PENDING_QTY_ONLY'\n";
} catch (\Throwable $e) {
    echo "PRINT_RULE_FAILED " . substr($e->getMessage(), 0, 140) . "\n";
}

// build: V62.4 build 2026-08-26
