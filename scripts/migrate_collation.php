<?php
// Idempotent: poore database ki tables ko utf8mb4 / utf8mb4_unicode_ci par
// normalize karta hai. Railway (MySQL 8) par purani tables DB-default
// utf8mb4_0900_ai_ci par ban gayi thin jabke nayi migrations explicit
// utf8mb4_unicode_ci hain — column-to-column joins par
// "Illegal mix of collations" aata tha. Yeh script mix khatam karti hai.
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();
$db  = $GLOBALS['config']['db']['database'];
$target = 'utf8mb4_unicode_ci';

// 1) DB default — aage banne wali tables sahi collation par banein.
try { $pdo->exec("ALTER DATABASE `$db` CHARACTER SET utf8mb4 COLLATE $target"); }
catch (\Throwable $e) { echo "  note(db-default): ".substr($e->getMessage(),0,120)."\n"; }

// 2) Har off-collation table convert karo (FK checks off — MySQL 8 par
//    id/FK columns bhi convert ho jaate hain; MariaDB kisi table par mana
//    kare to skip+log, crash nahi).
$q = $pdo->prepare(
    "SELECT table_name, table_collation FROM information_schema.tables
      WHERE table_schema=? AND table_type='BASE TABLE' AND table_collation<>?"
);
$q->execute([$db, $target]);
$rows = $q->fetchAll();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$done=0; $skip=0;
foreach ($rows as $r) {
    $t = $r['table_name'];
    try {
        $pdo->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE $target");
        $done++;
    } catch (\Throwable $e) {
        $skip++;
        echo "  skip $t: ".substr($e->getMessage(),0,110)."\n";
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

echo "COLLATION_NORMALIZED target=$target converted=$done skipped=$skip pending_before=".count($rows)."\n";
