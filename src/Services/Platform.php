<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * Platform — SaaS layer (Super User / Heads + business provisioning).
 * Online (cloud) node par chalta hai. Ek Super User restaurant business
 * create karta hai (payment + expiry ke saath) aur client ko dene ke liye
 * ek login link ban jata hai.
 */
final class Platform
{
    /* --------------------- super user auth --------------------- */

    public static function ensureSuperUser(): void
    {
        $pdo = DB::pdo();
        $n = (int)$pdo->query("SELECT COUNT(*) FROM platform_users WHERE role='SUPER'")->fetchColumn();
        if ($n === 0) {
            $pdo->prepare("INSERT INTO platform_users(id,role,full_name,email,password_hash,status) VALUES(?,?,?,?,?, 'ACTIVE')")
                ->execute([\uuid(), 'SUPER', 'Platform Owner', 'super@admin.local',
                           \password_hash('Super@123', PASSWORD_DEFAULT)]);
        }
    }

    public static function superUser(): ?array { return $_SESSION['platform_user'] ?? null; }

    public static function superLogin(string $email, string $password): bool
    {
        self::ensureSuperUser();
        $q = DB::pdo()->prepare("SELECT * FROM platform_users WHERE LOWER(email)=LOWER(?) AND status='ACTIVE' LIMIT 1");
        $q->execute([\trim($email)]);
        $u = $q->fetch(PDO::FETCH_ASSOC);
        if (!$u || !\password_verify($password, $u['password_hash'])) return false;
        unset($u['password_hash']);
        $_SESSION['platform_user'] = $u;
        return true;
    }

    public static function superLogout(): void { unset($_SESSION['platform_user']); }

    /* --------------------- slug --------------------- */

    private static function slugify(string $s): string
    {
        $s = \strtolower(\trim($s));
        $s = \preg_replace('/[^a-z0-9]+/', '-', $s);
        return \trim($s, '-') ?: 'business';
    }

