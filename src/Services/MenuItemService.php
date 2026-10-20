<?php
/**
 * MenuItemService — menu item banane / badalne ka EK raasta.
 *
 * ============================================================
 * MASLA JO YEH HAL KARTA HAI
 * ============================================================
 * Do jagah se item banta tha, aur dono ka taalluq hi nahi tha:
 *
 *  1. POS ka "+ New item" popup -> `pos-quick-item` -> ASLI `menu_items`
 *     table, inventory link ke sath (DIRECT_INVENTORY).
 *
 *  2. Left menu ka "Menu & Categories" -> `module.js` ka generic CRUD ->
 *     `ui_records` table. Yeh menu_items ko CHHOOTA HI NAHI THA. Us ke
 *     sirf paanch khane the: name, category, price, cost, status — na
 *     consumption_type, na inventory link.
 *
 * Nateeja: ek he restaurant ke do alag menu. POS par banaya item left
 * menu mein nazar na aata, aur left menu se banaya "item" kabhi POS par
 * bika hi nahi — kyunke woh asli menu_items row tha hi nahi. Customer ne
 * theek kaha: "dono same hone chahiye."
 *
 * Ab dono raaste yahan aate hain. Ek hi jagah ka sach.
 *
 * ============================================================
 * TEEN TARIQE — INVENTORY KAISE KATEGI
 * ============================================================
 * `menu_items.consumption_type` tay karta hai ke bill band hone par
 * `PosService::postInventory()` kya kaategi:
 *
 *   NONE              kuch nahi katega (service charge, ya aisa item jis
 *                     ka stock nahi rakha jata)
 *
 *   DIRECT_INVENTORY  jo item bika, WOHI stock se minus. Ek sale par
 *                     `direct_inventory_qty` utni units. Coke ki tin,
 *                     paani ki bottle, cigarette — jahan bikne wali cheez
 *                     aur stock wali cheez ek hi hai.
 *
 *   RECIPE            jo bika us ki recipe ke HAR ingredient se minus,
 *                     yield ke hisaab se aur waste % jorh kar. Biryani
 *                     bikay to chawal, murghi, masala — har ek alag.
 *
 * Dono tariqe pehle se engine mein thay; kami sirf unhen SET karne ki
 * jagah ki thi. Ab Menu screen aur POS popup dono se chun sakte hain.
 */
declare(strict_types=1);

namespace Aio\Services;

use Aio\DB;
use PDO;

