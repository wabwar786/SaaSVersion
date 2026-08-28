<?php
/**
 * migrate_security.php — V77 ki buniyad.
 *
 *  1. `users.email` ab optional (username hi asli pehchan hai)
 *  2. `audit_log` — har ahem amal ka mustaqil record
 *  3. `cashier_shifts` par CLOSING SNAPSHOT columns — shift band hone ke
 *     baad us ke totals kabhi na badlen (accounts/audit ke liye lazmi)
 *  4. `whatsapp_queue` — closing report owner ko bhejne ke liye
 *  5. POS ki raftaar ke liye indexes
 *
 * MySQL 8 vs MariaDB: har information_schema query par lowercase alias.
 * Idempotent.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();
$has = function (string $t) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) AS n FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]); return (bool)$q->fetchColumn();
};
$col = function (string $t, string $c) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) AS n FROM information_schema.columns
                         WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$t, $c]); return (bool)$q->fetchColumn();
};
$idx = function (string $t, string $i) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) AS n FROM information_schema.statistics
                         WHERE table_schema=DATABASE() AND table_name=? AND index_name=?");
    $q->execute([$t, $i]); return (bool)$q->fetchColumn();
};
$added = 0;

/* ---------- 1. email optional ---------- */
if ($has('users') && $col('users', 'email')) {
    try {
        $q = $pdo->prepare("SELECT is_nullable AS n FROM information_schema.columns
                             WHERE table_schema=DATABASE() AND table_name='users' AND column_name='email'");
        $q->execute();
        if (strtoupper((string)$q->fetchColumn()) === 'NO') {
            /* Email ab lazmi nahi — chhoti dukanon ke cashiers ke paas
               email hoti hi nahi. Username unique hai, wahi pehchan hai. */
            $pdo->exec("ALTER TABLE users MODIFY COLUMN email VARCHAR(190) NULL");
            echo "  ~ users.email -> optional\n"; $added++;
        }
    } catch (\Throwable $e) { echo "  ! users.email: ".substr($e->getMessage(),0,90)."\n"; }
}
if ($has('users') && !$col('users', 'username')) {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN username VARCHAR(120) NULL AFTER id");
          echo "  + users.username\n"; $added++; } catch (\Throwable $e) {}
}
/* Purane users jinke paas username nahi — email se bana do, warna wo
   login hi nahi kar payenge jab email optional ho jaye. */
try {
    $n = $pdo->exec("UPDATE users SET username = SUBSTRING_INDEX(email,'@',1)
                      WHERE (username IS NULL OR username='') AND email IS NOT NULL AND email<>''");
    if ($n) { echo "  ~ $n user(s) got a username from their email\n"; $added++; }
} catch (\Throwable $e) {}

/* ---------- 2. audit log ---------- */
$pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NULL,
  site_id      CHAR(36)     NULL,
  user_id      CHAR(36)     NULL,
  username     VARCHAR(120) NULL,
  role_name    VARCHAR(80)  NULL,
  action       VARCHAR(60)  NOT NULL,
  module       VARCHAR(60)  NULL,
  record_id    VARCHAR(64)  NULL,
  record_label VARCHAR(200) NULL,
  old_value    TEXT         NULL,
  new_value    TEXT         NULL,
  description  VARCHAR(400) NULL,
  device_info  VARCHAR(200) NULL,
  ip_address   VARCHAR(64)  NULL,
  created_at   DATETIME(6)  NOT NULL,
  KEY ix_al_time (tenant_id, site_id, created_at),
  KEY ix_al_user (user_id, created_at),
  KEY ix_al_action (action, created_at),
  KEY ix_al_module (module, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  = audit_log ready\n";

/* ---------- 3. shift closing snapshot ----------
   Shift band hone ke BAAD us ke totals kabhi dobara na ginay jayen.
   Warna purani closing report aaj chhapne par alag figure deti hai —
   accounts ke liye yeh na-qabil-e-qabool hai. */
foreach ([
    'snapshot_json'    => 'MEDIUMTEXT NULL',
    'closing_ref'      => 'VARCHAR(40) NULL',
    'gross_sales'      => 'DECIMAL(14,2) NOT NULL DEFAULT 0',
    'net_sales'        => 'DECIMAL(14,2) NOT NULL DEFAULT 0',
    'discount_total'   => 'DECIMAL(14,2) NOT NULL DEFAULT 0',
    'cash_sales'       => 'DECIMAL(14,2) NOT NULL DEFAULT 0',
    'card_sales'       => 'DECIMAL(14,2) NOT NULL DEFAULT 0',
    'credit_sales'     => 'DECIMAL(14,2) NOT NULL DEFAULT 0',
    'refund_total'     => 'DECIMAL(14,2) NOT NULL DEFAULT 0',
    'expense_total'    => 'DECIMAL(14,2) NOT NULL DEFAULT 0',
    'invoice_count'    => 'INT NOT NULL DEFAULT 0',
] as $c => $def) {
    if ($has('cashier_shifts') && !$col('cashier_shifts', $c)) {
        try { $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN `$c` $def");
              echo "  + cashier_shifts.$c\n"; $added++; } catch (\Throwable $e) {}
    }
}

/* ---------- 4. whatsapp queue ---------- */
$pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_queue (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NULL,
  site_id      CHAR(36)     NULL,
  kind         VARCHAR(40)  NOT NULL DEFAULT 'SHIFT_CLOSING',
  reference_id CHAR(36)     NULL,
  recipient    VARCHAR(40)  NOT NULL,
  message      MEDIUMTEXT   NOT NULL,
  status       VARCHAR(20)  NOT NULL DEFAULT 'PENDING',
  attempts     INT          NOT NULL DEFAULT 0,
  api_response TEXT         NULL,
  sent_at      DATETIME(6)  NULL,
  created_at   DATETIME(6)  NOT NULL,
  updated_at   DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_wa_ref (kind, reference_id),
  KEY ix_wa_status (tenant_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  = whatsapp_queue ready\n";

/* ---------- 5. POS speed indexes ----------
   Sale Point har bill par yeh columns dhoondta hai. Index ke baghair
   MySQL poori table parhta hai; 50,000 bills ke baad yeh mehsoos hone
   lagta hai. */
foreach ([
    ['menu_items',   'ix_mi_barcode',    '(site_id, barcode)'],
    ['menu_items',   'ix_mi_site_name',  '(site_id, name)'],
    ['orders',       'ix_ord_shift',     '(shift_id, order_status)'],
    ['orders',       'ix_ord_user_date', '(created_by_user_id, business_date)'],
    ['orders',       'ix_ord_site_bill', '(site_id, bill_no)'],
    ['order_items',  'ix_oi_order',      '(order_id, status)'],
    ['payments',     'ix_pay_shift2',    '(shift_id, status)'],
] as [$t, $name, $cols]) {
    if (!$has($t) || $idx($t, $name)) continue;
    /* Column maujood na ho to index banana bekaar — chup chaap chhor do */
    $ok = true;
    foreach (explode(',', trim($cols, '()')) as $c) if (!$col($t, trim($c))) $ok = false;
    if (!$ok) continue;
    try { $pdo->exec("ALTER TABLE `$t` ADD INDEX `$name` $cols");
          echo "  + index $t.$name\n"; $added++; }
    catch (\Throwable $e) {}
}

echo "SECURITY_MIGRATION_READY added=$added\n";

// build: V77 build 2026-08-28
