<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * AdminConsole — super admin ka command interface.
 *
 * Har command yahan parse aur execute hoti hai (browser mein nahi), taake
 * hifazat ek hi jagah lagu ho: khatarnak command par confirmation token,
 * SQL sirf read-only, aur har amal audit log mein.
 */
final class AdminConsole
{
    /** Jin commands ke liye --confirm "<business name>" lazmi hai. */
    private const NEEDS_CONFIRM = ['reset', 'delete', 'purge', 'resync'];

    public static function run(string $line, string $actor): array
    {
        $line = trim($line);
        if ($line === '') return self::out([]);

        $argv = self::tokenize($line);
        $cmd  = strtolower(array_shift($argv) ?? '');
        $flags = [];
        $args  = [];
        for ($i = 0; $i < count($argv); $i++) {
            $a = $argv[$i];
            if (str_starts_with($a, '--')) {
                $k = substr($a, 2);
                if (str_contains($k, '=')) { [$k, $v] = explode('=', $k, 2); }
                else { $v = ($argv[$i + 1] ?? ''); if ($v !== '' && !str_starts_with($v, '--')) $i++; else $v = '1'; }
                $flags[strtolower($k)] = $v;
            } else { $args[] = $a; }
        }

        // Log se seekha: log ke placeholders <slug> waise hi type ho jate hain.
        // Chupchaap saaf kar do aur bata do.
        $hadBrackets = false;
        foreach ($args as $i => $a) {
            if (\strlen($a) > 2 && $a[0] === '<' && \substr($a, -1) === '>') {
                $args[$i] = \substr($a, 1, -1); $hadBrackets = true;
            }
        }

        if (in_array($cmd, self::NEEDS_CONFIRM, true) && ($flags['confirm'] ?? '') === '') {
            return self::needConfirm($cmd, $args, $hadBrackets);
        }
        if ($hadBrackets) {
            // aage chalne do, magar user ko sahi shakal dikha do
            $note = 'Note: < > sirf placeholders hain — bina brackets likhein.';
            $r = self::dispatch($cmd, $args, $flags, $actor);
            \array_unshift($r['lines'], ['t' => 'd', 'v' => $note]);
            return $r;
        }
        return self::dispatch($cmd, $args, $flags, $actor);
    }

    /**
     * Confirmation ki zaroorat par asli business ka naam dhoond kar
     * **type karne laayak poori command** dikhate hain — pehle sirf
     * "<exact business name>" likha aata tha jo koi ishara nahi deta tha.
     */
    private static function needConfirm(string $cmd, array $args, bool $hadBrackets): array
    {
        $slug = $args[0] ?? '';
        $lines = [];
        if ($hadBrackets) $lines[] = ['t' => 'd', 'v' => 'Note: < > sirf placeholders hain — bina brackets likhein.'];

        $name = null;
        if ($slug !== '') {
            try { $t = self::tenant($slug); $name = (string)$t['name']; $slug = (string)$t['slug']; }
            catch (\Throwable $e) {
                $lines[] = ['t' => 'e', 'v' => $e->getMessage()];
                $lines[] = ['t' => 'd', 'v' => "Type 'list' to see the exact slugs."];
                return ['ok' => true, 'lines' => $lines];
            }
        }
        $lines[] = ['t' => 'e', 'v' => 'This command deletes data, so it needs the business name to confirm.'];
        if ($name !== null) {
            $rest = ($cmd === 'reset') ? ' ' . (strtolower($args[1] ?? 'txn')) :
                    (($cmd === 'purge') ? ' ' . (strtolower($args[1] ?? 'transactions')) : '');
            $lines[] = ['t' => 'k', 'v' => 'Run:  ' . $cmd . ' ' . $slug . $rest . ' --confirm "' . $name . '"'];
        } else {
            $lines[] = ['t' => 'k', 'v' => 'Run:  ' . $cmd . ' <slug> --confirm "<business name>"'];
            $lines[] = ['t' => 'd', 'v' => "Type 'list' to see the slugs and names."];
        }
        return ['ok' => true, 'lines' => $lines];
    }

    private static function dispatch(string $cmd, array $args, array $flags, string $actor): array
    {

        try {
            switch ($cmd) {
                case 'help':      return self::cmdHelp();
                case 'version':   return self::cmdVersion();
                case 'selftest':  return self::cmdSelftest($args[0] ?? '');
                case 'list':
                case 'ls':        return self::cmdList();
                case 'info':      return self::cmdInfo($args[0] ?? '');
                case 'users':     return self::cmdUsers($args[0] ?? '');
                case 'footprint': return self::cmdFootprint($args[0] ?? '');
                case 'backup':    return self::cmdBackup($args[0] ?? '', strtoupper($args[1] ?? 'FULL'), $actor);
                case 'reset':     return self::cmdReset($args[0] ?? '', strtoupper($args[1] ?? 'TXN'), (string)$flags['confirm'], $actor);
                /* V82 — business banane ka rasta console par bhi.
                   Pehle sirf `delete` tha: console se business mitaya ja
                   sakta tha magar banaya nahi ja sakta tha. */
                case 'create':    return self::cmdCreate($args, $flags, $actor, false);
                case 'demo':      return self::cmdCreate($args, $flags, $actor, true);
                case 'delete':    return self::cmdDelete($args[0] ?? '', (string)$flags['confirm'], $actor);
                case 'purge':     return self::cmdPurge($args[0] ?? '', strtolower($args[1] ?? ''),
                                      (string)($flags['before'] ?? ''), (string)$flags['confirm'], $actor);
                case 'suspend':   return self::cmdStatus($args[0] ?? '', 'SUSPENDED', $actor);
                case 'activate':  return self::cmdStatus($args[0] ?? '', 'ACTIVE', $actor);
                case 'resync':    return self::cmdResync($args[0] ?? '', strtolower($args[1] ?? 'transactions'), (string)$flags['confirm']);
                case 'tombstones':
                case 'deletes':   return self::cmdTombstones($args[0] ?? '');
                case 'permissions':
                case 'perms':     return self::cmdPermissions($args[0] ?? '');
                case 'nodes':     return self::cmdNodes();
                case 'sync':      return self::cmdSync($args[0] ?? '');
                case 'audit':     return self::cmdAudit($args[0] ?? '');
                case 'tables':    return self::cmdTables();
                case 'query':
                case 'select':    return self::cmdQuery($cmd === 'select' ? ('select ' . implode(' ', $args)) : implode(' ', $args));
                default:
                    return self::err("Unknown command: $cmd   (type 'help')");
            }
        } catch (\Throwable $e) {
            return self::err('Error: ' . $e->getMessage());
        }
    }

