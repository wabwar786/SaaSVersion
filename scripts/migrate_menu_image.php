<?php
// Idempotent: menu_items par image_url column (POS item picture ke liye).
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
$pdo=DB::pdo();
$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='menu_items' AND column_name='image_url'");
$q->execute();
if(!(int)$q->fetchColumn()) $pdo->exec("ALTER TABLE menu_items ADD COLUMN image_url TEXT NULL");
echo "MENU_IMAGE_MIGRATION_READY\n";
