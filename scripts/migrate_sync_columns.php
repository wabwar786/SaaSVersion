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
    'employee_profiles','paired_devices','notification_queue','ui_records','devices',
];

$exists = function (string $t) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]); return (bool)$q->fetchColumn();
};
$cols = function (string $t) use ($pdo): array {
    $q = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]); return array_column($q->fetchAll(), 'column_name');
};

$added = 0; $backfilled = 0; $skipped = 0;
foreach ($tables as $t) {
    if (!$exists($t)) { continue; }
    $c = $cols($t);
    if (in_array('updated_at', $c, true)) { $skipped++; continue; }
    try {
        $pdo->exec("ALTER TABLE `$t`
            ADD COLUMN `updated_at` DATETIME(6) NOT NULL
            DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)");
        $added++;
        if (in_array('created_at', $c, true)) {
            $pdo->exec("UPDATE `$t` SET `updated_at`=`created_at` WHERE `created_at` IS NOT NULL");
            $backfilled++;
        }
        // sync ko batao ke is table ki sab rows dobara bhejni hain
        $pdo->prepare("DELETE FROM sync_state WHERE scope IN (?,?)")
            ->execute(['push:'.$t, 'pull:'.$t]);
        echo "  + $t.updated_at\n";
    } catch (\Throwable $e) {
        echo "  ! $t: " . substr($e->getMessage(), 0, 90) . "\n";
    }
}
echo "SYNC_COLUMNS_READY added=$added backfilled=$backfilled already_ok=$skipped\n";
