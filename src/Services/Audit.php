<?php
namespace Aio\Services;

use Aio\Auth;
use Aio\DB;
use PDO;

/**
 * Audit — kis ne, kab, kya kiya.
 *
 * Pehle sirf `deletion_log` tha (sirf deletes) aur `admin_audit` (sirf
 * super admin). Restaurant ke andar ka koi amal record hi nahi hota tha:
 * kis ne rate badla, kis ne discount diya, kis ne shift kholi.
 *
 * Do usool:
 *  - `log()` KABHI exception nahi phenkta. Audit likhna nakaam ho to
 *    asal kaam (bill, shift, price) nahi rukna chahiye.
 *  - Yeh record MUSTAQIL hai. Koi UI se ise edit ya delete nahi kar
 *    sakta; sirf padha ja sakta hai.
 */
final class Audit
{
    public static function log(string $action, string $module = '', array $extra = []): void
    {
        try {
            $u = Auth::user() ?: [];
            DB::pdo()->prepare(
                "INSERT INTO audit_log
                   (id,tenant_id,site_id,user_id,username,role_name,action,module,
                    record_id,record_label,old_value,new_value,description,device_info,ip_address,created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(6))"
            )->execute([
                uuid(),
                function_exists('tenant_id') ? tenant_id() : null,
                function_exists('site_id') ? site_id() : null,
                $u['id'] ?? null,
                (string)($u['username'] ?? $u['email'] ?? $u['full_name'] ?? 'system'),
                (string)($u['role_name'] ?? (Auth::isAdmin() ? 'Admin' : (Auth::isManager() ? 'Manager' : 'User'))),
                strtoupper($action),
                $module,
                isset($extra['id']) ? substr((string)$extra['id'], 0, 64) : null,
                isset($extra['label']) ? substr((string)$extra['label'], 0, 200) : null,
                isset($extra['old']) ? substr(self::flat($extra['old']), 0, 2000) : null,
                isset($extra['new']) ? substr(self::flat($extra['new']), 0, 2000) : null,
                isset($extra['desc']) ? substr((string)$extra['desc'], 0, 400) : null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200) ?: null,
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) ?: null,
            ]);
        } catch (\Throwable $e) { /* audit kabhi asal kaam na roke */ }
    }

    private static function flat($v): string
    {
        return is_scalar($v) ? (string)$v : (string)json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    /** Sirf Owner/Manager. Cashier apna bhi nahi dekh sakta. */
    public static function search(array $f): array
    {
        if (!Auth::isManager() && !Auth::isAdmin()) {
            throw new \RuntimeException('You do not have permission to view the activity log.');
        }
        $w = ['al.tenant_id = ?']; $a = [tenant_id()];

        $from = self::day($f['from'] ?? '', date('Y-m-d', strtotime('-7 day')));
        $to   = self::day($f['to'] ?? '', date('Y-m-d'));
        $w[] = 'DATE(al.created_at) BETWEEN ? AND ?'; $a[] = $from; $a[] = $to;

        if (!empty($f['user']))   { $w[] = 'al.user_id = ?';  $a[] = (string)$f['user']; }
        if (!empty($f['action'])) { $w[] = 'al.action = ?';   $a[] = strtoupper((string)$f['action']); }
        if (!empty($f['module'])) { $w[] = 'al.module = ?';   $a[] = (string)$f['module']; }
        if (!empty($f['q'])) {
            $w[] = '(al.username LIKE ? OR al.record_label LIKE ? OR al.description LIKE ?)';
            $like = '%'.$f['q'].'%'; $a[] = $like; $a[] = $like; $a[] = $like;
        }
        $limit = max(20, min(1000, (int)($f['limit'] ?? 300)));

        $q = DB::pdo()->prepare(
            "SELECT al.created_at, al.username, al.role_name, al.action, al.module,
                    al.record_label, al.old_value, al.new_value, al.description, al.ip_address
               FROM audit_log al
              WHERE ".implode(' AND ', $w)."
              ORDER BY al.created_at DESC LIMIT $limit");
        $q->execute($a);
        return ['from' => $from, 'to' => $to, 'rows' => $q->fetchAll(PDO::FETCH_ASSOC)];
    }

    public static function actions(): array
    {
        try {
            $q = DB::pdo()->prepare("SELECT DISTINCT action FROM audit_log WHERE tenant_id=? ORDER BY action");
            $q->execute([tenant_id()]);
            return array_column($q->fetchAll(), 'action');
        } catch (\Throwable $e) { return []; }
    }

    private static function day(string $v, string $fb): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v)) ? trim($v) : $fb;
    }
}

// build: V77 build 2026-08-28
