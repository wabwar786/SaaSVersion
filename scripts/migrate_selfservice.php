<?php
/**
 * migrate_selfservice.php — customer khud register kare, khud demo le.
 *
 *  1. `tenants` par trial ke columns
 *  2. `activation_requests` — customer ki payment ki ittila, aap ki
 *     manzoori ka intezar
 *  3. `signup_throttle` — ek hi email/IP se bar bar business banane se
 *     rokne ke liye
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

foreach ([
    'is_trial'        => 'TINYINT(1) NOT NULL DEFAULT 0',
    'trial_ends_at'   => 'DATE NULL',
    'signup_source'   => "VARCHAR(30) NULL",
    'signup_ip'       => 'VARCHAR(64) NULL',
    'contact_phone'   => 'VARCHAR(40) NULL',
] as $c=>$d) {
    if ($has('tenants') && !$col('tenants',$c)) {
        try { $pdo->exec("ALTER TABLE tenants ADD COLUMN `$c` $d"); echo "  + tenants.$c\n"; $added++; }
        catch(\Throwable $e){}
    }
}

/* Customer kehta hai "paisay bhej diye, yeh transaction id hai".
   Yeh DAWA hai, saboot nahi — is liye status PENDING rehta hai jab tak
   aap khud tasdeeq na karein. Koi bhi kuch bhi likh kar software nahi
   chala sakta. */
$pdo->exec("CREATE TABLE IF NOT EXISTS activation_requests (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,
  business_name VARCHAR(190) NULL,
  contact_name  VARCHAR(120) NULL,
  contact_phone VARCHAR(40)  NULL,
  contact_email VARCHAR(190) NULL,
  plan          VARCHAR(40)  NULL,
  months        INT          NOT NULL DEFAULT 1,
  amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
  method        VARCHAR(40)  NULL,
  txn_reference VARCHAR(120) NULL,
  paid_on       DATE         NULL,
  note          VARCHAR(400) NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'PENDING',
  reviewed_by   VARCHAR(120) NULL,
  reviewed_at   DATETIME(6)  NULL,
  review_note   VARCHAR(400) NULL,
  created_at    DATETIME(6)  NOT NULL,
  updated_at    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  KEY ix_ar_status (status, created_at),
  KEY ix_ar_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  = activation_requests ready\n";

/* Public endpoint hai — bina rok ke koi bhi hazaron business bana sakta
   hai. Yeh table sirf ginti rakhti hai. */
$pdo->exec("CREATE TABLE IF NOT EXISTS signup_throttle (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(64)  NULL,
  email      VARCHAR(190) NULL,
  phone      VARCHAR(40)  NULL,
  created_at DATETIME(6)  NOT NULL,
  KEY ix_st_ip (ip, created_at),
  KEY ix_st_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  = signup_throttle ready\n";

/* Aap ke bank/easypaisa ki tafseel — customer ko activation screen par
   yehi dikhti hai. Platform-level hai, kisi ek business ki nahi. */
$pdo->exec("CREATE TABLE IF NOT EXISTS platform_settings (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  setting_group VARCHAR(40)  NOT NULL,
  setting_key   VARCHAR(60)  NOT NULL,
  value_json    TEXT         NULL,
  updated_at    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_ps (setting_group, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  = platform_settings ready\n";

/* Shuruati values — aap super admin se badal sakte hain. */
foreach ([
    'monthly_price' => '3000',
    'yearly_price'  => '30000',
    'bank_name'     => '',
    'account_title' => 'Wabwar Software House',
    'account_number'=> '',
    'easypaisa'     => '',
    'jazzcash'      => '',
] as $k => $v) {
    try {
        $pdo->prepare("INSERT IGNORE INTO platform_settings(id,setting_group,setting_key,value_json)
                       VALUES(?,'billing',?,?)")
            ->execute([uuid(), $k, json_encode($v)]);
    } catch (\Throwable $e) {}
}

/* V88 — backup ka record. Sirf file rakhna kaafi nahi: kis waqt, kis
   wajah se, kitni rows — yeh maloom hona chahiye, warna 40 files mein
   se sahi wali dhoondna namumkin ho jata hai. */
$pdo->exec("CREATE TABLE IF NOT EXISTS backup_log (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id  CHAR(36)     NULL,
  site_id    CHAR(36)     NULL,
  file_name  VARCHAR(200) NOT NULL,
  file_path  VARCHAR(400) NULL,
  reason     VARCHAR(40)  NOT NULL DEFAULT 'MANUAL',
  row_count  INT          NOT NULL DEFAULT 0,
  byte_size  BIGINT       NOT NULL DEFAULT 0,
  checksum   VARCHAR(80)  NULL,
  created_at DATETIME(6)  NOT NULL,
  KEY ix_bl_time (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  = backup_log ready\n";

echo "SELFSERVICE_MIGRATION_READY added=$added\n";

// build: V86 build 2026-09-01
