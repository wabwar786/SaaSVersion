<?php
// Per-counter shifts + cash handover records.
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
$pdo=DB::pdo();
function scol(PDO $p,string $t,string $c):bool{$q=$p->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");$q->execute([$t,$c]);return (bool)$q->fetchColumn();}
if(!scol($pdo,'cashier_shifts','counter_name'))  $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN counter_name VARCHAR(80) NULL");
if(!scol($pdo,'cashier_shifts','cash_cleared'))  $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN cash_cleared TINYINT(1) NOT NULL DEFAULT 0");
if(!scol($pdo,'cashier_shifts','cleared_amount'))$pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN cleared_amount DECIMAL(14,4) NULL");
if(!scol($pdo,'cashier_shifts','handover_to'))   $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN handover_to CHAR(36) NULL");
$pdo->exec("CREATE TABLE IF NOT EXISTS shift_handovers (
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NOT NULL,
  from_shift_id CHAR(36) NOT NULL,
  to_shift_id CHAR(36) NULL,
  from_user_id CHAR(36) NOT NULL,
  to_user_id CHAR(36) NOT NULL,
  counter_name VARCHAR(80) NULL,
  expected_cash DECIMAL(14,4) NOT NULL DEFAULT 0,
  counted_cash DECIMAL(14,4) NOT NULL DEFAULT 0,
  variance_amount DECIMAL(14,4) NOT NULL DEFAULT 0,
  handed_cash DECIMAL(14,4) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NULL,
  KEY ix_sh_site (site_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "SHIFTS_MIGRATION_READY\n";