    /* ------------------------------ commands -------------------------- */

    private static function cmdHelp(): array
    {
        return self::out([
            ['t' => 'h',  'v' => 'BUSINESSES'],
            ['t' => 'k',  'v' => 'list                              all businesses'],
            ['t' => 'k',  'v' => 'info <slug>                       details, counts, subscription'],
            ['t' => 'k',  'v' => 'users <slug>                      staff accounts'],
            ['t' => 'k',  'v' => 'footprint <slug>                  where this business has data'],
            ['t' => 'k',  'v' => 'suspend <slug> | activate <slug>  block or restore access'],
            ['t' => 'h',  'v' => 'DATA'],
            ['t' => 'k',  'v' => 'backup <slug> [full|master]       download a backup file'],
            ['t' => 'k',  'v' => 'reset <slug> [txn|full] --confirm "<name>"'],
            ['t' => 'd',  'v' => '                                  txn  = transactions only'],
            ['t' => 'd',  'v' => '                                  full = everything, only admin login stays'],
            ['t' => 'k',  'v' => 'create "<Name>" --email=owner@x.pk [--owner="Full Name"] [--expiry=YYYY-MM-DD]'],
            ['t' => 'i',  'v' => '                                  create a new business'],
            ['t' => 'k',  'v' => 'demo "<Name>" [--email=..] [--expiry=YYYY-MM-DD]'],
            ['t' => 'i',  'v' => '                                  demo business with sample data (clears every 5 days)'],
            ['t' => 'k',  'v' => 'delete <slug> --confirm "<name>"  remove the business completely'],
            ['t' => 'h',  'v' => 'PURGE (selective)'],
            ['t' => 'k',  'v' => 'purge <slug> <what> --confirm "<name>"'],
            ['t' => 'd',  'v' => '  what: transactions | orders | shifts | stock | qr | expenses'],
            ['t' => 'd',  'v' => '        logs | sync | all-logs'],
            ['t' => 'k',  'v' => 'purge <slug> orders --before 2026-01-01 --confirm "<name>"'],
            ['t' => 'd',  'v' => '  --before rakhne se sirf us tareekh se purana data jata hai'],
            ['t' => 'h',  'v' => 'MONITORING'],
            ['t' => 'k',  'v' => 'nodes                             branch computers'],
            ['t' => 'k',  'v' => 'sync [slug]                       transfer activity'],
            ['t' => 'k',  'v' => 'audit [slug]                      super-admin actions'],
            ['t' => 'h',  'v' => 'DATABASE'],
            ['t' => 'k',  'v' => 'tables                            tenant-scoped tables'],
            ['t' => 'k',  'v' => 'query SELECT ...                  read-only, max 100 rows'],
            ['t' => 'h',  'v' => 'CONSOLE'],
            ['t' => 'k',  'v' => 'resync <slug> [transactions|all] --confirm "<name>"'],
            ['t' => 'd',  'v' => '   branch computer ko cloud ke barabar laata hai (cloud ka data safe rehta hai)'],
            ['t' => 'k',  'v' => 'tombstones [slug]   — pending delete signals'],
            ['t' => 'k',  'v' => 'permissions <slug>  — har user ke modules aur unka zariya'],
            ['t' => 'k',  'v' => 'clear · version · help'],
            ['t' => 'i',  'v' => ''],
            ['t' => 'd',  'v' => 'purge groups: transactions|orders|shifts|stock|qr|expenses|logs|sync|all-logs'],
            ['t' => 'd',  'v' => 'reset/purge se pehle 1 ghante ke andar backup lazmi hai (logs/sync ke ilawa).'],
            ['t' => 'd',  'v' => '--confirm mein business ka POORA NAAM is required, slug nahi. <slug> ke brackets na likhein.'],
            ['t' => 'd',  'v' => 'Poori tafseel: docs/CONSOLE_COMMANDS.md'],
            ['t' => 'k',  'v' => 'selftest [slug]                   agar koi command fail ho to yeh please run'],
            ['t' => 'd',  'v' => 'Tip: Up/Down arrows walk through earlier commands.'],
        ]);
    }

    private static function cmdVersion(): array
    {
        $v = 'unknown';
        try {
            $f = \dirname(__DIR__, 2) . '/VERSION';
            if (\is_file($f)) $v = trim((string)\file_get_contents($f));
        } catch (\Throwable $e) {}
        return self::out([
            ['t' => 'i', 'v' => 'Build     ' . $v],
            ['t' => 'i', 'v' => 'PHP       ' . PHP_VERSION],
            ['t' => 'i', 'v' => 'Database  ' . (string)DB::pdo()->query('SELECT DATABASE()')->fetchColumn()],
        ]);
    }

