<?php
/**
 * RETAIL vertical — schema.
 *
 * USOOL (restaurant se seekha hua):
 *   1. Restaurant ki koi table RENAME nahi hoti. Naya vertical apni
 *      `rtl_` tables laata hai; jo cheezein pehle se common hain
 *      (customers, suppliers, expenses, payments, users, roles,
 *      cashier_shifts, audit, sync) unhein dobara NAHI banaya jata.
 *   2. Har rtl_ table mein tenant_id + site_id + updated_at + deleted_at
 *      hai. Sync watermark-based hai, is liye `updated_at` LAZMI hai —
 *      warna row kabhi cloud tak nahi pohanchti.
 *   3. Idempotent: dobara chalane par kuch nahi tootta.
 *
 * Chalane ka tareeqa:  php scripts/migrate_retail.php
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;

$pdo = DB::pdo();

function rcol(PDO $p, string $t, string $c): bool {
    $q = $p->prepare("SELECT COUNT(*) FROM information_schema.columns
                       WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$t, $c]);
    return (bool)$q->fetchColumn();
}
function rtab(PDO $p, string $t): bool {
    $q = $p->prepare("SELECT COUNT(*) FROM information_schema.tables
                       WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]);
    return (bool)$q->fetchColumn();
}

$added = [];

/* ============================================================
   1. TENANTS — region profile (PK / UK / US)
   Poora POS isi se badalta hai: currency, tax inclusive/exclusive,
   barcode standard, weight unit, credit ka naam.
   ============================================================ */
if (!rcol($pdo, 'tenants', 'region_profile')) {
    $pdo->exec("ALTER TABLE tenants ADD COLUMN region_profile VARCHAR(10) NOT NULL DEFAULT 'PK'");
    $added[] = 'tenants.region_profile';
}

/* ============================================================
   2. UNITS — multi-UOM conversion.
   Purani `units` table mein sirf code/name/type tha. Carton -> piece
   ka hisaab kahin tha hi nahi, is liye purchase aur sale ek doosre se
   nahi milte the.
   ============================================================ */
foreach ([
    ['tenant_id',         "CHAR(36) NULL"],
    ['base_unit_id',      "CHAR(36) NULL"],
    ['conversion_factor', "DECIMAL(18,6) NOT NULL DEFAULT 1"],
    ['updated_at',        "DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)"],
    ['deleted_at',        "DATETIME(6) NULL"],
] as [$c, $def]) {
    if (!rcol($pdo, 'units', $c)) { $pdo->exec("ALTER TABLE units ADD COLUMN $c $def"); $added[] = "units.$c"; }
}

/* ============================================================
   3. rtl_* TABLES
   ============================================================ */
$SYNC = "created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
         updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
         deleted_at DATETIME(6) NULL,
         row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
         origin_node_id CHAR(36) NULL";
