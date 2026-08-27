<?php
namespace Aio\Services;

use Aio\Auth;
use Aio\DB;
use PDO;

/**
 * DeleteService — poore software ke liye EK delete contract.
 *
 * Pehle har page ka apna tareeqa tha (ya bilkul tha hi nahi), aur
 * `module.js` server ka jawab check hi nahi karta tha — user ko "Record
 * removed" dikhta tha jabke row database mein zinda hoti thi. KHAMOSH
 * FAILURE. Ab har delete isi jagah se guzarta hai aur teen mein se EK
 * saaf natija deta hai:
 *
 *   DELETED  — done (soft: deleted_at + is_active=0)
 *   BLOCKED  — nahi ho sakta, ASLI wajah ke saath ("42 bills mein use hua hai")
 *              + can_deactivate / can_force ke options
 *   FORCED   — Admin ne manager password se zabardasti kiya; child rows
 *              samet, tombstone + audit ke saath
 *
 * SOFT vs HARD aur SYNC:
 *   Soft delete khud-ba-khud sync hoti hai (row `deleted_at` ke saath
 *   push/pull hoti hai, doosri taraf bhi gayab). HARD delete sync ke liye
 *   ANDHI hai — is liye har hard delete pehle `sync_tombstones` mein
 *   nishan chhorta hai, jo doosri taraf ja kar wahan bhi row uda deta hai.
 *   Isi liye default hamesha SOFT hai.
 *
 * Permission: Owner/Admin ko sab, warna `user_form_permissions.can_delete`
 * (yeh table pehle se maujood thi magar kabhi use hi nahi ho rahi thi).
 */
final class DeleteService
{
    /** row_id ki jagah yeh ho to poori scope wipe hoti hai (reset/purge). */
    public const WIPE_ALL = 'ALL';

