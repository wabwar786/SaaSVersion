<?php
// Idempotent BACKFILL: jo menu items pehle (ModuleBridge se pehle wale build par)
// Menu & Categories page se banaye gaye the, wo ui_records JSON mein phanse
// reh gaye the — POS unhe kabhi nahi dikha sakta tha. Yeh script unhe asli
// menu_items table mein le aati hai, phir ui_records row ko deleted mark
// kar deti hai. Dobara chalane par kuch nahi karti.
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
$pdo = DB::pdo();

$q = $pdo->query("SELECT id,tenant_id,site_id,data_json FROM ui_records WHERE module_key='menu' AND deleted=0");
$rows = $q->fetchAll();
$moved = 0; $skipped = 0;

foreach ($rows as $r) {
    $d = json_decode((string)$r['data_json'], true) ?: [];
    $name  = trim((string)($d['name'] ?? ''));
    $price = (float)($d['price'] ?? 0);
    if ($name === '' || $price <= 0) { $skipped++; continue; }

    $tid = $r['tenant_id'];
    $sid = $r['site_id'];
    if (!$sid) {
        $s = $pdo->prepare("SELECT id FROM sites WHERE tenant_id=? AND deleted_at IS NULL ORDER BY created_at LIMIT 1");
        $s->execute([$tid]); $sid = $s->fetchColumn();
    }
    if (!$sid) { $skipped++; continue; }

    $dupe = $pdo->prepare("SELECT id FROM menu_items WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");
    $dupe->execute([$sid, $name]);
    if ($dupe->fetchColumn()) {
        $pdo->prepare("UPDATE ui_records SET deleted=1 WHERE id=?")->execute([$r['id']]);
        $skipped++; continue;
    }

    $catName = trim((string)($d['category'] ?? 'General')) ?: 'General';
    $c = $pdo->prepare("SELECT id FROM menu_categories WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");
    $c->execute([$sid, $catName]);
    $cid = $c->fetchColumn();
    if (!$cid) {
        $cid = uuid();
        $pdo->prepare("INSERT INTO menu_categories(id,tenant_id,site_id,name,icon_text,sort_order,is_active) VALUES(?,?,?,?,'*',99,1)")
            ->execute([$cid, $tid, $sid, $catName]);
    }

    $active = (strtolower((string)($d['status'] ?? 'Active')) !== 'inactive') ? 1 : 0;
    $pdo->prepare("INSERT INTO menu_items(id,tenant_id,site_id,category_id,name,item_type,consumption_type,base_price,is_active,is_online,is_pos) VALUES(?,?,?,?,?,'STANDARD','NONE',?,?,1,1)")
        ->execute([uuid(), $tid, $sid, $cid, $name, $price, $active]);
    $pdo->prepare("UPDATE ui_records SET deleted=1 WHERE id=?")->execute([$r['id']]);
    $moved++;
    echo "  moved: $name (PKR $price)\n";
}
echo "UI_MENU_BACKFILL_READY candidates=".count($rows)." moved=$moved skipped=$skipped\n";
