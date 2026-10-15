<?php
/**
 * check_local_schema.php — KHALI DATABASE KA PEHRA.   (V155)
 *
 * KYUN BANI
 * Ek customer ka software php.exe ke crash aur "Login service error"
 * par aa kar ruk gaya. Teen ghante ki tafteesh ke baad wajah mili:
 * `aio_local` database MOJOOD tha — magar us mein EK BHI TABLE NAHI
 * THI. Software ne phir bhi chalne ki poori koshish ki, login ne
 * `users` par query ki, aur PHP wahin gir gaya.
 *
 * Us soorat mein customer ko jo nazar aata hai wo yeh: "mera saara
 * data mit gaya". Halanke data bilkul salamat tha — doosre folder ke
 * datadir mein.
 *
 * Yeh script sirf DEKHTI hai: kya database mein wo buniyadi tables
 * mojood hain jin ke baghair software chal hi nahi sakta?
 *
 * Exit code (launcher isi par faisla karta hai):
 *     0  SCHEMA_OK       — sab theek, server chala do
 *    20  SCHEMA_EMPTY    — database bilkul khali hai (nayi/khali datadir)
 *    21  SCHEMA_PARTIAL  — kuch tables hain, kuch nahi (adhoori setup)
 *     1  SCHEMA_NO_DB    — database se baat hi nahi ho rahi
 *
 * 20 aur 21 dono par launcher setup (install_schema + migrations)
 * dobara chala deta hai — yeh scripts idempotent hain, is liye mojood
 * data ko koi khatra nahi. `install_schema` sirf `CREATE TABLE IF NOT
 * EXISTS` chalati hai; kisi row ko haath nahi lagati.
 *
 * Yeh script kuch BADALTI NAHI. Faisla launcher ka hai, taake is ki
 * nakami kabhi khud data par asar na daal sake.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

/* Buniyadi tables — in ke baghair login bhi nahi hota, POS bhi nahi.
   Yeh fehrist jaan boojh kar CHHOTI rakhi gayi hai: maqsad "poora
   schema theek hai?" sabit karna nahi (us ke liye migrations hain),
   balke "yeh database khali/adhoora to nahi?" pakarna hai.

   `const` ke bajaye variable: sealed runner kabhi ek hi process mein
   do dafa script chala de to `const` "already defined" par fatal de
   deta — aur yeh script hi wo aakhri cheez hai jo us soorat mein
   chalni chahiye. */
$mustHave = ['tenants','sites','users','roles','orders','order_items','menu_items','site_settings'];

try {
    $pdo = DB::pdo();
    $db  = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
} catch (\Throwable $e) {
    fwrite(STDERR, 'SCHEMA_NO_DB '.$e->getMessage()."\n");
    echo "SCHEMA_NO_DB\n";
    exit(1);
}

$q = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema=?");
$q->execute([$db]);
$have = [];
foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $t) $have[strtolower((string)$t)] = true;

$total = count($have);
if ($total === 0) {
    echo "SCHEMA_EMPTY database={$db} tables=0\n";
    exit(20);
}

$missing = [];
foreach ($mustHave as $t) if (!isset($have[$t])) $missing[] = $t;

if ($missing) {
    echo "SCHEMA_PARTIAL database={$db} tables={$total} missing=".implode(',', $missing)."\n";
    exit(21);
}

echo "SCHEMA_OK database={$db} tables={$total}\n";
exit(0);

// build: V155 build 2026-10-15
