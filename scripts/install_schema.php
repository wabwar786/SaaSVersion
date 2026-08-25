<?php
// Load the base schema into the CONFIGURED database (local or cloud),
// ignoring the CREATE DATABASE / USE lines in the .sql so it works for any DB name.
// Usage:  [AIO_CONFIG=config/cloud.php] php scripts/install_schema.php [path-to-sql]
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$sqlFile = $argv[1] ?? (dirname(__DIR__).'/docs/02_local_mysql_schema.sql');
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
$ok=0; $fail=0; $errs=[];
foreach ($stmts as $stmt) {
    $stmt = trim($stmt);
    if ($stmt==='' || str_starts_with($stmt,'--')) continue;
    try { $pdo->exec($stmt); $ok++; }
    catch (\Throwable $e) { $fail++; if(count($errs)<5) $errs[]=substr($e->getMessage(),0,120); }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$db = $GLOBALS['config']['db']['database'];
$n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='".$db."'")->fetchColumn();
echo "SCHEMA_INSTALLED db=$db statements_ok=$ok failed=$fail tables=$n\n";
foreach ($errs as $e) echo "  note: $e\n";

// build: V17.1 build 2026-08-25
