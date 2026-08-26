<?php
namespace Aio\Services;

use Aio\DB;
use PDO;
use Aio\Services\Platform;

/**
 * AdminData — Super Admin ke data tools.
 *
 *  • backup()        : business ka data ek JSON file mein (download ke liye)
 *  • factoryReset()  : data saaf (backup lene ke baad hi)
 *  • importBackup()  : wahi backup file wapas import
 *
 * Import sirf MASTER DATA tak mehdood hai — orders/payments jaisa
 * transactional data import nahi hota (bill numbers, shifts aur stock
 * effects se gadbad ho jati hai).
 */
final class AdminData
{
    /** Master data — import aur backup dono mein shamil. */
    public const MASTER_TABLES = [
        'roles', 'role_modules',
        'menu_categories', 'menu_items', 'menu_item_variants', 'menu_category_printer_routes',
        'recipes', 'recipe_ingredients',
        'inventory_categories', 'inventory_items', 'stock_locations', 'units',
        'suppliers', 'supplier_items',
        'customers', 'customer_addresses',
        'expense_categories', 'payment_methods', 'printers',
        'floors', 'dining_tables', 'promotions', 'riders',
    ];

    /** Transactional — sirf backup mein (import nahi hota). */
    public const TXN_TABLES = [
        'orders', 'order_items', 'payments', 'order_item_voids',
        'kitchen_tickets', 'kitchen_ticket_items',
        'cashier_shifts', 'shift_cash_movements', 'shift_handovers',
        'stock_transactions', 'stock_transaction_lines', 'stock_balances', 'stock_adjustments',
        'goods_receipts', 'goods_receipt_items', 'purchase_orders', 'purchase_order_items',
        'expenses', 'reservations', 'delivery_orders',
        'qr_orders', 'qr_sessions', 'fiscal_invoices', 'ui_records',
    ];

    /* ------------------------------------------------------------------ */

    private static function cols(string $t): array
    {
        static $cache = [];
        if (isset($cache[$t])) return $cache[$t];
        try {
            $q = DB::pdo()->prepare(
                "SELECT column_name FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ?");
            $q->execute([$t]);
            return $cache[$t] = array_column($q->fetchAll(PDO::FETCH_ASSOC), 'column_name');
        } catch (\Throwable $e) { return $cache[$t] = []; }
    }

