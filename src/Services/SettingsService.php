<?php
namespace Aio\Services;

use Aio\Auth;
use Aio\DB;
use PDO;

/**
 * SettingsService — Settings page ka ASLI backing.
 *
 * Pehle `settings.html` 100% localStorage tha: "Urban Spoon",
 * "Islamabad — F10", NTN "1234567-8" — sab hardcoded demo values.
 * User "Save changes" dabata tha, "Settings saved" ka toast aata tha,
 * aur server par kuch nahi jata tha. Doosre computer par kholte hi
 * wapas wahi demo values. Yani page bilkul jhoota tha.
 *
 * Ab har field ki ek asli jagah hai:
 *
 *   Business name   -> tenants.display_name / tenants.name
 *   Branch          -> sites.name
 *   Phone / Address -> sites.phone / sites.address_text
 *   NTN / STRN      -> organizations.tax_no
 *   Taxes           -> ui_records / pos_settings  (WAHI jo POS use karta hai)
 *   Baqi sab        -> site_settings (tenant+site scoped, pehle se maujood table)
 *
 * Tax ko jaan-boojh kar `pos_settings` par rakha hai, alag copy nahi
 * banayi. Do jagah rakhne ka matlab hota: Settings kuch aur dikhaye,
 * POS kuch aur charge kare — aur farq mahinon tak nazar na aaye.
 */
final class SettingsService
{
    /** site_settings mein sab kuch isi group ke neeche. */
    private const GROUP = 'app';

    /** Jo keys site_settings mein rakhi jati hain (baqi asli tables mein). */
    private const KEYS = [
        'currency', 'rounding',
        'receipt_header', 'receipt_footer', 'receipt_logo', 'receipt_paper',
        'default_order_type', 'kot_autoprint', 'lowstock_alert',
        'receipt_template',
    ];

    private const DEFAULTS = [
        'currency'           => 'PKR',
        'rounding'           => 'Nearest 1',
        'receipt_header'     => '',
        'receipt_footer'     => '',
        'receipt_logo'       => 'Yes',
        'receipt_paper'      => '80mm',
        'receipt_template'   => 'classic',
        'default_order_type' => 'Dine In',
        'kot_autoprint'      => 'On',
        'lowstock_alert'     => 'On',
    ];

    /* ==================== READ ==================== */

