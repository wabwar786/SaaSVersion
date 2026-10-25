<?php
// Load the base schema into the CONFIGURED database (local or cloud),
// ignoring the CREATE DATABASE / USE lines in the .sql so it works for any DB name.
// Usage:  [AIO_CONFIG=config/cloud.php] php scripts/install_schema.php [path-to-sql]
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

/* V84 — sealed build mein $argv maujood hi nahi hota (scripts `php -r`
   se chalti hain). Guard ke baghair yeh "Undefined variable" deta tha
   aur offline setup wahin ruk jata tha. */
$sqlFile = ($GLOBALS['argv'][1] ?? null) ?: (dirname(__DIR__).'/docs/02_local_mysql_schema.sql');
if (!is_file($sqlFile)) { fwrite(STDERR, "Schema file not found: $sqlFile\n"); exit(1); }

$sql = file_get_contents($sqlFile);
// strip CREATE DATABASE ... ; and USE ...;
$sql = preg_replace('/CREATE\s+DATABASE[^;]*;/i', '', $sql);
$sql = preg_replace('/USE\s+`?[a-z0-9_]+`?\s*;/i', '', $sql);
// idempotent: don't fail on re-runs when tables already exist
$sql = preg_replace('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $sql);
// Strip whole-line -- comments BEFORE splitting. Otherwise a chunk that begins
// with a section-header comment ("-- PAYMENT ...\nCREATE TABLE ...") used to be
// skipped entirely by the str_starts_with('--') check, silently dropping tables.
$sql = preg_replace('/^\s*--[^\n]*$/m', '', $sql);

$pdo = DB::pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');

// split on semicolon at end of line (schema is plain DDL)
$stmts = preg_split('/;\s*\n/', $sql);

/* ============================================================
   V167 — "PEHLE SE MAUJOOD HAI" KOI KHARABI NAHI HAI.

   Yeh script HAR UPDATE par chalti hai, aur dusri dafa chalne par
   kuch cheezein pehle se bani hoti hain. Pehle woh "failed" ginn kar
   screen par aise chhapti thin:

     SCHEMA_INSTALLED ... statements_ok=95 failed=3
       note: SQLSTATE[HY000]: General error: 1826 Duplicate FOREIGN KEY
             constraint name 'fk_ii_default_location'
       note: SQLSTATE[HY000]: ... 'fk_ii_supplier'
       note: SQLSTATE[23000]: ... 1062 Duplicate entry 'V1' for PRIMARY

   Customer ko yeh teen laal lakeeren har update par nazar aati thin
   aur wo samajhta tha ke update kharab ho gayi — halanke matlab sirf
   itna tha ke "yeh cheez pehle se maujood hai".

   CREATE TABLE ko pehle se `IF NOT EXISTS` bana diya jata hai (upar).
   Magar MySQL mein `ADD CONSTRAINT IF NOT EXISTS` hota hi nahi, is
   liye FK ka ek hi raasta hai: koshish karo, aur "pehle se hai" wale
   error ko ginti mein alag rakho.

   AB: `already=` alag ginti, aur sirf WOH error chhapta hai jo waqai
   anjaan ho. Ek bhi asli masla ab bhi chhupta nahi.
   ============================================================ */
$EXPECTED = [
    1050,  // table already exists
    1060,  // duplicate column name
    1061,  // duplicate key name
    1062,  // duplicate entry (seed row pehle se)
    1091,  // can't DROP; doesn't exist
    1826,  // duplicate FOREIGN KEY constraint name
];

$ok=0; $already=0; $fail=0; $errs=[];
foreach ($stmts as $stmt) {
    $stmt = trim($stmt);
    if ($stmt==='' || str_starts_with($stmt,'--')) continue;
    try { $pdo->exec($stmt); $ok++; }
    catch (\Throwable $e) {
        $msg = $e->getMessage();
        $code = 0;
        /* PDO ka message: "SQLSTATE[HY000]: General error: 1826 Duplicate ..." */
        if (preg_match('/SQLSTATE\[[^\]]+\]:[^:]*:\s*(\d+)/', $msg, $m)) $code = (int)$m[1];
        if (in_array($code, $EXPECTED, true)) { $already++; continue; }
        $fail++; if(count($errs)<5) $errs[]=substr($msg,0,160);
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$db = $GLOBALS['config']['db']['database'];
$n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='".$db."'")->fetchColumn();
echo "SCHEMA_INSTALLED db=$db statements_ok=$ok already=$already failed=$fail tables=$n\n";
foreach ($errs as $e) echo "  ERROR: $e\n";

// build: V167 build 2026-10-25