    private static function scopedRows(string $table, string $tenantId, array $siteIds): array
    {
        $cols = self::cols($table);
        if (!$cols) return [];
        $pdo = DB::pdo();
        try {
            if (in_array('tenant_id', $cols, true)) {
                $q = $pdo->prepare("SELECT * FROM `$table` WHERE tenant_id = ?");
                $q->execute([$tenantId]);
                return $q->fetchAll(PDO::FETCH_ASSOC);
            }
            if (in_array('site_id', $cols, true) && $siteIds) {
                $in = implode(',', array_fill(0, count($siteIds), '?'));
                $q = $pdo->prepare("SELECT * FROM `$table` WHERE site_id IN ($in)");
                $q->execute($siteIds);
                return $q->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (\Throwable $e) {}
        return [];
    }

    private static function siteIds(string $tenantId): array
    {
        try {
            $q = DB::pdo()->prepare("SELECT id FROM sites WHERE tenant_id = ?");
            $q->execute([$tenantId]);
            return array_column($q->fetchAll(PDO::FETCH_ASSOC), 'id');
        } catch (\Throwable $e) { return []; }
    }

    /* ------------------------------- BACKUP --------------------------- */

    /**
     * @param string $scope 'MASTER' | 'FULL'
     * @return array{json:string,meta:array}
     */
    public static function backup(string $tenantId, string $scope = 'FULL'): array
    {
        $pdo = DB::pdo();
        $tq = $pdo->prepare("SELECT id,name,slug,industry_code FROM tenants WHERE id = ?");
        $tq->execute([$tenantId]);
        $tenant = $tq->fetch(PDO::FETCH_ASSOC) ?: [];

        $sites = self::siteIds($tenantId);
        $tables = $scope === 'MASTER'
            ? self::MASTER_TABLES
            : array_merge(self::MASTER_TABLES, self::TXN_TABLES);

        $data = []; $rowCount = 0;
        foreach ($tables as $t) {
            $rows = self::scopedRows($t, $tenantId, $sites);
            if ($rows) { $data[$t] = $rows; $rowCount += count($rows); }
        }

        // users bhi (password hashes ke saath) taake restore par login chale
        foreach (['users', 'user_roles'] as $t) {
            $rows = self::scopedRows($t, $tenantId, $sites);
            if ($t === 'user_roles' && isset($data['users'])) {
                $ids = array_column($data['users'], 'id');
                $rows = array_values(array_filter($rows, fn($r) => in_array($r['user_id'] ?? '', $ids, true)));
            }
            if ($rows) { $data[$t] = $rows; $rowCount += count($rows); }
        }

        $payload = [
            'format'      => 'smartpos-backup',
            'version'     => 1,
            'scope'       => $scope,
            'created_at'  => date('c'),
            'business'    => $tenant,
            'sites'       => self::scopedRows('sites', $tenantId, $sites),
            'tables'      => $data,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return ['json' => (string)$json, 'meta' => [
            'tables' => count($data), 'rows' => $rowCount,
            'bytes'  => strlen((string)$json), 'checksum' => hash('sha256', (string)$json),
        ]];
    }

    /* ---------------------------- FACTORY RESET ----------------------- */

    /** Reset par kabhi na chhui jane wali tables (platform-level). */
    private const NEVER_WIPE = [
        'tenants', 'sites', 'organizations',
        'platform_users', 'platform_modules', 'plans',
        'tenant_subscriptions', 'subscription_payments', 'signup_requests',
        'admin_audit', 'admin_backups', 'admin_imports',
        'sync_nodes', 'sync_activity', 'sync_runs', 'sync_state', 'sync_cursors',
        'migration_state', 'sync_retries',
    ];

    /**
     * Har wo table jo tenant ya site se bandhi hai — hardcoded list nahi.
     * (Pehle 47 tables ki list thi aur 48 tables chhoot rahi thin, is liye
     * "factory reset" ke baad bhi data reh jata tha.)
     * @return array<int,array{name:string,key:string}>
     */
    public static function wipeableTables(): array
    {
        try {
            $q = DB::pdo()->query(
                "SELECT table_name, GROUP_CONCAT(column_name) cols
                   FROM information_schema.columns
                  WHERE table_schema = DATABASE()
                    AND column_name IN ('tenant_id','site_id')
                  GROUP BY table_name ORDER BY table_name");
            $out = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $t = (string)$r['table_name'];
                if (in_array($t, self::NEVER_WIPE, true)) continue;
                if (str_ends_with($t, '_bk') || str_ends_with($t, '_backup')) continue;
                $cols = explode(',', (string)$r['cols']);
                $out[] = ['name' => $t, 'key' => in_array('tenant_id', $cols, true) ? 'tenant_id' : 'site_id'];
            }
            return $out;
        } catch (\Throwable $e) { return []; }
    }

    /**
     * @param string $mode 'TXN'  = sirf transactions (master data mehfooz)
     *                     'FULL' = sab kuch, sirf admin login bacha rehta hai
     * @return array{deleted:array<string,int>,total:int,kept_admin:?string}
     */
    public static function factoryReset(string $tenantId, string $mode = 'TXN'): array
    {
        $pdo   = DB::pdo();
        $sites = self::siteIds($tenantId);

        // FULL par bhi admin login bachana hai — us ke ids pehle nikal lo
        $adminId = null; $adminRoleIds = [];
        if ($mode === 'FULL') {
            try {
                $a = $pdo->prepare(
                    "SELECT id FROM users
                      WHERE tenant_id = ? AND status='ACTIVE' AND deleted_at IS NULL
                      ORDER BY is_tenant_admin DESC, created_at ASC LIMIT 1");
                $a->execute([$tenantId]);
                $adminId = $a->fetchColumn() ?: null;
                if ($adminId) {
                    $r = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
                    $r->execute([$adminId]);
                    $adminRoleIds = array_column($r->fetchAll(PDO::FETCH_ASSOC), 'role_id');
                }
            } catch (\Throwable $e) {}
        }

        $keepTxnSafe = $mode === 'TXN' ? self::MASTER_TABLES : [];
        $out = [];
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        foreach (self::wipeableTables() as $t) {
            $name = $t['name'];
            if ($keepTxnSafe && in_array($name, $keepTxnSafe, true)) continue;
            // TXN mode mein staff/roles/settings bhi mehfooz
            if ($mode === 'TXN' && in_array($name, [
                'users','user_roles','roles','role_modules','user_form_permissions',
                'user_module_access','user_site_access','employee_profiles',
                'site_settings','site_modules','tax_profiles','fiscal_settings',
                'document_sequences','modifier_groups','modifier_options','accounts',
            ], true)) continue;

            try {
                $where = $t['key'] === 'tenant_id' ? 'tenant_id = ?' : 'site_id IN (' .
                         implode(',', array_fill(0, max(1, count($sites)), '?')) . ')';
                $args  = $t['key'] === 'tenant_id' ? [$tenantId] : ($sites ?: ['-']);

                // admin login bachao
                if ($mode === 'FULL' && $name === 'users' && $adminId) {
                    $where .= ' AND id <> ?'; $args[] = $adminId;
                }
                if ($mode === 'FULL' && $name === 'user_roles' && $adminId) {
                    $where .= ' AND user_id <> ?'; $args[] = $adminId;
                }
                if ($mode === 'FULL' && $name === 'roles' && $adminRoleIds) {
                    $where .= ' AND id NOT IN (' . implode(',', array_fill(0, count($adminRoleIds), '?')) . ')';
                    $args = array_merge($args, $adminRoleIds);
                }
                if ($mode === 'FULL' && $name === 'role_modules' && $adminRoleIds) {
                    $where .= ' AND role_id NOT IN (' . implode(',', array_fill(0, count($adminRoleIds), '?')) . ')';
                    $args = array_merge($args, $adminRoleIds);
                }

                $st = $pdo->prepare("DELETE FROM `$name` WHERE $where");
                $st->execute($args);
                if ($st->rowCount() > 0) $out[$name] = $st->rowCount();
            } catch (\Throwable $e) { $out[$name] = -1; }
        }

        // sync watermarks reset — warna purana data dobara nahi behta
        try { $pdo->exec("DELETE FROM sync_state WHERE scope LIKE 'push:%' OR scope LIKE 'pull:%'"); }
        catch (\Throwable $e) {}
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        // FULL ke baad business chalne laayak rehna chahiye:
        // roles + branch defaults dobara bana do
        if ($mode === 'FULL') {
            try {
                $site = $sites[0] ?? null;
                if ($site) Platform::ensureSiteDefaults($pdo, $tenantId, $site);
            } catch (\Throwable $e) {}
        }

        return ['deleted' => $out, 'total' => array_sum(array_filter($out, fn($n) => $n > 0)),
                'kept_admin' => $adminId];
    }

    /* ------------------------------- IMPORT --------------------------- */

    /** Backup file parh kar batao ke us mein kya hai (import se pehle). */
    public static function inspect(string $json): array
    {
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['format'] ?? '') !== 'smartpos-backup') {
            return ['ok' => false, 'message' => 'This is not a SmartPOS backup file.'];
        }
        $tables = (array)($d['tables'] ?? []);
        $rows = [];
        foreach ($tables as $t => $r) {
            $rows[] = [
                'table'      => $t,
                'rows'       => is_array($r) ? count($r) : 0,
                'importable' => in_array($t, self::MASTER_TABLES, true)
                                || in_array($t, ['users', 'user_roles'], true),
            ];
        }
        usort($rows, fn($a, $b) => ($b['importable'] <=> $a['importable']) ?: strcmp($a['table'], $b['table']));
        return [
            'ok'       => true,
            'business' => $d['business'] ?? [],
            'scope'    => $d['scope'] ?? '?',
            'created'  => $d['created_at'] ?? '',
            'tables'   => $rows,
        ];
    }

    /**
     * Master data import. Transactional tables jaan-boojh kar chhoR di
     * jati hain.
     *
     * @param string $mode 'SKIP' (mojooda rows chhoR do) | 'UPDATE' (overwrite)
     */
    public static function importBackup(string $json, string $tenantId, ?string $siteId,
                                        string $mode = 'SKIP', array $only = []): array
    {
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['format'] ?? '') !== 'smartpos-backup') {
            return ['ok' => false, 'message' => 'This is not a SmartPOS backup file.'];
        }
        $tables = (array)($d['tables'] ?? []);
        $allowed = array_merge(self::MASTER_TABLES, ['users', 'user_roles']);

        $pdo = DB::pdo();
        $ins = 0; $upd = 0; $skip = 0; $perTable = []; $errors = [];

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($allowed as $t) {                   // tarteeb ahem hai (parents pehle)
            if (!isset($tables[$t]) || !is_array($tables[$t])) continue;
            if ($only && !in_array($t, $only, true)) continue;
            $cols = self::cols($t);
            if (!$cols) { $errors[] = "$t: not present in this database"; continue; }

            $tIns = 0; $tUpd = 0; $tSkip = 0;
            foreach ($tables[$t] as $row) {
                $data = array_intersect_key((array)$row, array_flip($cols));
                if (!isset($data['id'])) { $tSkip++; continue; }
                if (in_array('tenant_id', $cols, true)) $data['tenant_id'] = $tenantId;
                if ($siteId && in_array('site_id', $cols, true) && !empty($data['site_id'])) {
                    $data['site_id'] = $siteId;
                }
                try {
                    $ex = $pdo->prepare("SELECT 1 FROM `$t` WHERE id = ? LIMIT 1");
                    $ex->execute([$data['id']]);
                    if ($ex->fetchColumn()) {
                        if ($mode !== 'UPDATE') { $tSkip++; continue; }
                        $set = array_diff_key($data, ['id' => 1]);
                        if ($set) {
                            $sql = "UPDATE `$t` SET " . implode(',', array_map(fn($k) => "`$k`=?", array_keys($set))) . " WHERE id=?";
                            $pdo->prepare($sql)->execute(array_merge(array_values($set), [$data['id']]));
                        }
                        $tUpd++;
                    } else {
                        $keys = array_keys($data);
                        $ph = implode(',', array_fill(0, count($keys), '?'));
                        $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', $keys) . "`) VALUES ($ph)")
                            ->execute(array_values($data));
                        $tIns++;
                    }
                } catch (\Throwable $e) {
                    $tSkip++;
                    if (count($errors) < 8) {
                        $errors[] = $t . ': ' . substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 140);
                    }
                }
            }
            if ($tIns || $tUpd || $tSkip) {
                $perTable[$t] = ['inserted' => $tIns, 'updated' => $tUpd, 'skipped' => $tSkip];
            }
            $ins += $tIns; $upd += $tUpd; $skip += $tSkip;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $ignored = [];
        foreach (array_keys($tables) as $t) {
            if (!in_array($t, $allowed, true)) $ignored[] = $t;
        }

        return [
            'ok' => true, 'inserted' => $ins, 'updated' => $upd, 'skipped' => $skip,
            'per_table' => $perTable, 'errors' => $errors,
            'ignored_tables' => $ignored,
        ];
    }

    /* -------------------------------- AUDIT --------------------------- */

    public static function audit(string $actor, ?string $tenantId, string $action, string $detail = ''): void
    {
        try {
            DB::pdo()->prepare(
                "INSERT INTO admin_audit (id,actor,tenant_id,action,detail,ip,created_at)
                 VALUES (?,?,?,?,?,?,NOW(6))"
            )->execute([
                function_exists('uuid') ? uuid() : bin2hex(random_bytes(16)),
                substr($actor, 0, 120), $tenantId, substr($action, 0, 60), substr($detail, 0, 500),
                substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            ]);
        } catch (\Throwable $e) {}
    }
}