    /**
     * Server par sab kuch mojood hai ya nahi — jab koi command "Request
     * failed" de to sab se pehle yehi please run.
     */
    private static function cmdSelftest(string $slug): array
    {
        $p = DB::pdo();
        $out = [['t' => 'h', 'v' => 'CONSOLE SELF-TEST']];
        $add = function (string $label, bool $ok, string $detail = '') use (&$out) {
            $out[] = ['t' => $ok ? 'g' : 'e', 'v' => \sprintf('%s %-30s %s', $ok ? 'OK  ' : 'FAIL', $label, $detail)];
        };

        $add('PHP version', \version_compare(PHP_VERSION, '8.0', '>='), PHP_VERSION);
        $add('Time limit', true, (string)\ini_get('max_execution_time') . 's');
        try { $db = (string)$p->query('SELECT DATABASE()')->fetchColumn(); $add('Database', $db !== '', $db); }
        catch (\Throwable $e) { $add('Database', false, $e->getMessage()); }

        foreach (['admin_backups', 'admin_imports', 'admin_audit', 'sync_state',
                  'sync_nodes', 'sync_activity', 'tenants', 'sites', 'users'] as $t) {
            $add('Table ' . $t, (bool)AdminData::cols($t),
                AdminData::cols($t) ? (count(AdminData::cols($t)) . ' columns') : 'MISSING — run the migrations');
        }

        try { $p->exec('SET FOREIGN_KEY_CHECKS=0'); $p->exec('SET FOREIGN_KEY_CHECKS=1');
              $add('FK toggle permission', true, 'allowed'); }
        catch (\Throwable $e) { $add('FK toggle permission', false, \substr($e->getMessage(), 0, 70)); }

        try { $n = count(AdminData::wipeableTables()); $add('Wipeable tables', $n > 0, $n . ' found'); }
        catch (\Throwable $e) { $add('Wipeable tables', false, \substr($e->getMessage(), 0, 70)); }

        if ($slug !== '') {
            try {
                $t = self::tenant($slug);
                $add('Business ' . $slug, true, (string)$t['name']);
                $q = $p->prepare("SELECT COUNT(*) FROM admin_backups WHERE tenant_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");
                $q->execute([$t['id']]);
                $b = (int)$q->fetchColumn();
                $add('Recent backup (1h)', $b > 0, $b > 0 ? ($b . ' found — reset allowed')
                    : 'none — run:  backup ' . $t['slug'] . ' full');
                $fp = AdminData::footprint((string)$t['id']);
                $add('Data footprint', true, count($fp) . ' tables, ' . \array_sum($fp) . ' rows');
            } catch (\Throwable $e) { $add('Business ' . $slug, false, $e->getMessage()); }
        } else {
            $out[] = ['t' => 'd', 'v' => 'Tip: selftest <slug> — us business ke liye bhi check karega'];
        }
        return self::out($out);
    }

    private static function tenant(string $slug): array
    {
        if ($slug === '') throw new \RuntimeException('Business slug required');
        $q = DB::pdo()->prepare("SELECT * FROM tenants WHERE slug = ? OR id = ? LIMIT 1");
        $q->execute([$slug, $slug]);
        $t = $q->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new \RuntimeException("No business with slug '$slug'");
        return $t;
    }

    private static function cmdList(): array
    {
        $rows = DB::pdo()->query(
            "SELECT t.slug, t.name, t.status,
                    (SELECT MAX(ts.expiry_date) FROM tenant_subscriptions ts WHERE ts.tenant_id=t.id) expiry_date,
                    (SELECT COUNT(*) FROM sites s WHERE s.tenant_id=t.id) branches,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id=t.id) users
               FROM tenants t WHERE t.deleted_at IS NULL ORDER BY t.name")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return self::out([['t' => 'd', 'v' => 'No businesses yet']]);
        $out = [['t' => 'h', 'v' => \sprintf('%-24s %-26s %-10s %-12s %6s %6s',
            'SLUG', 'NAME', 'STATUS', 'EXPIRY', 'BRNCH', 'USERS')]];
        foreach ($rows as $r) {
            $out[] = ['t' => ($r['status'] === 'SUSPENDED' ? 'w' : 'i'),
                'v' => \sprintf('%-24s %-26s %-10s %-12s %6s %6s',
                    self::cut((string)$r['slug'], 24), self::cut((string)$r['name'], 26),
                    (string)$r['status'], (string)($r['expiry_date'] ?: '—'),
                    $r['branches'], $r['users'])];
        }
        $out[] = ['t' => 'd', 'v' => count($rows) . ' business(es)'];
        return self::out($out);
    }

