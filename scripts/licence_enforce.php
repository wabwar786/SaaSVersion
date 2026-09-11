<?php
/**
 * LICENCE ENFORCEMENT (offline node)
 *
 * Two jobs, both run on every start-up and by the sync loop:
 *
 *   1. Work out how far past expiry this node is.
 *   2. Once the grace period is over, remove the business data.
 *
 * WHY A GRACE PERIOD AT ALL
 * A licence that lapses on a Friday should not destroy a shop's records
 * on Saturday. The customer gets GRACE_DAYS to renew; the software is
 * locked the whole time (the router sends every page to activate.html),
 * but nothing is deleted. Applying a key at any point during the grace
 * period restores everything untouched.
 *
 * WHAT GETS REMOVED, AND WHAT DOES NOT
 * Sales, orders, stock movements, customers, catalogue — the business
 * data — are cleared. Deliberately kept:
 *   - the tenant/site rows and the sync token, so the node can still be
 *     reactivated instead of reinstalled;
 *   - users, so the owner can still sign in afterwards;
 *   - the purge record itself, so there is an honest trail of what
 *     happened and when.
 *
 * The cloud copy is NOT touched. Whatever synced up is still there, so a
 * renewed customer gets their data back on the next pull.
 *
 *   php scripts/licence_enforce.php            # check and act
 *   php scripts/licence_enforce.php --status   # report only, change nothing
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;
use Aio\Services\Licence;

const GRACE_DAYS = 30;

$statusOnly = (bool)cli_arg('status');

if ((string)cfg('app.role') === 'cloud') {
    echo "LICENCE_SKIP this runs on offline nodes only\n";
    return;
}

$lic = Licence::current();
$expiry = (string)($lic['expiry_date'] ?? '');

if ($expiry === '') { echo "LICENCE_NO_EXPIRY nothing to enforce\n"; return; }

$daysPast = (int)floor((time() - strtotime($expiry . ' 23:59:59')) / 86400);

if ($daysPast < 0) {
    echo "LICENCE_OK expires {$expiry} (" . abs($daysPast) . " days left)\n";
    return;
}

$left = GRACE_DAYS - $daysPast;

if ($left > 0) {
    echo "LICENCE_EXPIRED on {$expiry}; grace period, {$left} day(s) before data is removed\n";
    echo "  Enter an activation key to restore access. Nothing has been deleted.\n";
    return;
}

echo "LICENCE_GRACE_OVER expired {$expiry} ({$daysPast} days ago)\n";
if ($statusOnly) { echo "  --status given: nothing was changed.\n"; return; }

/* Already purged? Do it once, not on every start-up. */
$pdo = DB::pdo();
$marker = dirname(__DIR__) . '/storage/.licence_purged';
if (is_file($marker)) {
    echo "  Data was already removed on " . trim((string)@file_get_contents($marker)) . "\n";
    return;
}

/* Business data. Order matters: children before parents. */
$tables = [
    // restaurant
    'order_items','order_payments','orders','kot_tickets','reservations',
    'stock_adjustment_items','stock_adjustments','goods_receipt_items','goods_receipts',
    'stock_transfers','stock_counts','recipe_items','recipes',
    'menu_items','menu_categories','tables_floors','expenses','wastage',
    // retail
    'rtl_sale_items','rtl_sales','rtl_bill_reprints','rtl_held_bills','rtl_customer_ledger',
    'rtl_batches','rtl_product_barcodes','rtl_product_uom','rtl_products',
    'rtl_categories','rtl_departments','rtl_brands','rtl_counters',
    // shared
    'customers','suppliers','inventory_items','cashier_shifts','ui_records','audit_log',
];

$tid = tenant_id();
$removed = 0; $skipped = 0;
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $t) {
    try {
        $c = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema=DATABASE() AND table_name=?");
        $c->execute([$t]);
        if (!(int)$c->fetchColumn()) { $skipped++; continue; }

        $has = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                               WHERE table_schema=DATABASE() AND table_name=? AND column_name='tenant_id'");
        $has->execute([$t]);
        if ((int)$has->fetchColumn()) {
            $st = $pdo->prepare("DELETE FROM `$t` WHERE tenant_id=?");
            $st->execute([$tid]);
        } else {
            $st = $pdo->query("DELETE FROM `$t`");
        }
        $n = $st->rowCount();
        if ($n) { echo "  cleared {$t}: {$n} rows\n"; $removed += $n; }
    } catch (\Throwable $e) {
        echo "  skipped {$t}: " . $e->getMessage() . "\n";
        $skipped++;
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

@file_put_contents($marker, date('Y-m-d H:i'));

echo "LICENCE_PURGED {$removed} rows removed ({$skipped} tables not present)\n";
echo "  Kept: users, tenant/site and the sync token — a key still reactivates this node.\n";
echo "  The cloud copy is untouched; renewed data comes back on the next sync.\n";
