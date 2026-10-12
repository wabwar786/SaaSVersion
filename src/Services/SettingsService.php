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
 *   Taxes           -> site_settings, group 'tax'  (V148 se — neeche wajah)
 *   Baqi sab        -> site_settings (tenant+site scoped, pehle se maujood table)
 *
 * ============================================================
 * V148 — TAX SAVE HOTA THA, MAGAR WAPAS PURANA DIKHTA THA.
 *
 * Tax rates pehle `ui_records` / `module_key='pos_settings'` par thin.
 * Us table par koi unique key nahi hai, is liye EK SITE KI DO ROW ban
 * sakti thin — aur banti thin: branch computer apni `ui_records` cloud
 * par push karta hai, us row ka `id` alag hota hai, to cloud par doosri
 * copy ban jati thi.
 *
 * Phir yeh hota tha:
 *     PADHNE  wala code:  ORDER BY created_at DESC LIMIT 1   -> NAYI row
 *     LIKHNE  wala code:  LIMIT 1 (koi order nahi)           -> PURANI row
 *
 * Yani save PURANI row par lagta aur screen NAYI row dikhati. Toast
 * "Settings saved" aata, `ok=true` wapas aata, aur value kabhi nahi
 * badalti. Koi error kahin nazar nahi aata tha.
 *
 * Ab tax `site_settings` par hai, jahan UNIQUE (site_id, group, key)
 * laga hua hai — do row ban hi nahi sakti. Purani `pos_settings` row
 * pehli dafa parhte waqt khud utha li jati hai (dekho adoptLegacyTaxes)
 * aur `deleted=1` kar di jati hai, taake wo dobara wapas na aaye.
 * ============================================================
 */
final class SettingsService
{
    /** site_settings mein sab kuch isi group ke neeche. */
    private const GROUP = 'app';

    /** Tax ka alag group — isi mein `mode` bhi hai (TaxMode.php). */
    public const TAX_GROUP = 'tax';

    private const TAX_DEFAULTS = [
        'cash'           => 16.0,
        'card'           => 8.0,
        'service_charge' => 0.0,
    ];

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
        /* Price mode bhi isi payload mein — Settings ka ek hi Save button
           ise bhi bhejta hai, aur jawab mein wapas isi se paint hota hai. */
        $out['tax_mode']       = TaxMode::current();
        $out['tax_mode_info']  = TaxMode::describe($out['tax_mode']);

        /* --- FBR / fiscal (site_settings group 'fiscal') --- */
        $f = FiscalService::settings();
        foreach (FiscalService::defaults() as $k => $_) $out['fiscal_'.$k] = $f[$k];
        $out['fiscal_available_here'] = $f['available_here'];

