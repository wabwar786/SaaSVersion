<?php
/**
 * app_errors — har business ka apna error log.
 *
 * Pehle errors kahin nahi jate the. `display_errors=Off` hai (theek hai,
 * customer ko stack trace nahi dikhni chahiye) aur `log_errors=On` unhein
 * PHP ke apne log mein daal deta tha — Railway par container logs mein,
 * aur branch computer par ek file mein jise koi nahi kholta. Nateeja: jo
 * cheez toot rahi hoti thi uska pata sirf tab chalta jab koi customer
 * shikayat karta.
 *
 * GROUPING ka faisla: ek hi error din mein sainkron dafa aata hai. Har
 * dafa nayi row banti to table do din mein bekaar ho jata. Is liye
 * `fingerprint` (message + file + line) par ek hi row rehti hai aur
 * `hits` barhta hai.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;

$pdo = DB::pdo();

$pdo->exec("CREATE TABLE IF NOT EXISTS app_errors (
  id            CHAR(36)     NOT NULL,
  tenant_id     CHAR(36)     NULL,
  site_id       CHAR(36)     NULL,
  fingerprint   CHAR(40)     NOT NULL,
  level         VARCHAR(10)  NOT NULL DEFAULT 'ERROR',
  source        VARCHAR(16)  NOT NULL DEFAULT 'api',
  action        VARCHAR(120) NULL,
  message       TEXT         NOT NULL,
  file          VARCHAR(255) NULL,
  line          INT          NULL,
  trace         TEXT         NULL,
  url           VARCHAR(255) NULL,
  user_id       CHAR(36)     NULL,
  user_name     VARCHAR(120) NULL,
  app_role      VARCHAR(10)  NULL,
  build         VARCHAR(40)  NULL,
  hits          INT          NOT NULL DEFAULT 1,
  first_seen    DATETIME(6)  NOT NULL,
  last_seen     DATETIME(6)  NOT NULL,
  resolved_at   DATETIME(6)  NULL,
  row_version   INT          NOT NULL DEFAULT 1,
  updated_at    DATETIME(6)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_err_print (tenant_id, fingerprint),
  KEY ix_err_last  (last_seen),
  KEY ix_err_ten   (tenant_id, last_seen),
  KEY ix_err_level (level, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* Collation baqi tables jaisi honi chahiye. `tenants` utf8mb4_unicode_ci
   par hai; naya table general_ci par ban jata to har JOIN par MySQL
   "Illegal mix of collations" de kar ruk jata — aur wo bhi theek us
   jagah jahan errors dikhane the. */
try {
    $pdo->exec("ALTER TABLE app_errors CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (\Throwable $e) { }

/* Purane errors khud saaf — warna yeh table sab se bara ban jata hai.
   30 din kaafi hain: is se purana error ya theek ho chuka hai ya ab
   ahem nahi raha. */
try {
    $n = $pdo->exec("DELETE FROM app_errors WHERE last_seen < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    if ($n) echo "  cleaned {$n} error(s) older than 30 days\n";
} catch (\Throwable $e) { }

echo "ERROR_LOG_READY\n";
