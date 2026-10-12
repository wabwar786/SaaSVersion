<?php
/**
 * migrate_tax_settings.php — TAX EK HI ROW PAR LE AANA.  (V148)
 *
 * KYA KHARAB THA
 * Tax rates `ui_records` / `module_key='pos_settings'` par thin. Us table
 * par koi unique key nahi hai, is liye ek site ki DO row ban sakti thin —
 * aur banti thin: branch computer apni `ui_records` cloud par push karta
 * hai, us row ka `id` alag hota hai, to cloud par doosri copy ban jati.
 *
 * Phir yeh hota tha:
 *     PADHNE  wala code:  ORDER BY created_at DESC LIMIT 1   -> NAYI row
 *     LIKHNE  wala code:  LIMIT 1 (koi order nahi)           -> PURANI row
 *
 * Yani Settings par tax 0 (ya koi bhi value) save karo — `ok=true` aata,
 * "Settings saved" ka toast aata, aur screen par purana 16 / 8 wapas.
 * Hamesha. Koi error kahin nazar nahi aata tha.
 *
 * YEH SCRIPT KYA KARTI HAI (idempotent — kitni dafa bhi chalao)
 *   1) Har site ki SAB SE NAYI pos_settings row uthati hai aur us ke
 *      rates `site_settings` group 'tax' par likh deti hai, jahan
 *      UNIQUE (site_id, setting_group, setting_key) ki wajah se doosri
 *      row ban hi nahi sakti.
 *   2) Un purani ui_records rows ko `deleted=1` kar deti hai, warna wo
 *      kal phir "nayi" ban kar wapas aa jatin.
 *   3) Har site_settings row ka `id` derive kiye hue id par le aati hai
 *      (setting_uuid: site+group+key). Iske baghair branch aur cloud ke
 *      id alag hote hain aur sync un rows ko unique-key CONFLICT keh kar
 *      REJECT kar deta hai — settings neeche aati hi nahi.
 *
 * Chalane ka tareeqa (server shell / Railway, ya node par khud update):
 *     php scripts/migrate_tax_settings.php
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

function ss_has(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM site_settings LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
}
if (!ss_has($pdo)) { echo "TAX_SETTINGS_SKIPPED reason=site_settings-missing\n"; return; }

/* ---------- 1 + 2: purani pos_settings rows -> site_settings ---------- */
$moved = 0; $retired = 0; $sites = 0;
try {
    $rows = $pdo->query(
        "SELECT tenant_id, site_id, data_json, created_at
           FROM ui_records
          WHERE module_key='pos_settings' AND deleted=0 AND site_id IS NOT NULL
          ORDER BY site_id, created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $rows = []; }

/* Har site ki AAKHRI row hi sach hai — wahi POS parh raha tha. */
$bySite = [];
foreach ($rows as $r) $bySite[(string)$r['site_id']] = $r;

$put = $pdo->prepare(
    "INSERT INTO site_settings(id,tenant_id,site_id,setting_group,setting_key,value_json,is_secret)
     VALUES(?,?,?,'tax',?,?,0)
     ON DUPLICATE KEY UPDATE value_json=VALUES(value_json), updated_at=NOW(6)");
$exists = $pdo->prepare(
    "SELECT COUNT(*) FROM site_settings WHERE site_id=? AND setting_group='tax' AND setting_key=?");
$close = $pdo->prepare(
    "UPDATE ui_records SET deleted=1, row_version=row_version+1, updated_at=NOW(6)
      WHERE module_key='pos_settings' AND deleted=0 AND site_id=?");

foreach ($bySite as $siteId => $r) {
    $sites++;
    $d = json_decode((string)$r['data_json'], true);
    if (!is_array($d)) $d = [];
    $map = [
        'cash'           => isset($d['tax_cash'])       ? max(0.0,(float)$d['tax_cash'])       : 16.0,
        'card'           => isset($d['tax_card'])       ? max(0.0,(float)$d['tax_card'])       : 8.0,
        'service_charge' => isset($d['service_charge']) ? max(0.0,(float)$d['service_charge']) : 0.0,
    ];
    foreach ($map as $k => $v) {
        /* Agar site_settings par pehle se value hai to usay NA chhuo —
           wo nayi hai (V148 ke baad save hui). */
        $exists->execute([$siteId, $k]);
        if ((int)$exists->fetchColumn() > 0) continue;
        try {
            $put->execute([setting_uuid((string)$siteId,'tax',$k), (string)$r['tenant_id'],
                           (string)$siteId, $k, json_encode($v)]);
            $moved++;
        } catch (Throwable $e) {
            echo "  ! site $siteId key $k: ".substr($e->getMessage(),0,100)."\n";
        }
    }
    try { $close->execute([$siteId]); $retired += $close->rowCount(); } catch (Throwable $e) {}
}

/* ---------- 3: ids ko derive kiye hue id par le aana ---------- */
$fixed = 0;
try {
    $all = $pdo->query("SELECT id, site_id, setting_group, setting_key FROM site_settings")
               ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $all = []; }
$upd = $pdo->prepare("UPDATE site_settings SET id=? WHERE id=?");
foreach ($all as $r) {
    $want = setting_uuid((string)$r['site_id'], (string)$r['setting_group'], (string)$r['setting_key']);
    if ($want === (string)$r['id']) continue;
    try { $upd->execute([$want, (string)$r['id']]); $fixed++; }
    catch (Throwable $e) { /* takrao ho to chhor do — purani row waise hi chalti rahegi */ }
}

echo "TAX_SETTINGS_OK sites=$sites moved=$moved retired=$retired ids_fixed=$fixed\n";
