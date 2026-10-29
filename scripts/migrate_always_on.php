<?php
/**
 * migrate_always_on.php — PURANI FEATURES LISTON KI EK DAFA SAFAI.  (V175)
 *
 * MASLA KYA THA
 * `ModuleGroups::ALWAYS_ON` (`dashboard`, `activate`) ka maqsad hi yeh
 * hai ke yeh do raaste kabhi band na hon — khaas kar `activate`, jo
 * RENEWAL ki screen hai. Us ka band hona ka matlab yeh hai ke customer
 * paisay dena chahta ho to bhi na de sake.
 *
 * Magar `tenants.features_json` teen jagah se likhi jati thi aur sirf
 * EK jagah ALWAYS_ON jorhti thi:
 *
 *     Super Admin -> Features screen   ALWAYS_ON jorhta tha
 *     FBR on/off switch                NAHI
 *     Owner app on/off switch          NAHI
 *
 * Dono switch ka tareeqa yeh hai ke list `NULL` (= sab allowed) ho to
 * wo `platform_modules` se POORI list khud bana kar likh dete hain. Us
 * lamhe jo kuch wahan na ho, wo us business par hamesha ke liye band.
 * Asal customer par yeh isi tarah hua: Demo Restaurant aur Demo
 * Supermarket ki list mein `activate` kabhi aaya hi nahi, aur un par
 * Renew ka button dabane se page POS par wapas chala jata tha.
 *
 * V175 mein teeno raaste theek ho chuke hain, aur `canModule()` bhi ab
 * ALWAYS_ON ko girne nahi deti. Yeh script sirf PURANA data theek
 * karti hai, taake Super Admin ki Features screen bhi wohi sach
 * dikhaye jo software mein chal raha hai (wahan yeh khane pehle se
 * "locked" nazar aate hain, magar mehfooz list mein mojood nahi thay).
 *
 * MEHFOOZ HAI:
 *   - jin ki list `NULL` hai (= sab allowed) un ko haath nahi lagta
 *   - sirf kami poori ki jati hai, koi module HATAYA nahi jata
 *   - dobara chalane se kuch nahi badalta (idempotent)
 *
 *   php scripts/migrate_always_on.php            # theek karo
 *   php scripts/migrate_always_on.php --status   # sirf batao
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;
use Aio\Services\ModuleGroups;

$statusOnly = (bool)cli_arg('status');
$pdo = DB::pdo();

try {
    $rows = $pdo->query("SELECT id, name, features_json
                           FROM tenants
                          WHERE deleted_at IS NULL
                            AND features_json IS NOT NULL")->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    echo "ALWAYS_ON_SKIP tenants parhi nahi ja saki: " . $e->getMessage() . "\n";
    return;
}

$fixed = 0; $already = 0;
$upd = $pdo->prepare("UPDATE tenants SET features_json=?, updated_at=NOW(6) WHERE id=?");

foreach ($rows as $r) {
    $list = json_decode((string)$r['features_json'], true);
    /* Khali ya kharab JSON ko haath nahi lagate — us ka matlab
       `canModule()` ke nazdeek "sab allowed" hai, aur wo pehle hi
       theek soorat hai. */
    if (!is_array($list) || !$list) { $already++; continue; }

    $missing = [];
    foreach (ModuleGroups::ALWAYS_ON as $k) {
        if (!in_array($k, $list, true)) $missing[] = $k;
    }
    if (!$missing) { $already++; continue; }

    echo "  " . $r['name'] . " — kam hai: " . implode(', ', $missing) . "\n";
    $fixed++;
    if ($statusOnly) continue;

    $upd->execute([json_encode(array_values(array_merge($list, $missing))), $r['id']]);
}

if ($statusOnly) {
    echo "ALWAYS_ON_STATUS {$fixed} business mein kami hai, {$already} theek hain"
       . " — --status diya gaya tha, kuch badla nahi\n";
    return;
}

echo "ALWAYS_ON_OK {$fixed} business theek kiye, {$already} pehle se theek thay\n";
if ($fixed) {
    echo "  Offline node ko agli sync par khud pata chal jayega"
       . " (handshake features neeche le jata hai).\n";
}

// build: V175 build 2026-10-29
