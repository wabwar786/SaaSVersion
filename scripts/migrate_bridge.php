<?php
// Idempotent: columns needed by ModuleBridge (approved UI fields not in base schema).
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
$pdo=DB::pdo();
function colExists(PDO $p,string $t,string $c):bool{$q=$p->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");$q->execute([$t,$c]);return (bool)$q->fetchColumn();}
if(!colExists($pdo,'suppliers','city'))     $pdo->exec("ALTER TABLE suppliers ADD COLUMN city VARCHAR(120) NULL AFTER address_text");
if(!colExists($pdo,'suppliers','category')) $pdo->exec("ALTER TABLE suppliers ADD COLUMN category VARCHAR(120) NULL AFTER city");
if(!colExists($pdo,'suppliers','deleted_at'))$pdo->exec("ALTER TABLE suppliers ADD COLUMN deleted_at DATETIME(6) NULL");
echo "BRIDGE_MIGRATION_READY\n";
