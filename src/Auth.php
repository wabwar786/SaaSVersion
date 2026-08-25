<?php
namespace Aio;

use PDO;

final class Auth {
    public static function user(): ?array {
        return $_SESSION['user'] ?? null;
    }

    /**
     * Cloud par agar client link (?b=slug) ke baghair login aaye to tenant
     * email/username se resolve hota hai: exactly EK active tenant match ho
     * to wahi use hota hai; ek se zyada hon to slug lazmi hai (ambiguity).
     */
    private static function resolveCloudTenant(PDO $pdo, string $login): void {
        if (cfg('app.role') !== 'cloud') return;
        if (!empty($_SESSION['login_tenant_id']) || !empty($_SESSION['user']['tenant_id'])) return;
        $q = $pdo->prepare(
            "SELECT DISTINCT u.tenant_id
               FROM users u
               JOIN tenants t ON t.id=u.tenant_id
              WHERE u.status='ACTIVE' AND u.deleted_at IS NULL
                AND (LOWER(u.email)=LOWER(?) OR LOWER(u.username)=LOWER(?))
              LIMIT 2"
        );
        $q->execute([$login, $login]);
        $ids = array_column($q->fetchAll(), 'tenant_id');
        if (count($ids) === 1) $_SESSION['login_tenant_id'] = $ids[0];
    }

    /**
     * Subscription enforcement (cloud): EXPIRED ya SUSPENDED business ka
     * login block hota hai — clear message ke saath.
     * Return: null = OK, warna block message.
     */
    public static function subscriptionBlock(string $tenantId): ?string {
        if (cfg('app.role') !== 'cloud') return null;
        $pdo = DB::pdo();
        try {
            $t = $pdo->prepare("SELECT status FROM tenants WHERE id=? LIMIT 1");
            $t->execute([$tenantId]);
            $ts = (string)$t->fetchColumn();
            if ($ts === 'SUSPENDED') return 'This business is suspended. Please contact your provider.';
            $q = $pdo->prepare("SELECT status,expiry_date FROM tenant_subscriptions WHERE tenant_id=? ORDER BY created_at DESC LIMIT 1");
            $q->execute([$tenantId]);
            $s = $q->fetch();
            if ($s) {
                if ($s['status'] === 'SUSPENDED') return 'This business is suspended. Please contact your provider.';
                if (!empty($s['expiry_date']) && $s['expiry_date'] < date('Y-m-d')) {
                    return 'Subscription expired on '.$s['expiry_date'].'. Please renew to continue.';
                }
            }
        } catch (\Throwable $e) { /* enforcement best-effort; login kabhi crash na ho */ }
        return null;
    }

    public static function login(string $login, string $password): bool {
        $pdo = DB::pdo();
        $login = trim($login);
        self::resolveCloudTenant($pdo, $login);

        $q = $pdo->prepare(
            "SELECT *
               FROM users
              WHERE tenant_id=?
                AND status='ACTIVE'
                AND deleted_at IS NULL
                AND (LOWER(email)=LOWER(?) OR LOWER(username)=LOWER(?))
              LIMIT 1"
        );
        $q->execute([tenant_id(), $login, $login]);
        $u = $q->fetch();

        if (!$u || !is_string($u['password_hash'] ?? null)) {
            return false;
        }

        if (!password_verify($password, $u['password_hash'])) {
            return false;
        }

        // Create the authenticated session before doing any optional
        // permission/module query. This prevents unrelated permission
        // metadata problems from breaking a valid login.
        $u['modules'] = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['user'] = $u;

        if (!empty($u['is_tenant_admin'])) {
            try {
                $u['modules'] = array_column(
                    $pdo->query(
                        "SELECT module_key
                           FROM platform_modules
                          WHERE is_active=1
                          ORDER BY sort_order, name"
                    )->fetchAll(),
                    'module_key'
                );
            } catch (\Throwable $e) {
                $u['modules'] = [];
            }
        } else {
            try {
                $u['modules'] = self::moduleKeys($u['id']);
            } catch (\Throwable $e) {
                $u['modules'] = [];
            }
        }

        $_SESSION['user'] = $u;

        // Login history is best-effort only.
        try {
            $pdo->prepare("UPDATE users SET last_login_at=NOW(6) WHERE id=?")
                ->execute([$u['id']]);
        } catch (\Throwable $e) {
        }

        return true;
    }

    public static function logout(): void {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }

    public static function requireLogin(): void {
        if (!self::user()) redirect('/login.html');
    }

    public static function isAdmin(): bool {
        return (bool)(self::user()['is_tenant_admin'] ?? false);
    }

    public static function moduleKeys(string $userId): array {
        $current = self::user();

        if ($current && !empty($current['is_tenant_admin']) && ($current['id'] ?? '') === $userId) {
            return array_column(
                DB::pdo()->query(
                    "SELECT module_key
                       FROM platform_modules
                      WHERE is_active=1
                      ORDER BY sort_order, name"
                )->fetchAll(),
                'module_key'
            );
        }

        $pdo = DB::pdo();
        $sql = "
            SELECT DISTINCT pm.module_key
              FROM platform_modules pm
              LEFT JOIN user_module_access uma
                ON uma.module_id=pm.id
               AND uma.user_id=?
               AND (uma.site_id=? OR uma.site_id IS NULL)
              LEFT JOIN user_roles ur
                ON ur.user_id=?
               AND (ur.site_id=? OR ur.site_id IS NULL)
              LEFT JOIN role_modules rm
                ON rm.role_id=ur.role_id
               AND rm.module_id=pm.id
               AND rm.is_allowed=1
             WHERE pm.is_active=1
               AND (uma.access_mode='ALLOW' OR rm.id IS NOT NULL)
               AND COALESCE(uma.access_mode,'ALLOW')<>'DENY'
             ORDER BY pm.sort_order, pm.name
        ";

        $q = $pdo->prepare($sql);
        $q->execute([$userId, site_id(), $userId, site_id()]);
        return array_column($q->fetchAll(), 'module_key');
    }

    public static function canModule(string $key): bool {
        if (!self::user()) return false;
        if (self::isAdmin()) return true;
        return in_array($key, self::user()['modules'] ?? [], true);
    }

    public static function requireModule(string $key): void {
        self::requireLogin();
        if (!self::canModule($key)) {
            http_response_code(403);
            exit('Access denied.');
        }
    }

    public static function can(string $module, string $form, string $action='view'): bool {
        if (self::isAdmin()) return true;

        $u = self::user();
        if (!$u) return false;

        $col = [
            'view'=>'can_view',
            'add'=>'can_add',
            'edit'=>'can_edit',
            'delete'=>'can_delete',
            'approve'=>'can_approve',
            'export'=>'can_export',
            'print'=>'can_print'
        ][$action] ?? 'can_view';

        $q = DB::pdo()->prepare(
            "SELECT $col v
               FROM user_form_permissions
              WHERE user_id=?
                AND (site_id=? OR site_id IS NULL)
                AND module_key=?
                AND form_key=?
              ORDER BY site_id IS NULL ASC
              LIMIT 1"
        );
        $q->execute([$u['id'], site_id(), $module, $form]);
        $r = $q->fetch();

        return $r ? (bool)$r['v'] : self::canModule($module);
    }
}
