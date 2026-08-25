<?php
// Idempotent platform/SaaS migration. Safe to run repeatedly (local + cloud).
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
$pdo = DB::pdo();

// 1) platform_users : Super User + Heads (SaaS owner logins, separate from tenant users)
$pdo->exec("
CREATE TABLE IF NOT EXISTS platform_users (
  id            CHAR(36) NOT NULL,
  role          VARCHAR(20) NOT NULL DEFAULT 'SUPER',   -- SUPER | HEAD
  full_name     VARCHAR(180) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(50) NULL,
  password_hash VARCHAR(255) NOT NULL,
  status        VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  parent_head_id CHAR(36) NULL,
  commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id), UNIQUE KEY uq_pu_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

// 2) subscription_plans
$pdo->exec("
CREATE TABLE IF NOT EXISTS subscription_plans (
  id            CHAR(36) NOT NULL,
  name          VARCHAR(120) NOT NULL,
  industry_code VARCHAR(60) NOT NULL DEFAULT 'RESTAURANT',
  price         DECIMAL(12,2) NOT NULL DEFAULT 0,
  billing_cycle VARCHAR(20) NOT NULL DEFAULT 'MONTHLY',  -- MONTHLY | YEARLY | TRIAL
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

// 3) tenant_subscriptions (start + expiry + status)
$pdo->exec("
CREATE TABLE IF NOT EXISTS tenant_subscriptions (
  id            CHAR(36) NOT NULL,
  tenant_id     CHAR(36) NOT NULL,
  plan_id       CHAR(36) NULL,
  status        VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',   -- ACTIVE | TRIAL | SUSPENDED | EXPIRED
  amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
  start_date    DATE NOT NULL,
  expiry_date   DATE NOT NULL,
  created_by    CHAR(36) NULL,
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id), KEY ix_ts_tenant (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

// 4) subscription_payments (payment details captured at create / renewal)
$pdo->exec("
CREATE TABLE IF NOT EXISTS subscription_payments (
  id              CHAR(36) NOT NULL,
  tenant_id       CHAR(36) NOT NULL,
  subscription_id CHAR(36) NULL,
  amount          DECIMAL(12,2) NOT NULL DEFAULT 0,
  method          VARCHAR(30) NOT NULL DEFAULT 'CASH',   -- CASH|CARD|BANK|EASYPAISA|JAZZCASH|RAAST
  reference       VARCHAR(120) NULL,
  payer_name      VARCHAR(180) NULL,
  note            VARCHAR(300) NULL,
  paid_at         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  created_by      CHAR(36) NULL,
  PRIMARY KEY (id), KEY ix_sp_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

// 5) extend tenants with slug / industry / per-business sync token
function colExists(PDO $pdo, string $table, string $col): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
  $q->execute([$table,$col]); return (int)$q->fetchColumn()>0;
}
if(!colExists($pdo,'tenants','slug'))          $pdo->exec("ALTER TABLE tenants ADD COLUMN slug VARCHAR(140) NULL");
if(!colExists($pdo,'tenants','industry_code')) $pdo->exec("ALTER TABLE tenants ADD COLUMN industry_code VARCHAR(60) NOT NULL DEFAULT 'RESTAURANT'");
if(!colExists($pdo,'tenants','sync_token'))    $pdo->exec("ALTER TABLE tenants ADD COLUMN sync_token VARCHAR(80) NULL");
if(!colExists($pdo,'tenants','owner_email'))   $pdo->exec("ALTER TABLE tenants ADD COLUMN owner_email VARCHAR(190) NULL");
// unique slug (ignore error if dup index)
try { $pdo->exec("CREATE UNIQUE INDEX uq_tenants_slug ON tenants (slug)"); } catch (\Throwable $e) {}

// seed a couple of default plans
$have=(int)$pdo->query("SELECT COUNT(*) FROM subscription_plans")->fetchColumn();
if($have===0){
  $plans=[['Trial (14 days)','TRIAL',0],['Monthly','MONTHLY',10000],['Yearly','YEARLY',100000]];
  $ins=$pdo->prepare("INSERT INTO subscription_plans(id,name,industry_code,price,billing_cycle) VALUES (?,?,'RESTAURANT',?,?)");
  foreach($plans as $p2){ $ins->execute([uuid(),$p2[0],$p2[2],$p2[1]]); }
}
echo "PLATFORM_MIGRATION_READY\n";
