<?php
// Idempotent: menu_items.image_url — column banao, aur agar chhota (VARCHAR)
// ho to MEDIUMTEXT mein badlo taake uploaded data-URL images bhi fit hon.
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
$pdo=DB::pdo();
$q=$pdo->prepare("SELECT DATA_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='menu_items' AND column_name='image_url'");
$q->execute();
$type=$q->fetchColumn();
if(!$type){ $pdo->exec("ALTER TABLE menu_items ADD COLUMN image_url MEDIUMTEXT NULL"); echo "  added image_url MEDIUMTEXT\n"; }
elseif(strtolower((string)$type)!=='mediumtext' && strtolower((string)$type)!=='longtext'){
  $pdo->exec("ALTER TABLE menu_items MODIFY image_url MEDIUMTEXT NULL"); echo "  widened image_url ($type -> MEDIUMTEXT)\n";
}
echo "MENU_IMAGE_MIGRATION_READY\n";
