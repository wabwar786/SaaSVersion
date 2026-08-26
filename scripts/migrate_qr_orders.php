<?php
// QR table-ordering: har table ka apna QR, aur scan par ek SESSION banti hai
// jo waqt/table se bandhi hoti hai (customer ghar ja kar order na kar sake).
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
$pdo=DB::pdo();
$pdo->exec("CREATE TABLE IF NOT EXISTS qr_sessions (
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NOT NULL,
  table_id CHAR(36) NULL,
  table_name VARCHAR(100) NULL,
  token VARCHAR(64) NOT NULL,
  status ENUM('ACTIVE','CLOSED','EXPIRED') NOT NULL DEFAULT 'ACTIVE',
  started_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  closed_at DATETIME(6) NULL,
  guest_name VARCHAR(120) NULL,
  guest_phone VARCHAR(40) NULL,
  UNIQUE KEY uq_qr_token (token),
  KEY ix_qr_site (site_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS qr_orders (
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NOT NULL,
  session_id CHAR(36) NOT NULL,
  table_name VARCHAR(100) NULL,
  items_json MEDIUMTEXT NOT NULL,
  total DECIMAL(14,4) NOT NULL DEFAULT 0,
  status ENUM('PENDING','ACCEPTED','REJECTED') NOT NULL DEFAULT 'PENDING',
  note VARCHAR(255) NULL,
  created_at DATETIME(6) NOT NULL,
  handled_at DATETIME(6) NULL,
  handled_by CHAR(36) NULL,
  order_id CHAR(36) NULL,
  KEY ix_qro_site (site_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "QR_ORDERS_MIGRATION_READY\n";
