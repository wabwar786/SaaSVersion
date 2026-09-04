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

    /**
     * Device pairing ke liye: password ke baghair session banata hai.
     * Sirf server-side se call hota hai jab pairing token verify ho chuka ho.
     */
    public static function startSessionForUser(array $u): void
    {
        $pdo = DB::pdo();
        self::forgetTenantContext();
        $u['modules'] = [];
        if (session_status() === PHP_SESSION_ACTIVE) @session_regenerate_id(true);
        $_SESSION['user'] = $u;
        try {
            if (!empty($u['is_tenant_admin'])) {
                $u['modules'] = array_column($pdo->query(
                    "SELECT module_key FROM platform_modules WHERE is_active=1 ORDER BY sort_order,name")->fetchAll(), 'module_key');
            } else {
                $q = $pdo->prepare(
                    "SELECT DISTINCT pm.module_key FROM user_roles ur
                       JOIN role_modules rm ON rm.role_id=ur.role_id AND rm.is_allowed=1
                       JOIN platform_modules pm ON pm.id=rm.module_id
                      WHERE ur.user_id=?");
                $q->execute([$u['id']]);
                $u['modules'] = array_column($q->fetchAll(), 'module_key');
            }
        } catch (\Throwable $e) {}
        $_SESSION['user'] = $u;
        if (!empty($u['tenant_id'])) $_SESSION['login_tenant_id'] = $u['tenant_id'];
    }

    public static function login(string $login, string $password): bool {
        $pdo = DB::pdo();
        $login = trim($login);
        /* Naya login = naya business ho sakta hai. Purani tenant cache
           yahin girana zaroori hai, warna pichle business ka industry
           chipka reh jata hai. */
        self::forgetTenantContext();
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
                $mq = $pdo->prepare(
                    "SELECT module_key
                       FROM platform_modules
                      WHERE is_active=1
                        AND industry_code IN (?, 'COMMON')
                      ORDER BY sort_order, name"
                );
                $mq->execute([self::tenantIndustry()]);
                $u['modules'] = array_column($mq->fetchAll(), 'module_key');
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

    /**
     * Logout — magar business ka slug MEHFOOZ rakho.
     *
     * Pehle yahan poori session uda di jati thi, jis mein
     * `login_tenant_slug` bhi chala jata tha. Nateeja: login ke waqt URL
     * `login.html?b=akorwal-fish-point` hota tha, magar logout ke baad
     * sirf `login.html` reh jata tha — customer apne business ke login
     * par wapas hi nahi pohanchta tha, aur uska branding/menu bhi nahi
     * aata tha. Yeh sirf khaali "kaunsa business" ka nishan hai, koi
     * hifazati cheez nahi, is liye ise rakhna bilkul mehfooz hai.
     */
    public static function logout(): void {
        $slug = (string)($_SESSION['login_tenant_slug'] ?? '');
        $_SESSION = [];
        self::forgetTenantContext();
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);   // session id nayi, cookie zinda
        }
        if ($slug !== '') $_SESSION['login_tenant_slug'] = $slug;
    }

    public static function requireLogin(): void {
        if (!self::user()) redirect('/login.html');
    }

    public static function isAdmin(): bool {
        return (bool)(self::user()['is_tenant_admin'] ?? false);
    }

    /**
     * Tenant ka industry (RESTAURANT / RETAIL / ...).
     *
     * MASLA: `platform_modules.industry_code` pehle din se maujood tha
     * magar kisi query mein use hi nahi hota tha. Nateeja yeh hota ke
     * supermarket wale tenant ko bhi KDS, Tables aur Recipe mil jate.
     * Ab har module query is filter se guzarti hai:
     *     industry_code IN (tenant ka industry, 'COMMON')
     *
     * Session mein cache hai — har page par ek query bachti hai.
     */
    /** Abhi kaunsa tenant zer-e-ghaur hai. */
    private static function currentTenantId(): ?string
    {
        $tid = self::user()['tenant_id'] ?? ($_SESSION['login_tenant_id'] ?? null);
        if (!$tid) { try { $tid = tenant_id(); } catch (\Throwable $e) { $tid = null; } }
        return $tid ? (string)$tid : null;
    }

    /**
     * Cache HAMESHA tenant id ke saath rakhi jati hai.
     *
     * BUG JO ASAL TEST MEIN PAKRA GAYA:
     * pehle cache sirf `$_SESSION['tenant_industry']` thi. Ek hi browser
     * mein pehle restaurant ka login page kholein (cache = RESTAURANT),
     * phir usi session mein SUPERMARKET ke user se login karein — to
     * cache purani hi rehti thi. Nateeja: supermarket wale user ko
     * restaurant ke 39 modules milte the aur `canModule('kds')` TRUE
     * aata tha, yani doosre business ki screen khul jati.
     *
     * Ab cache ke saath tenant id bhi mehfooz hai; tenant badla to cache
     * apne aap bekaar ho jati hai. Login/logout par bhi saaf hoti hai.
     */
    public static function tenantIndustry(): string
    {
        $tid = self::currentTenantId();
        $c = $_SESSION['tenant_ctx'] ?? null;
        if (\is_array($c) && ($c['tid'] ?? null) === $tid && !empty($c['industry'])) {
            return (string)$c['industry'];
        }
        $ind = 'RESTAURANT'; $reg = 'PK';
        if ($tid) {
            try {
                $q = DB::pdo()->prepare("SELECT industry_code, region_profile FROM tenants WHERE id=? LIMIT 1");
                $q->execute([$tid]);
                if ($r = $q->fetch(\PDO::FETCH_ASSOC)) {
                    $ind = \strtoupper((string)($r['industry_code'] ?: 'RESTAURANT'));
                    $reg = \strtoupper((string)($r['region_profile'] ?: 'PK'));
                }
            } catch (\Throwable $e) {}
        }
        $_SESSION['tenant_ctx'] = ['tid' => $tid, 'industry' => $ind, 'region' => $reg];
        return $ind;
    }

    /** Tenant badalne par (login/logout) cache girana LAZMI hai. */
    public static function forgetTenantContext(): void
    {
        unset($_SESSION['tenant_ctx'], $_SESSION['tenant_industry'], $_SESSION['region_profile']);
        self::$moduleIndustryMap = null;
    }

    /** Tenant ka region profile (PK / UK / US) — currency aur tax isi se. */
    public static function tenantRegion(): string
    {
        self::tenantIndustry();                 // wahi tenant-keyed cache bhar deta hai
        return (string)($_SESSION['tenant_ctx']['region'] ?? 'PK');
    }

    public static function moduleKeys(string $userId): array {
        $current = self::user();

        if ($current && !empty($current['is_tenant_admin']) && ($current['id'] ?? '') === $userId) {
            $aq = DB::pdo()->prepare(
                "SELECT module_key
                   FROM platform_modules
                  WHERE is_active=1
                    AND industry_code IN (?, 'COMMON')
                  ORDER BY sort_order, name"
            );
            $aq->execute([self::tenantIndustry()]);
            return array_column($aq->fetchAll(), 'module_key');
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
               AND pm.industry_code IN (?, 'COMMON')
               AND (uma.access_mode='ALLOW' OR rm.id IS NOT NULL)
               AND COALESCE(uma.access_mode,'ALLOW')<>'DENY'
             ORDER BY pm.sort_order, pm.name
        ";

        $q = $pdo->prepare($sql);
        $q->execute([$userId, site_id(), $userId, site_id(), self::tenantIndustry()]);
        return array_column($q->fetchAll(), 'module_key');
    }

    /** Business ko super-admin se assign kiye gaye features (null = sab allowed). */
    public static function tenantFeatures(): ?array {
        static $cache = false;
        if ($cache !== false) return $cache;
        $cache = null;
        try {
            $q = DB::pdo()->prepare("SELECT features_json FROM tenants WHERE id=? LIMIT 1");
            $q->execute([tenant_id()]);
            $j = $q->fetchColumn();
            if ($j) { $a = json_decode((string)$j, true); if (is_array($a) && $a) $cache = $a; }
        } catch (\Throwable $e) {}
        return $cache;
    }

    /** module_key -> industry_code (ek dafa load, phir cache). */
    private static ?array $moduleIndustryMap = null;

    private static function moduleIndustry(string $key): ?string
    {
        if (self::$moduleIndustryMap === null) {
            $map = [];
            try {
                foreach (DB::pdo()->query("SELECT module_key, industry_code FROM platform_modules")->fetchAll() as $r) {
                    $map[(string)$r['module_key']] = \strtoupper((string)$r['industry_code']);
                }
            } catch (\Throwable $e) { $map = []; }
            self::$moduleIndustryMap = $map;
        }
        return self::$moduleIndustryMap[$key] ?? null;
    }

    public static function canModule(string $key): bool {
        if (!self::user()) return false;

        /* 0) INDUSTRY GATE — sab se pehle.
           Yeh admin par bhi lagta hai. Warna supermarket ka owner URL
           mein /kds.html likh kar restaurant ki screen khol leta, kyunke
           neeche `isAdmin()` har cheez ko haan keh deta hai. */
        $mi = self::moduleIndustry($key);
        if ($mi !== null && $mi !== 'COMMON' && $mi !== self::tenantIndustry()) return false;

        // 1) Business-level feature flag (super admin ka faisla) — admin par bhi lagta hai
        $feat = self::tenantFeatures();
        if ($feat !== null && !in_array($key, $feat, true)) return false;
        // 2) User-level permission
        if (self::isAdmin()) return true;
        return in_array($key, self::user()['modules'] ?? [], true);
    }

    /** Cashier = tenant admin nahi. Price change jaise kaam sirf admin/manager. */
    public static function isManager(): bool {
        if (!self::user()) return false;
        if (self::isAdmin()) return true;
        try {
            $q = DB::pdo()->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND (r.name LIKE '%Manager%' OR r.name LIKE '%Owner%' OR r.name LIKE '%Admin%')");
            $q->execute([self::user()['id']]);
            return (bool)$q->fetchColumn();
        } catch (\Throwable $e) { return false; }
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

// build: V17.1 build 2026-08-25
