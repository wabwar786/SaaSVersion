<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * ModuleBridge — approved UI ke generic module engine (records-list/save/delete)
 * ko REAL relational tables par map karta hai, UI contract change kiye baghair.
 *
 * UI shape wahi rehti hai jo MODULE_CONFIGS mein hai; yahan dono taraf translate
 * hota hai. Jo module yahan listed nahi, wo pehle ki tarah ui_records mein jata hai.
 *
 * Side effects real hain: wastage stock ghatata hai, expenses dashboard mein
 * aati hain, customers/suppliers POS aur purchasing ke saath share hote hain.
 */
final class ModuleBridge
{
    public const MODULES = ['customers', 'suppliers', 'expenses', 'wastage', 'menu', 'tables', 'printers'];

    /**
     * UI module -> DeleteService entity.
     * Pehle delete yahan hard-coded UPDATE se hota tha aur natija kabhi
     * user tak nahi pohanchta tha. Ab sab kuch DeleteService se guzarta
     * hai: wahi dependency checks, wahi tombstones, wahi audit.
     */
    private const DELETE_ENTITY = [
        'customers' => 'customer',
        'suppliers' => 'supplier',
        'expenses'  => 'expense',
        'menu'      => 'menu_item',
        'wastage'   => 'wastage',
        'tables'    => 'table',
        'printers'  => 'printer',
    ];

    public static function handles(string $module): bool
    {
        return in_array($module, self::MODULES, true);
    }

    /* ============================ LIST ============================ */

    public static function list(string $module): array
    {
        return match ($module) {
            'customers' => self::listCustomers(),
            'suppliers' => self::listSuppliers(),
            'expenses'  => self::listExpenses(),
            'wastage'   => self::listWastage(),
            'menu'      => self::listMenu(),
            'tables'    => self::listTables(),
            'printers'  => self::listPrinters(),
            default     => [],
        };
    }

    /* ============================ SAVE ============================ */

    /** Returns the record id (new or existing). */
    public static function save(string $module, string $id, array $d): string
    {
        return match ($module) {
            'customers' => self::saveCustomer($id, $d),
            'suppliers' => self::saveSupplier($id, $d),
            'expenses'  => self::saveExpense($id, $d),
            'wastage'   => self::saveWastage($id, $d),
            'menu'      => self::saveMenu($id, $d),
            'tables'    => self::saveTable($id, $d),
            'printers'  => self::savePrinter($id, $d),
            default     => throw new \RuntimeException('Unsupported module: '.$module),
        };
    }

    /* ============================ DELETE ============================ */

    /**
     * @param string $mode 'auto' | 'force' | 'deactivate'
     * @return array DeleteService ka poora natija — BLOCKED ki soorat mein
     *               asli wajah bhi saath jati hai (pehle sirf khamoshi thi).
     */
    public static function delete(string $module, string $id, string $mode = 'auto', string $reason = ''): array
    {
        $entity = self::DELETE_ENTITY[$module] ?? null;
        if ($entity === null) throw new \RuntimeException('Unsupported module: '.$module);
        return DeleteService::delete($entity, $id, $mode, $reason);
    }


    /* ------------------------- tables & floors -------------------------

       Yeh page pehle `ui_records` mein likhta tha jabke POS asli
       `dining_tables` se padhta hai — do alag jagah. Is liye yahan
       banayi hui table POS par kabhi nazar nahi aati thi aur yahan
       delete karne ka POS par koi asar hi nahi hota tha. Ab dono ek
       hi table par hain. */

