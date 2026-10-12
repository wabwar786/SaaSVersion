<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * PlatformSetting — `platform_settings` par likhne/parhne ki EK jagah.
 *
 * Yeh table poore platform ki hai (kisi ek business ki nahi): billing ki
 * tafseel, qeematein, aur platform ki apni WhatsApp API.
 *
 * `SiteSetting` (V148) ke saath wahi sabaq: ek table, ek hi darwaza.
 * Pehle is table ko do jagah se chhera jata tha — SelfService parhta tha
 * aur api.php apni SQL se likhta tha.
 *
 * Groups:
 *   billing -> bank_name, account_title, account_number, iban,
 *              easypaisa, jazzcash, monthly_price, yearly_price,
 *              tax_pct, ntn, invoice_note    (V151)
 *   wa      -> api_url, api_key              (V151 — invoice khud bhejne ke liye)
 */
final class PlatformSetting
{
    /** Ek group ki saari settings: key => value (string). */
    public static function all(string $group): array
    {
        $out = [];
        try {
            $q = DB::pdo()->prepare("SELECT setting_key AS k, value_json AS v
                                       FROM platform_settings WHERE setting_group=?");
            $q->execute([$group]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = \json_decode((string)$r['v'], true);
                $out[(string)$r['k']] = \is_scalar($d) ? (string)$d : '';
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    public static function one(string $group, string $key, string $default = ''): string
    {
        try {
            $q = DB::pdo()->prepare("SELECT value_json FROM platform_settings
                                      WHERE setting_group=? AND setting_key=? LIMIT 1");
            $q->execute([$group, $key]);
            $v = $q->fetchColumn();
            if ($v === false || $v === null) return $default;
            $d = \json_decode((string)$v, true);
            return \is_scalar($d) && (string)$d !== '' ? (string)$d : $default;
        } catch (\Throwable $e) { return $default; }
    }

    public static function put(string $group, string $key, string $value): void
    {
        $p = DB::pdo();
        $json = \json_encode($value, JSON_UNESCAPED_UNICODE);
        $q = $p->prepare("SELECT id FROM platform_settings
                           WHERE setting_group=? AND setting_key=? LIMIT 1");
        $q->execute([$group, $key]);
        if ($id = $q->fetchColumn()) {
            $p->prepare("UPDATE platform_settings SET value_json=?, updated_at=NOW(6) WHERE id=?")
              ->execute([$json, $id]);
        } else {
            $p->prepare("INSERT INTO platform_settings(id,setting_group,setting_key,value_json)
                         VALUES(?,?,?,?)")->execute([\uuid(), $group, $key, $json]);
        }
    }
}

// build: V151 build 2026-10-12
