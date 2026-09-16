<?php
/**
 * WHY IS THIS ITEM STILL ON THE POS?
 *
 * Deleting a menu item works on every system I can test, so when it does
 * not work on yours the answer is in your data, not in the code. This
 * prints exactly what the database holds for a given name, and what the
 * POS would receive for it.
 *
 *   php scripts/diagnose_item.php "Spicy Fish"
 *   php scripts/diagnose_item.php "Spicy Fish" --site=<site_id>
 *
 * It changes nothing. Read-only.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;

$needle = '';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) continue;
    $needle = $a; break;
}
if ($needle === '') {
    echo "Usage: php scripts/diagnose_item.php \"part of the item name\"\n";
    return;
}

$pdo = DB::pdo();
echo "\n=== Looking for: \"{$needle}\" ===\n";
echo "role    : " . (string)cfg('app.role') . "\n";
echo "database: " . (string)cfg('db.database') . "\n";

/* Konse site par POS chal raha hai.

   CLI par `site_id()` config se aata hai aur wo us branch ka na bhi ho
   sakta jis ki baat ho rahi hai. Aisi soorat mein site ke hisaab se
   chhantna ghalat nateeja deta hai ("doosri branch ka item hai"),
   is liye pehle tasdeeq karte hain ke yeh site waqai maujood hai. */
$siteNow = '';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--site=')) $siteNow = substr($a, 7);
}
if ($siteNow === '') { try { $siteNow = (string)site_id(); } catch (\Throwable $e) {} }
if ($siteNow !== '') {
    $chk = $pdo->prepare("SELECT COUNT(*) FROM sites WHERE id=?");
    $chk->execute([$siteNow]);
    if (!(int)$chk->fetchColumn()) $siteNow = '';   /* config ka site is DB mein hai hi nahi */
}
echo "site now: " . ($siteNow !== '' ? $siteNow : '(unknown — showing every branch)') . "\n\n";

/* ---------- 1. har milti julti row, chahe kisi bhi site ki ho ---------- */
$q = $pdo->prepare(
    "SELECT mi.id, mi.name, mi.site_id, mi.is_active, mi.is_pos, mi.deleted_at,
            mi.updated_at, s.name site_name,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.menu_item_id=mi.id) used_in_bills
       FROM menu_items mi
       LEFT JOIN sites s ON s.id = mi.site_id
      WHERE mi.name LIKE ?
      ORDER BY mi.deleted_at IS NULL DESC, mi.name");
$q->execute(['%' . $needle . '%']);
$rows = $q->fetchAll(\PDO::FETCH_ASSOC);

if (!$rows) { echo "No menu item matches that name, on any site.\n"; return; }

echo "Rows found: " . count($rows) . "\n";
printf("  %-10s %-26s %-7s %-6s %-20s %-8s %s\n",
       'id', 'name', 'active', 'on POS', 'deleted_at', 'in bills', 'site');
foreach ($rows as $r) {
    printf("  %-10s %-26s %-7s %-6s %-20s %-8s %s\n",
        substr((string)$r['id'], 0, 8),
        substr((string)$r['name'], 0, 26),
        $r['is_active'] ? 'yes' : 'no',
        $r['is_pos'] ? 'yes' : 'no',
        $r['deleted_at'] ? (string)$r['deleted_at'] : '— (LIVE)',
        (string)$r['used_in_bills'],
        (string)($r['site_name'] ?? $r['site_id']));
}

/* ---------- 2. POS is waqt kya dekhta ---------- */
$live = array_filter($rows, fn($r) =>
    $r['deleted_at'] === null && (int)$r['is_active'] === 1 && (int)$r['is_pos'] === 1
    && ($siteNow === '' || (string)$r['site_id'] === $siteNow));

echo "\n=== What the POS shows ===\n";
if (!$live) {
    echo "  Nothing. If the item is still on your screen, the screen is stale:\n";
    echo "  the page is running old code or an old response. Reload once.\n";
} else {
    echo "  " . count($live) . " row(s) would appear on the POS:\n";
    foreach ($live as $r) echo "    - " . $r['name'] . "  (id " . substr((string)$r['id'],0,8) . ")\n";
}

/* ---------- 3. saaf nateeja ---------- */
echo "\n=== Reading ===\n";
$deleted = array_filter($rows, fn($r) => $r['deleted_at'] !== null);
$others  = array_filter($rows, fn($r) => $siteNow !== '' && (string)$r['site_id'] !== $siteNow);
$sites   = array_unique(array_map(fn($r) => (string)$r['site_id'], $rows));

if (count($rows) > 1 && $deleted && $live) {
    echo "  DUPLICATE. One copy is deleted, another is still live.\n";
    echo "  Delete the live one too — its id is shown above.\n";
} elseif ($others && !$live && count($sites) === 1) {
    echo "  The item belongs to ANOTHER BRANCH. You are signed in to a different\n";
    echo "  site, so deleting here never touched it. Sign in to that branch.\n";
} elseif ($deleted && !$live) {
    echo "  Already deleted in the database. The screen is out of date — nothing\n";
    echo "  more to delete.\n";
} elseif ($live) {
    echo "  Still live in the database — the delete did not reach it.\n";
    $l = reset($live);
    if ((int)$l['used_in_bills'] > 0) {
        echo "  It is used in " . $l['used_in_bills'] . " bill line(s), so a plain delete is\n";
        echo "  refused on purpose. Mark it INACTIVE instead — history stays intact.\n";
    } else {
        echo "  Nothing blocks it. Try again and note the exact message.\n";
    }
}

/* ---------- 4. node par cloud se wapas to nahi aa raha ---------- */
if ((string)cfg('app.role') !== 'cloud') {
    echo "\n=== Offline node check ===\n";
    echo "  menu_items is a table this node PULLS from the cloud.\n";
    echo "  If you deleted it here but it still exists in the cloud, it can come\n";
    echo "  back on the next sync. Delete it from the online portal as well,\n";
    echo "  or delete it there and let this node pull the change.\n";
    try {
        $t = $pdo->prepare("SELECT COUNT(*) FROM sync_tombstones WHERE table_name='menu_items'");
        $t->execute();
        echo "  delete signals queued for menu_items: " . (int)$t->fetchColumn() . "\n";
    } catch (\Throwable $e) {}
}
echo "\n";