    /* ==================================================================
       ENTITY REGISTRY
       scope: tenant | site | none
       soft:  soft-delete column (null = sirf hard delete mumkin)
       deps:  rukawatein — har ek { sql (COUNT, ek ? = row id), msg }
       kids:  FORCE par saath delete hone wali child rows
       ================================================================== */
    private const ENTITIES = [

        'user' => [
            'label' => 'User', 'table' => 'users', 'scope' => 'tenant',
            'name' => 'full_name', 'soft' => 'deleted_at', 'active' => null,
            'module' => 'users', 'form' => 'users',
            'deps' => [
                ['sql' => "SELECT COUNT(*) FROM cashier_shifts WHERE cashier_user_id=? AND status='OPEN'",
                 'msg' => 'this user still has {n} open shift(s) - close them first'],
            ],
            'kids' => [
                ['table' => 'user_roles', 'col' => 'user_id'],
                ['table' => 'user_module_access', 'col' => 'user_id'],
                ['table' => 'user_form_permissions', 'col' => 'user_id'],
            ],
        ],

        'menu_item' => [
            'label' => 'Menu item', 'table' => 'menu_items', 'scope' => 'site',
            'name' => 'name', 'soft' => 'deleted_at', 'active' => 'is_active',
            'module' => 'menu', 'form' => 'menu_items',
            'deps' => [
                ['sql' => "SELECT COUNT(*) FROM order_items WHERE menu_item_id=?",
                 'msg' => 'used in {n} bill line(s) - marking it inactive is safer than deleting'],
            ],
            'kids' => [
                ['table' => 'recipes', 'col' => 'menu_item_id'],
                ['table' => 'menu_item_variants', 'col' => 'menu_item_id'],
            ],
        ],

        'menu_category' => [
            'label' => 'Menu category', 'table' => 'menu_categories', 'scope' => 'site',
            'name' => 'name', 'soft' => 'deleted_at', 'active' => 'is_active',
            'module' => 'menu', 'form' => 'menu_categories',
            'deps' => [
                ['sql' => "SELECT COUNT(*) FROM menu_items WHERE category_id=? AND deleted_at IS NULL",
                 'msg' => 'this category has {n} item(s) - move or remove them first'],
            ],
            'kids' => [
                ['table' => 'menu_category_printer_routes', 'col' => 'category_id'],
            ],
        ],

        'inventory_item' => [
            'label' => 'Inventory item', 'table' => 'inventory_items', 'scope' => 'site',
            'name' => 'name', 'soft' => 'deleted_at', 'active' => 'is_active',
            'module' => 'inventory', 'form' => 'inventory_items',
            'deps' => [
                ['sql' => "SELECT COUNT(*) FROM recipe_ingredients WHERE inventory_item_id=?",
                 'msg' => '{n} recipe(s) use this item'],
                ['sql' => "SELECT COALESCE(SUM(CASE WHEN qty_on_hand<>0 THEN 1 ELSE 0 END),0) FROM stock_balances WHERE inventory_item_id=?",
                 'msg' => 'this item still has stock in {n} location(s) - clear it via wastage or transfer first'],
            ],
            'kids' => [
                ['table' => 'stock_balances', 'col' => 'inventory_item_id'],
                ['table' => 'supplier_items', 'col' => 'inventory_item_id'],
            ],
        ],

        'inventory_category' => [
            'label' => 'Inventory category', 'table' => 'inventory_categories', 'scope' => 'site',
            'name' => 'name', 'soft' => 'deleted_at', 'active' => 'is_active',
            'module' => 'inventory', 'form' => 'inventory_categories',
            'deps' => [
                ['sql' => "SELECT COUNT(*) FROM inventory_items WHERE category_id=? AND deleted_at IS NULL",
                 'msg' => 'this category has {n} item(s)'],
            ],
            'kids' => [],
        ],

        'recipe' => [
            'label' => 'Recipe', 'table' => 'recipes', 'scope' => 'site',
            'name' => 'name', 'soft' => 'deleted_at', 'active' => null,
            'module' => 'recipe', 'form' => 'recipes',
            'deps' => [],
            'kids' => [
                ['table' => 'recipe_ingredients', 'col' => 'recipe_id'],
            ],
        ],

        'supplier' => [
            'label' => 'Supplier', 'table' => 'suppliers', 'scope' => 'tenant',
            'name' => 'name', 'soft' => 'deleted_at', 'active' => null,
            'module' => 'suppliers', 'form' => 'suppliers',
            'deps' => [
                ['sql' => "SELECT COUNT(*) FROM goods_receipts WHERE supplier_id=?",
                 'msg' => 'this supplier has {n} purchase receipt(s) - marking it inactive is safer than deleting'],
            ],
            'kids' => [
                ['table' => 'supplier_items', 'col' => 'supplier_id'],
            ],
        ],

        'customer' => [
            'label' => 'Customer', 'table' => 'customers', 'scope' => 'tenant',
            'name' => 'full_name', 'soft' => 'deleted_at', 'active' => null,
            'module' => 'customers', 'form' => 'customers',
            'deps' => [
                ['sql' => "SELECT COUNT(*) FROM orders WHERE customer_id=?",
                 'msg' => 'this customer has {n} order(s)'],
            ],
            'kids' => [
                ['table' => 'customer_addresses', 'col' => 'customer_id'],
            ],
        ],

        'expense' => [
            'label' => 'Expense', 'table' => 'expenses', 'scope' => 'site',
            'name' => 'expense_no', 'soft' => 'deleted_at', 'active' => null,
            'module' => 'expenses', 'form' => 'expenses',
            'deps' => [], 'kids' => [],
        ],

        'table' => [
            'label' => 'Table', 'table' => 'dining_tables', 'scope' => 'site',
            'name' => 'display_name', 'soft' => 'deleted_at', 'active' => 'is_active',
            'module' => 'tables', 'form' => 'dining_tables',
            'deps' => [
                ['sql' => "SELECT COUNT(*) FROM orders WHERE table_id=? AND order_status='OPEN'",
                 'msg' => 'this table has {n} open bill(s) - close them first'],
            ],
            'kids' => [],
        ],

        'floor' => [
            'label' => 'Floor', 'table' => 'floors', 'scope' => 'site',
            'name' => 'name', 'soft' => 'deleted_at', 'active' => 'is_active',
            'module' => 'tables', 'form' => 'floors',
            'deps' => [
                ['sql' => "SELECT COUNT(*) FROM dining_tables WHERE floor_id=? AND deleted_at IS NULL",
                 'msg' => 'this floor has {n} table(s)'],
            ],
            'kids' => [],
        ],

        'printer' => [
            'label' => 'Printer', 'table' => 'printers', 'scope' => 'site',
            'name' => 'name', 'soft' => 'deleted_at', 'active' => 'is_active',
            'module' => 'printers', 'form' => 'printers',
            'deps' => [], 'kids' => [
                ['table' => 'menu_category_printer_routes', 'col' => 'printer_id'],
            ],
        ],

        'shift' => [
            'label' => 'Shift', 'table' => 'cashier_shifts', 'scope' => 'site',
            'name' => 'shift_no', 'soft' => 'deleted_at', 'active' => null,
            'module' => 'shift', 'form' => 'cashier_shifts',
            'deps' => [
                ['sql' => "SELECT COUNT(*) FROM cashier_shifts WHERE id=? AND status='OPEN'",
                 'msg' => 'this shift is still open - close it first'],
            ],
            'kids' => [
                ['table' => 'shift_cash_movements', 'col' => 'shift_id'],
            ],
        ],

        'qr_order' => [
            'label' => 'QR order', 'table' => 'qr_orders', 'scope' => 'site',
            'name' => 'table_name', 'soft' => null, 'active' => null,
            'module' => 'pos', 'form' => 'qr_orders',
            'deps' => [], 'kids' => [],
        ],

        /* --- special: stock pehle hi hil chuka hai, reverse karna parta hai --- */
        'grn' => [
            'label' => 'Purchase receipt', 'table' => 'goods_receipts', 'scope' => 'site',
            'name' => 'grn_no', 'soft' => 'deleted_at', 'active' => null,
            'module' => 'purchasing', 'form' => 'goods_receipts',
            'reverses_stock' => true,
            'deps' => [], 'kids' => [],
        ],
        'wastage' => [
            'label' => 'Wastage entry', 'table' => 'stock_adjustments', 'scope' => 'site',
            'name' => 'adjustment_no', 'soft' => null, 'active' => null,
            'module' => 'wastage', 'form' => 'stock_adjustments',
            'reverses_stock' => true,
            'deps' => [], 'kids' => [],
        ],

        /* --- generic UI records (staff, riders, promotions, reservations...) --- */
        'ui_record' => [
            'label' => 'Record', 'table' => 'ui_records', 'scope' => 'tenant',
            'name' => 'module_key', 'soft' => 'deleted_flag', 'active' => null,
            'module' => '', 'form' => 'ui_records',
            'deps' => [], 'kids' => [],
        ],

        /* --- PROTECTED: bill kabhi delete nahi hota, sirf VOID (FBR + accounting) --- */
        'order' => [
            'label' => 'Bill', 'table' => 'orders', 'scope' => 'site',
            'name' => 'bill_no', 'soft' => null, 'active' => null,
            'module' => 'void', 'form' => 'orders',
            'protected' => 'Bills cannot be deleted - it would break accounting and FBR records. '
                         . 'Void it instead (with a reason and manager password); the bill number stays in history.',
            'deps' => [], 'kids' => [],
        ],
    ];

