<?php
/**
 * sync_directives — cloud se node ko ek chhota sa hukm.
 *
 * Cloud node ko khud kuch nahi bhej sakta: node kisi router ke peeche
 * hota hai, uska koi pata nahi hota. Baat hamesha node shuru karta hai.
 *
 * Is liye "sab kuch dobara bhejo" ka hukm yahan likha jata hai, aur node
 * apni agli sync ke handshake mein khud utha leta hai. Us waqt wo apne
 * pull watermarks peeche kar deta hai aur poora catalog dobara maang
 * leta hai.
 *
 * Yeh `resync` command se alag hai: wahan node ki tables MITAI jati hain
 * (tombstones ke zariye). Yahan kuch nahi mitta — sirf dobara bheja jata
 * hai. Rozana ke masle ke liye wahi chahiye.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;

$pdo = DB::pdo();

$pdo->exec("CREATE TABLE IF NOT EXISTS sync_directives (
  id          CHAR(36)     NOT NULL,
  tenant_id   CHAR(36)     NOT NULL,
  kind        VARCHAR(20)  NOT NULL DEFAULT 'REPULL',
  tables_csv  TEXT         NULL,
  issued_by   VARCHAR(160) NULL,
  issued_at   DATETIME(6)  NOT NULL,
  note        VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dir_tenant_kind (tenant_id, kind),
  KEY ix_dir_issued (issued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

try { $pdo->exec("ALTER TABLE sync_directives CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
catch (\Throwable $e) { }

echo "SYNC_DIRECTIVES_READY\n";