    private static function cmdInfo(string $slug): array
    {
        $t = self::tenant($slug);
        $p = DB::pdo();
        $n = function (string $sql, array $a) use ($p): int {
            try { $q = $p->prepare($sql); $q->execute($a); return (int)$q->fetchColumn(); }
            catch (\Throwable $e) { return 0; }
        };
        $id = $t['id'];
        $exp = '';
        try { $e = $p->prepare("SELECT MAX(expiry_date) FROM tenant_subscriptions WHERE tenant_id=?");
              $e->execute([$id]); $exp = (string)$e->fetchColumn(); } catch (\Throwable $x) {}
        return self::out([
            ['t' => 'h', 'v' => (string)$t['name'] . '  (' . $t['slug'] . ')'],
            ['t' => 'i', 'v' => 'Status      ' . $t['status'] . '   Expiry ' . ($exp ?: '—')],
            ['t' => 'i', 'v' => 'Owner       ' . ($t['owner_email'] ?? '—')],
            ['t' => 'i', 'v' => 'Branches    ' . $n("SELECT COUNT(*) FROM sites WHERE tenant_id=?", [$id])],
            ['t' => 'i', 'v' => 'Users       ' . $n("SELECT COUNT(*) FROM users WHERE tenant_id=?", [$id])],
            ['t' => 'i', 'v' => 'Menu items  ' . $n("SELECT COUNT(*) FROM menu_items WHERE tenant_id=?", [$id])],
            ['t' => 'i', 'v' => 'Customers   ' . $n("SELECT COUNT(*) FROM customers WHERE tenant_id=?", [$id])],
            ['t' => 'i', 'v' => 'Orders      ' . $n("SELECT COUNT(*) FROM orders WHERE tenant_id=?", [$id])],
            ['t' => 'i', 'v' => 'Payments    ' . $n("SELECT COUNT(*) FROM payments WHERE tenant_id=?", [$id])],
            ['t' => 'i', 'v' => 'Branch PCs  ' . $n("SELECT COUNT(*) FROM sync_nodes WHERE tenant_id=?", [$id])],
            ['t' => 'd', 'v' => 'id ' . $id],
        ]);
    }

