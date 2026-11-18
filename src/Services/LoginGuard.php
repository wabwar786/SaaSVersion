<?php
declare(strict_types=1);
namespace Aio\Services;

use Aio\DB;

/* ============================================================
   V205 — LOGIN KA TAALA (har login raaste par ek hi).

   Pehle 30 ghalat password ke baad bhi 31va sahi chal jata (POS ke
   password aksar 4 ank). Ab:
     - ek login (email / username) par 10 minute mein 5 ghalat
       = 10 minute taala;
     - ek IP se 10 minute mein 30 ghalat = us IP ka 10 minute taala.
   Har ghalat koshish audit_log (LOGIN_FAIL) mein. Login page ka form
   (login-submit.php) aur api.php (`login`, `sa-login`) dono isi se.
   ============================================================ */
final class LoginGuard
{
    /* Cloud (Railway) par proxy apna IP REMOTE_ADDR mein rakhta hai aur
       asli IP X-Forwarded-For ke AAKHRI hisse mein jodta hai (pehle hisse
       client khud likh sakta hai). */
    public static function ip(): string
    {
        $x = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($x !== '') {
            $parts = array_values(array_filter(array_map('trim', explode(',', $x))));
            if ($parts) return substr((string)end($parts), 0, 64);
        }
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
    }

    public static function locked(string $key): ?string
    {
        try {
            $p = DB::pdo();
            $q = $p->prepare("SELECT COUNT(*) FROM audit_log WHERE action='LOGIN_FAIL' AND record_label=? AND created_at > NOW(6) - INTERVAL 10 MINUTE");
            $q->execute([$key]);
            if ((int)$q->fetchColumn() >= 5) return 'Is login par 5 dafa ghalat password — 10 minute baad dobara koshish karein.';
            $q = $p->prepare("SELECT COUNT(*) FROM audit_log WHERE action='LOGIN_FAIL' AND ip_address=? AND created_at > NOW(6) - INTERVAL 10 MINUTE");
            $q->execute([self::ip()]);
            if ((int)$q->fetchColumn() >= 30) return 'Is device se bohat zyada ghalat koshishein — 10 minute baad dobara koshish karein.';
        } catch (\Throwable $e) {}
        return null;
    }

    public static function failed(string $key): void
    {
        try {
            DB::pdo()->prepare("INSERT INTO audit_log(id,tenant_id,site_id,user_id,username,role_name,action,module,record_id,record_label,description,ip_address,created_at)
                                VALUES(?,NULL,NULL,NULL,?,NULL,'LOGIN_FAIL','auth',NULL,?,?,?,NOW(6))")
              ->execute([uuid(), substr($key, 0, 120), substr($key, 0, 200), 'Wrong password', self::ip()]);
        } catch (\Throwable $e) {}
    }
}

// build: V205 build 2026-11-18
