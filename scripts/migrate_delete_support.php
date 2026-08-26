<?php
/**
 * migrate_delete_support.php — V62 "Delete Everywhere" ki buniyad.
 *
 * TEEN kaam karta hai:
 *
 * 1) `deletion_log` — har delete/void/restore ka audit record. Kis ne, kya,
 *    kab, kyun, kis node se. Local aur cloud dono par.
 *
 * 2) `sync_tombstones` — SYNC KA SABSE BARA GAP.
 *    Sync engine sirf "kya badla" bhejti thi (updated_at watermark + upsert).
 *    "Kya mit gaya" bhejne ka koi rasta tha hi nahi. Is liye cloud par
 *    factory reset / purge karne ke baad bhi branch computer par purana
 *    data zinda rehta tha — aur agar node ka sync_state kabhi reset ho jaye
 *    to wo saara purana data DOBARA cloud par push kar deta tha.
 *    Ab har HARD delete pehle tombstone likhta hai; tombstone khud ek
 *    normal syncable table hai, is liye wo dono taraf pohanchta hai aur
 *    doosri taraf row delete kar deta hai.
 *
 * 3) Jin tables par soft-delete ka sahara hi nahi tha un par `deleted_at`.
 *    (recipes, dining_tables, floors, printers, goods_receipts,
 *     cashier_shifts, expenses ... )
 *
 * MySQL 8 vs MariaDB: har information_schema query par EXPLICIT lowercase
 * alias. MySQL 8 TABLE_NAME/COLUMN_NAME uppercase deta hai.
 *
 * Idempotent — baar baar chal sakti hai.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

$tableExists = function (string $t) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) AS n FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]);
    return (bool)$q->fetchColumn();
};
$colsOf = function (string $t) use ($pdo): array {
    $q = $pdo->prepare("SELECT column_name AS c FROM information_schema.columns
                         WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]);
    return array_column($q->fetchAll(), 'c');
};

/* ================= 1. deletion_log ================= */
$pdo->exec("CREATE TABLE IF NOT EXISTS deletion_log (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NULL,
  site_id       CHAR(36)     NULL,
  entity        VARCHAR(60)  NOT NULL,
  table_name    VARCHAR(80)  NOT NULL,
  row_id        VARCHAR(64)  NOT NULL,
  row_label     VARCHAR(200) NULL,
  action        VARCHAR(20)  NOT NULL DEFAULT 'SOFT',
  reason        VARCHAR(300) NULL,
  actor_user_id CHAR(36)     NULL,
  actor_name    VARCHAR(120) NULL,
  origin_node   VARCHAR(40)  NULL,
  child_rows    INT          NOT NULL DEFAULT 0,
  created_at    DATETIME(6)  NOT NULL,
  KEY ix_dl_time   (created_at),
  KEY ix_dl_tenant (tenant_id, created_at),
  KEY ix_dl_row    (table_name, row_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  = deletion_log ready\n";

/* ================= 2. sync_tombstones ================= */
/* NOTE: is table par `updated_at` LAZMI hai — sync engine watermark isi
   par lagati hai. row_id 'ALL' ho to us table ki poori tenant/site scope
   wipe hoti hai (factory reset / purge ke liye). */
$pdo->exec("CREATE TABLE IF NOT EXISTS sync_tombstones (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id   CHAR(36)     NULL,
  site_id     CHAR(36)     NULL,
  table_name  VARCHAR(80)  NOT NULL,
  row_id      VARCHAR(64)  NOT NULL,
  scope_mode  VARCHAR(10)  NOT NULL DEFAULT 'ROW',
  before_ts   DATETIME(6)  NULL,
  reason      VARCHAR(200) NULL,
  origin_node VARCHAR(40)  NULL,
  created_at  DATETIME(6)  NOT NULL,
  updated_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  KEY ix_ts_time   (updated_at),
  KEY ix_ts_tenant (tenant_id, updated_at),
  KEY ix_ts_table  (table_name, row_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  = sync_tombstones ready\n";

/* Applied-marker LOCAL rehta hai — kabhi sync nahi hota. Warna ek node ka
   "maine apply kar liya" doosre node par chala jata aur wahan delete
   kabhi hoti hi nahi. */
$pdo->exec("CREATE TABLE IF NOT EXISTS sync_tombstones_applied (
  tombstone_id CHAR(36)    NOT NULL PRIMARY KEY,
  rows_deleted INT         NOT NULL DEFAULT 0,
  applied_at   DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  = sync_tombstones_applied ready\n";

/* ================= 3. deleted_at columns ================= */
/* Yeh wo tables hain jinme soft delete ka koi sahara hi nahi tha. */
$needSoftDelete = [
    'recipes', 'recipe_ingredients',
    'dining_tables', 'floors', 'printers',
    'goods_receipts', 'goods_receipt_items',
    'purchase_orders', 'purchase_order_items',
    'cashier_shifts', 'expenses', 'expense_categories',
    'menu_item_variants', 'menu_category_printer_routes',
    'stock_locations', 'payment_methods', 'units',
    'reservations', 'riders', 'delivery_orders', 'promotions',
    'roles', 'devices', 'paired_devices',
];
$added = 0; $already = 0;
foreach ($needSoftDelete as $t) {
    if (!$tableExists($t)) continue;
    if (in_array('deleted_at', $colsOf($t), true)) { $already++; continue; }
    try {
        $pdo->exec("ALTER TABLE `$t` ADD COLUMN `deleted_at` DATETIME(6) NULL");
        $added++;
        echo "  + $t.deleted_at\n";
    } catch (\Throwable $e) {
        echo "  ! $t: ".substr($e->getMessage(), 0, 90)."\n";
    }
}

/* sync_tombstones ki rows dobara beh sakein */
try {
    $pdo->prepare("DELETE FROM sync_state WHERE scope IN (?,?)")
        ->execute(['push:sync_tombstones', 'pull:sync_tombstones']);
} catch (\Throwable $e) {}

echo "DELETE_SUPPORT_READY added=$added already_ok=$already\n";

// build: V62 build 2026-08-26
