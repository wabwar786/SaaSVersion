<?php
/**
 * migrate_delivery_charge.php — HOME DELIVERY KA CHARGE.  (V171)
 *
 * Ek column: `orders.delivery_charge`.
 *
 * Pehle delivery ka kiraya rakhne ki koi jagah nahi thi. Jo restaurant
 * delivery par 150 rupay leta tha, usay do mein se ek buri soorat
 * chunni parti thi:
 *
 *   - khane ki keemat mein chhupa do  -> item-wise report jhooti,
 *     aur FBR ko bhi wohi ghalat raqam jati
 *   - service charge mein daal do     -> har bill par lagta hai, sirf
 *     delivery par nahi
 *
 * Ab delivery charge apni jagah par likha jata hai. Yeh TAX SE BAHAR
 * hai (malik ka faisla): tax sirf khane par banta hai, charge us ke
 * baad jurta hai.
 *
 *     taxable = subtotal - discount + service charge
 *     grand   = taxable (+ tax agar exclusive) + delivery charge
 *
 * NULL-safe, default 0, purani rows par koi asar nahi — jo bill pehle
 * se bane hue hain un ka charge 0 rehta hai aur un ka total nahi
 * badalta. Script idempotent hai, jitni dafa chalao.
 *
 * SYNC: `Sync::columns()` information_schema se columns parhta hai, to
 * yeh column khud ba khud safar karne lagta hai — magar SIRF jab DONO
 * taraf mojood ho. Is liye CLOUD PEHLE deploy hota hai; cloud par
 * column na ho to node ki row chup chaap chhoot jati hai.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

function dc_table_exists(\PDO $p, string $table): bool {
    $q = $p->prepare("SELECT COUNT(*) FROM information_schema.tables
                       WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$table]);
    return (bool)$q->fetchColumn();
}
function dc_col_exists(\PDO $p, string $table, string $col): bool {
    $q = $p->prepare("SELECT COUNT(*) FROM information_schema.columns
                       WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$table, $col]);
    return (bool)$q->fetchColumn();
}

$added = []; $skipped = 0;

foreach ([
    ['orders', 'delivery_charge', "DECIMAL(18,4) NOT NULL DEFAULT 0"],
] as [$table, $col, $def]) {
    if (!dc_table_exists($pdo, $table)) { continue; }
    if (dc_col_exists($pdo, $table, $col)) { $skipped++; continue; }
    try {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def AFTER `service_charge`");
        $added[] = "$table.$col";
        echo "  + $table.$col\n";
    } catch (\Throwable $e) {
        /* Deploy kabhi nahi rokte — delivery charge na bhi bane to bill
           banta rehna chahiye. Sirf bata do. */
        echo "  ! $table.$col: ".substr($e->getMessage(), 0, 120)."\n";
    }
}

echo 'DELIVERY_CHARGE_OK added='.count($added).' skipped='.$skipped."\n";

// build: V171 build 2026-10-27
