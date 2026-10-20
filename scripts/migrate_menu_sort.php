<?php
/**
 * migrate_menu_sort.php — MENU KI APNI TARTEEB.  (V158)
 *
 * MASLA:
 *   `menu_categories` par `sort_order` pehle se maujood tha, magar usay
 *   set karne ka koi rasta UI mein nahi tha — har nayi category 99 par
 *   banti thi, aur 99 ke beech tarteeb naam ke hisaab se lagti. Items
 *   ka to column hi nahi tha: POS par Chicken Karahi, Dal, Zinger isi
 *   tarteeb mein aate thay jis mein alphabet kehta.
 *
 *   Restaurant waale ko yeh tarteeb apni marzi se chahiye: jo cheez
 *   sab se zyada bikti hai wo pehle, warna cashier ko har bill par
 *   scroll karna parta hai.
 *
 * YEH SCRIPT:
 *   1. `menu_items.sort_order` banati hai (INT, default 99).
 *   2. Jin sites par categories sab 99 par hain (yani kisi ne tarteeb
 *      set hi nahi ki) un ki categories ko naam ke hisaab se 1,2,3...
 *      de deti hai. Is se aaj ki nazar aane wali tarteeb nahi badalti
 *      (woh pehle bhi naam par thi) — magar ab un numbers ko screen
 *      par utha kar badla ja sakta hai.
 *
 *   Jahan kisi ne tarteeb KHUD set kar li hai (koi bhi value 99 ke
 *   ilawa), us site ko chhua bhi nahi jata.
 *
 * Idempotent: jitni dafa chalao, pehli dafa ke baad kuch nahi hota.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

function ms_col_exists(\PDO $p, string $table, string $col): bool {
    $q = $p->prepare("SELECT COUNT(*) FROM information_schema.columns
                       WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$table, $col]);
    return (bool)$q->fetchColumn();
}
function ms_table_exists(\PDO $p, string $table): bool {
    $q = $p->prepare("SELECT COUNT(*) FROM information_schema.tables
                       WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$table]);
    return (bool)$q->fetchColumn();
}

$added = 0; $skipped = 0; $numbered = 0;

/* ---- 1. menu_items.sort_order ---- */
if (ms_table_exists($pdo, 'menu_items')) {
    if (ms_col_exists($pdo, 'menu_items', 'sort_order')) {
        $skipped++;
    } else {
        try {
            $pdo->exec("ALTER TABLE `menu_items` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 99");
            $added++;
            echo "  + menu_items.sort_order\n";
        } catch (\Throwable $e) {
            /* Deploy kabhi nahi rokte: tarteeb na bhi bane to bill banta
               rehna chahiye. */
            echo "  ! menu_items.sort_order: ".substr($e->getMessage(), 0, 120)."\n";
        }
    }
}

/* ---- 2. jin sites ne tarteeb set nahi ki, un ki categories ko number ---- */
if (ms_table_exists($pdo, 'menu_categories') && ms_col_exists($pdo, 'menu_categories', 'sort_order')) {
    try {
        $sites = $pdo->query(
            "SELECT site_id FROM menu_categories
              WHERE deleted_at IS NULL
              GROUP BY site_id
             HAVING MIN(sort_order) = 99 AND MAX(sort_order) = 99"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $up = $pdo->prepare("UPDATE menu_categories SET sort_order=? WHERE id=?");
        foreach ($sites as $sid) {
            $q = $pdo->prepare("SELECT id FROM menu_categories
                                 WHERE site_id=? AND deleted_at IS NULL
                                 ORDER BY name");
            $q->execute([$sid]);
            $n = 1;
            foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $cid) {
                $up->execute([$n++, $cid]);
                $numbered++;
            }
        }
    } catch (\Throwable $e) {
        echo "  ! category numbering: ".substr($e->getMessage(), 0, 120)."\n";
    }
}

echo 'MENU_SORT_OK added='.$added.' skipped='.$skipped.' numbered='.$numbered."\n";

// build: V158 build 2026-10-19
