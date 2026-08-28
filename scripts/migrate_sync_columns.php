<?php
/**
 * migrate_sync_columns.php — SYNC KA SABSE BARA MASLA.
 *
 * Sync engine watermark ke liye `updated_at` (ya `created_at`) column use
 * karta hai. Jis table mein yeh column NahI hota, wo table **khamoshi se
 * skip** ho jati thi — kabhi sync hoti hi nahi. `payments`,
 * `stock_transactions`, `kitchen_tickets`, `user_roles` waghera isi wajah
 * se cloud tak nahi pohanchte the, aur local vs live figures alag rehte the.
 *
 * Yeh migration har syncable table par `updated_at` add karti hai
 * (auto-update ke saath) aur purani rows ko `created_at` se backfill karti
 * hai. Idempotent — baar baar chal sakti hai.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

$tables = [
    // sales
    'orders','order_items','payments','order_payments','order_item_voids',
    'kitchen_tickets','kitchen_ticket_items','qr_orders','qr_sessions',
    'cashier_shifts','shift_cash_movements','shift_handovers','fiscal_invoices',
    // catalogue
    'menu_categories','menu_items','menu_item_variants','menu_category_printer_routes',
    'recipes','recipe_ingredients',
    // inventory
    'inventory_categories','inventory_items','stock_transactions','stock_transaction_lines',
    'stock_balances','stock_adjustments','stock_movements','stock_locations','units',
    'goods_receipts','goods_receipt_items','purchase_orders','purchase_order_items',
    // people & setup
    'customers','customer_addresses','suppliers','supplier_items',
    'expenses','expense_categories','reservations','riders','delivery_orders',
    'promotions','printers','floors','dining_tables','payment_methods',
    'users','user_roles','roles','role_modules','user_form_permissions',
    /* V62.2 — inke baghair sync inhen KHAMOSHI se skip karti thi. */
    'user_module_access','user_site_access',
    /* V79 — module ids dono taraf ek jaise hone chahiyen, warna
       permissions ka JOIN khali lautta hai. */
    'platform_modules',
    'employee_profiles','paired_devices','notification_queue','ui_records','devices',
];

$exists = function (string $t) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]); return (bool)$q->fetchColumn();
};
$cols = function (string $t) use ($pdo): array {
    $q = $pdo->prepare("SELECT column_name AS column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]); return array_column($q->fetchAll(), 'column_name');
};

$added = 0; $backfilled = 0; $skipped = 0;

/* V79 — YEH BUG THA.
   Pehle yahan sirf `updated_at` dekha jata tha:

       if (in_array('updated_at', $c)) { $skipped++; continue; }

   Yani jis table par `updated_at` PEHLE SE tha (users,
   user_module_access waghera), us par `row_version` aur
   `origin_node_id` KABHI nahi bante the — aur sync inhi do columns se
   faisla karti hai ke kaunsi row nayi hai. Nateeja: permissions ka sync
   khamoshi se kaam hi nahi karta tha, halanke V62.2 ka poora maqsad
   wahi tha.

   Ab teenon columns alag alag check hote hain. Yeh sirf asli DB par
   chala kar pakra gaya. */
$want = [
    'updated_at'     => "DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)",
    'row_version'    => "BIGINT NOT NULL DEFAULT 1",
    'origin_node_id' => "VARCHAR(64) NULL",
];

foreach ($tables as $t) {
    if (!$exists($t)) { continue; }
    $c = $cols($t);
    $touched = false;

    foreach ($want as $name => $def) {
        if (in_array($name, $c, true)) continue;
        try {
            $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$name` $def");
            $added++; $touched = true;
            echo "  + $t.$name\n";

            if ($name === 'updated_at' && in_array('created_at', $c, true)) {
                $pdo->exec("UPDATE `$t` SET `updated_at`=`created_at` WHERE `created_at` IS NOT NULL");
                $backfilled++;
            }
        } catch (\Throwable $e) {
            echo "  ! $t.$name: " . substr($e->getMessage(), 0, 80) . "\n";
        }
    }

    if ($touched) {
        /* Sync ko batao ke is table ki sab rows dobara bhejni hain. */
        try {
            $pdo->prepare("DELETE FROM sync_state WHERE scope IN (?,?)")
                ->execute(['push:'.$t, 'pull:'.$t]);
        } catch (\Throwable $e) {}
    } else {
        $skipped++;
    }
}

echo "SYNC_COLUMNS_READY added=$added backfilled=$backfilled already_ok=$skipped\n";
