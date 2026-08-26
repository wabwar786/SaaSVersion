<?php
// Idempotent: tenant branding (logo, colors) + per-business feature flags +
// WhatsApp integration settings.
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
$pdo=DB::pdo();
function col(PDO $p,string $t,string $c):bool{$q=$p->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");$q->execute([$t,$c]);return (bool)$q->fetchColumn();}
if(!col($pdo,'tenants','logo_url'))       $pdo->exec("ALTER TABLE tenants ADD COLUMN logo_url MEDIUMTEXT NULL");
if(!col($pdo,'tenants','brand_color'))    $pdo->exec("ALTER TABLE tenants ADD COLUMN brand_color VARCHAR(20) NULL");
if(!col($pdo,'tenants','brand_accent'))   $pdo->exec("ALTER TABLE tenants ADD COLUMN brand_accent VARCHAR(20) NULL");
if(!col($pdo,'tenants','display_name'))   $pdo->exec("ALTER TABLE tenants ADD COLUMN display_name VARCHAR(200) NULL");
if(!col($pdo,'tenants','features_json'))  $pdo->exec("ALTER TABLE tenants ADD COLUMN features_json MEDIUMTEXT NULL");
if(!col($pdo,'tenants','wa_api_url'))     $pdo->exec("ALTER TABLE tenants ADD COLUMN wa_api_url VARCHAR(300) NULL");
if(!col($pdo,'tenants','wa_api_key'))     $pdo->exec("ALTER TABLE tenants ADD COLUMN wa_api_key VARCHAR(200) NULL");
if(!col($pdo,'tenants','wa_events_json')) $pdo->exec("ALTER TABLE tenants ADD COLUMN wa_events_json MEDIUMTEXT NULL");
echo "BRANDING_MIGRATION_READY\n";
