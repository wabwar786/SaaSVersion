<?php
// Idempotent migration: creates ui_records + sync_state tables.
// Safe to run repeatedly on both local and cloud databases.
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

$pdo->exec("
CREATE TABLE IF NOT EXISTS ui_records (
    id            CHAR(36)     NOT NULL,
    tenant_id     CHAR(36)     NOT NULL,
    site_id       CHAR(36)     NULL,
    module_key    VARCHAR(60)  NOT NULL,
    record_no     VARCHAR(60)  NULL,
    data_json     LONGTEXT     NOT NULL,
    deleted       TINYINT(1)   NOT NULL DEFAULT 0,
    row_version   BIGINT UNSIGNED NOT NULL DEFAULT 1,
    origin_node   VARCHAR(40)  NULL,
    created_at    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ix_ui_records_mod (tenant_id, site_id, module_key, deleted),
    KEY ix_ui_records_upd (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS sync_state (
    scope          VARCHAR(80)  NOT NULL,   -- e.g. 'push:orders', 'pull:menu_items'
    watermark      DATETIME(6)  NOT NULL DEFAULT '1970-01-01 00:00:00.000000',
    last_run_at    DATETIME(6)  NULL,
    last_status    VARCHAR(20)  NULL,       -- OK / ERROR / SKIPPED
    last_error     TEXT         NULL,
    rows_synced    BIGINT       NOT NULL DEFAULT 0,
    updated_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// A single summary row for the whole sync engine (for the offline_sync UI).
$pdo->exec("
INSERT INTO sync_state (scope, last_status)
VALUES ('engine', 'IDLE')
ON DUPLICATE KEY UPDATE scope = scope;
");

echo "SYNC_MIGRATION_READY\n";
