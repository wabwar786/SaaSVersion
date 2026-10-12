<?php
/**
 * check_collation.php — MILI-JULI COLLATION KA PEHRA.   (V153)
 *
 * KYUN BANI
 * V153 ki testing ke dauran ek fresh install par yeh aaya:
 *
 *     SQLSTATE[HY000]: 1267 Illegal mix of collations
 *     (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT)
 *     for operation '='
 *
 * Wajah: `migrate_collation` har table ko `utf8mb4_unicode_ci` par le
 * jati hai, magar MariaDB us column ko badalne se inkaar kar deti hai
 * jo kisi FOREIGN KEY mein shamil ho ("Cannot change column 'id': used
 * in a foreign key"). Script wo table skip kar ke aage barh jati hai —
 * aur yehi theek bhi hai, kyunke rukna poore deploy ko mar deta.
 *
 * MASLA YEH NAHI KE EK TABLE PEECHE REH GAYI. MASLA YEH HAI KE KISI KO
 * PATA NAHI CHALTA. `tenants.id` general_ci par reh jaye aur
 * `invoices.tenant_id` unicode_ci par chala jaye, to har wo JOIN jo in
 * dono ko milata hai CHALTE HUE TOOT jata hai — deploy ke mahine baad,
 * kisi ek screen par, bina kisi wajah ke. Hamara invoice ka auto-banna
 * theek isi tarah khamoshi se nakaam ho raha tha.
 *
 * Yeh script sirf DEKHTI hai, badalti kuch nahi. Deploy log mein
 * `COLLATION_OK` likha ho to joins mehfooz hain. Warna wo columns gina
 * deti hai jo peeche reh gaye, aur unhen theek karne ka hukm bhi.
 *
 * Deploy KABHI NAHI ROKTI (exit 0). Yeh ek chetawni hai, deewar nahi —
 * aisa database jo 90% theek hai, band paday rehne se behtar chalta hai.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo    = DB::pdo();
$db     = (string)($GLOBALS['config']['db']['database'] ?? '');
$target = 'utf8mb4_unicode_ci';

/* Sirf wo columns jin par joins chalte hain. `description` ya `note`
   jaise columns ki collation kabhi kisi `=` mein nahi aati — unhen
   ginwana sirf shor hai. */
$joinCols = ['id','tenant_id','site_id','organization_id','user_id','order_id',
             'item_id','product_id','subscription_id','payment_id','invoice_id',
             'category_id','supplier_id','customer_id','parent_id','node_id',
             'source_node_id','origin_node_id','role_id','shift_id'];

try {
    $in  = implode(',', array_fill(0, count($joinCols), '?'));
    $q   = $pdo->prepare(
        "SELECT TABLE_NAME t, COLUMN_NAME c, COLLATION_NAME col
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ?
            AND COLLATION_NAME IS NOT NULL
            AND COLLATION_NAME NOT IN (?, 'utf8mb4_bin')
            AND COLUMN_NAME IN ($in)
          ORDER BY TABLE_NAME, COLUMN_NAME");
    $q->execute(array_merge([$db, $target], $joinCols));
    $bad = $q->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    echo "COLLATION_CHECK_SKIPPED ".substr($e->getMessage(), 0, 120)."\n";
    return;
}

if (!$bad) { echo "COLLATION_OK target=$target\n"; return; }

echo "COLLATION_DRIFT columns=".count($bad)." target=$target\n";
foreach (array_slice($bad, 0, 25) as $r) {
    echo "  {$r['t']}.{$r['c']} = {$r['col']}\n";
}
if (count($bad) > 25) echo "  ... aur ".(count($bad) - 25)." aur\n";
echo "  THEEK KARNE KA TAREEQA (server shell par):\n";
echo "    FORCE_COLLATION=1 php scripts/migrate_collation.php\n";
echo "  Phir bhi baqi rahen (MariaDB + foreign key), to us column ko\n";
echo "  haath se badlein — FK pehle drop, column MODIFY, FK wapas add.\n";

// build: V153 build 2026-10-12
