<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * LicenceKey — offline renewal.
 *
 * MASLA: branch computer par internet na ho, aur licence khatam ho jaye.
 * Customer paisay bhej deta hai, magar software chalu karne ka koi rasta
 * nahi — na sync, na portal.
 *
 * HAL: aap server par ek KEY banate hain, WhatsApp ya phone par customer
 * ko dete hain, wo software mein daal deta hai, aur licence barh jata
 * hai. Internet ki koi zaroorat nahi.
 *
 * KEY KESE MEHFOOZ HAI:
 *
 *  - Har business ka apna raaz (`tenants.licence_secret`). Ek business
 *    ki key doosre par NAHI chalti.
 *  - Key ke andar business ka nishan hai; ghalat jagah daalne par saaf
 *    inkaar hota hai.
 *  - Har key EK DAFA chalti hai (`licence_keys_used`). Customer ek hi
 *    key bar bar daal kar hamesha nahi chala sakta.
 *  - Key ki apni miyaad hai (default 30 din) — purani parhi hui key
 *    baad mein kaam nahi aati.
 *
 * EK BAAT SAAF: yeh raaz customer ke apne computer par hoti hai. Jo
 * bandah waqai chahe aur jaanta ho, wo apni khud ki key bana sakta hai.
 * Yeh nizam bhoolne aur suhoolat ke liye hai, chori rokne ka taala nahi.
 * Asli hifazat yeh hai ke har business ka raaz alag hai, is liye ek
 * jagah ka masla baqi customers tak nahi jata.
 */