final class MenuItemService
{
    /* ------------------------------------------------------------
       LIST — Menu screen ke liye. Wahi items jo POS par bikte hain.
       ------------------------------------------------------------ */
    public static function listItems(): array
    {
        /* V158 — tarteeb ka number list mein bhi (Menu screen par item
           ke naam ke saath "#3" nazar aata hai), aur ORDER BY mein bhi:
           screen par wahi tarteeb jo Sale Point par hai. `sort_order`
           nayi column hai, is liye dono jagah pehre ke saath. */
        $hasSort = self::hasSortColumn(DB::pdo());
        $q = DB::pdo()->prepare(
            "SELECT mi.id, mi.name, mi.base_price price, mi.item_type,
                    " . ($hasSort ? 'mi.sort_order,' : '') . "
                    mi.is_active, mi.consumption_type,
                    mi.direct_inventory_item_id, mi.direct_inventory_qty,
                    mc.id category_id, mc.name category,
                    ii.name inventory_name,
                    r.id recipe_id, r.yield_qty, r.food_cost_amount,
                    (SELECT COUNT(*) FROM recipe_ingredients ri WHERE ri.recipe_id=r.id) ingredients,
                    (SELECT COUNT(*) FROM menu_item_variants v
                      WHERE v.menu_item_id=mi.id AND v.is_active=1) variants
               FROM menu_items mi
               LEFT JOIN menu_categories mc ON mc.id = mi.category_id
               LEFT JOIN inventory_items  ii ON ii.id = mi.direct_inventory_item_id
               LEFT JOIN recipes r ON r.menu_item_id = mi.id AND r.is_current = 1
              WHERE mi.site_id = ? AND mi.deleted_at IS NULL
              ORDER BY COALESCE(mc.sort_order,999), mc.name, "
            . ($hasSort ? 'mi.sort_order, ' : '')
            . "mi.name"
        );
        $q->execute([site_id()]);

        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mode = 'none';
            if ($r['consumption_type'] === 'DIRECT_INVENTORY' && $r['direct_inventory_item_id']) $mode = 'direct';
            elseif ($r['consumption_type'] === 'RECIPE' && $r['recipe_id']) $mode = 'recipe';

            $out[] = [
                'id'          => $r['id'],
                'name'        => $r['name'],
                'category'    => $r['category'] ?: 'General',
                'category_id' => $r['category_id'],
                'price'       => (float)$r['price'],
                'type'        => $r['item_type'] === 'WEIGHTED' ? 'weighted' : 'standard',
                'active'      => (int)$r['is_active'] === 1,
                'sort_order'  => (int)($r['sort_order'] ?? 99),
                'mode'        => $mode,
                'stock_label' => self::stockLabel($mode, $r),
                'cost'        => (float)($r['food_cost_amount'] ?? 0),
                'variants'    => (int)$r['variants'],
            ];
        }
        return $out;
    }

    private static function stockLabel(string $mode, array $r): string
    {
        if ($mode === 'direct') {
            $q = (float)$r['direct_inventory_qty'];
            $n = (string)($r['inventory_name'] ?: 'item');
            return rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.') . ' x ' . $n;
        }
        if ($mode === 'recipe') {
            return (int)$r['ingredients'] . ' ingredient' . ((int)$r['ingredients'] === 1 ? '' : 's');
        }
        return 'No stock';
    }

    /* ------------------------------------------------------------
       GET — edit ke liye poora record, usi shakal mein jo save leta hai.
       ------------------------------------------------------------ */
    public static function getItem(string $id): ?array
    {
        $p = DB::pdo();
        $q = $p->prepare(
            "SELECT mi.*, mc.name category, r.id recipe_id, r.yield_qty
               FROM menu_items mi
               LEFT JOIN menu_categories mc ON mc.id = mi.category_id
               LEFT JOIN recipes r ON r.menu_item_id = mi.id AND r.is_current = 1
              WHERE mi.id = ? AND mi.site_id = ? AND mi.deleted_at IS NULL"
        );
        $q->execute([$id, site_id()]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;

        $mode = 'none';
        if ($r['consumption_type'] === 'DIRECT_INVENTORY' && $r['direct_inventory_item_id']) $mode = 'direct';
        elseif ($r['consumption_type'] === 'RECIPE' && $r['recipe_id']) $mode = 'recipe';

        $ing = [];
        if ($mode === 'recipe') {
            $iq = $p->prepare(
                "SELECT ri.inventory_item_id item_id, ri.qty_per_yield qty, ri.waste_pct, ii.name
                   FROM recipe_ingredients ri
                   JOIN inventory_items ii ON ii.id = ri.inventory_item_id
                  WHERE ri.recipe_id = ?"
            );
            $iq->execute([$r['recipe_id']]);
            foreach ($iq->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $ing[] = ['item_id' => $x['item_id'], 'name' => $x['name'],
                          'qty' => (float)$x['qty'], 'waste_pct' => (float)$x['waste_pct']];
            }
        }

        $vq = $p->prepare("SELECT name, price FROM menu_item_variants
                            WHERE menu_item_id=? AND is_active=1 ORDER BY sort_order");
        $vq->execute([$id]);

        return [
            'id'       => $r['id'],
            'name'     => $r['name'],
            'category' => $r['category'] ?: 'General',
            'price'    => (float)$r['base_price'],
            'type'     => $r['item_type'] === 'WEIGHTED' ? 'weighted' : 'standard',
            'desc'     => (string)($r['description'] ?? ''),
            'active'   => (int)$r['is_active'] === 1,
            /* V158 — Sale Point par tarteeb ka number. `sort_order` nayi
               column hai aur migration background stage mein chalti hai,
               is liye ??99 ka sahara: column na ho to screen par 99
               nazar aata hai (wahi default). */
            'sort_order'=> (int)($r['sort_order'] ?? 99),
            'variants' => $vq->fetchAll(PDO::FETCH_ASSOC),
            'inventory'=> [
                'mode'        => $mode,
                'item_id'     => $r['direct_inventory_item_id'],
                'qty'         => (float)($r['direct_inventory_qty'] ?: 1),
                'yield_qty'   => (float)($r['yield_qty'] ?: 1),
                'ingredients' => $ing,
            ],
        ];
    }

    /* ------------------------------------------------------------
       SAVE — banata bhi hai, badalta bhi. POS popup aur Menu screen
       dono yahi bulate hain.

       $d:
         id?          mojood ho to UPDATE, warna INSERT
         name, price, category, type(standard|weighted), desc?, active?
         variants?    [{name, price}, ...]
         inventory:
           {mode:'none'}
           {mode:'existing', item_id, qty}
           {mode:'new', name?, unit?, opening_qty?, cost?, qty}
           {mode:'recipe', yield_qty, ingredients:[{item_id|name, qty, waste_pct}]}
       ------------------------------------------------------------ */
    public static function save(array $d): array
    {
        $p    = DB::pdo();
        $id   = trim((string)($d['id'] ?? ''));
        if ($id !== '' && !preg_match('/^[0-9a-f-]{36}$/i', $id)) $id = '';
        $name = trim((string)($d['name'] ?? ''));
        $price= (float)($d['price'] ?? 0);

        if ($name === '')  throw new \RuntimeException('Item name is required');
        if ($price <= 0)   throw new \RuntimeException('A valid price is required');

        /* Dupe naam — magar apne aap se nahi takrana chahiye (edit ke waqt). */
        $dq = $p->prepare("SELECT id FROM menu_items
                            WHERE site_id=? AND name=? AND deleted_at IS NULL AND id<>? LIMIT 1");
        $dq->execute([site_id(), $name, $id ?: '-']);
        if ($dq->fetchColumn()) throw new \RuntimeException('A menu item with this name already exists');

        $cid      = self::categoryId(trim((string)($d['category'] ?? '')) ?: 'General');
        $itemType = (($d['type'] ?? 'standard') === 'weighted') ? 'WEIGHTED' : 'STANDARD';
        $active   = array_key_exists('active', $d) ? (!empty($d['active']) ? 1 : 0) : 1;

        $inv  = is_array($d['inventory'] ?? null) ? $d['inventory'] : ['mode' => 'none'];
        $mode = (string)($inv['mode'] ?? 'none');

        $consumption = 'NONE'; $directId = null; $directQty = null;

        if ($mode === 'existing' || $mode === 'direct') {
            $iq = $p->prepare("SELECT id FROM inventory_items
                                WHERE id=? AND site_id=? AND deleted_at IS NULL");
            $iq->execute([(string)($inv['item_id'] ?? ''), site_id()]);
            $directId = $iq->fetchColumn() ?: null;
            if (!$directId) throw new \RuntimeException('Selected inventory item not found');
            $consumption = 'DIRECT_INVENTORY';
            $directQty   = max(0.000001, (float)($inv['qty'] ?? 1));
        } elseif ($mode === 'new') {
            $directId    = self::createInventoryItem($p, $inv, $name);
            $consumption = 'DIRECT_INVENTORY';
            $directQty   = max(0.000001, (float)($inv['qty'] ?? 1));
        } elseif ($mode === 'recipe') {
            $consumption = 'RECIPE';
        }

        $ingredients = ($mode === 'recipe') ? self::resolveIngredients($p, $inv['ingredients'] ?? []) : [];
        if ($mode === 'recipe' && !$ingredients) {
            throw new \RuntimeException('A recipe needs at least one ingredient');
        }

        $created = ($id === '');

        /* ============================================================
           V158 — `sort_order` SIRF us waqt likhte hain jab column ho.

           Column `migrate_menu_sort` banati hai, jo background stage
           mein chalti hai. Agar yeh service us se pehle chal jaye aur
           hum column ko seedha INSERT/UPDATE mein daal dein, to item
           SAVE hona hi band ho jata — poora menu screen bekar. Is liye
           ek dafa pooch lete hain.
           ============================================================ */
        $hasSort = self::hasSortColumn($p);
        $sort    = max(0, (int)($d['sort_order'] ?? 99));

        DB::tx(function (PDO $p) use (&$id, $created, $d, $cid, $name, $itemType,
                                      $consumption, $directId, $directQty, $price,
                                      $active, $mode, $inv, $ingredients, $hasSort, $sort) {
            if ($created) {
                $id = uuid();
                $p->prepare(
                    "INSERT INTO menu_items(id,tenant_id,site_id,category_id,name,description,item_type,
                                            consumption_type,direct_inventory_item_id,direct_inventory_qty,
                                            base_price,is_active,is_online,is_pos"
                    . ($hasSort ? ',sort_order' : '') . ")
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,1,1" . ($hasSort ? ',?' : '') . ")"
                )->execute(array_merge(
                    [$id, tenant_id(), site_id(), $cid, $name, (string)($d['desc'] ?? ''),
                     $itemType, $consumption, $directId, $directQty, $price, $active],
                    $hasSort ? [$sort] : []
                ));
            } else {
                $p->prepare(
                    "UPDATE menu_items
                        SET category_id=?, name=?, description=?, item_type=?,
                            consumption_type=?, direct_inventory_item_id=?, direct_inventory_qty=?,
                            base_price=?, is_active=?"
                    . ($hasSort ? ', sort_order=?' : '') . ",
                            updated_at=NOW(6), row_version=row_version+1
                      WHERE id=? AND site_id=?"
                )->execute(array_merge(
                    [$cid, $name, (string)($d['desc'] ?? ''), $itemType,
                     $consumption, $directId, $directQty, $price, $active],
                    $hasSort ? [$sort] : [],
                    [$id, site_id()]
                ));
            }

            /* Variants — poori list dobara likhi jati hai. Purani ko
               is_active=0 karte hain, mitate nahi: purane bills un ka
               naam snapshot mein rakhte hain. */
            if (array_key_exists('variants', $d)) {
                $p->prepare("UPDATE menu_item_variants SET is_active=0 WHERE menu_item_id=?")->execute([$id]);
                $n = 0;
                foreach ((array)$d['variants'] as $o) {
                    $vn = trim((string)($o['name'] ?? ''));
                    $vp = (float)($o['price'] ?? 0);
                    if ($vn === '' || $vp <= 0) continue;
                    $n++;
                    $ex = $p->prepare("SELECT id FROM menu_item_variants
                                        WHERE menu_item_id=? AND name=? LIMIT 1");
                    $ex->execute([$id, $vn]);
                    if ($vid = $ex->fetchColumn()) {
                        $p->prepare("UPDATE menu_item_variants SET price=?,sort_order=?,is_active=1 WHERE id=?")
                          ->execute([$vp, $n, $vid]);
                    } else {
                        $p->prepare("INSERT INTO menu_item_variants(id,tenant_id,site_id,menu_item_id,name,price,sort_order,is_active)
                                     VALUES(?,?,?,?,?,?,?,1)")
                          ->execute([uuid(), tenant_id(), site_id(), $id, $vn, $vp, $n]);
                    }
                }
            }

            /* Recipe — har save ek nayi version banata hai aur purani ko
               band kar deta hai. Purane bills ki lagat us waqt ki version
               se judi rehti hai, is liye history kabhi nahi badalti. */
            if ($mode === 'recipe') {
                $p->prepare("UPDATE recipes SET is_current=0, effective_to=NOW(6)
                              WHERE menu_item_id=? AND is_current=1")->execute([$id]);
                $vq = $p->prepare("SELECT COALESCE(MAX(version_no),0)+1 FROM recipes WHERE menu_item_id=?");
                $vq->execute([$id]);
                $ver   = (int)$vq->fetchColumn();
                $rid   = uuid();
                $yield = max(0.000001, (float)($inv['yield_qty'] ?? 1));
                $p->prepare("INSERT INTO recipes(id,tenant_id,site_id,menu_item_id,version_no,yield_qty,is_current,created_by_user_id)
                             VALUES(?,?,?,?,?,?,1,?)")
                  ->execute([$rid, tenant_id(), site_id(), $id, $ver, $yield, current_user()['id'] ?? null]);

                $cost = 0.0;
                foreach ($ingredients as $i) {
                    $cq = $p->prepare("SELECT avg_cost_per_stock_unit FROM inventory_items WHERE id=?");
                    $cq->execute([$i['item_id']]);
                    $line = (float)$cq->fetchColumn() * (float)$i['qty'];
                    $cost += $line;
                    $p->prepare("INSERT INTO recipe_ingredients(id,recipe_id,inventory_item_id,qty_per_yield,waste_pct,line_cost)
                                 VALUES(?,?,?,?,?,?)")
                      ->execute([uuid(), $rid, $i['item_id'], $i['qty'], $i['waste_pct'], $line]);
                }
                $p->prepare("UPDATE recipes SET food_cost_amount=? WHERE id=?")->execute([$cost, $rid]);
            } elseif (!$created) {
                /* Recipe se hata kar direct/none kiya gaya — purani recipe
                   band kar do, warna postInventory dono dekhti rehti. */
                $p->prepare("UPDATE recipes SET is_current=0, effective_to=NOW(6)
                              WHERE menu_item_id=? AND is_current=1")->execute([$id]);
            }
        });

        return ['id' => $id, 'created' => $created, 'inventory_item_id' => $directId, 'mode' => $mode];
    }

    /**
     * V158 — `menu_items.sort_order` maujood hai ya nahi.
     *
     * Ek hi dafa poochte hain aur jawab yaad rakh lete hain. Migration
     * background stage mein chalti hai, is liye ek aadh request us se
     * pehle aa sakti hai — aur us waqt column ko query mein daalna
     * poore menu screen ko tor deta.
     */
    private static function hasSortColumn(PDO $p): bool
    {
        static $has = null;
        if ($has !== null) return $has;
        try {
            $q = $p->prepare("SELECT COUNT(*) FROM information_schema.columns
                               WHERE table_schema=DATABASE() AND table_name='menu_items'
                                 AND column_name='sort_order'");
            $q->execute();
            $has = (bool)$q->fetchColumn();
        } catch (\Throwable $e) { $has = false; }
        return $has;
    }

    /**
     * V158 — CATEGORY KI TARTEEB BADALNA.
     *
     * `menu_categories.sort_order` column pehle se thi, magar usay set
     * karne ka koi rasta UI mein nahi tha: har nayi category 99 par
     * banti thi aur POS un ko naam ke hisaab se lagata tha. Ab Menu
     * screen se number diya ja sakta hai.
     *
     * $rows: [{id, sort_order}, ...]
     */
    public static function saveCategoryOrder(array $rows): array
    {
        $p = DB::pdo();
        /* `row_version` bhi barhate hain — sync isi par chalta hai. */
        $up = $p->prepare("UPDATE menu_categories
                              SET sort_order=?, updated_at=NOW(6), row_version=row_version+1
                            WHERE id=? AND site_id=? AND deleted_at IS NULL");
        $n = 0;
        foreach ($rows as $r) {
            $cid = trim((string)($r['id'] ?? ''));
            if ($cid === '') continue;
            $up->execute([max(0, (int)($r['sort_order'] ?? 99)), $cid, site_id()]);
            $n += $up->rowCount() > 0 ? 1 : 0;
        }
        return ['saved' => $n];
    }

    /** Category naam se id — na mile to bana do (POS bhi yahi karta hai). */
    public static function categoryId(string $name): string
    {
        $p = DB::pdo();
        $q = $p->prepare("SELECT id FROM menu_categories WHERE site_id=? AND name=? LIMIT 1");
        $q->execute([site_id(), $name]);
        if ($id = $q->fetchColumn()) return (string)$id;

        $id = uuid();
        $sq = $p->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM menu_categories WHERE site_id=?");
        $sq->execute([site_id()]);
        $p->prepare("INSERT INTO menu_categories(id,tenant_id,site_id,name,sort_order,is_active)
                     VALUES(?,?,?,?,?,1)")
          ->execute([$id, tenant_id(), site_id(), $name, (int)$sq->fetchColumn()]);
        return $id;
    }

    /** `mode:'new'` — bikne wali cheez ka apna inventory item bana do. */
    /**
     * STOCK SET UP — menu ke items ka inventory EK CLICK MEIN.   (V149)
     *
     * KYUN:
     * Customer ne bilkul theek kaha — "aik hi kaam do do baar karna
     * mushkil hai". 57 menu items wale restaurant ko har item dobara
     * Inventory mein banana parta tha, warna "The same item" ka picker
     * khali rehta aur stock kabhi minus hi na hota.
     *
     * AB: menu hi se har item ka inventory item ban jata hai, usi naam
     * se, aur link bhi lag jata hai (DIRECT_INVENTORY, 1 unit per sale).
     * Phir wo picker mein bhi aa jata hai.
     *
     * Dobara chalana mehfooz hai:
     *   - usi naam ka inventory item pehle se ho to NAYA nahi banta,
     *     wahi link ho jata hai;
     *   - jis item ka stock pehle se set hai (direct ya recipe) usay
     *     haath nahi lagaya jata — recipe kabhi nahi tooti.
     *
     * @param array|null $ids  khaas menu items, ya null = jin ka stock set nahi
     * @return array{created:int,linked:int,skipped:int,items:array}
     */
    public static function stockSetup(?array $ids = null): array
    {
        $p = DB::pdo();

        $sql = "SELECT id, name, consumption_type, direct_inventory_item_id
                  FROM menu_items
                 WHERE site_id=? AND deleted_at IS NULL";
        $args = [site_id()];
        if ($ids !== null) {
            $ids = array_values(array_filter($ids, function ($x) {
                return is_string($x) && preg_match('/^[0-9a-f-]{36}$/i', $x);
            }));
            if (!$ids) throw new \RuntimeException('No items selected');
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $args = array_merge($args, $ids);
        }
        $q = $p->prepare($sql . ' ORDER BY name');
        $q->execute($args);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        $created = 0; $linked = 0; $skipped = 0; $done = [];
        foreach ($rows as $r) {
            /* Pehle se set hai to chhera na jaye — khaas kar recipe wale. */
            if ($r['consumption_type'] === 'RECIPE'
                || ($r['consumption_type'] === 'DIRECT_INVENTORY' && $r['direct_inventory_item_id'])) {
                $skipped++; continue;
            }
            $name = (string)$r['name'];
            try {
                $ex = $p->prepare("SELECT id FROM inventory_items
                                    WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");
                $ex->execute([site_id(), $name]);
                $iid = $ex->fetchColumn();
                if ($iid) { $linked++; }
                else { $iid = self::createInventoryItem($p, [], $name); $created++; }

                $p->prepare("UPDATE menu_items
                                SET consumption_type='DIRECT_INVENTORY',
                                    direct_inventory_item_id=?, direct_inventory_qty=1,
                                    updated_at=NOW(6), row_version=row_version+1
                              WHERE id=? AND site_id=?")
                  ->execute([$iid, $r['id'], site_id()]);
                $done[] = $name;
            } catch (\Throwable $e) {
                /* Ek item ki nakami baqi 56 ko na rokay. */
                $skipped++;
            }
        }
        return ['created' => $created, 'linked' => $linked, 'skipped' => $skipped, 'items' => $done];
    }

    /** Kitne items ka stock abhi set nahi — button par ginti dikhane ke liye. */
    public static function stockPending(): int
    {
        try {
            $q = DB::pdo()->prepare(
                "SELECT COUNT(*) FROM menu_items
                  WHERE site_id=? AND deleted_at IS NULL
                    AND (consumption_type IS NULL OR consumption_type='NONE'
                         OR (consumption_type='DIRECT_INVENTORY' AND direct_inventory_item_id IS NULL))");
            $q->execute([site_id()]);
            return (int)$q->fetchColumn();
        } catch (\Throwable $e) { return 0; }
    }

    private static function createInventoryItem(PDO $p, array $inv, string $fallbackName): string
    {
        $invName = trim((string)($inv['name'] ?? '')) ?: $fallbackName;

        $ex = $p->prepare("SELECT id FROM inventory_items WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");
        $ex->execute([site_id(), $invName]);
        if ($found = $ex->fetchColumn()) return (string)$found;   // pehle se hai to wahi

        $unitCode = strtoupper(trim((string)($inv['unit'] ?? 'PCS'))) ?: 'PCS';
        $uq = $p->prepare("SELECT id FROM units WHERE code=? LIMIT 1");
        $uq->execute([$unitCode]);
        $unitId = $uq->fetchColumn();
        if (!$unitId) $unitId = $p->query("SELECT id FROM units ORDER BY code LIMIT 1")->fetchColumn();
        if (!$unitId) throw new \RuntimeException('No units configured');

        /* V146 — wahi location jahan POS bikri post karta hai. Pehle
           yahan `ORDER BY name` tha, is liye opening stock aur sale alag
           khaton mein chale jate thay. */
        $loc = null;
        try { $loc = InventoryService::defaultLocationId($p); } catch (\Throwable $e) { $loc = null; }

        return InventoryService::createItem([
            'category_id' => null, 'sku' => null, 'barcode' => null,
            'name' => $invName, 'usage_mode' => 'DIRECT',
            'stock_unit_id' => $unitId, 'purchase_unit_name' => $unitCode, 'purchase_factor' => 1,
            'avg_cost' => (float)($inv['cost'] ?? 0), 'reorder_level' => 0,
            'track_batch' => 0, 'track_expiry' => 0,
            'location_id' => $loc, 'opening_qty' => (float)($inv['opening_qty'] ?? 0),
        ]);
    }

    /** Ingredient id ya naam -> id. Naam se aane walon ko bhi qubool karo. */
    private static function resolveIngredients(PDO $p, $rows): array
    {
        $out = [];
        foreach ((array)$rows as $x) {
            $iid = (string)($x['item_id'] ?? '');
            if (!preg_match('/^[0-9a-f-]{36}$/i', $iid) && !empty($x['name'])) {
                $q = $p->prepare("SELECT id FROM inventory_items
                                   WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");
                $q->execute([site_id(), (string)$x['name']]);
                $iid = (string)($q->fetchColumn() ?: '');
            }
            if ($iid === '') continue;
            $qty = (float)($x['qty'] ?? 0);
            if ($qty <= 0) continue;
            $out[] = ['item_id' => $iid, 'qty' => $qty, 'waste_pct' => (float)($x['waste_pct'] ?? 0)];
        }
        return $out;
    }
}

// build: V149 build 2026-10-12
