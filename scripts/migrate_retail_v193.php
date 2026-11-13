<?php
/**
 * migrate_retail_v193.php — CUSTOMER KE BILL TEZI SE.   (V193)
 *
 * KYUN
 * Duplicate bill mein customer ke naam se dhoondna bade database par
 * bohat dheema tha. 11.75 GB (9 lakh bills) par aisa naam jo kahin na
 * mile: 6-11 second, aur ek customer ke 20 hazar bills hon to 4 second.
 * Us dauran counter ruka rehta hai.
 *
 * KYA KARTI HAI
 * rtl_sales par ek index: (tenant_id, site_id, customer_id, sold_at).
 * RetailPos::sales() isi index se customer ke aakhri bills uthata hai,
 * table ki rows parhe baghair.
 *
 * Idempotent: index pehle se ho to kuch nahi karti. Data ko haath nahi
 * lagati. Bari table par pehli dafa ek-do minute lag sakte hain (InnoDB
 * online index build — bills bante rehte hain).
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

$t = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='rtl_sales'");
$t->execute();
if (!(int)$t->fetchColumn()) { echo "RETAIL_V193_SKIP rtl_sales nahi hai\n"; return; }

$i = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics
                     WHERE table_schema=DATABASE() AND table_name='rtl_sales' AND index_name='ix_rs_cust_sold'");
$i->execute();
if ((int)$i->fetchColumn()) { echo "RETAIL_V193_OK already\n"; return; }

$t0 = microtime(true);
try {
    $pdo->exec("ALTER TABLE `rtl_sales` ADD INDEX `ix_rs_cust_sold` (`tenant_id`,`site_id`,`customer_id`,`sold_at`)");
} catch (\Throwable $e) {
    /* Deploy kabhi nahi rokte — index na bane to search sirf dheemi rehti hai. */
    echo "  ! ix_rs_cust_sold: " . substr($e->getMessage(), 0, 140) . "\n";
    echo "RETAIL_V193_OK added=0\n";
    return;
}
printf("  + rtl_sales.ix_rs_cust_sold (%.1f s)\n", microtime(true) - $t0);
echo "RETAIL_V193_OK added=1\n";

// build: V193 build 2026-11-13