$ENG = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$tables = [

'rtl_departments' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(30) NULL,
  sort_order INT NOT NULL DEFAULT 99,
  $SYNC,
  KEY ix_rd_tenant (tenant_id, deleted_at)",

'rtl_categories' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  department_id CHAR(36) NULL,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 99,
  $SYNC,
  KEY ix_rc_tenant (tenant_id, deleted_at),
  KEY ix_rc_dept (department_id)",

'rtl_brands' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  name VARCHAR(120) NOT NULL,
  $SYNC,
  KEY ix_rb_tenant (tenant_id, deleted_at)",

/* Product = supermarket ka dil. Barcode alag table mein hai kyunke
   ek product ke kai barcode hote hain (purana stock, imported pack). */
'rtl_products' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  sku VARCHAR(60) NOT NULL,
  name VARCHAR(200) NOT NULL,
  department_id CHAR(36) NULL,
  category_id CHAR(36) NULL,
  brand_id CHAR(36) NULL,
  base_unit_id CHAR(36) NULL,
  tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
  cost_price DECIMAL(14,4) NOT NULL DEFAULT 0,
  retail_price DECIMAL(14,4) NOT NULL DEFAULT 0,
  wholesale_price DECIMAL(14,4) NOT NULL DEFAULT 0,
  mrp DECIMAL(14,4) NOT NULL DEFAULT 0,
  stock_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  min_stock DECIMAL(18,3) NOT NULL DEFAULT 0,
  max_stock DECIMAL(18,3) NOT NULL DEFAULT 0,
  is_scale_item TINYINT(1) NOT NULL DEFAULT 0,
  plu_code VARCHAR(20) NULL,
  track_batch TINYINT(1) NOT NULL DEFAULT 0,
  shelf_life_days INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'Active',
  $SYNC,
  UNIQUE KEY uq_rp_sku (tenant_id, sku),
  KEY ix_rp_tenant (tenant_id, deleted_at),
  KEY ix_rp_plu (tenant_id, plu_code),
  KEY ix_rp_name (tenant_id, name)",

'rtl_product_barcodes' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  product_id CHAR(36) NOT NULL,
  barcode VARCHAR(64) NOT NULL,
  $SYNC,
  UNIQUE KEY uq_rpb (tenant_id, barcode),
  KEY ix_rpb_product (product_id)",

/* Pack sizes: 1 carton = 24 pcs. Stock hamesha base unit mein girta hai. */
'rtl_product_uom' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  product_id CHAR(36) NOT NULL,
  unit_id CHAR(36) NULL,
  barcode VARCHAR(64) NULL,
  factor DECIMAL(18,6) NOT NULL DEFAULT 1,
  cost_price DECIMAL(14,4) NOT NULL DEFAULT 0,
  retail_price DECIMAL(14,4) NOT NULL DEFAULT 0,
  is_default_purchase TINYINT(1) NOT NULL DEFAULT 0,
  $SYNC,
  KEY ix_rpu_product (product_id),
  KEY ix_rpu_barcode (tenant_id, barcode)",

'rtl_batches' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  product_id CHAR(36) NOT NULL,
  batch_no VARCHAR(80) NOT NULL,
  expiry_date DATE NULL,
  qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  cost_price DECIMAL(14,4) NOT NULL DEFAULT 0,
  received_on DATE NULL,
  $SYNC,
  KEY ix_rbt_product (product_id, expiry_date),
  KEY ix_rbt_tenant (tenant_id, deleted_at)",

'rtl_counters' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  name VARCHAR(80) NOT NULL,
  device_name VARCHAR(120) NULL,
  printer VARCHAR(160) NULL,
  drawer VARCHAR(30) NOT NULL DEFAULT 'Attached',
  cashier VARCHAR(160) NULL,
  opening_cash DECIMAL(14,4) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'Closed',
  $SYNC,
  KEY ix_rct_tenant (tenant_id, deleted_at)",

/* Bill. reprint_count yahin hai — duplicate receipt cash chori ka
   aam raasta hai, is liye har copy ginti mein aati hai. */
'rtl_sales' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  bill_no VARCHAR(40) NOT NULL,
  counter_name VARCHAR(80) NULL,
  cashier_user_id CHAR(36) NULL,
  cashier_name VARCHAR(160) NULL,
  customer_id CHAR(36) NULL,
  customer_name VARCHAR(180) NULL,
  price_level VARCHAR(20) NOT NULL DEFAULT 'Retail',
  line_count INT NOT NULL DEFAULT 0,
  subtotal DECIMAL(14,4) NOT NULL DEFAULT 0,
  discount DECIMAL(14,4) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(14,4) NOT NULL DEFAULT 0,
  total DECIMAL(14,4) NOT NULL DEFAULT 0,
  paid_cash DECIMAL(14,4) NOT NULL DEFAULT 0,
  paid_card DECIMAL(14,4) NOT NULL DEFAULT 0,
  change_amount DECIMAL(14,4) NOT NULL DEFAULT 0,
  payment_method VARCHAR(20) NOT NULL DEFAULT 'CASH',
  status VARCHAR(20) NOT NULL DEFAULT 'Completed',
  sold_at DATETIME(6) NOT NULL,
  reprint_count INT NOT NULL DEFAULT 0,
  last_reprint_at DATETIME(6) NULL,
  $SYNC,
  UNIQUE KEY uq_rs_bill (tenant_id, site_id, bill_no),
  KEY ix_rs_soldat (tenant_id, sold_at),
  KEY ix_rs_customer (customer_id)",

'rtl_sale_items' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  sale_id CHAR(36) NOT NULL,
  product_id CHAR(36) NULL,
  product_name VARCHAR(200) NOT NULL,
  unit_code VARCHAR(30) NULL,
  qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  unit_price DECIMAL(14,4) NOT NULL DEFAULT 0,
  discount DECIMAL(14,4) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
  line_total DECIMAL(14,4) NOT NULL DEFAULT 0,
  $SYNC,
  KEY ix_rsi_sale (sale_id)",

/* Har reprint ka apna record — kis ne, kab, kaunse counter se. */
'rtl_bill_reprints' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  sale_id CHAR(36) NOT NULL,
  bill_no VARCHAR(40) NOT NULL,
  copy_no INT NOT NULL DEFAULT 1,
  user_id CHAR(36) NULL,
  user_name VARCHAR(160) NULL,
  counter_name VARCHAR(80) NULL,
  reason VARCHAR(255) NULL,
  $SYNC,
  KEY ix_rbr_sale (sale_id),
  KEY ix_rbr_tenant (tenant_id, created_at)",

/* Parked carts. Counter par rakhi hui bill bijli jane par bhi zinda rahe. */
'rtl_held_bills' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  bill_no VARCHAR(40) NULL,
  counter_name VARCHAR(80) NULL,
  customer_id CHAR(36) NULL,
  customer_name VARCHAR(180) NULL,
  price_level VARCHAR(20) NOT NULL DEFAULT 'Retail',
  line_count INT NOT NULL DEFAULT 0,
  total DECIMAL(14,4) NOT NULL DEFAULT 0,
  cart_json LONGTEXT NULL,
  held_by VARCHAR(160) NULL,
  $SYNC,
  KEY ix_rhb_tenant (tenant_id, deleted_at)",

/* Khata / account ledger — har credit bill aur har recovery. */
'rtl_customer_ledger' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  customer_id CHAR(36) NOT NULL,
  entry_type VARCHAR(20) NOT NULL,
  ref_table VARCHAR(60) NULL,
  ref_id CHAR(36) NULL,
  ref_no VARCHAR(60) NULL,
  debit DECIMAL(14,4) NOT NULL DEFAULT 0,
  credit DECIMAL(14,4) NOT NULL DEFAULT 0,
  balance_after DECIMAL(14,4) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  entry_at DATETIME(6) NOT NULL,
  $SYNC,
  KEY ix_rcl_cust (customer_id, entry_at)",
];