    private static function uniqueSlug(string $base): string
    {
        $pdo = DB::pdo(); $slug = $base; $i = 1;
        while (true) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE slug=?");
            $q->execute([$slug]);
            if ((int)$q->fetchColumn() === 0) return $slug;
            $i++; $slug = $base.'-'.$i;
        }
    }

    /* --------------------- provisioning --------------------- */

    /**
     * Create a full business (tenant + org + site + admin + subscription + payment).
     * Returns a summary incl. the client login link and one-time admin password.
     */
    public static function provisionBusiness(array $d): array
    {
        $name = \trim($d['business_name'] ?? '');
        if ($name === '') throw new \RuntimeException('Business name required');
        $ownerEmail = \trim($d['owner_email'] ?? '');
        if ($ownerEmail === '') throw new \RuntimeException('Owner email required');

        $industry = \strtoupper(\trim($d['industry_code'] ?? 'RESTAURANT'));
        $slug     = self::uniqueSlug(self::slugify($name));
        $tz       = $d['timezone'] ?? 'Asia/Karachi';
        $currency = $d['currency'] ?? 'PKR';
        $branch   = \trim($d['branch_name'] ?? 'Main Branch');
        $adminName= \trim($d['owner_name'] ?? $name.' Owner');
        $password = \trim($d['admin_password'] ?? '') ?: self::genPassword();
        $syncToken= \bin2hex(\random_bytes(20));
        $baseUrl  = \rtrim((string)($GLOBALS['config']['app']['base_url'] ?? ''), '/');

        $ids = DB::tx(function (PDO $pdo) use ($d,$name,$slug,$industry,$tz,$currency,$branch,$adminName,$ownerEmail,$password,$syncToken) {
            $tenantId = \uuid(); $orgId = \uuid(); $siteId = \uuid(); $userId = \uuid();
            $code = \strtoupper(\preg_replace('/[^A-Z0-9]+/','-', \strtoupper($slug)));

            $pdo->prepare("INSERT INTO tenants(id,code,name,slug,industry_code,sync_token,owner_email,status,timezone,default_currency)
                           VALUES(?,?,?,?,?,?,?, 'ACTIVE',?,?)")
                ->execute([$tenantId,$code,$name,$slug,$industry,$syncToken,$ownerEmail,$tz,$currency]);

            $pdo->prepare("INSERT INTO organizations(id,tenant_id,organization_type,industry_code,name,email,phone,address_text,status)
                           VALUES(?,?,?,?,?,?,?,?, 'ACTIVE')")
                ->execute([$orgId,$tenantId,'BUSINESS',$industry,$name,$ownerEmail,($d['owner_phone']??null),($d['address']??null)]);

            $pdo->prepare("INSERT INTO sites(id,tenant_id,organization_id,code,name,site_type,timezone,currency,address_text,phone,status)
                           VALUES(?,?,?,?,?, 'BRANCH',?,?,?,?, 'ACTIVE')")
                ->execute([$siteId,$tenantId,$orgId, $code.'-01',$branch,$tz,$currency,($d['address']??null),($d['owner_phone']??null)]);

            $pdo->prepare("INSERT INTO users(id,tenant_id,username,email,phone,full_name,password_hash,password_algo,status,is_tenant_admin)
                           VALUES(?,?,?,?,?,?,?, 'BCRYPT','ACTIVE',1)")
                ->execute([$userId,$tenantId, \explode('@',$ownerEmail)[0], $ownerEmail, ($d['owner_phone']??null), $adminName, \password_hash($password, PASSWORD_DEFAULT)]);

            // subscription + payment
            $planId = $d['plan_id'] ?? null;
            if ($planId) { $c=$pdo->prepare("SELECT COUNT(*) FROM subscription_plans WHERE id=?"); $c->execute([$planId]); if(!(int)$c->fetchColumn()) $planId=null; }
            $start  = $d['start_date'] ?? \date('Y-m-d');
            $expiry = $d['expiry_date'] ?? \date('Y-m-d', \strtotime('+1 month'));
            $amount = (float)($d['amount'] ?? 0);
            $subId  = \uuid();
            $pdo->prepare("INSERT INTO tenant_subscriptions(id,tenant_id,plan_id,status,amount,start_date,expiry_date,created_by)
                           VALUES(?,?,?, 'ACTIVE',?,?,?,?)")
                ->execute([$subId,$tenantId,$planId,$amount,$start,$expiry, ($_SESSION['platform_user']['id']??null)]);

            if ($amount > 0 || !empty($d['payment_method'])) {
                $pdo->prepare("INSERT INTO subscription_payments(id,tenant_id,subscription_id,amount,method,reference,payer_name,note,created_by)
                               VALUES(?,?,?,?,?,?,?,?,?)")
                    ->execute([\uuid(),$tenantId,$subId,$amount, \strtoupper($d['payment_method']??'CASH'),
                               ($d['payment_reference']??null),($d['payer_name']??$adminName),($d['payment_note']??null),
                               ($_SESSION['platform_user']['id']??null)]);
            }
            return ['tenant'=>$tenantId,'org'=>$orgId,'site'=>$siteId,'user'=>$userId,'sub'=>$subId];
        });

        $link = ($baseUrl ?: '') . '/login.html?b=' . $slug;
        return [
            'slug' => $slug,
            'tenant_id' => $ids['tenant'],
            'site_id' => $ids['site'],
            'business_name' => $name,
            'client_link' => $link,
            'admin_email' => $ownerEmail,
            'admin_password' => $password,   // shown once to hand to the client
            'sync_token' => $syncToken,
        ];
    }

    private static function genPassword(): string
    {
        $a='ABCDEFGHJKLMNPQRSTUVWXYZ'; $b='abcdefghijkmnpqrstuvwxyz'; $c='23456789'; $d='@#%&*';
        $pick=fn($s,$n)=>\substr(\str_shuffle(\str_repeat($s,$n)),0,$n);
        return \str_shuffle($pick($a,2).$pick($b,4).$pick($c,3).$pick($d,1));
    }

    /* --------------------- listing --------------------- */

    public static function listBusinesses(): array
    {
        $pdo = DB::pdo();
        $rows = $pdo->query("
            SELECT t.id, t.name, t.slug, t.industry_code, t.status, t.owner_email, t.created_at,
                   (SELECT s.status      FROM tenant_subscriptions s WHERE s.tenant_id=t.id ORDER BY s.created_at DESC LIMIT 1) AS sub_status,
                   (SELECT s.expiry_date FROM tenant_subscriptions s WHERE s.tenant_id=t.id ORDER BY s.created_at DESC LIMIT 1) AS expiry_date,
                   (SELECT s.amount      FROM tenant_subscriptions s WHERE s.tenant_id=t.id ORDER BY s.created_at DESC LIMIT 1) AS amount,
                   (SELECT COUNT(*) FROM sites si WHERE si.tenant_id=t.id AND si.deleted_at IS NULL) AS branches
              FROM tenants t
             WHERE t.slug IS NOT NULL
             ORDER BY t.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $base = \rtrim((string)($GLOBALS['config']['app']['base_url'] ?? ''), '/');
        foreach ($rows as &$r) {
            $r['client_link'] = $base . '/login.html?b=' . $r['slug'];
            $r['expired'] = (!empty($r['expiry_date']) && $r['expiry_date'] < \date('Y-m-d'));
        }
        return $rows;
    }

    /** Resolve a slug to a tenant id (for client-link login scoping). */
    public static function tenantIdBySlug(string $slug): ?string
    {
        $q = DB::pdo()->prepare("SELECT id FROM tenants WHERE slug=? LIMIT 1"); // status yahan filter NAHI hota — suspension login ke baad clear message ke saath enforce hoti hai
        $q->execute([\trim($slug)]);
        return $q->fetchColumn() ?: null;
    }
}
