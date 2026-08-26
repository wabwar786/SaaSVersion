<?php
/**
 * bootstrap_offline.php — offline node ka pehla setup.
 * Sealed config mein jo tenant/site hai, wahi local DB mein banata hai
 * (cloud jaisa hi id — taake sync par rows match karein), phir roles,
 * admin user aur operational defaults.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
use Aio\Services\Platform;

$pdo = DB::pdo();
$cfg = $GLOBALS['config'];
$tid = (string)($cfg['tenant']['id'] ?? '');
$sid = (string)($cfg['tenant']['site_id'] ?? '');
$name= (string)($cfg['app']['name'] ?? 'Restaurant');
$sname=(string)($cfg['tenant']['site_name'] ?? 'Main Branch');
$slug= (string)($cfg['tenant']['slug'] ?? 'local');
if ($tid === '' || $sid === '') { echo "OFFLINE_BOOTSTRAP_SKIPPED (no tenant in config)\n"; return; }

$has = function(string $t, string $id) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE id=?"); $q->execute([$id]); return (bool)$q->fetchColumn();
};

if (!$has('tenants', $tid)) {
    $pdo->prepare("INSERT INTO tenants(id,code,name,slug,industry_code,status,created_at) VALUES(?,?,?,?,?,'ACTIVE',NOW(6))")
        ->execute([$tid, strtoupper(substr(preg_replace('/[^A-Za-z0-9]/','',$slug).'X0000',0,10)), $name, $slug,
                   (string)($cfg['app']['industry'] ?? 'RESTAURANT')]);
    echo "  tenant created\n";
}
$oq = $pdo->prepare("SELECT id FROM organizations WHERE tenant_id=? LIMIT 1"); $oq->execute([$tid]);
$oid = $oq->fetchColumn();
if (!$oid) {
    $oid = uuid();
    $pdo->prepare("INSERT INTO organizations(id,tenant_id,organization_type,industry_code,name,status,created_at) VALUES(?,?,'BRANCH_GROUP',?,?,'ACTIVE',NOW(6))")
        ->execute([$oid, $tid, (string)($cfg['app']['industry'] ?? 'RESTAURANT'), $name]);
}
if (!$has('sites', $sid)) {
    $pdo->prepare("INSERT INTO sites(id,tenant_id,organization_id,code,name,site_type,status,created_at) VALUES(?,?,?,?,?,'BRANCH','ACTIVE',NOW(6))")
        ->execute([$sid, $tid, $oid, strtoupper(substr(preg_replace('/[^A-Za-z0-9]/','',$sname).'MAIN',0,10)), $sname]);
    echo "  site created\n";
}

Platform::ensureSiteDefaults($pdo, $tid, $sid);
echo "OFFLINE_BOOTSTRAP_READY tenant={$slug} site={$sname}\n";
