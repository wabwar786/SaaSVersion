<?php
/**
 * migrate_sync_log.php — Synchronization ka poora record.
 *
 *  sync_runs      (local node)  : har sync pass ka ek row — kab chala,
 *                                 kitni der laga, kaun si tables, kitni rows,
 *                                 kamyab ya nakaam.
 *  sync_activity  (cloud + node): har table ka har transfer — direction,
 *                                 rows, kis branch/node se.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
$pdo = DB::pdo();

$pdo->exec("CREATE TABLE IF NOT EXISTS sync_runs (
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NULL,
  site_id CHAR(36) NULL,
  trigger_by VARCHAR(40) NOT NULL DEFAULT 'auto',
  started_at DATETIME(6) NOT NULL,
  finished_at DATETIME(6) NULL,
  duration_ms INT NULL,
  pushed_rows INT NOT NULL DEFAULT 0,
  pulled_rows INT NOT NULL DEFAULT 0,
  tables_touched INT NOT NULL DEFAULT 0,
  status ENUM('OK','PARTIAL','ERROR') NOT NULL DEFAULT 'OK',
  detail_json MEDIUMTEXT NULL,
  error_text VARCHAR(400) NULL,
  KEY ix_sr_time (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS sync_activity (
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NULL,
  site_id CHAR(36) NULL,
  run_id CHAR(36) NULL,
  direction ENUM('PUSH','PULL') NOT NULL,
  table_name VARCHAR(80) NOT NULL,
  rows_count INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'OK',
  note VARCHAR(300) NULL,
  node_ip VARCHAR(64) NULL,
  created_at DATETIME(6) NOT NULL,
  KEY ix_sa_time (created_at),
  KEY ix_sa_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

echo "SYNC_LOG_MIGRATION_READY\n";