foreach ($tables as $name => $cols) {
    if (!rtab($pdo, $name)) {
        $pdo->exec("CREATE TABLE $name ($cols) $ENG");
        $added[] = "table $name";
    }
}

/* ============================================================
   4. CUSTOMERS — retail ko credit limit aur balance chahiye.
   Yeh restaurant ki table hai, sirf columns barh rahe hain.
   ============================================================ */
foreach ([
    ['credit_limit',   "DECIMAL(14,4) NOT NULL DEFAULT 0"],
    ['balance',        "DECIMAL(14,4) NOT NULL DEFAULT 0"],
    ['loyalty_points', "INT NOT NULL DEFAULT 0"],
    ['area',           "VARCHAR(120) NULL"],
] as [$c, $def]) {
    if (!rcol($pdo, 'customers', $c)) { $pdo->exec("ALTER TABLE customers ADD COLUMN $c $def"); $added[] = "customers.$c"; }
}

/* ============================================================
   5. UNITS ka unique index.

   BUG JO ASAL TEST MEIN PAKRA GAYA:
   `units` par unique index sirf `code` par tha (uq_unit_code). Jab
   doosra supermarket bana, uske default units (DOZ, CTN24...) pehle
   tenant ke rows se takra gaye aur POORA business creation fail ho
   gaya — "Duplicate entry 'DOZ'".

   Ab index (code, tenant_id) par hai:
     ('DOZ', NULL)      -> global unit, sab ke liye
     ('CTN18', tenant1) -> sirf us tenant ka apna pack size
     ('CTN18', tenant2) -> alag row, koi takrao nahi
   ============================================================ */
$ix = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics
                      WHERE table_schema=DATABASE() AND table_name='units' AND index_name='uq_unit_code'");
$ix->execute();
if ((int)$ix->fetchColumn() > 0) {
    $pdo->exec("ALTER TABLE units DROP INDEX uq_unit_code");
    $added[] = 'units: dropped uq_unit_code (code-only)';
}
$ix = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics
                      WHERE table_schema=DATABASE() AND table_name='units' AND index_name='uq_unit_code_tenant'");
$ix->execute();
if ((int)$ix->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE units ADD UNIQUE KEY uq_unit_code_tenant (code, tenant_id)");
    $added[] = 'units: unique (code, tenant_id)';
}

echo "RETAIL_MIGRATION_READY added=" . count($added) . "\n";
foreach ($added as $a) echo "  + $a\n";