    private static function cmdUsers(string $slug): array
    {
        $t = self::tenant($slug);
        $q = DB::pdo()->prepare(
            "SELECT u.username, u.full_name, u.email, u.status, u.is_tenant_admin,
                    (SELECT r.name FROM user_roles ur JOIN roles r ON r.id=ur.role_id
                      WHERE ur.user_id=u.id LIMIT 1) role
               FROM users u WHERE u.tenant_id=? ORDER BY u.is_tenant_admin DESC, u.full_name");
        $q->execute([$t['id']]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return self::out([['t' => 'w', 'v' => 'No users']]);
        $out = [['t' => 'h', 'v' => \sprintf('%-18s %-24s %-18s %-9s %s', 'LOGIN', 'NAME', 'ROLE', 'STATUS', 'ADMIN')]];
        foreach ($rows as $r) {
            $out[] = ['t' => 'i', 'v' => \sprintf('%-18s %-24s %-18s %-9s %s',
                self::cut((string)$r['username'], 18), self::cut((string)$r['full_name'], 24),
                self::cut((string)($r['role'] ?? '—'), 18), (string)$r['status'],
                $r['is_tenant_admin'] ? 'yes' : '')];
        }
        return self::out($out);
    }

    private static function cmdFootprint(string $slug): array
    {
        $t = self::tenant($slug);
        $fp = AdminData::footprint((string)$t['id']);
        if (!$fp) return self::out([['t' => 'd', 'v' => 'No data in any table']]);
        \arsort($fp);
        $out = [['t' => 'h', 'v' => (string)$t['name'] . ' — data in ' . count($fp) . ' tables']];
        foreach ($fp as $tbl => $n) $out[] = ['t' => 'i', 'v' => \sprintf('%-34s %7d', $tbl, $n)];
        $out[] = ['t' => 'd', 'v' => \array_sum($fp) . ' rows total'];
        return self::out($out);
    }

    private static function cmdBackup(string $slug, string $scope, string $actor): array
    {
        $t = self::tenant($slug);
        if (!in_array($scope, ['FULL', 'MASTER'], true)) $scope = 'FULL';
        return self::out([
            ['t' => 'g', 'v' => 'Starting download…'],
        ], ['download' => '/api.php?action=sa-backup&tenant_id=' . $t['id'] . '&scope=' . $scope]);
    }

    private static function cmdReset(string $slug, string $mode, string $confirm, string $actor): array
    {
        $t = self::tenant($slug);
        if (!in_array($mode, ['TXN', 'FULL'], true)) $mode = 'TXN';
        if (trim($confirm) !== trim((string)$t['name'])) {
            return self::err('Confirmation does not match. Expected: ' . $t['name']);
        }
        $q = DB::pdo()->prepare(
            "SELECT COUNT(*) FROM admin_backups WHERE tenant_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");
        $q->execute([$t['id']]);
        if (!(int)$q->fetchColumn()) {
            return self::err('Take a backup first:  backup ' . $t['slug'] . ' full');
        }
        $r = AdminData::factoryReset((string)$t['id'], $mode);
        AdminData::audit($actor, (string)$t['id'], 'FACTORY_RESET',
            'console ' . $mode . ' - ' . $r['total'] . ' rows');
        $out = [['t' => 'g', 'v' => 'Reset complete — ' . $r['total'] . ' rows deleted from '
            . count($r['deleted']) . ' tables']];
        foreach (\array_slice($r['deleted'], 0, 12, true) as $tbl => $n) {
            $out[] = ['t' => 'i', 'v' => \sprintf('  %-32s %6d', $tbl, $n)];
        }
        if (count($r['deleted']) > 12) $out[] = ['t' => 'd', 'v' => '  … +' . (count($r['deleted']) - 12) . ' more tables'];
        if ($mode === 'FULL') $out[] = ['t' => 'd', 'v' => 'Admin login kept; branch defaults re-created.'];
        return self::out($out, ['refresh' => true]);
    }

    /**
     * create "Business Name" --owner="Name" --email=a@b.c [--expiry=YYYY-MM-DD]
     * demo   "Business Name" [--owner=...] [--email=...]
     *
     * `demo` wahi kaam karta hai magar business ko DEMO nishan lagata
     * hai aur sample data (menu, tables, customers) daal deta hai.
     */
    private static function cmdCreate(array $args, array $flags, string $actor, bool $demo): array
    {
        $name = trim((string)($args[0] ?? ''));
        if ($name === '') {
            return self::err('Business name required. Example: '
                . ($demo ? 'demo "Royal Grill"' : 'create "Royal Grill" --email=owner@royal.pk'));
        }

        $email = trim((string)($flags['email'] ?? ''));
        $owner = trim((string)($flags['owner'] ?? 'Owner'));
        if (!$demo && $email === '') {
            return self::err('Owner email required. Example: create "' . $name . '" --email=owner@shop.pk');
        }
        if ($email === '') $email = 'demo@' . preg_replace('/[^a-z0-9]/', '', strtolower($name)) . '.local';

        try {
            $r = Platform::provisionBusiness([
                'business_name' => $name,
                'owner_email'   => $email,
                'owner_name'    => $owner,
            ]);
        } catch (\Throwable $e) {
            return self::err('Could not create the business: ' . $e->getMessage());
        }

        $tid  = (string)($r['tenant_id'] ?? '');
        $slug = (string)($r['slug'] ?? '');
        $pass = (string)($r['password'] ?? $r['owner_password'] ?? '');

        $out = [['t' => 'g', 'v' => ($demo ? 'DEMO business created' : 'Business created') . ': ' . $name]];
        $out[] = ['t' => 'i', 'v' => sprintf('  %-14s %s', 'Slug', $slug)];
        $out[] = ['t' => 'i', 'v' => sprintf('  %-14s %s', 'Owner login', $email)];
        if ($pass !== '') $out[] = ['t' => 'i', 'v' => sprintf('  %-14s %s', 'Password', $pass)];

        if ($demo) {
            try {
                DB::pdo()->prepare("UPDATE tenants SET is_demo=1, demo_last_reset=NOW(6) WHERE id=?")
                  ->execute([$tid]);
                $sq = DB::pdo()->prepare("SELECT id FROM sites WHERE tenant_id=? LIMIT 1");
                $sq->execute([$tid]);
                $seeded = DemoBusiness::seed($tid, (string)$sq->fetchColumn());
                $out[] = ['t' => 'g', 'v' => '  Sample data: ' . ($seeded['menu_items'] ?? 0)
                    . ' menu items, ' . ($seeded['tables'] ?? 0) . ' tables, customers, suppliers, printer'];
                $out[] = ['t' => 'i', 'v' => '  Customer data clears every '
                    . DemoBusiness::RESET_DAYS . ' days; this sample data stays.'];
            } catch (\Throwable $e) {
                $out[] = ['t' => 'r', 'v' => '  Business made, but sample data failed: '
                    . substr($e->getMessage(), 0, 90)];
            }
        }

        /* Expiry — na di jaye to business hamesha chalta rahega. */
        $exp = trim((string)($flags['expiry'] ?? ''));
        if ($exp !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) {
            try {
                $p = DB::pdo();
                $p->prepare("INSERT INTO tenant_subscriptions(id,tenant_id,status,amount,start_date,expiry_date,created_by)
                             VALUES(?,?,'ACTIVE',0,CURDATE(),?,?)")
                  ->execute([uuid(), $tid, $exp, Platform::superUser()['id'] ?? null]);
                $out[] = ['t' => 'i', 'v' => sprintf('  %-14s %s', 'Expires', $exp)];
            } catch (\Throwable $e) {}
        } else {
            $out[] = ['t' => 'r', 'v' => '  No expiry set — use the Licence control, or pass --expiry=YYYY-MM-DD'];
        }

        AdminData::audit($actor, $tid, $demo ? 'DEMO_CREATE' : 'CREATE_BUSINESS', $name . ' (' . $slug . ')');
        return self::out($out);
    }

    private static function cmdDelete(string $slug, string $confirm, string $actor): array
    {
        $t = self::tenant($slug);
        if (trim($confirm) !== trim((string)$t['name'])) {
            return self::err('Confirmation does not match. Expected: ' . $t['name']);
        }
        $name = (string)$t['name'];
        $r = AdminData::deleteBusiness((string)$t['id']);
        AdminData::audit($actor, null, 'DELETE_BUSINESS',
            $name . ' (' . $t['slug'] . ') - ' . $r['total'] . ' rows');
        $out = [['t' => 'g', 'v' => 'Deleted "' . $name . '" — ' . $r['total'] . ' rows from '
            . count($r['deleted']) . ' tables']];
        foreach (\array_slice($r['deleted'], 0, 12, true) as $tbl => $n) {
            $out[] = ['t' => 'i', 'v' => \sprintf('  %-32s %6d', $tbl, $n)];
        }
        if (count($r['deleted']) > 12) $out[] = ['t' => 'd', 'v' => '  … +' . (count($r['deleted']) - 12) . ' more tables'];
        return self::out($out, ['refresh' => true]);
    }

    private static function cmdPurge(string $slug, string $what, string $before,
                                     string $confirm, string $actor): array
    {
        $t = self::tenant($slug);
        if ($what === '') {
            return self::err('Usage: purge <slug> <transactions|orders|shifts|stock|qr|expenses|logs|sync|all-logs> --confirm "<name>"');
        }
        if (!AdminData::purgeTables($what)) {
            return self::err('Unknown group "' . $what . '". Try: transactions, orders, shifts, stock, qr, expenses, logs, sync, all-logs');
        }
        if (trim($confirm) !== trim((string)$t['name'])) {
            return self::err('Confirmation does not match. Expected: ' . $t['name']);
        }
        if ($before !== '' && !\preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) {
            return self::err('--before needs a date like 2026-01-01');
        }
        // Business data mita rahe hain to backup lazmi. Logs mehez record
        // hain — un ke liye backup ki shart nahi.
        $isLogs = in_array($what, ['logs', 'sync', 'all-logs'], true);
        if (!$isLogs) {
            $q = DB::pdo()->prepare(
                "SELECT COUNT(*) FROM admin_backups WHERE tenant_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");
            $q->execute([$t['id']]);
            if (!(int)$q->fetchColumn()) {
                return self::err('Take a backup first:  backup ' . $t['slug'] . ' full');
            }
        }

        $r = AdminData::purge((string)$t['id'], $what, $before !== '' ? $before : null);
        AdminData::audit($actor, (string)$t['id'], 'PURGE',
            $what . ($before !== '' ? (' before ' . $before) : '') . ' - ' . $r['total'] . ' rows');

        if ($r['total'] === 0) {
            return self::out([['t' => 'w', 'v' => 'Nothing to purge — no matching rows.']]);
        }
        $out = [['t' => 'g', 'v' => 'Purged ' . $r['total'] . ' rows from ' . count($r['deleted']) . ' tables'
            . ($before !== '' ? (' (older than ' . $before . ')') : '')]];
        foreach ($r['deleted'] as $tbl => $n) {
            $out[] = ['t' => 'i', 'v' => \sprintf('  %-32s %6d', $tbl, $n)];
        }
        if ($r['skipped']) {
            $out[] = ['t' => 'd', 'v' => 'skipped (not in this database or no date column): '
                . \implode(', ', \array_slice($r['skipped'], 0, 8))];
        }
        return self::out($out, ['refresh' => true]);
    }

    private static function cmdStatus(string $slug, string $status, string $actor): array
    {
        $t = self::tenant($slug);
        DB::pdo()->prepare("UPDATE tenants SET status=? WHERE id=?")->execute([$status, $t['id']]);
        AdminData::audit($actor, (string)$t['id'], $status === 'ACTIVE' ? 'ACTIVATE' : 'SUSPEND', 'console');
        return self::out([['t' => 'g', 'v' => $t['name'] . ' is now ' . $status]], ['refresh' => true]);
    }

    /**
     * resync — BRANCH COMPUTER ko cloud ke barabar laata hai.
     *
     * Yeh command us haqiqi masle ke liye hai jahan cloud par data 0 hai
     * magar branch computer par purana data abhi bhi nazar aata hai. Reason:
     * purane build mein sync sirf "kya badla" bhejti thi, "kya mit gaya"
     * ka koi channel tha hi nahi — is liye cloud par ki hui delete kabhi
     * node tak pohanchti hi nahi thi.
     *
     * Yeh command CLOUD ka data BILKUL NahI chhoota. Sirf wipe markers
     * likhta hai; agli sync par node apni wahi tables khud saaf kar ke
     * cloud se dobara bharta hai.
     */
    private static function cmdResync(string $slug, string $what, string $confirm): array
    {
        $t = self::tenant($slug);
        if ($confirm !== (string)$t['name']) {
            return self::err('Confirm name match nahi hua. Run:  resync ' . $t['slug']
                           . ' ' . $what . ' --confirm "' . $t['name'] . '"');
        }

        $master = AdminData::MASTER_TABLES;
        $skip = ['sync_tombstones', 'sync_tombstones_applied', 'deletion_log', 'users', 'user_roles',
                 'roles', 'role_modules', 'user_form_permissions', 'user_module_access'];

        $cut = date('Y-m-d H:i:s.u');
        $pdo = DB::pdo();
        $n = 0; $names = [];
        foreach (AdminData::wipeableTables() as $tb) {
            $name = $tb['name'];
            if (in_array($name, $skip, true)) continue;
            if ($what !== 'all' && in_array($name, $master, true)) continue;
            try {
                DeleteService::wipeMarker($pdo, $name, 'console resync', $cut, (string)$t['id'], null);
                $n++; $names[] = $name;
            } catch (\Throwable $e) {
                return self::err('Tombstone nahi likha ja saka: ' . $e->getMessage()
                               . '  — pehle `php scripts/migrate_delete_support.php` first.');
            }
        }

        AdminData::audit('console', (string)$t['id'], 'RESYNC', $what . ' (' . $n . ' tables)');

        $lines = [
            ['t' => 'g', 'v' => 'Resync markers bana diye: ' . $n . ' tables'],
            ['t' => 'd', 'v' => 'Cloud ka data waisa hi hai — kuch delete NahI hua.'],
            ['t' => 'd', 'v' => 'Agli sync par branch computer yeh tables khud saaf kar ke'],
            ['t' => 'd', 'v' => 'cloud se dobara bharega. Node par "Sync now" dabayein.'],
            ['t' => 'k', 'v' => 'Cut-off: ' . $cut . '  (is ke baad ka naya data mehfooz hai)'],
        ];
        if ($names) $lines[] = ['t' => 'd', 'v' => implode(', ', array_slice($names, 0, 18))
                                                . (count($names) > 18 ? ' …' : '')];
        return self::out($lines);
    }

    /** Pending aur applied delete signals — sync ka delete channel dekhne ke liye. */
    private static function cmdTombstones(string $slug): array
    {
        $pdo = DB::pdo();
        $where = ''; $args = [];
        if ($slug !== '') { $t = self::tenant($slug); $where = ' WHERE ts.tenant_id = ?'; $args[] = $t['id']; }

        try {
            $q = $pdo->prepare(
                "SELECT ts.table_name, ts.scope_mode, ts.row_id, ts.reason, ts.origin_node,
                        ts.created_at, a.applied_at, a.rows_deleted
                   FROM sync_tombstones ts
                   LEFT JOIN sync_tombstones_applied a ON a.tombstone_id = ts.id
                   $where
                  ORDER BY ts.created_at DESC LIMIT 40");
            $q->execute($args);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return self::err('sync_tombstones table not found — `php scripts/migrate_delete_support.php` first.');
        }
        if (!$rows) return self::out([['t' => 'd', 'v' => 'Koi delete signal nahi.']]);

        $lines = [['t' => 'k', 'v' => sprintf('%-26s %-6s %-19s %s', 'TABLE', 'MODE', 'CREATED', 'APPLIED HERE')]];
        foreach ($rows as $r) {
            $lines[] = ['t' => $r['applied_at'] ? 'g' : 'd', 'v' => sprintf('%-26s %-6s %-19s %s',
                substr((string)$r['table_name'], 0, 26),
                (string)$r['scope_mode'],
                substr((string)$r['created_at'], 0, 19),
                $r['applied_at'] ? (substr((string)$r['applied_at'], 0, 19) . '  (' . (int)$r['rows_deleted'] . ' rows)') : 'pending')];
        }
        return self::out($lines);
    }

    /**
     * permissions — har user ke modules, aur yeh ke wo kahan se aa rahe hain.
     *
     * Yeh command us haqiqi masle ke baad bani jahan node par user ke 6
     * modules the aur cloud par usi user ke 0. Reason do thin:
     *   1) `user_module_access` kisi sync list mein thi hi nahi
     *   2) `platform_modules.id` har installation par random thi, is liye
     *      row pohanch bhi jati to join match hi nahi karta
     * Dono soorton mein UI sirf "0 Modules" dikhata tha — koi error nahi.
     */
    private static function cmdPermissions(string $slug): array
    {
        $t = self::tenant($slug);
        $p = DB::pdo();

        $lines = [];
        $fp = \Aio\Services\Sync::moduleFingerprint();
        $lines[] = ['t' => 'k', 'v' => 'Module fingerprint: ' . ($fp !== '' ? $fp : '(none)')];
        $lines[] = ['t' => 'd', 'v' => 'Node par bhi yehi hona is required. Alag ho to permissions be-asar rehti hain.'];
        $lines[] = ['t' => 'i', 'v' => ''];

        $q = $p->prepare(
            "SELECT u.id, u.full_name, u.email, u.is_tenant_admin,
                    COALESCE(r.name,'-') role_name,
                    (SELECT COUNT(*) FROM user_module_access a
                      WHERE a.user_id=u.id AND a.access_mode='ALLOW') direct_mods,
                    (SELECT COUNT(*) FROM role_modules rm
                      JOIN user_roles ur2 ON ur2.role_id=rm.role_id AND ur2.user_id=u.id
                     WHERE rm.is_allowed=1) role_mods
               FROM users u
               LEFT JOIN user_roles ur ON ur.user_id=u.id
               LEFT JOIN roles r ON r.id=ur.role_id
              WHERE u.tenant_id=? AND u.deleted_at IS NULL
              GROUP BY u.id
              ORDER BY u.is_tenant_admin DESC, u.full_name");
        $q->execute([$t['id']]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return self::out([['t' => 'd', 'v' => 'Is business ka koi user nahi.']]);

        $lines[] = ['t' => 'k', 'v' => sprintf('%-26s %-16s %7s %7s', 'USER', 'ROLE', 'DIRECT', 'VIA ROLE')];
        $blank = 0;
        foreach ($rows as $r) {
            $d = (int)$r['direct_mods']; $rm = (int)$r['role_mods'];
            $admin = (int)$r['is_tenant_admin'] === 1;
            $total = $admin ? -1 : ($d + $rm);
            if (!$admin && $total === 0) $blank++;
            $lines[] = ['t' => ($admin || $total > 0) ? 'g' : 'e',
                        'v' => sprintf('%-26s %-16s %7s %7s',
                            substr((string)$r['full_name'], 0, 26),
                            substr((string)$r['role_name'], 0, 16),
                            $admin ? 'ALL' : (string)$d,
                            $admin ? 'ALL' : (string)$rm)];
        }
        if ($blank > 0) {
            $lines[] = ['t' => 'i', 'v' => ''];
            $lines[] = ['t' => 'e', 'v' => $blank . ' user ke paas ek bhi module nahi hai.'];
            $lines[] = ['t' => 'd', 'v' => 'Agar node par yeh users modules ke saath dikhte hain to dono taraf'];
            $lines[] = ['t' => 'd', 'v' => '`php scripts/migrate_module_ids.php` please run, phir node par Sync now.'];
        }
        return self::out($lines);
    }

    private static function cmdNodes(): array
    {
        $rows = DB::pdo()->query(
            "SELECT n.node_code, n.machine_fingerprint ip, n.app_version, n.last_seen_at, n.status,
                    t.name business
               FROM sync_nodes n LEFT JOIN tenants t ON t.id=n.tenant_id
              ORDER BY n.last_seen_at DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return self::out([['t' => 'w', 'v' => 'No branch computer has connected yet']]);
        $out = [['t' => 'h', 'v' => \sprintf('%-24s %-6s %-16s %-22s %s',
            'BUSINESS', 'NODE', 'IP', 'BUILD', 'LAST SEEN')]];
        foreach ($rows as $r) {
            $out[] = ['t' => 'i', 'v' => \sprintf('%-24s %-6s %-16s %-22s %s',
                self::cut((string)($r['business'] ?? '—'), 24), (string)($r['node_code'] ?? '—'),
                self::cut((string)$r['ip'], 16), self::cut((string)($r['app_version'] ?? '?'), 22),
                \substr((string)$r['last_seen_at'], 0, 19))];
        }
        return self::out($out);
    }

    private static function cmdSync(string $slug): array
    {
        $p = DB::pdo();
        if ($slug !== '') {
            $t = self::tenant($slug);
            $q = $p->prepare("SELECT direction,table_name,rows_count,status,note,created_at
                                FROM sync_activity WHERE tenant_id=? ORDER BY created_at DESC LIMIT 25");
            $q->execute([$t['id']]);
        } else {
            $q = $p->query("SELECT s.direction,s.table_name,s.rows_count,s.status,s.note,s.created_at,
                                   t.name business
                              FROM sync_activity s LEFT JOIN tenants t ON t.id=s.tenant_id
                             ORDER BY s.created_at DESC LIMIT 25");
        }
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return self::out([['t' => 'd', 'v' => 'No sync activity']]);
        $out = [];
        foreach ($rows as $r) {
            $bad = in_array((string)$r['status'], ['FAILED', 'REJECTED'], true);
            $out[] = ['t' => $bad ? 'e' : 'i', 'v' => \sprintf('%s  %-5s %-28s %5d  %s',
                \substr((string)$r['created_at'], 0, 19), (string)$r['direction'],
                self::cut((string)$r['table_name'], 28), (int)$r['rows_count'],
                $bad ? ((string)$r['status'] . ' ' . \substr((string)($r['note'] ?? ''), 0, 60)) : '')];
        }
        return self::out($out);
    }

    private static function cmdAudit(string $slug): array
    {
        $p = DB::pdo();
        if ($slug !== '') {
            $t = self::tenant($slug);
            $q = $p->prepare("SELECT actor,action,detail,created_at FROM admin_audit
                               WHERE tenant_id=? ORDER BY created_at DESC LIMIT 30");
            $q->execute([$t['id']]);
        } else {
            $q = $p->query("SELECT actor,action,detail,created_at FROM admin_audit
                             ORDER BY created_at DESC LIMIT 30");
        }
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return self::out([['t' => 'd', 'v' => 'No actions recorded']]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['t' => 'i', 'v' => \sprintf('%s  %-16s %-22s %s',
                \substr((string)$r['created_at'], 0, 19), self::cut((string)$r['actor'], 16),
                (string)$r['action'], self::cut((string)($r['detail'] ?? ''), 60))];
        }
        return self::out($out);
    }

    private static function cmdTables(): array
    {
        $t = AdminData::wipeableTables();
        $out = [['t' => 'h', 'v' => count($t) . ' tenant-scoped tables (these are wiped on reset/delete)']];
        $line = '';
        foreach ($t as $x) {
            $line .= \str_pad($x['name'], 30);
            if (\strlen($line) >= 90) { $out[] = ['t' => 'i', 'v' => $line]; $line = ''; }
        }
        if ($line !== '') $out[] = ['t' => 'i', 'v' => $line];
        return self::out($out);
    }

    /** Sirf SELECT — koi likhne wali command nahi chalti. */
    private static function cmdQuery(string $sql): array
    {
        $sql = trim($sql);
        if ($sql === '') return self::err('Usage: query SELECT ...');
        if (!\preg_match('/^\s*(select|show|describe|desc|explain)\b/i', $sql)) {
            return self::err('Only SELECT / SHOW / DESCRIBE are allowed here.');
        }
        if (\preg_match('/\b(insert|update|delete|drop|alter|truncate|create|grant|replace)\b/i', $sql)) {
            return self::err('Write statements are blocked in the console.');
        }
        if (!\preg_match('/\blimit\b/i', $sql)) $sql .= ' LIMIT 100';

        $rows = DB::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return self::out([['t' => 'd', 'v' => '0 rows']]);
        $cols = \array_keys($rows[0]);
        $w = [];
        foreach ($cols as $c) {
            $w[$c] = \min(30, \max(\strlen($c), ...\array_map(fn($r) => \strlen((string)($r[$c] ?? '')), $rows)));
        }
        $fmt = fn(array $r) => \implode('  ', \array_map(
            fn($c) => \str_pad(self::cut((string)($r[$c] ?? ''), $w[$c]), $w[$c]), $cols));
        $out = [['t' => 'h', 'v' => $fmt(\array_combine($cols, $cols))]];
        foreach ($rows as $r) $out[] = ['t' => 'i', 'v' => $fmt($r)];
        $out[] = ['t' => 'd', 'v' => count($rows) . ' row(s)'];
        return self::out($out);
    }

    /* ------------------------------- helpers -------------------------- */

    private static function cut(string $s, int $n): string
    {
        $s = \preg_replace('/\s+/', ' ', $s) ?? $s;
        // mbstring har server par nahi hota (Railway image par bhi nahi)
        return \strlen($s) > $n ? (\substr($s, 0, max(1, $n - 1)) . '~') : $s;
    }

    /** Command line ko tokens mein toRo, quoted strings ka khayal rakhte hue. */
    private static function tokenize(string $line): array
    {
        \preg_match_all('/"([^"]*)"|\'([^\']*)\'|(\S+)/', $line, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) {
            $out[] = $x[3] ?? '' !== '' ? ($x[3] ?? '') : '';
            $last = count($out) - 1;
            if (($x[3] ?? '') === '') $out[$last] = $x[1] !== '' ? $x[1] : ($x[2] ?? '');
        }
        return \array_values(\array_filter($out, fn($v) => $v !== ''));
    }

    private static function out(array $lines, array $extra = []): array
    {
        return \array_merge(['ok' => true, 'lines' => $lines], $extra);
    }

    private static function err(string $msg): array
    {
        return ['ok' => true, 'lines' => [['t' => 'e', 'v' => $msg]]];
    }
}