    private static function listTables(): array
    {
        $q = DB::pdo()->prepare(
            "SELECT dt.id, dt.display_name name, dt.seats, dt.status,
                    COALESCE(f.name,'Main Floor') floor,
                    COALESCE((SELECT o.grand_total FROM orders o
                               WHERE o.table_id = dt.id AND o.order_status='OPEN'
                               ORDER BY o.created_at DESC LIMIT 1),0) bill
               FROM dining_tables dt
               LEFT JOIN floors f ON f.id = dt.floor_id
              WHERE dt.tenant_id=? AND dt.site_id=? AND dt.deleted_at IS NULL
              ORDER BY f.sort_order, dt.display_name"
        );
        $q->execute([tenant_id(), site_id()]);
        $map = ['AVAILABLE' => 'Free', 'OCCUPIED' => 'Occupied', 'RESERVED' => 'Reserved'];
        return array_map(fn($x) => [
            'id' => $x['id'], 'name' => $x['name'], 'floor' => $x['floor'],
            'seats' => (int)$x['seats'], 'bill' => (float)$x['bill'],
            'status' => $map[strtoupper((string)$x['status'])] ?? 'Free',
        ], $q->fetchAll());
    }

    private static function saveTable(string $id, array $d): string
    {
        $p = DB::pdo();
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') throw new \RuntimeException('Table name required');
        $seats = max(1, (int)($d['seats'] ?? 4));
        $status = ['Free' => 'AVAILABLE', 'Occupied' => 'OCCUPIED', 'Reserved' => 'RESERVED'][$d['status'] ?? 'Free'] ?? 'AVAILABLE';

        $floorName = trim((string)($d['floor'] ?? '')) ?: 'Main Floor';
        $fq = $p->prepare("SELECT id FROM floors WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");
        $fq->execute([site_id(), $floorName]);
        $fid = $fq->fetchColumn();
        if (!$fid) {
            $fid = uuid();
            $p->prepare("INSERT INTO floors(id,tenant_id,site_id,name,sort_order,is_active) VALUES(?,?,?,?,9,1)")
              ->execute([$fid, tenant_id(), site_id(), $floorName]);
        }

        if ($id !== '') {
            $st = $p->prepare("UPDATE dining_tables SET display_name=?, floor_id=?, seats=?, status=?, updated_at=NOW(6)
                                WHERE id=? AND tenant_id=? AND site_id=?");
            $st->execute([$name, $fid, $seats, $status, $id, tenant_id(), site_id()]);
            return $id;
        }
        $dupe = $p->prepare("SELECT id FROM dining_tables WHERE site_id=? AND display_name=? AND deleted_at IS NULL LIMIT 1");
        $dupe->execute([site_id(), $name]);
        if ($dupe->fetchColumn()) throw new \RuntimeException('A table with this name already exists');

        $id = uuid();
        $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 10))
              ?: ('T'.substr(str_replace('-', '', $id), 0, 4));
        $p->prepare("INSERT INTO dining_tables(id,tenant_id,site_id,floor_id,table_code,display_name,seats,shape,status,is_active)
                     VALUES(?,?,?,?,?,?,?,'SQUARE',?,1)")
          ->execute([$id, tenant_id(), site_id(), $fid, $code, $name, $seats, $status]);
        return $id;
    }

    /* ------------------------- printers -------------------------

       Yeh page bhi `ui_records` mein likhta tha jabke POS asli
       `printers` table se KOT bhejta hai. Yani yahan banaya hua printer
       kabhi print karta hi nahi tha. Aur "Status" ka dropdown user khud
       set karta tha — woh raye thi, haqeeqat nahi. Ab dono ek hi table
       par hain aur status asli test se aata hai. */

