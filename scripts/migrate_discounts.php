<?php
/**
 * migrate_discounts.php — DISCOUNT KA PERCENTAGE BHI YAAD RAHE.  (V157)
 *
 * DO CHHOTI COLUMNS, DO ALAG MASLE:
 *
 * 1) orders.discount_pct
 *    Bill par discount do tarah lagta hai: flat raqam, ya percent.
 *    Magar `orders` mein sirf NATIJA (`discount_amount`) mehfooz hota
 *    tha. Bill hold kar ke wapas kholne par cashier ko 10% wapas nahi
 *    milta tha — us ki jagah usi raqam ka flat discount aata. Items
 *    badal jayen to wo raqam ghalat ho jati (10% ka matlab hi yeh hai
 *    ke wo bill ke saath badle).
 *    Ab percent bhi row par likha jata hai, to hold/unhold par "10%"
 *    hi wapas aata hai.
 *
 * 2) customers.discount_pct
 *    Kuch customers ka tay-shuda discount hota hai. Pehle us ke liye
 *    koi jagah hi nahi thi — cashier ko har bill par haath se lagana
 *    parta tha, aur bhool jana aam baat thi. Ab customer par likha
 *    jata hai aur POS us ko chunte hi khud lag jata hai (cashier
 *    phir bhi badal sakta hai).
 *
 * Donon NULL-safe hain, default 0, aur purani rows ko koi farq nahi
 * parta. Script idempotent hai — jitni dafa chalao.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

function col_exists(\PDO $p, string $table, string $col): bool {
    $q = $p->prepare("SELECT COUNT(*) FROM information_schema.columns
                       WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$table, $col]);
    return (bool)$q->fetchColumn();
}
function table_exists(\PDO $p, string $table): bool {
    $q = $p->prepare("SELECT COUNT(*) FROM information_schema.tables
                       WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$table]);
    return (bool)$q->fetchColumn();
}

$added = []; $skipped = 0;

foreach ([
    ['orders',    'discount_pct', "DECIMAL(6,3) NOT NULL DEFAULT 0"],
    ['customers', 'discount_pct', "DECIMAL(6,3) NOT NULL DEFAULT 0"],
] as [$table, $col, $def]) {
    if (!table_exists($pdo, $table)) { continue; }
    if (col_exists($pdo, $table, $col)) { $skipped++; continue; }
    try {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        $added[] = "$table.$col";
        echo "  + $table.$col\n";
    } catch (\Throwable $e) {
        /* Deploy kabhi nahi rokte: discount ka percent na bhi bane to
           bill banta rehna chahiye. Sirf bata do. */
        echo "  ! $table.$col: ".substr($e->getMessage(), 0, 100)."\n";
    }
}

echo 'DISCOUNTS_OK added='.count($added).' skipped='.$skipped."\n";

// build: V157 build 2026-10-19
