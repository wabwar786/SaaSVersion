<?php
/**
 * migrate_licence_key.php — offline renewal ke liye jagah.
 *
 *  1. `tenants.licence_secret` — har business ka apna raaz
 *  2. `licence_keys`      — jo keys server par bani
 *  3. `licence_keys_used` — jo lag chuki hain (ek key, ek dafa)
 *
 * `licence_keys_used` sync mein KABHI nahi jati: wo us computer ki apni
 * yaad-dasht hai. Warna node reset kar ke wahi key dobara lagai ja
 * sakti thi.
 *
 * Idempotent.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();
$has=function(string $t)use($pdo):bool{$q=$pdo->prepare("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$t]);return (bool)$q->fetchColumn();};
$col=function(string $t,string $c)use($pdo):bool{$q=$pdo->prepare("SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");$q->execute([$t,$c]);return (bool)$q->fetchColumn();};
$added=0;

if ($has('tenants') && !$col('tenants','licence_secret')) {
    try { $pdo->exec("ALTER TABLE tenants ADD COLUMN licence_secret VARCHAR(64) NULL");
          echo "  + tenants.licence_secret\n"; $added++; } catch(\Throwable $e){}
}

$pdo->exec("CREATE TABLE IF NOT EXISTS licence_keys (
  id         CHAR(36)    NOT NULL PRIMARY KEY,
  tenant_id  CHAR(36)    NOT NULL,
  key_code   VARCHAR(40) NOT NULL,
  days       INT         NOT NULL DEFAULT 30,
  issued_by  VARCHAR(120) NULL,
  created_at DATETIME(6) NOT NULL,
  UNIQUE KEY uq_lk (key_code),
  KEY ix_lk_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  = licence_keys ready\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS licence_keys_used (
  key_code   VARCHAR(40) NOT NULL,
  tenant_id  CHAR(36)    NOT NULL,
  days       INT         NOT NULL DEFAULT 0,
  applied_at DATETIME(6) NOT NULL,
  PRIMARY KEY (key_code, tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  = licence_keys_used ready\n";

echo "LICENCE_KEY_MIGRATION_READY added=$added\n";

// build: V90 build 2026-09-01