final class LicenceKey
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford: I,L,O,U nahi
    private const VALID_DAYS = 30;   // key khud kitne din tak chalegi

    /* ---------------- raaz ---------------- */

    public static function secret(string $tenantId): string
    {
        $p = DB::pdo();
        try {
            $q = $p->prepare("SELECT licence_secret FROM tenants WHERE id=? LIMIT 1");
            $q->execute([$tenantId]);
            $s = (string)($q->fetchColumn() ?: '');
            if ($s !== '') return $s;
        } catch (\Throwable $e) {}

        $s = bin2hex(random_bytes(24));
        try {
            $p->prepare("UPDATE tenants SET licence_secret=? WHERE id=?")->execute([$s, $tenantId]);
        } catch (\Throwable $e) {}
        return $s;
    }

    /** Business ka chhota nishan — key ke andar jata hai. */
    private static function fingerprint(string $tenantId, string $secret): int
    {
        return (int)(hexdec(substr(hash_hmac('sha256', 'fp:' . $tenantId, $secret), 0, 4)) & 0xFFFF);
    }

    /* ---------------- banao (server par) ---------------- */

    /**
     * @param int $days  kitne din barhane hain
     * @return array{key:string,expires:string,days:int}
     */
    public static function generate(string $tenantId, int $days, string $actor = 'super'): array
    {
        $days = max(1, min(3650, $days));
        $secret = self::secret($tenantId);

        $issued = (int)floor(time() / 86400);          // din ki ginti (epoch se)
        $nonce  = random_int(0, 0xFFFFFF);

        /* payload: fp(2) + days(2) + issued(3) + nonce(3) = 10 bytes */
        $payload = pack('n', self::fingerprint($tenantId, $secret))
                 . pack('n', $days)
                 . substr(pack('N', $issued), 1)
                 . substr(pack('N', $nonce), 1);

        $sig = substr(hash_hmac('sha256', $payload, $secret, true), 0, 5);
        $key = self::b32(  $payload . $sig);           // 15 bytes -> 24 chars

        try {
            DB::pdo()->prepare(
                "INSERT INTO licence_keys(id,tenant_id,key_code,days,issued_by,created_at)
                 VALUES(?,?,?,?,?,NOW(6))")
              ->execute([uuid(), $tenantId, $key, $days, $actor]);
        } catch (\Throwable $e) {}

        try {
            AdminData::audit($actor, $tenantId, 'LICENCE_KEY',
                $days . ' day(s) — ' . self::pretty($key));
        } catch (\Throwable $e) {}

        return ['key' => self::pretty($key), 'raw' => $key, 'days' => $days,
                'expires' => date('Y-m-d', strtotime('+' . self::VALID_DAYS . ' day')),
                'note' => 'This key works only for this business, only once, '
                        . 'and only within ' . self::VALID_DAYS . ' days.'];
    }

    /* ---------------- lagao (branch computer par) ---------------- */

    public static function apply(string $typed): array
    {
        Scope::requireManagement('activating a licence key');

        $key = strtoupper(preg_replace('/[^0-9A-Z]/i', '', $typed));
        /* Aam ghalatiyan khud sudhaar do — customer phone par sun kar
           likhta hai, aur O/0 aur I/1 mein farq nazar nahi aata. */
        $key = strtr($key, ['O' => '0', 'I' => '1', 'L' => '1', 'U' => 'V']);

        if (strlen($key) !== 24) {
            throw new \RuntimeException('That key does not look complete. It should be 24 characters.');
        }

        $raw = self::unb32($key);
        if ($raw === null || strlen($raw) !== 15) {
            throw new \RuntimeException('That key is not valid. Please check it and type it again.');
        }

        $payload = substr($raw, 0, 10);
        $sig     = substr($raw, 10, 5);
        $secret  = self::secret(tenant_id());

        if (!hash_equals(substr(hash_hmac('sha256', $payload, $secret, true), 0, 5), $sig)) {
            throw new \RuntimeException(
                'That key is not for this business, or it was typed wrong. '
              . 'Check it with your supplier.');
        }

        $fp     = unpack('n', substr($payload, 0, 2))[1];
        $days   = unpack('n', substr($payload, 2, 2))[1];
        $issued = unpack('N', "\x00" . substr($payload, 4, 3))[1];

        if ($fp !== self::fingerprint(tenant_id(), $secret)) {
            throw new \RuntimeException('That key belongs to a different business.');
        }

        $age = (int)floor(time() / 86400) - $issued;
        if ($age > self::VALID_DAYS) {
            throw new \RuntimeException(
                'That key is too old (issued ' . $age . ' days ago). Please ask for a new one.');
        }
        if ($age < -2) {
            /* Computer ki tareekh peeche ho to key "mustaqbil" ki lagti
               hai — customer ko asli wajah batao. */
            throw new \RuntimeException(
                'This computer\'s date looks wrong (' . date('d M Y') . '). Fix the date and try again.');
        }

        $p = DB::pdo();
        try {
            $c = $p->prepare("SELECT COUNT(*) FROM licence_keys_used WHERE key_code=? AND tenant_id=?");
            $c->execute([$key, tenant_id()]);
            if ((int)$c->fetchColumn() > 0) {
                throw new \RuntimeException('This key has already been used. Please ask for a new one.');
            }
        } catch (\RuntimeException $e) { throw $e; }
          catch (\Throwable $e) {}

        /* Mojooda expiry se AAGE — bache hue din zaya na hon. */
        $cq = $p->prepare("SELECT expiry_date FROM tenant_subscriptions
                            WHERE tenant_id=? ORDER BY created_at DESC LIMIT 1");
        $cq->execute([tenant_id()]);
        $cur  = (string)($cq->fetchColumn() ?: '');
        $base = ($cur !== '' && $cur >= date('Y-m-d')) ? $cur : date('Y-m-d');
        $exp  = date('Y-m-d', strtotime($base . ' +' . $days . ' day'));

        $sq = $p->prepare("SELECT id FROM tenant_subscriptions WHERE tenant_id=? ORDER BY created_at DESC LIMIT 1");
        $sq->execute([tenant_id()]);
        if ($sid = $sq->fetchColumn()) {
            $p->prepare("UPDATE tenant_subscriptions SET expiry_date=?, status='ACTIVE', updated_at=NOW(6) WHERE id=?")
              ->execute([$exp, $sid]);
        } else {
            $p->prepare("INSERT INTO tenant_subscriptions(id,tenant_id,status,amount,start_date,expiry_date)
                         VALUES(?,?,'ACTIVE',0,CURDATE(),?)")->execute([uuid(), tenant_id(), $exp]);
        }
        $p->prepare("UPDATE tenants SET status='ACTIVE', is_trial=0, is_demo=0 WHERE id=?")->execute([tenant_id()]);

        try {
            $p->prepare("INSERT INTO licence_keys_used(key_code,tenant_id,days,applied_at)
                         VALUES(?,?,?,NOW(6))")->execute([$key, tenant_id(), $days]);
        } catch (\Throwable $e) {}

        Audit::log('LICENCE_KEY_APPLIED', 'billing',
                   ['label' => self::pretty($key), 'new' => $days . ' days, now expires ' . $exp]);

        return ['days' => $days, 'expiry' => $exp,
                'message' => 'Activated for ' . $days . ' more day(s). Your software now works until ' . $exp . '.'];
    }

    /* ---------------- base32 ---------------- */

    private static function b32(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $bits = str_pad($bits, (int)(ceil(strlen($bits) / 5) * 5), '0', STR_PAD_RIGHT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) $out .= self::ALPHABET[bindec($chunk)];
        return $out;
    }

    private static function unb32(string $s): ?string
    {
        $bits = '';
        foreach (str_split($s) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i === false) return null;
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split(substr($bits, 0, (int)(floor(strlen($bits) / 8) * 8)), 8) as $b) {
            $out .= chr(bindec($b));
        }
        return $out;
    }

    /** Chaar-chaar ke tukron mein — phone par bolna aur likhna asaan. */
    public static function pretty(string $key): string
    {
        return implode('-', str_split(strtoupper($key), 4));
    }

    /** Kis business ke liye kaunsi keys bani (server par). */
    public static function history(string $tenantId, int $limit = 30): array
    {
        try {
            $q = DB::pdo()->prepare(
                "SELECT lk.key_code, lk.days, lk.issued_by, lk.created_at,
                        (SELECT applied_at FROM licence_keys_used u
                          WHERE u.key_code = lk.key_code AND u.tenant_id = lk.tenant_id LIMIT 1) AS used_at
                   FROM licence_keys lk
                  WHERE lk.tenant_id=? ORDER BY lk.created_at DESC LIMIT $limit");
            $q->execute([$tenantId]);
            return array_map(fn($r) => [
                'key'     => self::pretty((string)$r['key_code']),
                'days'    => (int)$r['days'],
                'by'      => (string)$r['issued_by'],
                'made'    => substr((string)$r['created_at'], 0, 16),
                'used_at' => $r['used_at'] ? substr((string)$r['used_at'], 0, 16) : '',
            ], $q->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) { return []; }
    }
}

// build: V90 build 2026-09-01
