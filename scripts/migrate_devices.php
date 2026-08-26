<?php
// Paired devices (tablet / mobile) - QR se local server ke saath connect.
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
$pdo=DB::pdo();
$pdo->exec("CREATE TABLE IF NOT EXISTS paired_devices (
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NOT NULL,
  device_name VARCHAR(120) NULL,
  device_role ENUM('WAITER','CASHIER','KDS','MANAGER') NOT NULL DEFAULT 'WAITER',
  pair_token VARCHAR(64) NOT NULL,
  user_id CHAR(36) NULL,
  status ENUM('PENDING','ACTIVE','REVOKED') NOT NULL DEFAULT 'PENDING',
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  paired_at DATETIME(6) NULL,
  last_seen_at DATETIME(6) NULL,
  user_agent VARCHAR(255) NULL,
  UNIQUE KEY uq_pair_token (pair_token),
  KEY ix_pd_site (site_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "DEVICES_MIGRATION_READY\n";