    public static function has(string $entity): bool
    {
        return isset(self::ENTITIES[$entity]);
    }

    /** UI ke liye: kaunsi entities delete ho sakti hain. */
    public static function entities(): array
    {
        $out = [];
        foreach (self::ENTITIES as $k => $e) {
            $out[] = ['entity' => $k, 'label' => $e['label'], 'table' => $e['table'],
                      'soft' => !empty($e['soft']), 'protected' => (string)($e['protected'] ?? '')];
        }
        return $out;
    }

    /* ================= helpers ================= */

    private static function cfgOf(string $entity): array
    {
        if (!isset(self::ENTITIES[$entity])) {
            throw new \RuntimeException('Unknown record type: '.$entity);
        }
        return self::ENTITIES[$entity];
    }

    private static function tableExists(string $t): bool
    {
        static $cache = [];
        if (isset($cache[$t])) return $cache[$t];
        $q = DB::pdo()->prepare("SELECT COUNT(*) AS n FROM information_schema.tables
                                  WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$t]);
        return $cache[$t] = (bool)$q->fetchColumn();
    }

    private static function columns(string $t): array
    {
        static $cache = [];
        if (isset($cache[$t])) return $cache[$t];
        /* MySQL 8 UPPERCASE deta hai, MariaDB lowercase — explicit alias. */
        $q = DB::pdo()->prepare("SELECT column_name AS c FROM information_schema.columns
                                  WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$t]);
        return $cache[$t] = array_column($q->fetchAll(), 'c');
    }

    private static function hasCol(string $t, string $c): bool
    {
        return in_array($c, self::columns($t), true);
    }

    /** ui_records ka soft column `deleted` hai, baqi sab ka `deleted_at`. */
    private static function softCol(array $e): ?string
    {
        if (($e['soft'] ?? null) === 'deleted_flag') return 'deleted';
        return $e['soft'] ?? null;
    }

    /** Scope WHERE — ek tenant doosre ka data kabhi delete na kar sake. */
    private static function scopeWhere(array $e): array
    {
        $t = $e['table'];
        $where = 'id = ?';
        $args  = [];
        if ($e['scope'] === 'tenant' || $e['scope'] === 'site') {
            if (self::hasCol($t, 'tenant_id')) { $where .= ' AND tenant_id = ?'; $args[] = tenant_id(); }
        }
        if ($e['scope'] === 'site' && self::hasCol($t, 'site_id')) {
            $where .= ' AND (site_id = ? OR site_id IS NULL)'; $args[] = site_id();
        }
        return [$where, $args];
    }

    private static function loadRow(array $e, string $id): ?array
    {
        [$where, $args] = self::scopeWhere($e);
        $q = DB::pdo()->prepare("SELECT * FROM `{$e['table']}` WHERE $where LIMIT 1");
        $q->execute(array_merge([$id], $args));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private static function labelOf(array $e, array $row): string
    {
        $c = $e['name'] ?? 'id';
        return (string)($row[$c] ?? $row['id'] ?? '');
    }

    /* ================= permission ================= */

    private static function mayDelete(array $e): bool
    {
        if (Auth::isAdmin()) return true;
        $m = (string)($e['module'] ?? '');
        if ($m === '') return Auth::isManager();
        return Auth::can($m, (string)$e['form'], 'delete');
    }

    private static function mayForce(): bool
    {
        return Auth::isAdmin() || Auth::isManager();
    }

    /* ================= dependency check ================= */

    /** @return array<int,string> saaf, insaani wajahein */
    private static function blockers(array $e, string $id): array
    {
        $out = [];
        foreach (($e['deps'] ?? []) as $d) {
            try {
                // jis table par check hai wo maujood na ho to check skip
                if (preg_match('/FROM\s+`?(\w+)`?/i', $d['sql'], $m) && !self::tableExists($m[1])) continue;
                $q = DB::pdo()->prepare($d['sql']);
                $q->execute([$id]);
                $n = (int)$q->fetchColumn();
                if ($n > 0) $out[] = str_replace('{n}', (string)$n, $d['msg']);
            } catch (\Throwable $ex) {
                /* Check khud fail ho jaye to delete rok dena galat hai, magar
                   chupchaap guzarna bhi galat. Reason user tak jayegi. */
                $out[] = 'Dependency check could not run: '.substr($ex->getMessage(), 0, 120);
            }
        }
        return $out;
    }

    /* ================= tombstone ================= */

    /**
     * HARD delete ka nishan. Isi ke baghair cloud par delete ki hui rows
     * branch computer par zinda reh jati thin.
     * AdminData (factory reset / purge / delete business) bhi yehi use karta hai.
     */
    public static function tombstone(PDO $pdo, string $table, string $rowId,
                                     string $reason = '', string $mode = 'ROW',
                                     ?string $beforeTs = null, ?string $tenantId = null,
                                     ?string $siteId = null): void
    {
        try {
            $pdo->prepare(
                "INSERT INTO sync_tombstones
                   (id,tenant_id,site_id,table_name,row_id,scope_mode,before_ts,reason,origin_node,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW(6),NOW(6))"
            )->execute([
                uuid(),
                $tenantId ?? (function_exists('tenant_id') ? tenant_id() : null),
                $siteId   ?? (function_exists('site_id') ? site_id() : null),
                $table, $rowId, $mode, $beforeTs,
                $reason !== '' ? substr($reason, 0, 200) : null,
                (string)cfg('app.role'),
            ]);
        } catch (\Throwable $e) {
            /* Tombstone likhna nakaam ho to delete bhi nahi honi is required —
               warna dono taraf ka data hamesha ke liye alag ho jayega. */
            throw new \RuntimeException('Delete stopped: the sync tombstone could not be written ('
                . substr($e->getMessage(), 0, 120) . '). Please run migrate_delete_support.php.');
        }
    }

    /**
     * Poori table ka wipe marker (factory reset / purge ke liye).
     * Doosri taraf yeh ek hi row us table ki saari tenant/site rows uda deti hai.
     */
    public static function wipeMarker(PDO $pdo, string $table, string $reason,
                                      ?string $beforeTs = null,
                                      ?string $tenantId = null, ?string $siteId = null): void
    {
        self::tombstone($pdo, $table, self::WIPE_ALL, $reason, 'WIPE', $beforeTs, $tenantId, $siteId);
    }

    /* ================= audit ================= */

    private static function log(string $entity, array $e, string $rowId, string $label,
                                string $action, string $reason, int $kids = 0): void
    {
        try {
            $u = Auth::user();
            DB::pdo()->prepare(
                "INSERT INTO deletion_log
                   (id,tenant_id,site_id,entity,table_name,row_id,row_label,action,reason,
                    actor_user_id,actor_name,origin_node,child_rows,created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(6))"
            )->execute([
                uuid(), tenant_id(), site_id(), $entity, $e['table'], $rowId,
                substr($label, 0, 200), $action,
                $reason !== '' ? substr($reason, 0, 300) : null,
                $u['id'] ?? null, substr((string)($u['full_name'] ?? ''), 0, 120),
                (string)cfg('app.role'), $kids,
            ]);
        } catch (\Throwable $ex) { /* audit best-effort — delete kabhi na rukay */ }
    }

    /* ==================================================================
       MAIN ENTRY
       ================================================================== */

    /**
     * @param string $mode 'auto' (soft, blockers respect) | 'force' (Admin, cascade)
     *                     | 'deactivate' (sirf Inactive)
     * @return array{result:string,message:string,blockers:array,can_deactivate:bool,can_force:bool,label:string}
     */
    public static function delete(string $entity, string $id, string $mode = 'auto', string $reason = ''): array
    {
        $e  = self::cfgOf($entity);
        $id = trim($id);
        if ($id === '') throw new \RuntimeException('Record id is required');

        if (!self::tableExists($e['table'])) {
            throw new \RuntimeException("Table `{$e['table']}` does not exist in this database. Please run the migrations.");
        }

        $row = self::loadRow($e, $id);
        if (!$row) {
            /* Pehle yeh khamoshi se "ok" ho jata tha aur user reload par row
               dobara dekh kar hairan hota tha. */
            throw new \RuntimeException(($e['label'] ?? 'Record').' not found (or it does not belong to your branch/business).');
        }
        $label = self::labelOf($e, $row);

        if (!self::mayDelete($e)) {
            throw new \RuntimeException('You do not have permission to '.strtolower($e['label']).'. Please contact your administrator.');
        }

        /* --- PROTECTED (bills) --- */
        if (!empty($e['protected'])) {
            return ['result' => 'BLOCKED', 'label' => $label,
                    'message' => (string)$e['protected'],
                    'blockers' => [(string)$e['protected']],
                    'can_deactivate' => false, 'can_force' => false, 'can_void' => true];
        }

        /* --- sirf Inactive --- */
        if ($mode === 'deactivate') {
            return self::deactivate($entity, $id, $reason);
        }

        $blockers = self::blockers($e, $id);
        $soft     = self::softCol($e);

        if ($blockers && $mode !== 'force') {
            return ['result' => 'BLOCKED', 'label' => $label,
                    'message' => $e['label'].' "'.$label.'" cannot be deleted:',
                    'blockers' => $blockers,
                    'can_deactivate' => (bool)($e['active'] ?? null) || $soft !== null,
                    'can_force' => self::mayForce(), 'can_void' => false];
        }

        if ($mode === 'force') {
            if (!self::mayForce()) {
                throw new \RuntimeException('Only an Admin or Manager can force delete.');
            }
            $kids = self::hardDelete($e, $id, $reason ?: 'force delete');
            self::log($entity, $e, $id, $label, 'FORCE', $reason, $kids);
            return ['result' => 'FORCED', 'label' => $label,
                    'message' => $e['label'].' "'.$label.'" was permanently deleted'
                               . ($kids ? " ({$kids} including related rows)" : '').'.',
                    'blockers' => [], 'can_deactivate' => false, 'can_force' => false, 'can_void' => false];
        }

        /* --- default: SOFT (sync-safe) --- */
        if ($soft === null) {
            /* Soft column hai hi nahi (qr_orders, wastage) -> hard + tombstone */
            $kids = self::hardDelete($e, $id, $reason ?: 'delete');
            self::log($entity, $e, $id, $label, 'HARD', $reason, $kids);
            return ['result' => 'DELETED', 'label' => $label,
                    'message' => $e['label'].' "'.$label.'" was deleted.',
                    'blockers' => [], 'can_deactivate' => false, 'can_force' => false, 'can_void' => false];
        }

        self::softDelete($e, $id);
        self::log($entity, $e, $id, $label, 'SOFT', $reason);
        return ['result' => 'DELETED', 'label' => $label,
                'message' => $e['label'].' "'.$label.'" was deleted.',
                'blockers' => [], 'can_deactivate' => false, 'can_force' => false, 'can_void' => false];
    }

    /* ================= soft ================= */

    private static function softDelete(array $e, string $id): void
    {
        $p = DB::pdo();
        $t = $e['table'];
        $soft = self::softCol($e);
        [$where, $args] = self::scopeWhere($e);

        /* Migration chali hi na ho to raw SQL error user ke munh par
           lagta hai. Saaf wajah behtar hai. */
        $physical = $soft === 'deleted' ? 'deleted' : $soft;
        if (!self::hasCol($t, $physical)) {
            throw new \RuntimeException(
                "`$t` is missing the `$physical` column - please run "
                . "`php scripts/migrate_delete_support.php` first.");
        }

        $sets = [];
        if ($soft === 'deleted') $sets[] = "`deleted` = 1";
        else                     $sets[] = "`$soft` = NOW(6)";
        if (!empty($e['active']) && self::hasCol($t, (string)$e['active'])) {
            $sets[] = "`{$e['active']}` = 0";
        }
        /* User delete = login band bhi. Sirf deleted_at kaafi nahi lagta
           tha; status bhi SUSPENDED kar dete hain taake har rasta band ho. */
        if ($t === 'users' && self::hasCol($t, 'status')) {
            $sets[] = "`status` = 'SUSPENDED'";
        }
        /* updated_at is required — isi se sync ko pata chalta hai ke row
           badli hai aur doosri taraf bhi gayab honi is required. */
        if (self::hasCol($t, 'updated_at')) $sets[] = "`updated_at` = NOW(6)";
        if (self::hasCol($t, 'row_version')) $sets[] = "`row_version` = `row_version` + 1";

        $sql = "UPDATE `$t` SET ".implode(', ', $sets)." WHERE $where";
        $st = $p->prepare($sql);
        $st->execute(array_merge([$id], $args));

        if ($st->rowCount() < 1) {
            throw new \RuntimeException(($e['label'] ?? 'Record').' was not updated - it may already have been deleted. Please refresh the screen.');
        }
    }

    /* ================= hard (+ tombstone + cascade) ================= */

    private static function hardDelete(array $e, string $id, string $reason): int
    {
        return DB::tx(function (PDO $p) use ($e, $id, $reason) {
            $kids = 0;

            /* Stock wapas — GRN / wastage delete par balances theek karne hain,
               warna inventory hamesha ke liye galat ho jati hai. */
            if (!empty($e['reverses_stock'])) {
                $kids += self::reverseStock($p, $e, $id, $reason);
            }

            foreach (($e['kids'] ?? []) as $k) {
                if (!self::tableExists($k['table'])) continue;
                $q = $p->prepare("SELECT id FROM `{$k['table']}` WHERE `{$k['col']}` = ?");
                $q->execute([$id]);
                foreach (array_column($q->fetchAll(PDO::FETCH_ASSOC), 'id') as $kid) {
                    self::tombstone($p, $k['table'], (string)$kid, 'cascade: '.$e['table']);
                }
                $d = $p->prepare("DELETE FROM `{$k['table']}` WHERE `{$k['col']}` = ?");
                $d->execute([$id]);
                $kids += $d->rowCount();
            }

            /* Nishan PEHLE, delete BAAD mein. Ulta please to crash ki soorat
               mein row gayab aur tombstone ghayab — dono taraf hamesha alag. */
            self::tombstone($p, $e['table'], $id, $reason);

            [$where, $args] = self::scopeWhere($e);
            $st = $p->prepare("DELETE FROM `{$e['table']}` WHERE $where");
            $st->execute(array_merge([$id], $args));
            if ($st->rowCount() < 1) {
                throw new \RuntimeException(($e['label'] ?? 'Record').' could not be deleted - please refresh the screen and try again.');
            }
            return $kids;
        });
    }

    /** GRN / wastage delete par stock ko wapas pehle jaisa karna. */
    private static function reverseStock(PDO $p, array $e, string $id, string $reason): int
    {
        $refType = $e['table'] === 'goods_receipts' ? 'GOODS_RECEIPT' : 'STOCK_ADJUSTMENT';
        $q = $p->prepare(
            "SELECT stl.inventory_item_id item_id, stl.stock_location_id loc,
                    stl.qty_change qty, stl.unit_cost cost
               FROM stock_transactions st
               JOIN stock_transaction_lines stl ON stl.stock_transaction_id = st.id
              WHERE st.reference_type = ? AND st.reference_id = ?"
        );
        $q->execute([$refType, $id]);
        $lines = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$lines) return 0;

        $rev = [];
        foreach ($lines as $l) {
            $rev[] = (object)[
                'item_id' => $l['item_id'], 'location_id' => $l['loc'],
                'qty' => -(float)$l['qty'], 'unit_cost' => (float)$l['cost'],
                'source_order_item_id' => null,
            ];
        }
        InventoryService::postMovement(
            $p, 'REVERSAL', $refType, $id, 'REV-'.substr($id, 0, 8),
            $rev, Auth::user()['id'] ?? null
        );
        return count($rev);
    }

    /* ================= deactivate ================= */

    public static function deactivate(string $entity, string $id, string $reason = ''): array
    {
        $e = self::cfgOf($entity);
        $row = self::loadRow($e, $id);
        if (!$row) throw new \RuntimeException(($e['label'] ?? 'Record').' not found.');
        if (!self::mayDelete($e)) throw new \RuntimeException('You do not have permission to do this.');

        $label = self::labelOf($e, $row);
        $col = $e['active'] ?? null;
        if (!$col || !self::hasCol($e['table'], $col)) {
            throw new \RuntimeException($e['label'].' cannot be marked inactive - it has no active/inactive setting.');
        }
        [$where, $args] = self::scopeWhere($e);
        $sets = ["`$col` = 0"];
        if (self::hasCol($e['table'], 'updated_at')) $sets[] = "`updated_at` = NOW(6)";
        $p = DB::pdo();
        $st = $p->prepare("UPDATE `{$e['table']}` SET ".implode(', ', $sets)." WHERE $where");
        $st->execute(array_merge([$id], $args));

        self::log($entity, $e, $id, $label, 'DEACTIVATE', $reason);
        return ['result' => 'DEACTIVATED', 'label' => $label,
                'message' => $e['label'].' "'.$label.'" is now inactive (the data is kept).',
                'blockers' => [], 'can_deactivate' => false, 'can_force' => false, 'can_void' => false];
    }

    /* ================= restore ================= */

    /** Soft-deleted row wapas. Hard/force delete wapas NahI aati. */
    public static function restore(string $entity, string $id): array
    {
        $e = self::cfgOf($entity);
        $soft = self::softCol($e);
        if ($soft === null) {
            throw new \RuntimeException($e['label'].' was permanently deleted and cannot be restored.');
        }
        if (!self::mayDelete($e)) throw new \RuntimeException('You do not have permission to do this.');

        $p = DB::pdo();
        [$where, $args] = self::scopeWhere($e);
        $sets = [$soft === 'deleted' ? "`deleted` = 0" : "`$soft` = NULL"];
        if (!empty($e['active']) && self::hasCol($e['table'], (string)$e['active'])) {
            $sets[] = "`{$e['active']}` = 1";
        }
        if ($e['table'] === 'users' && self::hasCol('users', 'status')) {
            $sets[] = "`status` = 'ACTIVE'";
        }
        if (self::hasCol($e['table'], 'updated_at')) $sets[] = "`updated_at` = NOW(6)";

        $st = $p->prepare("UPDATE `{$e['table']}` SET ".implode(', ', $sets)." WHERE $where");
        $st->execute(array_merge([$id], $args));
        if ($st->rowCount() < 1) throw new \RuntimeException('Record not found, or it has already been restored.');

        $row = self::loadRow($e, $id);
        $label = $row ? self::labelOf($e, $row) : $id;
        self::log($entity, $e, $id, $label, 'RESTORE', '');
        return ['result' => 'RESTORED', 'label' => $label,
                'message' => $e['label'].' "'.$label.'" has been restored.'];
    }

    /* ================= recycle bin ================= */

    /** Pichle 30 din ke soft-deleted records, taake ghalti wapas ho sake. */
    public static function recycleBin(int $days = 30, int $limit = 200): array
    {
        $out = [];
        foreach (self::ENTITIES as $key => $e) {
            $soft = self::softCol($e);
            if ($soft === null || $soft === 'deleted') continue;
            if (!self::tableExists($e['table'])) continue;
            if (!self::hasCol($e['table'], $soft)) continue;

            [$w, $args] = self::scopeWhere($e);
            // scopeWhere 'id = ?' se shuru hota hai — yahan id nahi is required
            $w = preg_replace('/^id = \?\s*/', '1=1 ', $w);
            $nameCol = $e['name'] ?? 'id';
            if (!self::hasCol($e['table'], $nameCol)) $nameCol = 'id';

            try {
                $q = DB::pdo()->prepare(
                    "SELECT id, `$nameCol` AS label, `$soft` AS deleted_at
                       FROM `{$e['table']}`
                      WHERE $w AND `$soft` IS NOT NULL AND `$soft` >= DATE_SUB(NOW(), INTERVAL $days DAY)
                      ORDER BY `$soft` DESC LIMIT $limit"
                );
                $q->execute($args);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[] = ['entity' => $key, 'entity_label' => $e['label'],
                              'id' => $r['id'], 'label' => (string)$r['label'],
                              'deleted_at' => substr((string)$r['deleted_at'], 0, 19)];
                }
            } catch (\Throwable $ex) { /* ek table fail ho to baqi bin phir bhi dikhe */ }
        }
        usort($out, fn($a, $b) => strcmp($b['deleted_at'], $a['deleted_at']));
        return array_slice($out, 0, $limit);
    }

    /* ================= order VOID ================= */

    /**
     * Bill delete nahi hota — VOID hota hai. Bill number, items aur audit
     * trail sab rehta hai; sirf order VOID mark hota hai, payments cancel
     * hoti hain aur consume hua stock wapas aata hai.
     */
    public static function voidOrder(string $orderId, string $reason, ?string $managerId = null): array
    {
        if (trim($reason) === '') throw new \RuntimeException('A reason is required to void a bill.');
        if (!Auth::isManager() && !Auth::isAdmin()) {
            throw new \RuntimeException('Only an Admin or Manager can void a bill.');
        }

        return DB::tx(function (PDO $p) use ($orderId, $reason, $managerId) {
            $q = $p->prepare("SELECT id,bill_no,order_status,grand_total FROM orders
                               WHERE id=? AND site_id=? LIMIT 1");
            $q->execute([$orderId, site_id()]);
            $o = $q->fetch(PDO::FETCH_ASSOC);
            if (!$o) throw new \RuntimeException('Bill not found.');
            if (($o['order_status'] ?? '') === 'VOID') {
                throw new \RuntimeException('This bill has already been voided.');
            }

            $p->prepare("UPDATE orders SET order_status='VOID', updated_at=NOW(6) WHERE id=?")
              ->execute([$orderId]);

            if (self::tableExists('payments')) {
                $p->prepare("UPDATE payments SET status='CANCELLED', updated_at=NOW(6)
                              WHERE order_id=? AND status='COMPLETED'")->execute([$orderId]);
            }

            /* Bik kar consume hua stock wapas */
            $rev = [];
            try {
                $sq = $p->prepare(
                    "SELECT stl.inventory_item_id item_id, stl.stock_location_id loc,
                            stl.qty_change qty, stl.unit_cost cost
                       FROM stock_transactions st
                       JOIN stock_transaction_lines stl ON stl.stock_transaction_id = st.id
                      WHERE st.reference_type='ORDER' AND st.reference_id=?"
                );
                $sq->execute([$orderId]);
                foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $l) {
                    $rev[] = (object)['item_id' => $l['item_id'], 'location_id' => $l['loc'],
                                      'qty' => -(float)$l['qty'], 'unit_cost' => (float)$l['cost'],
                                      'source_order_item_id' => null];
                }
                if ($rev) {
                    InventoryService::postMovement($p, 'VOID_RETURN', 'ORDER', $orderId,
                        'VOID-'.$o['bill_no'], $rev, Auth::user()['id'] ?? null);
                }
            } catch (\Throwable $ex) { /* stock reversal best-effort */ }

            $u = Auth::user();
            try {
                $p->prepare(
                    "INSERT INTO deletion_log
                       (id,tenant_id,site_id,entity,table_name,row_id,row_label,action,reason,
                        actor_user_id,actor_name,origin_node,child_rows,created_at)
                     VALUES (?,?,?,'order','orders',?,?,'VOID',?,?,?,?,?,NOW(6))"
                )->execute([uuid(), tenant_id(), site_id(), $orderId, (string)$o['bill_no'],
                            substr($reason, 0, 300), $managerId ?: ($u['id'] ?? null),
                            substr((string)($u['full_name'] ?? ''), 0, 120),
                            (string)cfg('app.role'), count($rev)]);
            } catch (\Throwable $ex) {}

            return ['result' => 'VOIDED', 'bill_no' => $o['bill_no'],
                    'message' => 'Bill '.$o['bill_no'].' has been voided. Stock has been returned.'];
        });
    }
}

// build: V62 build 2026-08-26
