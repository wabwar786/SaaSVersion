<?php
// Idempotent BACKFILL: pehle se banaye gaye (pre-V17) businesses jo empty
// shell the — unke har active site par operational defaults ensure karta hai
// (payment methods, stock locations, printer, starter categories, floor+tables,
// units, expense categories). Naye business provisioning par khud milte hain.
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
use Aio\Services\Platform;
$pdo = DB::pdo();
$sites = $pdo->query("SELECT s.id site_id, s.tenant_id FROM sites s JOIN tenants t ON t.id=s.tenant_id WHERE s.deleted_at IS NULL AND t.deleted_at IS NULL")->fetchAll();
$touched = 0;
foreach ($sites as $s) {
    $added = Platform::ensureSiteDefaults($pdo, $s['tenant_id'], $s['site_id']);
    if ($added) { $touched++; echo "  site {$s['site_id']}: ".implode(', ', $added)."\n"; }
}
echo "SITE_DEFAULTS_READY sites=".count($sites)." backfilled=$touched\n";