    private static function listPrinters(): array
    {
        $q = DB::pdo()->prepare(
            "SELECT id, name, printer_type, station_code, connection_type,
                    ip_address, port_no, windows_name, paper_width_mm, is_default, is_active
               FROM printers
              WHERE tenant_id=? AND site_id=? AND deleted_at IS NULL
              ORDER BY is_default DESC, name");
        $q->execute([tenant_id(), site_id()]);
        $types = ['RECEIPT' => 'Receipt Printer', 'KITCHEN' => 'KOT Printer',
                  'BAR' => 'Bar Printer', 'LABEL' => 'Label Printer'];
        return array_map(fn($x) => [
            'id'       => $x['id'],
            'name'     => $x['name'],
            'type'     => $types[strtoupper((string)$x['printer_type'])] ?? (string)$x['printer_type'],
            'location' => (string)($x['station_code'] ?? ''),
            'ip'       => (string)($x['ip_address'] ?? ''),
            'port'     => (int)($x['port_no'] ?? 0) ?: 9100,
            'conn'     => (string)$x['connection_type'],
            'winname'  => (string)($x['windows_name'] ?? ''),
            'paper'    => (int)($x['paper_width_mm'] ?? 80),
            'default'  => ((int)$x['is_default'] === 1) ? 'Yes' : 'No',
            'status'   => ((int)$x['is_active'] === 1) ? 'Active' : 'Inactive',
        ], $q->fetchAll());
    }

    private static function savePrinter(string $id, array $d): string
    {
        $p = DB::pdo();
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') throw new \RuntimeException('Printer name is required');

        $types = ['Receipt Printer' => 'RECEIPT', 'KOT Printer' => 'KITCHEN',
                  'Bar Printer' => 'BAR', 'Label Printer' => 'LABEL'];
        $type = $types[(string)($d['type'] ?? '')] ?? 'RECEIPT';
        $conn = strtoupper(trim((string)($d['conn'] ?? 'NETWORK'))) ?: 'NETWORK';
        $ip   = trim((string)($d['ip'] ?? ''));
        $port = (int)($d['port'] ?? 0) ?: 9100;
        $win  = trim((string)($d['winname'] ?? ''));
        $paper= (int)($d['paper'] ?? 80) ?: 80;
        $isDef= (string)($d['default'] ?? 'No') === 'Yes' ? 1 : 0;
        $act  = (string)($d['status'] ?? 'Active') === 'Active' ? 1 : 0;

        if ($conn === 'NETWORK' && $ip === '' && $win === '') {
            throw new \RuntimeException('Enter an IP address for a network printer, or a Windows printer name.');
        }

        if ($id !== '') {
            $st = $p->prepare("UPDATE printers SET name=?, printer_type=?, station_code=?, connection_type=?,
                                      ip_address=?, port_no=?, windows_name=?, paper_width_mm=?,
                                      is_default=?, is_active=?, updated_at=NOW(6)
                                WHERE id=? AND tenant_id=? AND site_id=?");
            $st->execute([$name, $type, (string)($d['location'] ?? ''), $conn, $ip ?: null, $port,
                          $win ?: null, $paper, $isDef, $act, $id, tenant_id(), site_id()]);
        } else {
            $id = uuid();
            $p->prepare("INSERT INTO printers(id,tenant_id,site_id,name,printer_type,station_code,connection_type,
                                              ip_address,port_no,windows_name,paper_width_mm,is_default,is_active)
                         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")
              ->execute([$id, tenant_id(), site_id(), $name, $type, (string)($d['location'] ?? ''), $conn,
                         $ip ?: null, $port, $win ?: null, $paper, $isDef, $act]);
        }

        /* Ek hi default reh sakta hai. */
        if ($isDef) {
            $p->prepare("UPDATE printers SET is_default=0 WHERE site_id=? AND id<>?")
              ->execute([site_id(), $id]);
        }
        return $id;
    }

    /* ------------------------- menu ------------------------- */

    private static function listMenu(): array
    {
        $q = DB::pdo()->prepare(
            "SELECT mi.id, mi.name, mi.base_price price, mi.is_active,
                    COALESCE(mc.name,'General') category,
                    COALESCE(r.food_cost_amount,
                             mi.direct_inventory_qty * ii.avg_cost_per_stock_unit, 0) cost
               FROM menu_items mi
               LEFT JOIN menu_categories mc ON mc.id = mi.category_id
               LEFT JOIN recipes r ON r.menu_item_id = mi.id AND r.is_current = 1 AND r.variant_id IS NULL
               LEFT JOIN inventory_items ii ON ii.id = mi.direct_inventory_item_id
              WHERE mi.tenant_id=? AND mi.site_id=? AND mi.deleted_at IS NULL
              ORDER BY mi.created_at DESC"
        );
        $q->execute([tenant_id(), site_id()]);
        return array_map(fn($x) => [
            'id' => $x['id'], 'name' => $x['name'], 'category' => $x['category'],
            'price' => (float)$x['price'], 'cost' => round((float)$x['cost'], 2),
            'status' => ((int)$x['is_active']) ? 'Active' : 'Inactive',
        ], $q->fetchAll());
    }

    /** Menu & Categories page ka save — POS grid mein foran reflect hota hai. */
    private static function saveMenu(string $id, array $d): string
    {
        $p = DB::pdo();
        $name  = trim((string)($d['name'] ?? ''));
        $price = (float)($d['price'] ?? 0);
        if ($name === '' || $price <= 0) throw new \RuntimeException('Item name and valid price required');
        $active = (strtolower((string)($d['status'] ?? 'Active')) !== 'inactive') ? 1 : 0;

        $catName = trim((string)($d['category'] ?? 'General')) ?: 'General';
        $cq = $p->prepare("SELECT id FROM menu_categories WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");
        $cq->execute([site_id(), $catName]);
        $cid = $cq->fetchColumn();
        if (!$cid) {
            $cid = uuid();
            $p->prepare("INSERT INTO menu_categories(id,tenant_id,site_id,name,icon_text,sort_order,is_active) VALUES(?,?,?,?,'•',99,1)")
              ->execute([$cid, tenant_id(), site_id(), $catName]);
        }

        if ($id !== '') {
            $p->prepare("UPDATE menu_items SET name=?, category_id=?, base_price=?, is_active=?, updated_at=NOW(6) WHERE id=? AND tenant_id=? AND site_id=?")
              ->execute([$name, $cid, $price, $active, $id, tenant_id(), site_id()]);
            return $id;
        }
        $dupe = $p->prepare("SELECT id FROM menu_items WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");
        $dupe->execute([site_id(), $name]);
        if ($dupe->fetchColumn()) throw new \RuntimeException('A menu item with this name already exists');
        $id = uuid();
        $p->prepare("INSERT INTO menu_items(id,tenant_id,site_id,category_id,name,item_type,consumption_type,base_price,is_active,is_online,is_pos) VALUES(?,?,?,?,?,'STANDARD','NONE',?,?,1,1)")
          ->execute([$id, tenant_id(), site_id(), $cid, $name, $price, $active]);
        return $id;
    }

    /* ------------------------- customers ------------------------- */

    private static function listCustomers(): array
    {
        $q = DB::pdo()->prepare(
            "SELECT c.id, c.full_name name, c.phone, c.customer_type type, c.status,
                    ca.address_text address, ca.area,
                    (SELECT COUNT(*) FROM orders o WHERE o.customer_id=c.id) orders,
                    (SELECT COALESCE(SUM(o.grand_total),0) FROM orders o WHERE o.customer_id=c.id AND o.order_status='CLOSED') spend
               FROM customers c
               LEFT JOIN customer_addresses ca ON ca.customer_id=c.id AND ca.is_default=1
              WHERE c.tenant_id=? AND c.deleted_at IS NULL AND c.status<>'INACTIVE'
              ORDER BY c.created_at DESC"
        );
        $q->execute([tenant_id()]);
        return array_map(fn($x) => [
            'id' => $x['id'], 'name' => $x['name'], 'phone' => $x['phone'] ?: '',
            'type' => ucfirst(strtolower((string)$x['type'] ?: 'Regular')),
            'address' => $x['address'] ?: '', 'area' => $x['area'] ?: '',
            'orders' => (int)$x['orders'], 'spend' => (float)$x['spend'],
            'status' => 'Active',
        ], $q->fetchAll());
    }

    private static function saveCustomer(string $id, array $d): string
    {
        $p = DB::pdo();
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') throw new \RuntimeException('Customer name required');
        $type = strtoupper(trim((string)($d['type'] ?? 'REGULAR'))) ?: 'REGULAR';
        if ($id !== '') {
            $p->prepare("UPDATE customers SET full_name=?, phone=?, customer_type=?, updated_at=NOW(6) WHERE id=? AND tenant_id=?")
              ->execute([$name, $d['phone'] ?? '', $type, $id, tenant_id()]);
        } else {
            $id = uuid();
            $p->prepare("INSERT INTO customers(id,tenant_id,full_name,phone,customer_type,status,created_at) VALUES(?,?,?,?,?,'ACTIVE',NOW(6))")
              ->execute([$id, tenant_id(), $name, $d['phone'] ?? '', $type]);
        }
        $addr = trim((string)($d['address'] ?? ''));
        if ($addr !== '') {
            $q = $p->prepare("SELECT id FROM customer_addresses WHERE customer_id=? AND is_default=1 LIMIT 1");
            $q->execute([$id]);
            if ($aid = $q->fetchColumn()) {
                $p->prepare("UPDATE customer_addresses SET address_text=?, area=? WHERE id=?")
                  ->execute([$addr, $d['area'] ?? '', $aid]);
            } else {
                $p->prepare("INSERT INTO customer_addresses(id,customer_id,label,address_text,area,is_default) VALUES(?,?,?,?,?,1)")
                  ->execute([uuid(), $id, 'Default', $addr, $d['area'] ?? '']);
            }
        }
        return $id;
    }

    /* ------------------------- suppliers ------------------------- */

    private static function listSuppliers(): array
    {
        $q = DB::pdo()->prepare(
            "SELECT s.id, s.name, s.contact_person person, s.phone, s.city, s.category cat, s.status,
                    (SELECT COUNT(DISTINCT si.inventory_item_id) FROM supplier_items si WHERE si.supplier_id=s.id) items,
                    (SELECT COALESCE(SUM(gr.total_amount),0) FROM goods_receipts gr
                      WHERE gr.supplier_id=s.id AND gr.received_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')) mtd,
                    (SELECT COALESCE(SUM(gr.total_amount),0) FROM goods_receipts gr WHERE gr.supplier_id=s.id)
                    - (SELECT COALESCE(SUM(sp.amount),0) FROM supplier_payments sp WHERE sp.supplier_id=s.id) bal
               FROM suppliers s
              WHERE s.tenant_id=? AND s.deleted_at IS NULL AND s.status<>'INACTIVE'
              ORDER BY s.name"
        );
        $q->execute([tenant_id()]);
        return array_map(fn($x) => [
            'id' => $x['id'], 'name' => $x['name'], 'person' => $x['person'] ?: '',
            'phone' => $x['phone'] ?: '', 'city' => $x['city'] ?: '', 'cat' => $x['cat'] ?: 'General',
            'items' => (int)$x['items'], 'mtd' => (float)$x['mtd'], 'bal' => max(0, (float)$x['bal']),
            'status' => ucfirst(strtolower((string)$x['status'])),
        ], $q->fetchAll());
    }

    private static function saveSupplier(string $id, array $d): string
    {
        $p = DB::pdo();
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') throw new \RuntimeException('Supplier name required');
        if ($id !== '') {
            $p->prepare("UPDATE suppliers SET name=?, contact_person=?, phone=?, city=?, category=?, status=?, updated_at=NOW(6) WHERE id=? AND tenant_id=?")
              ->execute([$name, $d['person'] ?? '', $d['phone'] ?? '', $d['city'] ?? '', $d['cat'] ?? 'General',
                         strtoupper((string)($d['status'] ?? 'ACTIVE')) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE',
                         $id, tenant_id()]);
        } else {
            $id = uuid();
            $p->prepare("INSERT INTO suppliers(id,tenant_id,site_id,name,contact_person,phone,city,category,status,created_at) VALUES(?,?,?,?,?,?,?,?,'ACTIVE',NOW(6))")
              ->execute([$id, tenant_id(), site_id(), $name, $d['person'] ?? '', $d['phone'] ?? '', $d['city'] ?? '', $d['cat'] ?? 'General']);
        }
        return $id;
    }

    /* ------------------------- expenses ------------------------- */

    private static function listExpenses(): array
    {
        $q = DB::pdo()->prepare(
            "SELECT e.id, e.expense_no ref, DATE_FORMAT(e.expense_date,'%d %b %Y') date, e.amount,
                    e.payment_method method, e.description note, e.status, COALESCE(ec.name,'General') cat
               FROM expenses e
               LEFT JOIN expense_categories ec ON ec.id=e.category_id
              WHERE e.tenant_id=? AND e.site_id=? AND e.status<>'REJECTED' AND e.deleted_at IS NULL
              ORDER BY e.expense_date DESC, e.created_at DESC LIMIT 500"
        );
        $q->execute([tenant_id(), site_id()]);
        return array_map(fn($x) => [
            'id' => $x['id'], 'ref' => $x['ref'], 'date' => $x['date'], 'cat' => $x['cat'],
            'amount' => (float)$x['amount'], 'method' => ucfirst(strtolower((string)$x['method'])),
            'note' => $x['note'] ?: '', 'status' => ucfirst(strtolower((string)$x['status'])),
        ], $q->fetchAll());
    }

    private static function saveExpense(string $id, array $d): string
    {
        $p = DB::pdo();
        $amount = (float)($d['amount'] ?? 0);
        if ($amount <= 0) throw new \RuntimeException('Expense amount must be greater than zero');
        $catName = trim((string)($d['cat'] ?? 'General')) ?: 'General';
        $q = $p->prepare("SELECT id FROM expense_categories WHERE tenant_id=? AND name=? LIMIT 1");
        $q->execute([tenant_id(), $catName]);
        $cid = $q->fetchColumn();
        if (!$cid) {
            $cid = uuid();
            $p->prepare("INSERT INTO expense_categories(id,tenant_id,name,is_active) VALUES(?,?,?,1)")
              ->execute([$cid, tenant_id(), $catName]);
        }
        $method = strtoupper((string)($d['method'] ?? 'CASH')) ?: 'CASH';
        $note = (string)($d['note'] ?? '');
        if ($id !== '') {
            $p->prepare("UPDATE expenses SET category_id=?, amount=?, payment_method=?, description=?, updated_at=NOW(6) WHERE id=? AND tenant_id=? AND site_id=?")
              ->execute([$cid, $amount, $method, $note, $id, tenant_id(), site_id()]);
            return $id;
        }
        $id = uuid();
        $ref = trim((string)($d['ref'] ?? '')) ?: ('EXP-'.date('ymd').'-'.strtoupper(substr(str_replace('-', '', $id), 0, 4)));
        $p->prepare("INSERT INTO expenses(id,tenant_id,site_id,expense_no,expense_date,category_id,amount,payment_method,description,status,created_by_user_id,created_at)
                     VALUES(?,?,?,?,CURDATE(),?,?,?,?,'APPROVED',?,NOW(6))")
          ->execute([$id, tenant_id(), site_id(), $ref, $cid, $amount, $method, $note, current_user()['id'] ?? null]);
        return $id;
    }

    /* ------------------------- wastage ------------------------- */

    private static function listWastage(): array
    {
        $q = DB::pdo()->prepare(
            "SELECT sa.id, sa.adjustment_no ref, DATE_FORMAT(sa.requested_at,'%d %b %Y') date,
                    sa.reason_code reason, sa.note, ii.name item, u.code unit,
                    ABS(stl.qty_change) qty, ABS(stl.value_change) value
               FROM stock_adjustments sa
               JOIN stock_transactions st ON st.reference_type='STOCK_ADJUSTMENT' AND st.reference_id=sa.id
               JOIN stock_transaction_lines stl ON stl.stock_transaction_id=st.id
               JOIN inventory_items ii ON ii.id=stl.inventory_item_id
               JOIN units u ON u.id=ii.stock_unit_id
              WHERE sa.tenant_id=? AND sa.site_id=?
              ORDER BY sa.requested_at DESC LIMIT 500"
        );
        $q->execute([tenant_id(), site_id()]);
        return array_map(fn($x) => [
            'id' => $x['id'], 'ref' => $x['ref'], 'date' => $x['date'],
            'item' => $x['item'], 'qty' => (float)$x['qty'], 'unit' => $x['unit'],
            'reason' => $x['reason'] ?: 'WASTAGE', 'value' => (float)$x['value'],
            'note' => $x['note'] ?: '', 'status' => 'Posted',
        ], $q->fetchAll());
    }

    /**
     * Wastage save = REAL stock decrement:
     * stock_adjustments + stock_transactions/lines + stock_balances (via InventoryService).
     * UI field shape: { item | itemId, qty, reason, note }
     */
    private static function saveWastage(string $id, array $d): string
    {
        if ($id !== '') throw new \RuntimeException('Posted wastage cannot be edited. Post a correcting entry.');
        $qty = (float)($d['qty'] ?? 0);
        if ($qty <= 0) throw new \RuntimeException('Wastage quantity must be greater than zero');

        return DB::tx(function (PDO $p) use ($d, $qty) {
            // resolve inventory item by id or name
            $item = null;
            $raw = (string)($d['itemId'] ?? '');
            if ($raw !== '' && preg_match('/^[0-9a-f-]{36}$/i', $raw)) {
                $q = $p->prepare("SELECT id,name,avg_cost_per_stock_unit,default_storage_location_id FROM inventory_items WHERE id=? AND site_id=? AND deleted_at IS NULL");
                $q->execute([$raw, site_id()]);
                $item = $q->fetch();
            }
            if (!$item && !empty($d['item'])) {
                $q = $p->prepare("SELECT id,name,avg_cost_per_stock_unit,default_storage_location_id FROM inventory_items WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");
                $q->execute([site_id(), (string)$d['item']]);
                $item = $q->fetch();
            }
            if (!$item) throw new \RuntimeException('Inventory item not found: '.(string)($d['item'] ?? $d['itemId'] ?? ''));

            $loc = $item['default_storage_location_id'];
            if (!$loc) {
                $q = $p->prepare("SELECT id FROM stock_locations WHERE site_id=? AND is_active=1 ORDER BY name LIMIT 1");
                $q->execute([site_id()]);
                $loc = $q->fetchColumn();
            }
            if (!$loc) throw new \RuntimeException('No stock location configured');

            $uid = current_user()['id'] ?? null;
            if (!$uid) throw new \RuntimeException('Login required');
            $said = uuid();
            $ref  = 'WST-'.date('ymd').'-'.strtoupper(substr(str_replace('-', '', $said), 0, 4));
            $reason = strtoupper(trim((string)($d['reason'] ?? 'WASTAGE'))) ?: 'WASTAGE';
            $p->prepare("INSERT INTO stock_adjustments(id,tenant_id,site_id,adjustment_no,reason_code,note,status,requested_by_user_id,approved_by_user_id,requested_at,approved_at)
                         VALUES(?,?,?,?,?,?,'APPROVED',?,?,NOW(6),NOW(6))")
              ->execute([$said, tenant_id(), site_id(), $ref, $reason, (string)($d['note'] ?? ''), $uid, $uid]);
              $uid = current_user()['id'] ?? null;
            if(!$uid) throw new \RuntimeException('Login required');

            InventoryService::postMovement($p, 'WASTAGE', 'STOCK_ADJUSTMENT', $said, $ref, [
                (object)[
                    'item_id' => $item['id'], 'location_id' => $loc,
                    'qty' => -$qty, 'unit_cost' => (float)$item['avg_cost_per_stock_unit'],
                    'source_order_item_id' => null,
                ],
            ], current_user()['id'] ?? null);

            return $said;
        });
    }
}

// build: V62 build 2026-08-26
