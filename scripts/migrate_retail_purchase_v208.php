<?php
/**
 * migrate_retail_purchase_v208.php — RETAIL PURCHASE ENTRY.   (V208)
 *
 * Adds the Purchase Entry columns:
 *   rtl_docs       entry_kind, bill_date, pay_mode, adv_tax, calc_total,
 *                  bill_discount, bill_gst, bill_subtotal, bill_adv, bill_total,
 *                  approved_by/at, verified_by/at
 *   rtl_doc_lines  barcode, rate, disc1_pct/amt, gst_pct/amt, disc2_pct/amt,
 *                  adv_pct/amt, net_amt, prev_cost, mkt_price, sale_price,
 *                  old_sale_price, calc_modes
 *   suppliers      rtl_strn, rtl_filer, rtl_adv_pct, rtl_disc_pct (nullable;
 *                  no restaurant code reads them)
 *
 * Idempotent: only missing columns are added. No data is changed.
 * The same check also runs on first use of the Purchase Entry screen.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();
$t = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='rtl_docs'");
$t->execute();
if (!(int)$t->fetchColumn()) { echo "RETAIL_PURCHASE_V208_SKIP rtl_docs not present\n"; return; }
$r = \Aio\Services\RetailPurchase::ensureSchema($pdo);
echo "RETAIL_PURCHASE_V208_OK added=".$r['added']."\n";

// build: V208 build 2026-11-22
