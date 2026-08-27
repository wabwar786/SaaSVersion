<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * Licence — subscription expiry, aur wo cheez jo customer ko waqt par
 * nazar aani is required.
 *
 * Masla: expiry sirf CLOUD ke super-admin console mein thi. Restaurant
 * ka apna software (aur khaas kar OFFLINE node) ko kuch pata hi nahi
 * hota tha. Customer ek din aata aur software band. Koi warning nahi,
 * aur band hone par yeh bhi nahi ke kis ko phone kare.
 *
 * Ab:
 *   - cloud: seedha `tenant_subscriptions` se
 *   - offline node: har sync par cloud se licence aata hai aur
 *     `site_settings` (group 'licence') mein mehfooz ho jata hai, taake
 *     net band ho tab bhi POS ko maloom ho
 */
final class Licence
{
    public const WARN_DAYS = 3;

    public const VENDOR = [
        'company' => 'Wabwar Software House',
        'person'  => 'Waseem Iqbal',
        'phone'   => '+92 342 5095104',
        'email'   => 'support@wabwar.pk',
        'website' => 'www.wabwar.pk',
    ];

    /** Cloud par asli record se. */
    public static function fromDb(?string $tenantId = null): array
    {
        $tid = $tenantId ?: tenant_id();
        try {
            $q = DB::pdo()->prepare(
                "SELECT s.expiry_date, s.status, t.name, t.status AS tenant_status
                   FROM tenants t
                   LEFT JOIN tenant_subscriptions s
                          ON s.id = (SELECT s2.id FROM tenant_subscriptions s2
                                      WHERE s2.tenant_id = t.id COLLATE utf8mb4_unicode_ci
                                      ORDER BY s2.created_at DESC LIMIT 1)
                  WHERE t.id = ? LIMIT 1");
            $q->execute([$tid]);
            $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { $r = []; }

        return self::shape(
            (string)($r['expiry_date'] ?? ''),
            (string)($r['tenant_status'] ?? ''),
            (string)($r['name'] ?? '')
        );
    }

    /** Node par: aakhri sync mein jo aaya tha. */
    public static function cached(): array
    {
        $v = [];
        try {
            $q = DB::pdo()->prepare("SELECT setting_key AS k, value_json AS v FROM site_settings
                                      WHERE tenant_id=? AND site_id=? AND setting_group='licence'");
            $q->execute([tenant_id(), site_id()]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = json_decode((string)$r['v'], true);
                $v[(string)$r['k']] = is_scalar($d) ? (string)$d : '';
            }
        } catch (\Throwable $e) {}
        return self::shape($v['expiry_date'] ?? '', $v['tenant_status'] ?? '', $v['business'] ?? '');
    }

    public static function current(): array
    {
        return (string)cfg('app.role') === 'cloud' ? self::fromDb() : self::cached();
    }

    /** Sync ke waqt cloud se mila hua licence mehfooz karo. */
    public static function store(array $lic): void
    {
        $keep = ['expiry_date' => (string)($lic['expiry_date'] ?? ''),
                 'tenant_status' => (string)($lic['tenant_status'] ?? ''),
                 'business' => (string)($lic['business'] ?? '')];
        foreach ($keep as $k => $v) {
            try {
                $p = DB::pdo();
                $q = $p->prepare("SELECT id FROM site_settings WHERE site_id=? AND setting_group='licence' AND setting_key=? LIMIT 1");
                $q->execute([site_id(), $k]);
                $json = json_encode($v, JSON_UNESCAPED_UNICODE);
                if ($id = $q->fetchColumn()) {
                    $p->prepare("UPDATE site_settings SET value_json=?, updated_at=NOW(6) WHERE id=?")->execute([$json, $id]);
                } else {
                    $p->prepare("INSERT INTO site_settings(id,tenant_id,site_id,setting_group,setting_key,value_json,is_secret)
                                 VALUES(?,?,?,'licence',?,?,0)")
                      ->execute([uuid(), tenant_id(), site_id(), $k, $json]);
                }
            } catch (\Throwable $e) { /* licence cache best-effort */ }
        }
    }

    private static function shape(string $expiry, string $tenantStatus, string $business): array
    {
        $expiry = trim($expiry);
        $days   = null;
        if ($expiry !== '') {
            $d1 = new \DateTime(date('Y-m-d'));
            $d2 = new \DateTime(substr($expiry, 0, 10));
            $days = (int)$d1->diff($d2)->format('%r%a');
        }
        $suspended = strtoupper($tenantStatus) === 'SUSPENDED';
        $expired   = $suspended || ($days !== null && $days < 0);
        $warn      = !$expired && $days !== null && $days <= self::WARN_DAYS;

        return [
            'business'      => $business,
            'expiry_date'   => $expiry,
            'days_left'     => $days,
            'expired'       => $expired,
            'warn'          => $warn,
            'tenant_status' => $tenantStatus,
            'vendor'        => self::VENDOR,
            'message'       => self::message($expired, $warn, $days, $expiry),
        ];
    }

    private static function message(bool $expired, bool $warn, ?int $days, string $expiry): string
    {
        if ($expired) {
            return 'Your subscription has expired. Please contact '
                 . self::VENDOR['company'] . ' to renew: ' . self::VENDOR['phone'];
        }
        if ($warn) {
            if ($days === 0) return 'Your subscription expires today (' . $expiry . '). Please renew.';
            if ($days === 1) return 'Your subscription expires tomorrow (' . $expiry . '). Please renew.';
            return 'Your subscription expires in ' . $days . ' days (' . $expiry . '). Please renew.';
        }
        return '';
    }
}

// build: V65 build 2026-08-27
