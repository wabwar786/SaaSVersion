<?php
/**
 * dl_tickets — offline package ka ek dafa chalne wala download link.
 *
 * Package ka endpoint `?node_token=` bhi qubool karta hai, aur us se link
 * banana aasan tha. Magar wo token us business ki SARI sync ki kunji hai:
 * URL mein daalne ka matlab hai wo browser history, proxy aur server logs
 * mein hamesha ke liye baith jaye. Jis ko wo mil jaye wo us business ka
 * poora catalog utaar sakta hai.
 *
 * Is liye alag ticket: ek dafa chalta hai, 30 minute mein khud mar jata
 * hai, aur us se sirf package download hota hai — kuch aur nahi.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;

$pdo = DB::pdo();

$pdo->exec("CREATE TABLE IF NOT EXISTS dl_tickets (
  token       CHAR(48)     NOT NULL,
  tenant_id   CHAR(36)     NOT NULL,
  issued_by   VARCHAR(160) NULL,
  issued_at   DATETIME(6)  NOT NULL,
  expires_at  DATETIME(6)  NOT NULL,
  used_at     DATETIME(6)  NULL,
  used_ip     VARCHAR(45)  NULL,
  PRIMARY KEY (token),
  KEY ix_dl_tenant (tenant_id, issued_at),
  KEY ix_dl_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

try { $pdo->exec("ALTER TABLE dl_tickets CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
catch (\Throwable $e) { }

/* Purane tickets rakhne ka koi faida nahi. */
try {
    $n = $pdo->exec("DELETE FROM dl_tickets WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    if ($n) echo "  cleaned {$n} expired ticket(s)\n";
} catch (\Throwable $e) { }

echo "DL_TICKETS_READY\n";
