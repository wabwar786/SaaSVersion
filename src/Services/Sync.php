<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * Sync — offline-first local <-> cloud synchronisation.
 *
 *  - LOCAL node ke reads/writes hamesha LOCAL MySQL par hote hain (offline bhi).
 *  - Jab internet + cloud reachable ho, PUSH: local changed rows cloud par upsert.
 *  - PULL: cloud master data local par upsert (last-write-wins by updated_at).
 *  - Watermark-based: koi trigger/per-write code nahi. updated_at (ya created_at)
 *    ke basis par sirf changed rows bhejte hain. Sab upserts idempotent hain.
 */
final class Sync
{
    /** Column existence cache per table. */
    private static array $colCache = [];

    /** Aakhri connectivity error (diagnostics ke liye). */
    public static string $lastError = '';

    /** CA bundle: package ke saath aaya hua, warna system ka. */
    public static function caBundle(): string
    {
        static $ca = null;
        if ($ca !== null) return $ca;
        $ca = '';
        $root = \defined('APP_ROOT') ? APP_ROOT : \dirname(__DIR__, 2);
        foreach ([$root . '/runtime/cacert.pem', $root . '/vendor/cacert.pem'] as $p) {
            if (\is_file($p) && \filesize($p) > 1000) { $ca = $p; return $ca; }
        }
        $ini = (string) \ini_get('curl.cainfo');
        if ($ini !== '' && \is_file($ini)) { $ca = $ini; return $ca; }
        foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt'] as $p) {
            if (\is_file($p)) { $ca = $p; return $ca; }
        }
        return $ca;
    }

    /**
     * Step-by-step connectivity check. Chip par "Offline" dikhe to yeh
     * batata hai ke masla theek kahan hai.
     * @return array<int,array{step:string,ok:bool,detail:string}>
     */
    public static function diagnose(): array
    {
        $out = [];
        $add = function (string $step, bool $ok, string $detail) use (&$out) {
            $out[] = ['step' => $step, 'ok' => $ok, 'detail' => $detail];
        };
        $c = self::cfg();

        $add('Configuration', !empty($c['enabled']), !empty($c['enabled']) ? 'Sync is enabled' : 'Sync disabled in config');
        $url = (string)($c['cloud_api_url'] ?? '');
        $add('Cloud URL', $url !== '', $url !== '' ? $url : 'Not set - re-download the offline package');
        $add('Sync token', !empty($c['token']), !empty($c['token']) ? 'Present (' . \strlen((string)$c['token']) . ' chars)' : 'Missing - re-download the package');
        if ($url === '') return $out;

        $parts  = \parse_url($url) ?: [];
        $host   = (string)($parts['host'] ?? '');
        $scheme = (string)($parts['scheme'] ?? 'http');
        $port   = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // curl na ho to bhi stream fallback chalta hai - is liye yeh failure nahi
        $add('HTTP client', true, \function_exists('curl_init')
            ? ('cURL ' . (\curl_version()['version'] ?? ''))
            : 'Built-in fallback (cURL not loaded)');

        if ($scheme === 'https') {
            $ca = self::caBundle();
            $add('SSL certificates', $ca !== '', $ca !== '' ? $ca : 'No CA bundle found - HTTPS will fail on Windows');
        }

        if (\filter_var($host, FILTER_VALIDATE_IP)) {
            $add('DNS lookup', true, "$host is a direct IP address (no lookup needed)");
        } else {
            $ip = $host !== '' ? \gethostbyname($host) : '';
            $dnsOk = ($ip !== '' && $ip !== $host);
            $add('DNS lookup', $dnsOk, $dnsOk ? "$host -> $ip"
                : "Could not resolve $host - internet ya DNS ka masla hai");
        }

        $t0 = \microtime(true);
        $sock = @\fsockopen(($scheme === 'https' ? 'ssl://' : '') . $host, $port, $en, $es, 6);
        $ms = (int)((\microtime(true) - $t0) * 1000);
        if ($sock) { \fclose($sock); $add('Connection', true, "Connected to $host:$port in {$ms}ms"); }
        else { $add('Connection', false, "Cannot reach $host:$port - $es (errno $en). Firewall/antivirus ya proxy check karein."); return $out; }

        try {
            [$code, $res] = self::httpPost(self::endpoint('sync-ping'), ['Content-Type: application/json'], '{}', 6, 15);
            $j = \json_decode((string)$res, true);
            $add('Cloud response', $code === 200, $code === 200
                ? ('HTTP 200 - server role: ' . (string)($j['role'] ?? '?'))
                : ("HTTP $code - " . \substr(\strip_tags((string)$res), 0, 120)));
        } catch (\Throwable $e) {
            $add('Cloud response', false, $e->getMessage());
            return $out;
        }

        try {
            [$code2, $res2] = self::httpPost(self::endpoint('sync-push'),
                ['Content-Type: application/json', 'X-Sync-Token: ' . ($c['token'] ?? '')],
                \json_encode(['table' => 'customers', 'rows' => []]), 6, 15);
            $j2 = \json_decode((string)$res2, true);
            $ok2 = ($code2 === 200 && !empty($j2['ok']));
            $add('Token accepted', $ok2, $ok2 ? 'Cloud accepted this business token'
                : ('Rejected: ' . (string)($j2['message'] ?? "HTTP $code2")));
        } catch (\Throwable $e) {
            $add('Token accepted', false, $e->getMessage());
        }
        return $out;
    }

    private static function cfg(): array
    {
        $c = $GLOBALS['config']['sync'] ?? [];
        /* Backward-compatible: kuch configs mein 'endpoint' likha hota hai
           aur kuch mein 'cloud_api_url'. Dono chalne chahiye. */
        if (empty($c['cloud_api_url'])) {
            if (!empty($c['endpoint']))      $c['cloud_api_url'] = $c['endpoint'];
            elseif (!empty($GLOBALS['config']['app']['cloud_url'])) {
                $c['cloud_api_url'] = rtrim((string)$GLOBALS['config']['app']['cloud_url'], '/') . '/api.php';
            }
        }
        return $c;
    }

    /** UI ke liye: sync kyun band hai, saaf wajah. */
    public static function statusReason(): string
    {
        $c = self::cfg();
        if (empty($c['enabled']))       return 'Sync is turned off in the configuration.';
        if (empty($c['cloud_api_url'])) return 'Cloud URL is not set in this installation.';
        if (empty($c['token']))         return 'Sync token is missing - download the offline package again.';
        return '';
    }

    public static function enabled(): bool
    {
        $c = self::cfg();
        return !empty($c['enabled']) && !empty($c['cloud_api_url']);
    }

    /* ------------------------- schema helpers ------------------------- */

    private static function columns(string $table): array
    {
        if (isset(self::$colCache[$table])) return self::$colCache[$table];
        $db = $GLOBALS['config']['db']['database'];
        $q = DB::pdo()->prepare(
            "SELECT column_name FROM information_schema.columns
              WHERE table_schema=? AND table_name=?"
        );
        $q->execute([$db, $table]);
        $cols = array_column($q->fetchAll(PDO::FETCH_ASSOC), 'column_name');
        return self::$colCache[$table] = $cols;
    }

    private static function tableExists(string $table): bool
    {
        return count(self::columns($table)) > 0;
    }

    /** Which timestamp column to watermark on. */
    private static function tsColumn(string $table): ?string
    {
        $cols = self::columns($table);
        if (in_array('updated_at', $cols, true)) return 'updated_at';
        if (in_array('created_at', $cols, true)) return 'created_at';
        return null;
    }

    public static function tsCol(string $table): ?string { return self::tsColumn($table); }

    /* ------------------------- read/apply ------------------------- */

    /** Local rows changed after $since (exclusive), oldest first. */
    public static function changedRows(string $table, string $since, int $limit): array
    {
        if (!self::tableExists($table)) return [];
        $ts = self::tsColumn($table);
        if (!$ts) return [];
        // TENANT SCOPE: cloud par pull sirf usi tenant ki rows deta hai jo
        // token se authenticate hua. Warna ek node doosron ka data parh leta.
        $scope = $GLOBALS['sync_tenant_id'] ?? null;
        $args  = [$since];
        $where = "`$ts` > ?";
        if ($scope && in_array('tenant_id', self::columns($table), true)) {
            $where .= " AND tenant_id = ?";
            $args[] = $scope;
        }
        $sql = "SELECT * FROM `$table` WHERE $where ORDER BY `$ts` ASC LIMIT $limit";
        $q = DB::pdo()->prepare($sql);
        $q->execute($args);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Generic idempotent upsert of $rows into $table (match by primary key `id`).
     * Runs with FK checks off inside a transaction — source data is trusted.
     * Returns number of rows applied.
     */
    /**
     * @param string|null $forceTenantId Cloud par: jo tenant token se
     *   authenticate hua uska id. Har incoming row par FORCE hota hai —
     *   ek node doosre tenant ka data likh hi nahi sakta.
     */
    public static function applyRows(string $table, array $rows, ?string $forceTenantId = null): int
    {
        if (!$rows || !self::tableExists($table)) return 0;
        $cols = self::columns($table);
        $pdo = DB::pdo();
        $hasTenant = in_array('tenant_id', $cols, true);

        return (int) DB::tx(function (PDO $pdo) use ($table, $rows, $cols, $forceTenantId, $hasTenant) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $applied = 0;
            foreach ($rows as $row) {
                // keep only real columns of the target table
                $data = array_intersect_key($row, array_flip($cols));
                if (!isset($data['id'])) continue;
                // TENANT LOCK: incoming tenant_id ko trust nahi karte
                if ($forceTenantId !== null && $hasTenant) $data['tenant_id'] = $forceTenantId;
                $keys = array_keys($data);
                $ph = implode(',', array_fill(0, count($keys), '?'));
                $set = implode(',', array_map(fn($k) => "`$k`=VALUES(`$k`)",
                        array_filter($keys, fn($k) => $k !== 'id')));
                $sql = "INSERT INTO `$table` (`" . implode('`,`', $keys) . "`) VALUES ($ph)";
                if ($set !== '') $sql .= " ON DUPLICATE KEY UPDATE $set";
                $pdo->prepare($sql)->execute(array_values($data));
                $applied++;
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            return $applied;
        });
    }

    /* ------------------------- watermark state ------------------------- */

    public static function watermark(string $scope): string
    {
        $q = DB::pdo()->prepare("SELECT watermark FROM sync_state WHERE scope=?");
        $q->execute([$scope]);
        return $q->fetchColumn() ?: '1970-01-01 00:00:00.000000';
    }

    public static function setWatermark(string $scope, string $wm, string $status = 'OK', ?string $err = null, int $rows = 0): void
    {
        DB::pdo()->prepare(
            "INSERT INTO sync_state (scope, watermark, last_run_at, last_status, last_error, rows_synced)
             VALUES (?,?,NOW(6),?,?,?)
             ON DUPLICATE KEY UPDATE watermark=VALUES(watermark), last_run_at=NOW(6),
                last_status=VALUES(last_status), last_error=VALUES(last_error),
                rows_synced=rows_synced+VALUES(rows_synced)"
        )->execute([$scope, $wm, $status, $err, $rows]);
    }

    private static function touchState(string $scope, string $status, ?string $err = null): void
    {
        DB::pdo()->prepare(
            "INSERT INTO sync_state (scope, last_run_at, last_status, last_error)
             VALUES (?,NOW(6),?,?)
             ON DUPLICATE KEY UPDATE last_run_at=NOW(6), last_status=VALUES(last_status), last_error=VALUES(last_error)"
        )->execute([$scope, $status, $err]);
    }

    /* ------------------------- HTTP to cloud ------------------------- */

    /**
     * HTTP POST JSON. Uses curl if available, otherwise falls back to a
     * stream context — so sync works even where php-curl is disabled.
     * Returns [httpCode, bodyString]. Throws on transport failure.
     */
    private static function httpPost(string $url, array $headers, string $payload, int $connectTimeout, int $timeout): array
    {
        if (\function_exists('curl_init')) {
            $ch = \curl_init($url);
            $opts = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
            ];
            // Windows par PHP ke saath koi CA bundle nahi aata, is liye
            // https:// (Railway) ka certificate verify fail ho jata tha aur
            // sync "Cloud unreachable" par ruk jati thi. Package ke saath
            // bheja gaya cacert.pem yahan use hota hai.
            $ca = self::caBundle();
            if ($ca !== '') $opts[CURLOPT_CAINFO] = $ca;
            \curl_setopt_array($ch, $opts);
            $res = \curl_exec($ch);
            $code = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = \curl_errno($ch);
            $errStr = \curl_error($ch);
            \curl_close($ch);
            if ($errno) {
                self::$lastError = "curl($errno): $errStr";
                throw new \RuntimeException("Cloud unreachable: $errStr (curl $errno)");
            }
            return [$code, (string) $res];
        }
        // stream fallback
        $ctx = \stream_context_create(['http' => [
            'method' => 'POST',
            'header' => \implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $res = @\file_get_contents($url, false, $ctx);
        if ($res === false) {
            $e = \error_get_last();
            self::$lastError = 'stream: ' . \substr((string)($e['message'] ?? 'unknown'), 0, 160);
            throw new \RuntimeException('Cloud unreachable: ' . self::$lastError);
        }
        $code = 200;
        if (isset($http_response_header[0]) && \preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int) $m[1];
        return [$code, (string) $res];
    }

    /** cloud_api_url ya to base URL ho sakta hai ya seedha .../api.php —
     *  dono suraton mein sahi endpoint banao (pehle /api.php/api.php ban
     *  jata tha aur cloud 302 return karta tha). */
    private static function endpoint(string $action): string
    {
        $c = self::cfg();
        $base = \rtrim((string)($c['cloud_api_url'] ?? ''), '/');
        if ($base === '') return '';
        if (\substr($base, -8) !== '/api.php') $base .= '/api.php';
        return $base . '?action=' . $action;
    }

    private static function post(string $action, array $body): array
    {
        $c = self::cfg();
        $url = self::endpoint($action);
        $headers = ['Content-Type: application/json', 'X-Sync-Token: ' . ($c['token'] ?? '')];
        [$code, $res] = self::httpPost($url, $headers, \json_encode($body, JSON_UNESCAPED_UNICODE), 6, 45);
        if ($code !== 200) {
            $hint = \trim(\strip_tags((string)$res));
            if ($hint !== '') $hint = ' - ' . \substr($hint, 0, 120);
            throw new \RuntimeException("Cloud HTTP $code$hint");
        }
        $j = \json_decode($res, true);
        if (!\is_array($j) || empty($j['ok'])) throw new \RuntimeException($j['message'] ?? 'Cloud rejected sync');
        return $j;
    }

    /** Quick reachability probe (no throw). */
    /**
     * Cached online status — har 30 second par live probe karna POS ko
     * block kar deta tha (PHP ka built-in server single-threaded hai).
     * Ab natija 60 second cache hota hai.
     */
    public static function cloudOnlineCached(int $ttl = 60): array
    {
        try {
            /* Age DB ke apne clock se — PHP aur MySQL ke timezone alag ho
               sakte hain, aur microsecond wale timestamp par strtotime()
               fail kar deta hai (isi wajah se cache kabhi hit nahi hota tha). */
            $q = DB::pdo()->prepare(
                "SELECT last_status,last_error,TIMESTAMPDIFF(SECOND,last_run_at,NOW()) age
                   FROM sync_state WHERE scope='cloud_probe' LIMIT 1");
            $q->execute();
            if ($r = $q->fetch()) {
                $age = (int)$r['age'];
                if ($age >= 0 && $age < $ttl) {
                    return ['online' => $r['last_status'] === 'OK', 'error' => (string)($r['last_error'] ?? ''), 'cached' => true, 'age' => $age];
                }
            }
        } catch (\Throwable $e) {}
        $on = self::cloudOnline();
        try {
            DB::pdo()->prepare(
                "INSERT INTO sync_state (scope,watermark,last_run_at,last_status,last_error)
                 VALUES ('cloud_probe','1970-01-01 00:00:00.000000',NOW(6),?,?)
                 ON DUPLICATE KEY UPDATE last_run_at=NOW(6), last_status=VALUES(last_status), last_error=VALUES(last_error)"
            )->execute([$on ? 'OK' : 'ERROR', $on ? null : \substr(self::$lastError, 0, 200)]);
        } catch (\Throwable $e) {}
        return ['online' => $on, 'error' => self::$lastError, 'cached' => false, 'age' => 0];
    }

    public static function cloudOnline(): bool
    {
        if (!self::enabled()) { self::$lastError = self::statusReason(); return false; }
        try {
            [$code, $body] = self::httpPost(self::endpoint('sync-ping'), ['Content-Type: application/json'], '{}', 4, 10);
            if ($code === 200) { self::$lastError = ''; return true; }
            self::$lastError = "Cloud replied HTTP $code";
            return false;
        } catch (\Throwable $e) {
            if (self::$lastError === '') self::$lastError = $e->getMessage();
            return false;
        }
    }

    /* ------------------------- PUSH / PULL ------------------------- */

    public static function push(): array
    {
        $c = self::cfg();
        $batch = (int)($c['batch'] ?? 300);
        $summary = [];
        foreach (($c['push_tables'] ?? []) as $table) {
            $scope = "push:$table";
            $since = self::watermark($scope);
            $rows = self::changedRows($table, $since, $batch);
            if (!$rows) { $summary[$table] = 0; continue; }
            self::post('sync-push', [
                'site_id' => $GLOBALS['config']['app']['site_id'] ?? null,
                'table' => $table,
                'rows' => $rows,
            ]);
            $ts = self::tsColumn($table);
            $maxWm = end($rows)[$ts];
            self::setWatermark($scope, $maxWm, 'OK', null, count($rows));
            $summary[$table] = count($rows);
        }
        return $summary;
    }

    public static function pull(): array
    {
        $c = self::cfg();
        $batch = (int)($c['batch'] ?? 300);
        $summary = [];
        foreach (($c['pull_tables'] ?? []) as $table) {
            $scope = "pull:$table";
            $since = self::watermark($scope);
            $resp = self::post('sync-pull', [
                'site_id' => $GLOBALS['config']['app']['site_id'] ?? null,
                'table' => $table,
                'since' => $since,
                'limit' => $batch,
            ]);
            $rows = $resp['rows'] ?? [];
            if ($rows) {
                self::applyRows($table, $rows);
                $ts = self::tsColumn($table) ?: 'updated_at';
                $maxWm = $resp['watermark'] ?? (end($rows)[$ts] ?? $since);
                self::setWatermark($scope, $maxWm, 'OK', null, count($rows));
            }
            $summary[$table] = count($rows);
        }
        return $summary;
    }

    /** One full sync run (push then pull). Never throws to the caller. */
    public static function run(): array
    {
        if (!self::enabled()) {
            self::touchState('engine', 'LOCAL_ONLY', 'cloud_api_url not set — running local-only');
            return ['ok' => true, 'skipped' => true, 'reason' => 'local-only'];
        }
        try {
            self::touchState('engine', 'SYNCING');
            $pushed = self::push();
            $pulled = self::pull();
            $total = array_sum($pushed) + array_sum($pulled);
            self::touchState('engine', 'OK', null);
            return ['ok' => true, 'pushed' => $pushed, 'pulled' => $pulled, 'total' => $total];
        } catch (\Throwable $e) {
            self::touchState('engine', 'ERROR', $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Status for the offline/sync screen. */
    public static function status(): array
    {
        $rows = DB::pdo()->query("SELECT scope,watermark,last_run_at,last_status,last_error,rows_synced FROM sync_state")->fetchAll(PDO::FETCH_ASSOC);
        $engine = ['last_status' => 'IDLE', 'last_run_at' => null, 'last_error' => null];
        $pending = 0; $tables = [];
        foreach ($rows as $r) {
            if ($r['scope'] === 'engine') { $engine = $r; continue; }
            if (\str_starts_with($r['scope'], 'push:')) {
                $t = substr($r['scope'], 5);
                try {
                    $q = DB::pdo()->prepare("SELECT COUNT(*) FROM `$t` WHERE " . (self::tsColumn($t) ?: 'updated_at') . " > ?");
                    $q->execute([$r['watermark']]);
                    $p = (int)$q->fetchColumn(); $pending += $p;
                    $tables[] = ['table' => $t, 'pending' => $p, 'synced' => (int)$r['rows_synced'], 'last' => $r['last_run_at'], 'status' => $r['last_status']];
                } catch (\Throwable $e) {}
            }
        }
        return [
            'enabled' => self::enabled(),
            'role' => $GLOBALS['config']['app']['role'] ?? 'local',
            'cloud_configured' => !empty(self::cfg()['cloud_api_url']),
            'engine' => $engine,
            'pending' => $pending,
            'tables' => $tables,
        ];
    }
}

// build: V17.1 build 2026-08-25
