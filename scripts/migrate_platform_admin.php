<?php
/**
 * Super Admin ke tools: backup record, import batches, audit trail.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
$pdo = DB::pdo();

$pdo->exec("CREATE TABLE IF NOT EXISTS admin_backups (
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  scope VARCHAR(20) NOT NULL DEFAULT 'MASTER',
  file_name VARCHAR(200) NOT NULL,
  tables_count INT NOT NULL DEFAULT 0,
  rows_count INT NOT NULL DEFAULT 0,
  size_bytes BIGINT NOT NULL DEFAULT 0,
  checksum CHAR(64) NULL,
  created_by VARCHAR(120) NULL,
  created_at DATETIME(6) NOT NULL,
  KEY ix_ab_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS admin_imports (
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'BACKUP',
  file_name VARCHAR(200) NULL,
  tables_json MEDIUMTEXT NULL,
  rows_inserted INT NOT NULL DEFAULT 0,
  rows_updated INT NOT NULL DEFAULT 0,
  rows_skipped INT NOT NULL DEFAULT 0,
  status ENUM('OK','PARTIAL','FAILED','ROLLED_BACK') NOT NULL DEFAULT 'OK',
  error_text VARCHAR(500) NULL,
  created_by VARCHAR(120) NULL,
  created_at DATETIME(6) NOT NULL,
  KEY ix_ai_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit (
  id CHAR(36) NOT NULL PRIMARY KEY,
  actor VARCHAR(120) NULL,
  tenant_id CHAR(36) NULL,
  action VARCHAR(60) NOT NULL,
  detail VARCHAR(500) NULL,
  ip VARCHAR(64) NULL,
  created_at DATETIME(6) NOT NULL,
  KEY ix_aa_time (created_at),
  KEY ix_aa_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

echo "PLATFORM_ADMIN_MIGRATION_READY\n";