    public static function get(): array
    {
        $p = DB::pdo();
        $out = self::DEFAULTS;

        /* --- site_settings --- */
        try {
            $q = $p->prepare("SELECT setting_key AS k, value_json AS v
                                FROM site_settings
                               WHERE tenant_id=? AND site_id=? AND setting_group=?");
            $q->execute([tenant_id(), site_id(), self::GROUP]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $key = (string)$r['k'];
                if (!in_array($key, self::KEYS, true)) continue;
                $val = json_decode((string)$r['v'], true);
                $out[$key] = is_scalar($val) ? (string)$val : '';
            }
        } catch (\Throwable $e) { /* table na ho to defaults se kaam chalao */ }

        /* --- business (tenants) --- */
        $out['business_name'] = '';
        try {
            $q = $p->prepare("SELECT COALESCE(NULLIF(display_name,''),name) AS n FROM tenants WHERE id=? LIMIT 1");
            $q->execute([tenant_id()]);
            $out['business_name'] = (string)($q->fetchColumn() ?: '');
        } catch (\Throwable $e) {
            try {
                $q = $p->prepare("SELECT name FROM tenants WHERE id=? LIMIT 1");
                $q->execute([tenant_id()]);
                $out['business_name'] = (string)($q->fetchColumn() ?: '');
            } catch (\Throwable $e2) {}
        }

        /* --- branch (sites) --- */
        $out['branch_name'] = '';
        $out['phone']       = '';
        $out['address']     = '';
        try {
            $q = $p->prepare("SELECT name, phone, address_text FROM sites WHERE id=? LIMIT 1");
            $q->execute([site_id()]);
            if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                $out['branch_name'] = (string)($r['name'] ?? '');
                $out['phone']       = (string)($r['phone'] ?? '');
                $out['address']     = (string)($r['address_text'] ?? '');
            }
        } catch (\Throwable $e) {}

        /* --- NTN / STRN (organizations) --- */
        $out['ntn'] = '';
        try {
            $q = $p->prepare("SELECT o.tax_no FROM organizations o
                               JOIN sites s ON s.organization_id = o.id
                              WHERE s.id=? LIMIT 1");
            $q->execute([site_id()]);
            $out['ntn'] = (string)($q->fetchColumn() ?: '');
        } catch (\Throwable $e) {}

        /* --- taxes: WAHI source jo POS use karta hai --- */
        $tax = self::taxes();
        $out['tax_cash']       = $tax['tax_cash'];
        $out['tax_card']       = $tax['tax_card'];
        $out['service_charge'] = $tax['service_charge'];

        /* --- FBR / fiscal (site_settings group 'fiscal') --- */
        $f = FiscalService::settings();
        foreach (FiscalService::defaults() as $k => $_) $out['fiscal_'.$k] = $f[$k];
        $out['fiscal_available_here'] = $f['available_here'];

        $out['templates'] = BillTemplate::options();
        $out['can_edit']  = Auth::isManager() || Auth::isAdmin();
        return $out;
    }

    /** pos_settings se tax rates — POS ka bilkul wahi record. */
    public static function taxes(): array
    {
        $d = [];
        try {
            $q = DB::pdo()->prepare("SELECT data_json FROM ui_records
                                      WHERE tenant_id=? AND site_id=? AND module_key='pos_settings' AND deleted=0
                                      ORDER BY created_at DESC LIMIT 1");
            $q->execute([tenant_id(), site_id()]);
            $j = $q->fetchColumn();
            $d = $j ? (json_decode((string)$j, true) ?: []) : [];
        } catch (\Throwable $e) {}
        return [
            'tax_cash'       => isset($d['tax_cash'])       ? (float)$d['tax_cash']       : 16.0,
            'tax_card'       => isset($d['tax_card'])       ? (float)$d['tax_card']       : 8.0,
            'service_charge' => isset($d['service_charge']) ? (float)$d['service_charge'] : 0.0,
        ];
    }

    /* ==================== WRITE ==================== */

    public static function save(array $d): array
    {
        if (!Auth::isManager() && !Auth::isAdmin()) {
            throw new \RuntimeException('Only an Admin or Manager can change settings.');
        }

        $p = DB::pdo();
        $changed = [];

        /* --- business name --- */
        if (array_key_exists('business_name', $d)) {
            $n = trim((string)$d['business_name']);
            if ($n === '') throw new \RuntimeException('Business name cannot be empty.');
            try {
                $p->prepare("UPDATE tenants SET display_name=?, updated_at=NOW(6) WHERE id=?")
                  ->execute([$n, tenant_id()]);
                $changed[] = 'business_name';
            } catch (\Throwable $e) {
                throw new \RuntimeException('Business name could not be saved: '.substr($e->getMessage(), 0, 120));
            }
        }

        /* --- branch / phone / address --- */
        $sSet = []; $sArg = [];
        if (array_key_exists('branch_name', $d)) {
            $b = trim((string)$d['branch_name']);
            if ($b === '') throw new \RuntimeException('Branch name cannot be empty.');
            $sSet[] = 'name=?';         $sArg[] = $b;  $changed[] = 'branch_name';
        }
        if (array_key_exists('phone', $d))   { $sSet[] = 'phone=?';        $sArg[] = trim((string)$d['phone']);   $changed[] = 'phone'; }
        if (array_key_exists('address', $d)) { $sSet[] = 'address_text=?'; $sArg[] = trim((string)$d['address']); $changed[] = 'address'; }
        if ($sSet) {
            $sArg[] = site_id();
            try {
                $p->prepare("UPDATE sites SET ".implode(', ', $sSet).", updated_at=NOW(6) WHERE id=?")->execute($sArg);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Branch details could not be saved: '.substr($e->getMessage(), 0, 120));
            }
        }

        /* --- NTN / STRN --- */
        if (array_key_exists('ntn', $d)) {
            try {
                $q = $p->prepare("SELECT o.id FROM organizations o JOIN sites s ON s.organization_id=o.id WHERE s.id=? LIMIT 1");
                $q->execute([site_id()]);
                if ($oid = $q->fetchColumn()) {
                    $p->prepare("UPDATE organizations SET tax_no=?, updated_at=NOW(6) WHERE id=?")
                      ->execute([trim((string)$d['ntn']) ?: null, $oid]);
                    $changed[] = 'ntn';
                }
            } catch (\Throwable $e) {}
        }

        /* --- taxes: seedha pos_settings par, alag copy NahI --- */
        if (array_key_exists('tax_cash', $d) || array_key_exists('tax_card', $d)
            || array_key_exists('service_charge', $d)) {
            $cur = self::taxes();
            $val = [
                'tax_cash'       => array_key_exists('tax_cash', $d)       ? max(0, (float)$d['tax_cash'])       : $cur['tax_cash'],
                'tax_card'       => array_key_exists('tax_card', $d)       ? max(0, (float)$d['tax_card'])       : $cur['tax_card'],
                'service_charge' => array_key_exists('service_charge', $d) ? max(0, (float)$d['service_charge']) : $cur['service_charge'],
            ];
            foreach ($val as $k => $v) {
                if ($v > 100) throw new \RuntimeException(str_replace('_', ' ', $k).' cannot be more than 100%.');
            }
            $json = json_encode($val, JSON_UNESCAPED_UNICODE);
            try {
                $q = $p->prepare("SELECT id FROM ui_records
                                   WHERE tenant_id=? AND site_id=? AND module_key='pos_settings' AND deleted=0 LIMIT 1");
                $q->execute([tenant_id(), site_id()]);
                if ($id = $q->fetchColumn()) {
                    $p->prepare("UPDATE ui_records SET data_json=?, row_version=row_version+1, updated_at=NOW(6) WHERE id=?")
                      ->execute([$json, $id]);
                } else {
                    $p->prepare("INSERT INTO ui_records(id,tenant_id,site_id,module_key,data_json,deleted,created_at)
                                 VALUES(?,?,?,'pos_settings',?,0,NOW(6))")
                      ->execute([uuid(), tenant_id(), site_id(), $json]);
                }
                $changed[] = 'taxes';
            } catch (\Throwable $e) {
                throw new \RuntimeException('Tax settings could not be saved: '.substr($e->getMessage(), 0, 120));
            }
        }

        /* --- FBR / fiscal --- */
        foreach (FiscalService::defaults() as $k => $_) {
            $form = 'fiscal_'.$k;
            if (!array_key_exists($form, $d)) continue;
            $v = trim((string)$d[$form]);
            if ($k === 'provider' && !in_array($v, FiscalService::PROVIDERS, true)) {
                throw new \RuntimeException('Invalid provider.');
            }
            self::put($p, $k, $v, 'fiscal');
            $changed[] = $form;
        }

        /* --- baqi sab site_settings mein --- */
        foreach (self::KEYS as $k) {
            if (!array_key_exists($k, $d)) continue;
            self::put($p, $k, (string)$d[$k]);
            $changed[] = $k;
        }

        return ['changed' => array_values(array_unique($changed))];
    }

    private static function put(PDO $p, string $key, string $value, string $group = self::GROUP): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        try {
            $q = $p->prepare("SELECT id FROM site_settings
                               WHERE site_id=? AND setting_group=? AND setting_key=? LIMIT 1");
            $q->execute([site_id(), $group, $key]);
            if ($id = $q->fetchColumn()) {
                $p->prepare("UPDATE site_settings SET value_json=?, updated_at=NOW(6) WHERE id=?")
                  ->execute([$json, $id]);
            } else {
                $p->prepare("INSERT INTO site_settings(id,tenant_id,site_id,setting_group,setting_key,value_json,is_secret)
                             VALUES(?,?,?,?,?,?,0)")
                  ->execute([uuid(), tenant_id(), site_id(), $group, $key, $json]);
            }
        } catch (\Throwable $e) {
            /* Khamosh nahi. Ek setting bhi na bache to user ko pata chale. */
            throw new \RuntimeException("Setting '$key' could not be saved: ".substr($e->getMessage(), 0, 120));
        }
    }
}

// build: V63 build 2026-08-26
