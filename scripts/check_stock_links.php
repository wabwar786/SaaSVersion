<?php
/**
 * check_stock_links.php — TOOTI HUI STOCK WIRING KA PEHRA.  (V172)
 *
 * Yeh script KUCH BADALTI NAHI. Sirf dekhti hai aur batati hai.
 *
 * KYUN BANI:
 * Asal customer par Complete Payment dabate hi yeh aaya tha:
 *
 *     SQLSTATE[23000] 1452 ... stock_transaction_lines,
 *     CONSTRAINT fk_stl_item ... REFERENCES inventory_items(id)
 *
 * Wajah: kisi menu item ki recipe (ya us ka direct inventory link)
 * aise inventory item ki taraf ishara kar raha tha jo us database
 * mein mojood hi nahi tha. Bill band hi nahi hota tha.
 *
 * V172 se bill ab band ho jata hai (aisi line chhant kar error log
 * mein likh di jati hai). Magar wo ek MARHAM hai, ilaaj nahi — asal
 * kharabi data mein hai aur us item ka stock katna band ho jata hai.
 * Yeh script har deploy par bata deti hai ke kaun si recipe tooti
 * hui hai, taake malik usay theek kar sake.
 *
 * EK HI DATABASE ke andar aisa ban nahi sakta — `recipe_ingredients`
 * aur `menu_items` dono par foreign key hai. Yeh cloud par SYNC ke
 * raaste se banta hai: bachcha row (recipe ki line) pohanch jaye aur
 * us ka baap (inventory item) kisi wajah se chhoot jaye.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

function sl_table(\PDO $p, string $t): bool {
    $q = $p->prepare("SELECT COUNT(*) FROM information_schema.tables
                       WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]);
    return (bool)$q->fetchColumn();
}

$bad = 0; $lines = [];

/* 1) recipe ki lines jin ka inventory item gayab hai */
if (sl_table($pdo,'recipe_ingredients') && sl_table($pdo,'inventory_items')) {
    try {
        $q = $pdo->query(
            "SELECT COALESCE(mi.name,'(unknown item)') menu_name,
                    ri.inventory_item_id miss, COUNT(*) n
               FROM recipe_ingredients ri
               LEFT JOIN inventory_items ii ON ii.id = ri.inventory_item_id
               LEFT JOIN recipes r          ON r.id  = ri.recipe_id
               LEFT JOIN menu_items mi      ON mi.id = r.menu_item_id
              WHERE ii.id IS NULL
              GROUP BY menu_name, ri.inventory_item_id
              ORDER BY n DESC
              LIMIT 20");
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $bad++;
            $lines[] = sprintf('  ! recipe "%s" -> inventory item %s (gayab)',
                               (string)$r['menu_name'], (string)$r['miss']);
        }
    } catch (\Throwable $e) { echo '  ? recipe_ingredients: '.substr($e->getMessage(),0,90)."\n"; }
}

/* 2) menu items jin ka DIRECT inventory link gayab hai */
if (sl_table($pdo,'menu_items') && sl_table($pdo,'inventory_items')) {
    try {
        $q = $pdo->query(
            "SELECT mi.name, mi.direct_inventory_item_id miss
               FROM menu_items mi
               LEFT JOIN inventory_items ii ON ii.id = mi.direct_inventory_item_id
              WHERE mi.direct_inventory_item_id IS NOT NULL
                AND mi.direct_inventory_item_id <> ''
                AND ii.id IS NULL
              LIMIT 20");
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $bad++;
            $lines[] = sprintf('  ! menu item "%s" -> inventory item %s (gayab)',
                               (string)$r['name'], (string)$r['miss']);
        }
    } catch (\Throwable $e) { echo '  ? menu_items: '.substr($e->getMessage(),0,90)."\n"; }
}

foreach ($lines as $l) echo $l."\n";

if ($bad) {
    echo 'STOCK_LINKS_BROKEN count='.$bad
        ." - in menu items ka stock NAHI katega. Recipe kholein aur ingredient dobara chunein."
        ." Bill phir bhi band hota rahega (V172).\n";
} else {
    echo "STOCK_LINKS_OK\n";
}

// build: V172 build 2026-10-28
