<?php
/**
 * migrate_fiscal.php — FBR / fiscal invoicing ke liye jagah.
 *
 * MySQL 8 information_schema UPPERCASE deta hai, MariaDB lowercase —
 * har query par explicit lowercase alias. Idempotent.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

$hasTable = function (string $t) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) AS n FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]); return (bool)$q->fetchColumn();
};
$hasCol = function (string $t, string $c) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) AS n FROM information_schema.columns
                         WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$t, $c]); return (bool)$q->fetchColumn();
};

$added = 0;

/* orders: fiscal status + invoice number */
if ($hasTable('orders')) {
    if (!$hasCol('orders', 'fiscal_invoice_no')) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN fiscal_invoice_no VARCHAR(60) NULL");
        echo "  + orders.fiscal_invoice_no\n"; $added++;
    }
    if (!$hasCol('orders', 'fiscal_status')) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN fiscal_status VARCHAR(20) NOT NULL DEFAULT 'NONE'");
        echo "  + orders.fiscal_status\n"; $added++;
    }
}

/* menu_items: per-item PCT code */
if ($hasTable('menu_items') && !$hasCol('menu_items', 'pct_code')) {
    $pdo->exec("ALTER TABLE menu_items ADD COLUMN pct_code VARCHAR(12) NULL");
    echo "  + menu_items.pct_code\n"; $added++;
}

/* fiscal_invoices: submission log */
$pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_invoices (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NULL,
  site_id      CHAR(36)     NULL,
  order_id     CHAR(36)     NOT NULL,
  bill_no      VARCHAR(40)  NULL,
  provider     VARCHAR(20)  NULL,
  invoice_no   VARCHAR(60)  NULL,
  status       VARCHAR(20)  NOT NULL DEFAULT 'PENDING',
  message      VARCHAR(300) NULL,
  sale_value   DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  attempts     INT          NOT NULL DEFAULT 0,
  created_at   DATETIME(6)  NOT NULL,
  updated_at   DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_fi_order (order_id),
  KEY ix_fi_status (tenant_id, site_id, status),
  KEY ix_fi_time (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  = fiscal_invoices ready\n";

/* pehle se maujood table par bhi zaroori columns */
foreach ([['bill_no','VARCHAR(40) NULL'],['provider','VARCHAR(20) NULL'],
          ['message','VARCHAR(300) NULL'],['attempts','INT NOT NULL DEFAULT 0'],
          ['sale_value','DECIMAL(14,2) NOT NULL DEFAULT 0'],
          ['tax_amount','DECIMAL(14,2) NOT NULL DEFAULT 0'],
          ['total_amount','DECIMAL(14,2) NOT NULL DEFAULT 0']] as [$c,$def]) {
    if (!$hasCol('fiscal_invoices', $c)) {
        try { $pdo->exec("ALTER TABLE fiscal_invoices ADD COLUMN `$c` $def"); echo "  + fiscal_invoices.$c\n"; $added++; }
        catch (\Throwable $e) {}
    }
}

/* V72 — kis tablet ne kaunsi item punch ki.
   `orders.device_id` pehle se tha magar ITEM level par kuch nahi tha.
   Ek hi table par teen order taker kaam karein to yeh jaanna zaroori
   hai ke kis ne kya daala — warna jhagre ka koi hal nahi. */
if ($hasTable('order_items')) {
    if (!$hasCol('order_items', 'device_id')) {
        $pdo->exec("ALTER TABLE order_items ADD COLUMN device_id CHAR(36) NULL");
        echo "  + order_items.device_id\n"; $added++;
    }
    if (!$hasCol('order_items', 'created_by_user_id')) {
        $pdo->exec("ALTER TABLE order_items ADD COLUMN created_by_user_id CHAR(36) NULL");
        echo "  + order_items.created_by_user_id\n"; $added++;
    }
}

echo "FISCAL_MIGRATION_READY added=$added\n";

// build: V64 build 2026-08-27
