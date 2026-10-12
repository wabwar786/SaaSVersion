<?php
/**
 * migrate_billing.php — BILLING RATE + INVOICES.   (V151)
 *
 * MASLA JO YEH HAL KARTA HAI
 * Har business ki apni mahana raqam kahin likhi hi nahi jati thi. Teen
 * jagah kuch kuch tha, aur teeno ka aapas mein koi rabta nahi:
 *
 *   subscription_plans          -> qeemat ki fehrist, magar KABHI
 *                                  istemal hi nahi hoti thi (plan_id NULL)
 *   platform_settings.billing   -> ek global `monthly_price`, sirf
 *                                  customer ki screen par LIKHA jata tha
 *   tenant_subscriptions.amount -> sirf tab bharta jab renew karte waqt
 *                                  raqam type hoti — yani payment ka
 *                                  record, rate ka nahi
 *
 * Aur invoice ka to poora tasawwur hi mojood nahi tha: `subscription_
 * payments` sirf itna likhti hai ke paise aa gaye. Na invoice number,
 * na period, na unpaid/paid, na yeh ke customer ko bheji gayi ya nahi.
 *
 * AB:
 *   tenants.billing_amount / billing_cycle / billing_note
 *       -> har business ka apna rate, kisi bhi waqt kam ya zyada
 *   invoices
 *       -> har mahine ka apna parcha: number, period, raqam, tax,
 *          status (UNPAID/PAID/CANCELLED), kab bheja, kab wasool hua
 *
 * Idempotent — kitni dafa bhi chalao.
 *
 *     php scripts/migrate_billing.php
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

function col_exists(PDO $p, string $t, string $c): bool {
    $q = $p->prepare("SELECT COUNT(*) FROM information_schema.columns
                       WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$t, $c]);
    return (int)$q->fetchColumn() > 0;
}

/* ---------- 1) har business ka apna rate ---------- */
$added = [];
foreach ([
    'billing_amount' => "ALTER TABLE tenants ADD COLUMN billing_amount DECIMAL(12,2) NOT NULL DEFAULT 0",
    'billing_cycle'  => "ALTER TABLE tenants ADD COLUMN billing_cycle VARCHAR(20) NOT NULL DEFAULT 'MONTHLY'",
    'billing_note'   => "ALTER TABLE tenants ADD COLUMN billing_note VARCHAR(255) NULL",
] as $col => $sql) {
    if (col_exists($pdo, 'tenants', $col)) continue;
    try { $pdo->exec($sql); $added[] = $col; }
    catch (Throwable $e) { echo "  ! tenants.$col: ".substr($e->getMessage(),0,110)."\n"; }
}

/* ---------- 2) invoices ---------- */
$pdo->exec("
CREATE TABLE IF NOT EXISTS invoices (
  id            CHAR(36) NOT NULL,
  invoice_no    VARCHAR(40)  NOT NULL,
  tenant_id     CHAR(36)     NOT NULL,
  business_name VARCHAR(190) NOT NULL DEFAULT '',
  period_from   DATE NULL,
  period_to     DATE NULL,
  months        INT NOT NULL DEFAULT 1,
  amount        DECIMAL(12,2) NOT NULL DEFAULT 0,   -- tax se pehle
  tax_pct       DECIMAL(6,3)  NOT NULL DEFAULT 0,
  tax_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
  total         DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency      VARCHAR(10)  NOT NULL DEFAULT 'PKR',
  status        VARCHAR(16)  NOT NULL DEFAULT 'UNPAID',  -- UNPAID | PAID | CANCELLED
  note          VARCHAR(255) NULL,
  issued_on     DATE NULL,
  due_on        DATE NULL,
  /* Customer ko link isi token se khulta hai — login ke baghair, magar
     sirf usi ka apna invoice. Number andaza lagana aasan hai, token nahi. */
  public_token  CHAR(32) NOT NULL,
  sent_at       DATETIME(6) NULL,
  sent_channel  VARCHAR(20) NULL,
  paid_at       DATETIME(6) NULL,
  paid_method   VARCHAR(30) NULL,
  paid_reference VARCHAR(120) NULL,
  payment_id    CHAR(36) NULL,
  created_by    CHAR(36) NULL,
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_inv_no (invoice_no),
  UNIQUE KEY uq_inv_token (public_token),
  KEY ix_inv_tenant (tenant_id, status),
  KEY ix_inv_status (status, due_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

/* ---------- 3) rate ki tabdeeli ka record ----------
   "Kis ne, kab, kitne se kitna kiya" — yeh paisay ka maamla hai, is
   liye isay yaad rakhna lazmi hai. */
$pdo->exec("
CREATE TABLE IF NOT EXISTS billing_rate_log (
  id          CHAR(36) NOT NULL,
  tenant_id   CHAR(36) NOT NULL,
  old_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
  new_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
  old_cycle   VARCHAR(20) NULL,
  new_cycle   VARCHAR(20) NULL,
  reason      VARCHAR(255) NULL,
  changed_by  CHAR(36) NULL,
  created_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id), KEY ix_brl_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

/* ---------- 4) purane businesses ka rate ----------
   Jin ka rate 0 hai un par platform ka global `monthly_price` laga do —
   taake pehle hi din se invoice ban sake. Aage se har business ka apna. */
$seed = 0;
try {
    $q = $pdo->prepare("SELECT value_json FROM platform_settings
                         WHERE setting_group='billing' AND setting_key='monthly_price' LIMIT 1");
    $q->execute();
    $mp = (float)json_decode((string)$q->fetchColumn(), true);
    if ($mp > 0) {
        $st = $pdo->prepare("UPDATE tenants SET billing_amount=?, billing_cycle='MONTHLY'
                              WHERE (billing_amount IS NULL OR billing_amount=0) AND deleted_at IS NULL");
        $st->execute([$mp]);
        $seed = $st->rowCount();
    }
} catch (Throwable $e) {}

echo "BILLING_OK columns_added=".(count($added)?implode(',', $added):'none')
   ." rate_seeded=$seed\n";
