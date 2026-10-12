<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * SiteSetting — `site_settings` par likhne/parhne ki EK HI JAGAH.  (V148)
 *
 * KYUN BANI
 * Is table par PAANCH jagah bilkul wahi SQL likhi hui thi —
 * SettingsService, TaxMode, Licence, BackupService, WhatsApp — aur har
 * ek ka apna "pehle SELECT, phir UPDATE ya INSERT" ka raqs tha. Paanch
 * copies ka matlab yeh hai ke koi bhi behtari (misal derive kiya hua
 * `id`) chaar jagah lagti hai aur paanchwin bhool jati hai. Yehi shakal
 * pehle bhi teen dafa bug ban chuki hai:
 *
 *     V141  module ki list do jagah       -> hidden features wapas aa gaye
 *     V142  tools/*.ps1 ki list do jagah  -> native_helper.ps1 package mein gaya hi nahi
 *     V145  migrations ki list do jagah   -> 33 mein se sirf 5 offline chalti thin
 *     V148  tax ki query do jagah         -> save hota tha, dikhta nahi tha
 *
 * `id` DERIVE HOTA HAI, RANDOM NAHI
 * Sync upsert `id` ke hisaab se chalta hai (jaan-boojh kar: pehle
 * unique-key par upsert hota tha aur do bills mil kar ek ban jate the).
 * Random `uuid()` ka matlab tha: branch par row ka id kuch aur, cloud
 * par kuch aur, aur UNIQUE (site_id, group, key) takra kar row REJECT.
 * Settings neeche aati hi nahi thin. Ab id = setting_uuid(site,group,key).
 */
final class SiteSetting
{
    /** Ek setting likho (banao ya badlo). */
    public static function put(string $group, string $key, mixed $value, bool $secret = false): void
    {
        $p    = DB::pdo();
        $json = \json_encode($value, JSON_UNESCAPED_UNICODE);
        $want = \setting_uuid(site_id(), $group, $key);

        $q = $p->prepare("SELECT id FROM site_settings
                           WHERE site_id=? AND setting_group=? AND setting_key=? LIMIT 1");
        $q->execute([site_id(), $group, $key]);
        $id = $q->fetchColumn();

        if ($id) {
            /* Purani rows ka id random tha — unhen bhi derive kiye hue id
               par le aao, warna sync har dafa conflict likhta rahega. */
            $p->prepare("UPDATE site_settings SET id=?, value_json=?, is_secret=?, updated_at=NOW(6) WHERE id=?")
              ->execute([$want, $json, $secret ? 1 : 0, $id]);
        } else {
            $p->prepare("INSERT INTO site_settings(id,tenant_id,site_id,setting_group,setting_key,value_json,is_secret)
                         VALUES(?,?,?,?,?,?,?)")
              ->execute([$want, tenant_id(), site_id(), $group, $key, $json, $secret ? 1 : 0]);
        }
    }

    /** Ek group ki saari settings: key => value (decoded). */
    public static function all(string $group): array
    {
        $out = [];
        try {
            $q = DB::pdo()->prepare("SELECT setting_key AS k, value_json AS v
                                       FROM site_settings
                                      WHERE site_id=? AND setting_group=?");
            $q->execute([site_id(), $group]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string)$r['k']] = \json_decode((string)$r['v'], true);
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /** Ek value, ya $default agar na mile. */
    public static function one(string $group, string $key, mixed $default = null): mixed
    {
        try {
            $q = DB::pdo()->prepare("SELECT value_json FROM site_settings
                                      WHERE site_id=? AND setting_group=? AND setting_key=? LIMIT 1");
            $q->execute([site_id(), $group, $key]);
            $v = $q->fetchColumn();
            if ($v === false || $v === null) return $default;
            return \json_decode((string)$v, true);
        } catch (\Throwable $e) { return $default; }
    }
}

// build: V148 build 2026-10-12
