<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * CatalogService — inventory edit, recipe cost, purchase orders,
 * aur reservation ka table.
 *
 * Yeh char wo cheezein hain jo "adhoore" modules mein sab se ziada
 * takleef deti thin.
 */
final class CatalogService
{
    /* ==================== INVENTORY EDIT ====================
       Pehle item banane ke BAAD badalne ka koi rasta nahi tha — naam
       ghalat ho ya reorder level, item delete kar ke dobara banana
       parta tha (aur uske saath stock history ka rishta toot jata). */

    public static function inventoryItem(string $id): array
    {
        $q = DB::pdo()->prepare(
            "SELECT ii.id, ii.name, ii.sku, ii.barcode, ii.category_id, ii.usage_mode,
                    ii.stock_unit_id, ii.purchase_unit_name, ii.purchase_factor,
                    ii.reorder_level, ii.is_tracked, ii.is_active,
                    ii.preferred_supplier_id, ii.avg_cost_per_stock_unit,
                    COALESCE((SELECT SUM(sb.qty_on_hand) FROM stock_balances sb
                               WHERE sb.inventory_item_id=ii.id AND sb.site_id=?),0) AS on_hand
               FROM inventory_items ii
              WHERE ii.id=? AND ii.site_id=? AND ii.deleted_at IS NULL LIMIT 1");
        $q->execute([site_id(), $id, site_id()]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new \RuntimeException('Item not found.');
        return $r;
    }

    public static function saveInventoryItem(string $id, array $d): array
    {
        Scope::requireManagement('editing inventory items');
        $p = DB::pdo();

        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') throw new \RuntimeException('Item name is required.');

        $factor = (float)($d['purchase_factor'] ?? 1);
        if ($factor <= 0) throw new \RuntimeException('Purchase factor must be more than zero.');

        /* SKU unique rakhо — warna purchasing aur reports mein do items
           ek jaise nazar aate hain. */
        $sku = trim((string)($d['sku'] ?? ''));
        if ($sku !== '') {
            $c = $p->prepare("SELECT COUNT(*) FROM inventory_items
                               WHERE site_id=? AND LOWER(sku)=LOWER(?) AND id<>? AND deleted_at IS NULL");
            $c->execute([site_id(), $sku, $id]);
            if ((int)$c->fetchColumn() > 0) throw new \RuntimeException('Another item already uses that code.');
        }

        $old = self::inventoryItem($id);

        $st = $p->prepare(
            "UPDATE inventory_items
                SET name=?, sku=?, barcode=?, category_id=?, usage_mode=?,
                    purchase_unit_name=?, purchase_factor=?, reorder_level=?,
                    is_tracked=?, is_active=?, updated_at=NOW(6)
              WHERE id=? AND site_id=? AND deleted_at IS NULL");
        $st->execute([
            $name, $sku ?: null, trim((string)($d['barcode'] ?? '')) ?: null,
            trim((string)($d['category_id'] ?? '')) ?: null,
            strtoupper((string)($d['usage_mode'] ?? $old['usage_mode'])),
            trim((string)($d['purchase_unit_name'] ?? '')) ?: null,
            $factor, (float)($d['reorder_level'] ?? 0),
            !empty($d['is_tracked']) ? 1 : 0,
            isset($d['is_active']) ? (!empty($d['is_active']) ? 1 : 0) : (int)$old['is_active'],
            $id, site_id(),
        ]);
        if ($st->rowCount() < 1 && $old['name'] === $name) {
            /* rowCount 0 ka matlab "kuch badla hi nahi" bhi ho sakta hai */
        }

        Audit::log('INVENTORY_EDIT', 'inventory', [
            'id' => $id, 'label' => $name,
            'old' => $old['name'].' / reorder '.$old['reorder_level'],
            'new' => $name.' / reorder '.(float)($d['reorder_level'] ?? 0),
        ]);
        return ['message' => 'Item updated.'] + self::inventoryItem($id);
    }

    /* ==================== RECIPE / FOOD COST ====================
       Food cost sirf screen par tha. Profit & Loss recipe consumption se
       cost leta hai — jin items ki recipe nahi, un ka cost SIFAR rehta
       hai aur profit asal se ZYADA dikhta hai.
       Ab: cost mehfooz hota hai, aur report saaf batati hai ke kitne
       items bina recipe ke hain. */

    /** Ek menu item ka food cost recipe se nikalo. */
    public static function foodCost(string $menuItemId): array
    {
        $p = DB::pdo();
        $q = $p->prepare(
            "SELECT r.id, r.yield_qty,
                    COALESCE(SUM(ri.qty_per_yield * (1 + COALESCE(ri.waste_pct,0)/100)
                             * COALESCE(ii.avg_cost_per_stock_unit,0)),0) AS cost
               FROM recipes r
               LEFT JOIN recipe_ingredients ri ON ri.recipe_id = r.id
               LEFT JOIN inventory_items ii    ON ii.id = ri.inventory_item_id
              WHERE r.menu_item_id=? AND r.is_current=1
              GROUP BY r.id LIMIT 1");
        $q->execute([$menuItemId]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['has_recipe' => false, 'cost' => 0.0];

        $yield = (float)($r['yield_qty'] ?: 1);
        return ['has_recipe' => true, 'cost' => round((float)$r['cost'] / max(0.0001, $yield), 4)];
    }

    /** Sab menu items ka cost dobara nikaal kar mehfooz karo. */
    public static function refreshFoodCosts(): array
    {
        $p = DB::pdo();
        $q = $p->prepare("SELECT id FROM menu_items WHERE site_id=? AND deleted_at IS NULL");
        $q->execute([site_id()]);
        $done = 0; $withRecipe = 0;
        foreach (array_column($q->fetchAll(), 'id') as $mid) {
            $c = self::foodCost($mid);
            if ($c['has_recipe']) $withRecipe++;
            try {
                $p->prepare("UPDATE menu_items SET food_cost=?, food_cost_at=NOW(6) WHERE id=?")
                  ->execute([$c['cost'], $mid]);
                $done++;
            } catch (\Throwable $e) {}
        }
        Audit::log('FOOD_COST_REFRESH', 'recipe', ['desc' => "$withRecipe of $done items have a recipe"]);
        return ['items' => $done, 'with_recipe' => $withRecipe,
                'without_recipe' => $done - $withRecipe];
    }

    /** Recipe coverage — Profit & Loss ise apne note mein dikhata hai. */
    public static function recipeCoverage(): array
    {
        try {
            $q = DB::pdo()->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN EXISTS(SELECT 1 FROM recipes r
                                              WHERE r.menu_item_id=mi.id AND r.is_current=1)
                                 THEN 1 ELSE 0 END) AS with_recipe
                   FROM menu_items mi
                  WHERE mi.site_id=? AND mi.deleted_at IS NULL AND mi.is_active=1");
            $q->execute([site_id()]);
            $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
            $t = (int)($r['total'] ?? 0); $w = (int)($r['with_recipe'] ?? 0);
            return ['total' => $t, 'with_recipe' => $w, 'without_recipe' => $t - $w,
                    'pct' => $t > 0 ? (int)round($w * 100 / $t) : 0];
        } catch (\Throwable $e) { return ['total'=>0,'with_recipe'=>0,'without_recipe'=>0,'pct'=>0]; }
    }

    /* ==================== PURCHASE ORDERS ====================
       Ab tak sirf GRN tha ("maal aa gaya"). Supplier ko order bhejne ka
       koi rasta nahi tha, aur na yeh pata chalta tha ke kya mangwaya
       hua hai magar aaya nahi. */

    public static function poList(string $status = ''): array
    {
        $w = ['po.site_id = ?']; $a = [site_id()];
        if ($status !== '' && in_array(strtoupper($status), ['DRAFT','SENT','PARTIAL','RECEIVED','CANCELLED'], true)) {
            $w[] = 'po.status = ?'; $a[] = strtoupper($status);
        }
        $q = DB::pdo()->prepare(
            "SELECT po.id, po.po_no, po.order_date, po.expected_date, po.status,
                    po.total_amount, COALESCE(s.name,'-') AS supplier,
                    COALESCE(u.full_name,'-') AS created_by,
                    (SELECT COUNT(*) FROM purchase_order_items i WHERE i.purchase_order_id=po.id) AS item_count,
                    (SELECT COALESCE(SUM(i.received_qty),0) FROM purchase_order_items i WHERE i.purchase_order_id=po.id) AS received,
                    (SELECT COALESCE(SUM(i.purchase_qty),0) FROM purchase_order_items i WHERE i.purchase_order_id=po.id) AS ordered
               FROM purchase_orders po
               LEFT JOIN suppliers s ON s.id = po.supplier_id
               LEFT JOIN users u     ON u.id = po.created_by_user_id
              WHERE ".implode(' AND ', $w)."
              ORDER BY po.order_date DESC, po.created_at DESC LIMIT 200");
        $q->execute($a);
        return array_map(fn($x) => [
            'id'       => $x['id'],
            'po_no'    => (string)$x['po_no'],
            'supplier' => (string)$x['supplier'],
            'date'     => substr((string)$x['order_date'], 0, 10),
            'expected' => $x['expected_date'] ? substr((string)$x['expected_date'], 0, 10) : '',
            'items'    => (int)$x['item_count'],
            'ordered'  => (float)$x['ordered'],
            'received' => (float)$x['received'],
            'amount'   => (float)$x['total_amount'],
            'by'       => (string)$x['created_by'],
            'status'   => ucfirst(strtolower((string)$x['status'])),
        ], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function poCreate(array $d): array
    {
        Scope::requireManagement('creating purchase orders');
        $sup = trim((string)($d['supplier_id'] ?? ''));
        if ($sup === '') throw new \RuntimeException('Choose a supplier.');

        $lines = [];
        foreach ((array)($d['items'] ?? []) as $l) {
            $qty = (float)($l['qty'] ?? 0);
            $item = (string)($l['item_id'] ?? '');
            if ($item === '' || $qty <= 0) continue;
            $lines[] = ['item_id' => $item, 'qty' => $qty, 'cost' => (float)($l['cost'] ?? 0)];
        }
        if (!$lines) throw new \RuntimeException('Add at least one item with a quantity.');

        return DB::tx(function (PDO $p) use ($sup, $lines, $d) {
            $id = uuid(); $no = 'PO-' . date('ymd-His');
            $total = 0.0;
            foreach ($lines as $l) $total += $l['qty'] * $l['cost'];

            $p->prepare("INSERT INTO purchase_orders(id,tenant_id,site_id,po_no,supplier_id,order_date,
                            expected_date,status,subtotal,total_amount,notes,created_by_user_id)
                         VALUES(?,?,?,?,?,CURDATE(),?, 'SENT',?,?,?,?)")
              ->execute([$id, tenant_id(), site_id(), $no, $sup,
                         trim((string)($d['expected_date'] ?? '')) ?: null,
                         $total, $total, trim((string)($d['notes'] ?? '')) ?: null,
                         \Aio\Auth::user()['id'] ?? null]);

            foreach ($lines as $l) {
                /* `purchase_order_items` par tenant_id/site_id hai hi nahi —
                   wo parent PO se aate hain. Schema dump mein yeh columns
                   agli table ki lines ke saath mil gaye the aur main ne
                   ghalat maan liya. Asli DB par chala kar pakra gaya. */
                /* Purchase unit aur factor ITEM se lo — warna PO par
                   "10" likha hota hai aur kisi ko pata nahi 10 bag hain
                   ya 10 kg. `purchase_unit_name` ka koi default bhi
                   nahi, is liye insert wahin ruk jata tha. */
                $iq = $p->prepare("SELECT COALESCE(purchase_unit_name,'Unit') AS pu,
                                          COALESCE(purchase_factor,1) AS pf
                                     FROM inventory_items WHERE id=? LIMIT 1");
                $iq->execute([$l['item_id']]);
                $inv = $iq->fetch(PDO::FETCH_ASSOC) ?: ['pu' => 'Unit', 'pf' => 1];

                $p->prepare("INSERT INTO purchase_order_items(id,purchase_order_id,
                                inventory_item_id,purchase_qty,purchase_unit_name,purchase_factor,
                                unit_cost,tax_amount,line_total,received_qty)
                             VALUES(?,?,?,?,?,?,?,0,?,0)")
                  ->execute([uuid(), $id, $l['item_id'], $l['qty'],
                             (string)$inv['pu'], (float)$inv['pf'],
                             $l['cost'], $l['qty'] * $l['cost']]);
            }
            Audit::log('PO_CREATE', 'purchasing', ['id' => $id, 'label' => $no,
                       'new' => count($lines).' items / '.number_format($total, 2)]);
            return ['id' => $id, 'po_no' => $no,
                    'message' => 'Purchase order ' . $no . ' created for ' . count($lines) . ' item(s).'];
        });
    }

    /** PO ki abhi tak na aayi hui qty — GRN banate waqt kaam aati hai. */
    public static function poPending(string $poId): array
    {
        $q = DB::pdo()->prepare(
            "SELECT i.inventory_item_id AS item_id, COALESCE(ii.name,'?') AS name,
                    i.purchase_qty AS ordered, i.received_qty AS received,
                    (i.purchase_qty - i.received_qty) AS pending, i.unit_cost
               FROM purchase_order_items i
               LEFT JOIN inventory_items ii ON ii.id = i.inventory_item_id
              WHERE i.purchase_order_id=? AND (i.purchase_qty - i.received_qty) > 0.0001");
        $q->execute([$poId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function poCancel(string $poId, string $reason): array
    {
        Scope::requireManagement('cancelling purchase orders');
        $p = DB::pdo();
        $q = $p->prepare("SELECT po_no,status FROM purchase_orders WHERE id=? AND site_id=? LIMIT 1");
        $q->execute([$poId, site_id()]);
        $po = $q->fetch(PDO::FETCH_ASSOC);
        if (!$po) throw new \RuntimeException('Purchase order not found.');
        if (strtoupper((string)$po['status']) === 'RECEIVED') {
            throw new \RuntimeException('This order was already received and cannot be cancelled.');
        }
        $p->prepare("UPDATE purchase_orders SET status='CANCELLED', notes=CONCAT(COALESCE(notes,''),' | cancelled: ',?), updated_at=NOW(6) WHERE id=?")
          ->execute([substr($reason, 0, 160), $poId]);
        Audit::log('PO_CANCEL', 'purchasing', ['id' => $poId, 'label' => (string)$po['po_no'], 'desc' => $reason]);
        return ['message' => 'Purchase order ' . $po['po_no'] . ' cancelled.'];
    }

    /* ==================== RESERVATION -> TABLE ====================
       Booking kisi table se juri hi nahi hoti thi, is liye POS ko pata
       nahi chalta tha ke table 8 baje reserved hai. */

    /** Kya yeh table us waqt khali hai? */
    public static function tableFree(string $tableId, string $when, int $mins = 90, string $ignoreId = ''): bool
    {
        try {
            $sql = "SELECT COUNT(*) FROM reservations
                     WHERE site_id=? AND table_id=? AND status IN ('BOOKED','SEATED')
                       AND deleted_at IS NULL
                       AND reservation_at < DATE_ADD(?, INTERVAL ? MINUTE)
                       AND DATE_ADD(reservation_at, INTERVAL COALESCE(duration_min,90) MINUTE) > ?";
            $a = [site_id(), $tableId, $when, $mins, $when];
            if ($ignoreId !== '') { $sql .= " AND id<>?"; $a[] = $ignoreId; }
            $q = DB::pdo()->prepare($sql);
            $q->execute($a);
            return (int)$q->fetchColumn() === 0;
        } catch (\Throwable $e) { return true; }
    }

    /** POS ke liye: agle chand ghanton ki bookings, table ke hisab se. */
    public static function upcomingByTable(int $hours = 6): array
    {
        try {
            $q = DB::pdo()->prepare(
                "SELECT r.table_id, r.guest_name, r.guest_count, r.reservation_at,
                        COALESCE(dt.display_name,'') AS table_name
                   FROM reservations r
                   LEFT JOIN dining_tables dt ON dt.id = r.table_id
                  WHERE r.site_id=? AND r.table_id IS NOT NULL
                    AND r.status='BOOKED' AND r.deleted_at IS NULL
                    AND r.reservation_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? HOUR)
                  ORDER BY r.reservation_at");
            $q->execute([site_id(), $hours]);
            $out = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string)$r['table_id']][] = [
                    'guest' => (string)$r['guest_name'],
                    'pax'   => (int)$r['guest_count'],
                    'at'    => substr((string)$r['reservation_at'], 11, 5),
                ];
            }
            return $out;
        } catch (\Throwable $e) { return []; }
    }
}

// build: V83 build 2026-08-28