        $out['templates'] = BillTemplate::options();
        $out['can_edit']  = Auth::isManager() || Auth::isAdmin();
        return $out;
    }

    /**
     * Tax rates — EK hi jagah se, jahan do row ban hi nahi sakti.
     * POS, Settings, bill aur FBR sab isi function se rates lete hain.
     */
    public static function taxes(): array
    {
        $v = self::readTaxGroup();
        if ($v === null) $v = self::adoptLegacyTaxes();
        return [
            'tax_cash'       => (float)$v['cash'],
            'tax_card'       => (float)$v['card'],
            'service_charge' => (float)$v['service_charge'],
        ];
    }

    /** site_settings group 'tax' — null agar ek bhi rate wahan na ho. */
    private static function readTaxGroup(): ?array
    {
        $out = self::TAX_DEFAULTS;
        $found = false;
        try {
            $q = DB::pdo()->prepare("SELECT setting_key AS k, value_json AS v
                                       FROM site_settings
                                      WHERE site_id=? AND setting_group=?");
            $q->execute([site_id(), self::TAX_GROUP]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = (string)$r['k'];
                if (!array_key_exists($k, $out)) continue;   // 'mode' yahan nahi chahiye
                $out[$k] = (float)json_decode((string)$r['v'], true);
                $found = true;
            }
        } catch (\Throwable $e) { return null; }
        return $found ? $out : null;
    }

    /**
     * Purani `pos_settings` row ko utha kar site_settings par le aana —
     * EK DAFA, khud ba khud. Deploy ki migration bulk mein yahi karti
     * hai; yeh function un sites ke liye hai jo migration ke baad bani
     * hon ya jin par purana offline node abhi tak push kar raha ho.
     */
    private static function adoptLegacyTaxes(): array
    {
        $legacy = null;
        try {
            $q = DB::pdo()->prepare("SELECT data_json FROM ui_records
                                      WHERE tenant_id=? AND site_id=? AND module_key='pos_settings' AND deleted=0
                                      ORDER BY created_at DESC LIMIT 1");
            $q->execute([tenant_id(), site_id()]);
            $j = $q->fetchColumn();
            if ($j) $legacy = json_decode((string)$j, true);
        } catch (\Throwable $e) {}

        if (!is_array($legacy)) return self::TAX_DEFAULTS;

        $v = [
            'cash'           => isset($legacy['tax_cash'])       ? max(0.0, (float)$legacy['tax_cash'])       : self::TAX_DEFAULTS['cash'],
            'card'           => isset($legacy['tax_card'])       ? max(0.0, (float)$legacy['tax_card'])       : self::TAX_DEFAULTS['card'],
            'service_charge' => isset($legacy['service_charge']) ? max(0.0, (float)$legacy['service_charge']) : self::TAX_DEFAULTS['service_charge'],
        ];
        try { self::writeTaxGroup($v); self::retireLegacyTaxRows(); } catch (\Throwable $e) {}
        return $v;
    }

    private static function writeTaxGroup(array $v): void
    {
        $p = DB::pdo();
        foreach (['cash', 'card', 'service_charge'] as $k) {
            self::putRaw($p, $k, (float)$v[$k], self::TAX_GROUP);
        }
    }

    /** Purani rows band — warna wo phir "nayi" ban kar wapas aa jati hain. */
    private static function retireLegacyTaxRows(): void
    {
        try {
            DB::pdo()->prepare("UPDATE ui_records SET deleted=1, row_version=row_version+1, updated_at=NOW(6)
                                 WHERE tenant_id=? AND site_id=? AND module_key='pos_settings' AND deleted=0")
                     ->execute([tenant_id(), site_id()]);
        } catch (\Throwable $e) {}
    }

    /**
     * Tax rates likhna — Settings page aur POS ka chhota settings modal
     * DONO yahin se guzarte hain. Pehle dono ki apni apni SQL thi.
     *
     * @param array $d  'tax_cash' / 'tax_card' / 'service_charge' (jo mojood ho)
     */
    public static function saveTaxes(array $d): array
    {
        $cur = self::taxes();
        $v = [
            'cash'           => array_key_exists('tax_cash', $d)       ? max(0.0, (float)$d['tax_cash'])       : (float)$cur['tax_cash'],
            'card'           => array_key_exists('tax_card', $d)       ? max(0.0, (float)$d['tax_card'])       : (float)$cur['tax_card'],
            'service_charge' => array_key_exists('service_charge', $d) ? max(0.0, (float)$d['service_charge']) : (float)$cur['service_charge'],
        ];
        foreach (['cash' => 'Cash tax', 'card' => 'Card tax', 'service_charge' => 'Service charge'] as $k => $label) {
            if ($v[$k] > 100) throw new \RuntimeException($label.' cannot be more than 100%.');
        }
        self::writeTaxGroup($v);
        self::retireLegacyTaxRows();
        return [
            'tax_cash'       => $v['cash'],
            'tax_card'       => $v['card'],
            'service_charge' => $v['service_charge'],
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

        /* --- taxes: ek hi row, ek hi raasta (saveTaxes) --- */
        if (array_key_exists('tax_cash', $d) || array_key_exists('tax_card', $d)
            || array_key_exists('service_charge', $d)) {
            self::saveTaxes($d);
            $changed[] = 'taxes';
        }

        /* --- price mode (inclusive / exclusive) ---
           V148: pehle is ke liye US SCREEN PAR ALAG "Save price mode"
           button tha. Log "Save changes" dabate the, toast "Settings
           saved" aata tha, aur price mode wahi purana reh jata tha —
           phir bill par "(incl.)" chhapta tha jab ke screen par "Tax
           added at the till" likha hota. Ab ek hi button. */
        if (array_key_exists('tax_mode', $d) && trim((string)$d['tax_mode']) !== '') {
            try {
                TaxMode::set((string)$d['tax_mode']);
                $changed[] = 'tax_mode';
            } catch (\Throwable $e) {
                throw new \RuntimeException($e->getMessage());
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
        self::putRaw($p, $key, $value, $group);
    }

    /**
     * Ek setting likhna. Number number rehta hai, string string.
     *
     * `id` ab `setting_uuid()` se banta hai — har node par wahi. Iske
     * baghair branch ki row ka id alag hota tha aur sync us ko unique-key
     * conflict ke taur par REJECT kar deta tha (dekho helpers.php).
     */
    private static function putRaw(PDO $p, string $key, mixed $value, string $group = self::GROUP): void
    {
        try {
            SiteSetting::put($group, $key, $value);
        } catch (\Throwable $e) {
            /* Khamosh nahi. Ek setting bhi na bache to user ko pata chale. */
            throw new \RuntimeException("Setting '$key' could not be saved: ".substr($e->getMessage(), 0, 120));
        }
    }
}

// build: V148 build 2026-10-12
