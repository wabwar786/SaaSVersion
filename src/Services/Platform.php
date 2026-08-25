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
            self::seedSiteDefaults($pdo, $tenantId, $siteId);
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


    /**
     * Naye business ke liye OPERATIONAL DEFAULTS — iske baghair site khali
     * shell hoti thi: POS mein na payment method (bill hi nahi banta), na
     * stock location (inventory posting throw karti thi), na printer (KOT
     * routing nahi), na tables/floor, na units. Ab pehle din se billing
     * possible hai. Menu jaan-boojh kar khali rehta hai — client POS ke
     * "+ New Item" se apna menu banata hai (starter categories ready hain).
     */
    public static function seedSiteDefaults(PDO $pdo, string $tenantId, string $siteId): void
    {
        // Units (GLOBAL table) — sirf missing codes insert hote hain.
        $units = [['PCS','Piece','COUNT',0],['G','Gram','WEIGHT',3],['KG','Kilogram','WEIGHT',3],['ML','Millilitre','VOLUME',3],['L','Litre','VOLUME',3]];
        $uq = $pdo->prepare("SELECT COUNT(*) FROM units WHERE code=?");
        $ui = $pdo->prepare("INSERT INTO units(id,code,name,unit_type,decimal_places) VALUES(?,?,?,?,?)");
        foreach ($units as $u) { $uq->execute([$u[0]]); if (!(int)$uq->fetchColumn()) $ui->execute([\uuid(),$u[0],$u[1],$u[2],$u[3]]); }

        // Payment methods — POS finalize inhi par depend karta hai.
        $pm = $pdo->prepare("INSERT INTO payment_methods(id,tenant_id,site_id,code,name,method_type) VALUES(?,?,?,?,?,?)");
        foreach ([['CASH','Cash','CASH'],['CARD','Card','CARD'],['RAAST','Raast','BANK'],['EASYPAISA','Easypaisa','WALLET'],['JAZZCASH','JazzCash','WALLET'],['BANK','Bank Transfer','BANK'],['COD','Cash on Delivery','COD']] as $m) {
            $pm->execute([\uuid(),$tenantId,$siteId,$m[0],$m[1],$m[2]]);
        }

        // Stock locations — inventory/recipe posting ke liye lazmi.
        $sl = $pdo->prepare("INSERT INTO stock_locations(id,tenant_id,site_id,code,name,location_type,is_active) VALUES(?,?,?,?,?,?,1)");
        $sl->execute([\uuid(),$tenantId,$siteId,'STORE','Main Store','STORE']);
        $sl->execute([\uuid(),$tenantId,$siteId,'KITCHEN','Kitchen','KITCHEN']);

        // Default kitchen printer + starter menu categories (routes ke saath).
        $printerId = \uuid();
        $pdo->prepare("INSERT INTO printers(id,tenant_id,site_id,name,printer_type,station_code,connection_type,is_active,is_default) VALUES(?,?,?,?,?,?,?,1,1)")
            ->execute([$printerId,$tenantId,$siteId,'Main Kitchen','KITCHEN','main','WINDOWS']);
        $cats = ['Pakistani','Fast Food','BBQ','Beverages'];
        $ci = $pdo->prepare("INSERT INTO menu_categories(id,tenant_id,site_id,name,icon_text,sort_order,is_active) VALUES(?,?,?,?,?,?,1)");
        $ri = $pdo->prepare("INSERT INTO menu_category_printer_routes(id,tenant_id,site_id,category_id,printer_id,is_primary,route_priority,is_active) VALUES(?,?,?,?,?,1,1,1)");
        foreach ($cats as $i => $c) { $cid=\uuid(); $ci->execute([$cid,$tenantId,$siteId,$c,'•',$i+1]); $ri->execute([\uuid(),$tenantId,$siteId,$cid,$printerId]); }

        // Floor + 8 tables — dine-in pehle din se.
        $floorId = \uuid();
        $pdo->prepare("INSERT INTO floors(id,tenant_id,site_id,name,sort_order,is_active) VALUES(?,?,?,?,1,1)")
            ->execute([$floorId,$tenantId,$siteId,'Main Floor']);
        $ti = $pdo->prepare("INSERT INTO dining_tables(id,tenant_id,site_id,floor_id,table_code,display_name,seats,shape,status,is_active) VALUES(?,?,?,?,?,?,?,?,?,1)");
        for ($i=1; $i<=8; $i++) { $ti->execute([\uuid(),$tenantId,$siteId,$floorId,'T-'.str_pad((string)$i,2,'0',STR_PAD_LEFT),'Table '.$i,4,'SQUARE','AVAILABLE']); }

        // Expense categories.
        $ec = $pdo->prepare("INSERT INTO expense_categories(id,tenant_id,name,is_active) VALUES(?,?,?,1)");
        foreach (['Kitchen Supplies','Utilities','Staff','Fuel / Delivery','Cleaning','General'] as $c) { $ec->execute([\uuid(),$tenantId,$c]); }
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
