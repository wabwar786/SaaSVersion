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

    public static function cols(string $t): array
    {
        static $cache = [];
        if (isset($cache[$t])) return $cache[$t];
        try {
            $q = DB::pdo()->prepare(
                "SELECT column_name AS c FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ?");
            $q->execute([$t]);
            return $cache[$t] = array_column($q->fetchAll(PDO::FETCH_ASSOC), 'c');
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
        /* V90 — reset ke baad wahi licence key dobara na chal sake. */
        'licence_keys', 'licence_keys_used',
        'migration_state', 'sync_retries',
        /* V62 — yeh reset mein kabhi na jayen. Agar factory reset khud
           tombstones uda de to node ko delete ki khabar hi na pohanche. */
        'sync_tombstones', 'sync_tombstones_applied', 'deletion_log',
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
                "SELECT table_name AS t, GROUP_CONCAT(column_name) AS cols
                   FROM information_schema.columns
                  WHERE table_schema = DATABASE()
                    AND column_name IN ('tenant_id','site_id')
                  GROUP BY table_name ORDER BY table_name");
            $out = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $t = (string)$r['t'];
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

        /* V62 — YEH WO LAMHA HAI JAHAN PEHLE SAB TOOTTA THA.
           Reset cloud par rows uda deta tha aur peeche koi nishan nahi
           chhorta tha, is liye branch computer par purana data zinda
           rehta tha (aur node ka sync_state kabhi reset hone par wapas
           cloud par chala jata tha). Ab har table par ek WIPE marker
           likha jata hai; wo tombstone node tak jata hai aur wahan bhi
           wahi rows uda deta hai.
           `$cut` guard: reset ke BAAD ka naya data kabhi na mite. */
        $cut = date('Y-m-d H:i:s.u');
        $wiped = 0;
        foreach (self::wipeableTables() as $t) {
            $name = $t['name'];
            if ($keepTxnSafe && in_array($name, $keepTxnSafe, true)) continue;
            try {
                DeleteService::wipeMarker($pdo, $name, 'factory reset ('.$mode.')', $cut, $tenantId, null);
                $wiped++;
            } catch (\Throwable $e) {
                /* Tombstone na likha ja saka to reset rok dena behtar hai —
                   warna cloud khali aur node bhara: wahi purana masla. */
                throw new \RuntimeException(
                    'Factory reset stopped: the sync tombstone could not be written ('
                    . substr($e->getMessage(), 0, 140)
                    . '). Pehle `php scripts/migrate_delete_support.php` first.');
            }
        }

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

        // FULL ke baad business chalne laayak rehna is required:
        // roles + branch defaults dobara bana do
        if ($mode === 'FULL') {
            try {
                $site = $sites[0] ?? null;
                if ($site) Platform::ensureSiteDefaults($pdo, $tenantId, $site);
            } catch (\Throwable $e) {}
        }

        return ['deleted' => $out, 'total' => array_sum(array_filter($out, fn($n) => $n > 0)),
                'kept_admin' => $adminId, 'wipe_markers' => $wiped];
    }

    /* ------------------------------- PURGE ---------------------------- */

    /** Purge ke groups — `purge <slug> <group>` command inhen use karta hai. */
    public const PURGE_GROUPS = [
        'orders'   => ['orders','order_items','payments','order_payments','order_item_voids',
                       'order_status_history','online_order_details','refunds',
                       'kitchen_tickets','kitchen_ticket_items','delivery_orders','fiscal_invoices'],
        'shifts'   => ['cashier_shifts','shift_cash_movements','shift_handovers'],
        'stock'    => ['stock_transactions','stock_transaction_lines','stock_balances',
                       'stock_adjustments','stock_movements','stock_batches',
                       'stock_count_sessions','stock_transfers',
                       'goods_receipts','goods_receipt_items','purchase_orders','purchase_order_items'],
        'qr'       => ['qr_orders','qr_sessions'],
        'expenses' => ['expenses','supplier_payments'],
        'logs'     => ['audit_logs','notification_queue','printer_jobs','background_jobs'],
        'sync'     => ['sync_activity','sync_runs','sync_conflicts','sync_inbox','sync_outbox','sync_cursors'],
    ];

    /** 'transactions' = orders + shifts + stock + qr + expenses */
    public static function purgeTables(string $group): array
    {
        $group = strtolower($group);
        if ($group === 'transactions' || $group === 'txn') {
            return array_merge(
                self::PURGE_GROUPS['orders'], self::PURGE_GROUPS['shifts'],
                self::PURGE_GROUPS['stock'],  self::PURGE_GROUPS['qr'],
                self::PURGE_GROUPS['expenses']);
        }
        if ($group === 'all-logs') {
            return array_merge(self::PURGE_GROUPS['logs'], self::PURGE_GROUPS['sync']);
        }
        return self::PURGE_GROUPS[$group] ?? [];
    }

    /**
     * Chuni hui tables se is business ka data hatao, marzi ho to sirf ek
     * tareekh se purana.
     *
     * @param string|null $before 'YYYY-MM-DD' — is se purani rows hi jayengi
     * @return array{deleted:array<string,int>,total:int,skipped:array<int,string>}
     */
    public static function purge(string $tenantId, string $group, ?string $before = null): array
    {
        $tables = self::purgeTables($group);
        if (!$tables) return ['deleted' => [], 'total' => 0, 'skipped' => []];

        $pdo   = DB::pdo();
        $sites = self::siteIds($tenantId);
        $out = []; $skipped = [];

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $t) {
            $cols = self::cols($t);
            if (!$cols) { $skipped[] = $t; continue; }

            $where = ''; $args = [];
            if (in_array('tenant_id', $cols, true)) { $where = 'tenant_id = ?'; $args[] = $tenantId; }
            elseif (in_array('site_id', $cols, true) && $sites) {
                $where = 'site_id IN (' . implode(',', array_fill(0, count($sites), '?')) . ')';
                $args = $sites;
            } else { $skipped[] = $t; continue; }

            // date filter — jo column mojood ho
            if ($before !== null && $before !== '') {
                $dateCol = null;
                foreach (['business_date', 'created_at', 'opened_at', 'paid_at'] as $c) {
                    if (in_array($c, $cols, true)) { $dateCol = $c; break; }
                }
                if ($dateCol === null) { $skipped[] = $t; continue; }
                $where .= " AND `$dateCol` < ?";
                $args[] = $before;
            }

            try {
                /* V62 — delete se PEHLE nishan, warna node par yeh rows
                   zinda reh jayengi (poora local-vs-live wala masla). */
                if (!in_array($t, ['sync_tombstones', 'sync_tombstones_applied', 'deletion_log'], true)) {
                    DeleteService::wipeMarker(
                        $pdo, $t, 'purge: '.$group,
                        ($before !== null && $before !== '') ? $before : date('Y-m-d H:i:s.u'),
                        $tenantId, null
                    );
                }
                $st = $pdo->prepare("DELETE FROM `$t` WHERE $where");
                $st->execute($args);
                if ($st->rowCount() > 0) $out[$t] = $st->rowCount();
            } catch (\Throwable $e) { $skipped[] = $t; }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        // agar transactions gaye hain to watermark reset — warna cloud/node
        // dobara nahi bhejte aur figures aadhe reh jate hain
        if (in_array(strtolower($group), ['transactions', 'txn', 'orders'], true)) {
            try { $pdo->exec("DELETE FROM sync_state WHERE scope LIKE 'push:%' OR scope LIKE 'pull:%'"); }
            catch (\Throwable $e) {}
        }

        return ['deleted' => $out, 'total' => array_sum($out), 'skipped' => $skipped];
    }

    /* ------------------------------ DELETE ---------------------------- */

    /**
     * Business ko poori tarah mita do — har table se, tenant row samet.
     * Factory reset se aage: yahan admin login bhi nahi bachta.
     *
     * @return array{deleted:array<string,int>,total:int}
     */
    public static function deleteBusiness(string $tenantId): array
    {
        $pdo   = DB::pdo();
        $sites = self::siteIds($tenantId);
        $out   = [];

        /* V62 — business delete karne se pehle har table par WIPE marker.
           Tombstones khud NEVER_WIPE mein hain, is liye zinda rehte hain
           aur node agli baar connect hone par apna data khud saaf kar deta
           hai. (Uske baad node ka token bhi mar chuka hoga, magar agar
           node pehle se connect ho to yeh usay foran saaf kar deta hai.) */
        $cut = date('Y-m-d H:i:s.u');
        foreach (self::wipeableTables() as $t) {
            try { DeleteService::wipeMarker($pdo, $t['name'], 'business deleted', $cut, $tenantId, null); }
            catch (\Throwable $e) { /* delete-business tombstone par nahi rukta */ }
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        // 1) har wo table jismein tenant_id / site_id hai
        foreach (self::wipeableTables() as $t) {
            $name = $t['name'];
            try {
                if ($t['key'] === 'tenant_id') {
                    $st = $pdo->prepare("DELETE FROM `$name` WHERE tenant_id = ?");
                    $st->execute([$tenantId]);
                } else {
                    if (!$sites) continue;
                    $in = implode(',', array_fill(0, count($sites), '?'));
                    $st = $pdo->prepare("DELETE FROM `$name` WHERE site_id IN ($in)");
                    $st->execute($sites);
                }
                if ($st->rowCount() > 0) $out[$name] = $st->rowCount();
            } catch (\Throwable $e) { $out[$name] = -1; }
        }

        // 2) platform-level rows jo isi business ke hain
        foreach ([
            'sync_activity', 'sync_nodes', 'sync_runs', 'sync_conflicts',
            'sync_inbox', 'sync_outbox', 'sync_cursors',
            'admin_backups', 'admin_imports', 'admin_audit',
            'subscription_payments', 'tenant_subscriptions',
            'site_modules', 'site_settings', 'sites', 'organizations',
        ] as $name) {
            try {
                if (!self::cols($name)) continue;
                $st = $pdo->prepare("DELETE FROM `$name` WHERE tenant_id = ?");
                $st->execute([$tenantId]);
                if ($st->rowCount() > 0) $out[$name] = ($out[$name] ?? 0) + $st->rowCount();
            } catch (\Throwable $e) {}
        }

        // 3) signup requests (email se bandhi)
        try {
            $e = $pdo->prepare("SELECT owner_email FROM tenants WHERE id = ?");
            $e->execute([$tenantId]);
            $mail = (string)$e->fetchColumn();
            if ($mail !== '' && self::cols('signup_requests')) {
                $st = $pdo->prepare("DELETE FROM signup_requests WHERE email = ?");
                $st->execute([$mail]);
                if ($st->rowCount() > 0) $out['signup_requests'] = $st->rowCount();
            }
        } catch (\Throwable $e) {}

        // 4) aakhir mein khud tenant
        try {
            $st = $pdo->prepare("DELETE FROM tenants WHERE id = ?");
            $st->execute([$tenantId]);
            if ($st->rowCount() > 0) $out['tenants'] = $st->rowCount();
        } catch (\Throwable $e) { $out['tenants'] = -1; }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        return ['deleted' => $out, 'total' => array_sum(array_filter($out, fn($n) => $n > 0))];
    }

    /** Kisi bhi business ka data kitni tables mein bikhra hai. */
    public static function footprint(string $tenantId): array
    {
        $sites = self::siteIds($tenantId);
        $out = [];
        foreach (self::wipeableTables() as $t) {
            try {
                if ($t['key'] === 'tenant_id') {
                    $q = DB::pdo()->prepare("SELECT COUNT(*) FROM `{$t['name']}` WHERE tenant_id = ?");
                    $q->execute([$tenantId]);
                } else {
                    if (!$sites) continue;
                    $in = implode(',', array_fill(0, count($sites), '?'));
                    $q = DB::pdo()->prepare("SELECT COUNT(*) FROM `{$t['name']}` WHERE site_id IN ($in)");
                    $q->execute($sites);
                }
                $n = (int)$q->fetchColumn();
                if ($n > 0) $out[$t['name']] = $n;
            } catch (\Throwable $e) {}
        }
        return $out;
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
