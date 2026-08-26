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

    /** Is installation ka build (VERSION file se). */
    public static function localBuild(): string
    {
        static $v = null;
        if ($v !== null) return $v;
        $v = 'unknown';
        try {
            // Sealed package mein VERSION bundle ke andar hoti hai
            if (isset($GLOBALS['__sealed_files']['VERSION'])) {
                $t = (string)$GLOBALS['__sealed_files']['VERSION'];
                if (\trim($t) !== '') return $v = \trim($t);
            }
            $root = \defined('APP_ROOT') ? APP_ROOT : \dirname(__DIR__, 2);
            if (@\is_file($root . '/VERSION')) {
                $t = @\file_get_contents($root . '/VERSION');
                if ($t !== false && \trim($t) !== '') $v = \trim($t);
            }
        } catch (\Throwable $e) {}
        return $v;
    }

    /**
     * Cloud ka build. Dono taraf ka build match na kare to naye fixes
     * aadhe adhoore chalte hain — UI ko saaf batana zaroori hai.
     */
    public static function cloudBuild(int $ttl = 300): array
    {
        try {
            $q = DB::pdo()->prepare(
                "SELECT last_error, TIMESTAMPDIFF(SECOND,last_run_at,NOW()) age
                   FROM sync_state WHERE scope='cloud_build' LIMIT 1");
            $q->execute();
            if ($r = $q->fetch()) {
                $age = (int)$r['age'];
                if ($age >= 0 && $age < $ttl) {
                    return ['build' => (string)($r['last_error'] ?? ''), 'cached' => true];
                }
            }
        } catch (\Throwable $e) {}

        $build = '';
        try {
            [$code, $body] = self::httpPost(self::endpoint('sync-ping'),
                ['Content-Type: application/json'], '{}', 4, 12);
            if ($code === 200) {
                $j = \json_decode((string)$body, true);
                $build = (string)($j['build'] ?? '');
                if (empty($j['features']['bulk_sync'])) $build .= ' (no bulk sync)';
            }
        } catch (\Throwable $e) {}

        try {
            DB::pdo()->prepare(
                "INSERT INTO sync_state (scope,watermark,last_run_at,last_status,last_error)
                 VALUES ('cloud_build','1970-01-01 00:00:00.000000',NOW(6),?,?)
                 ON DUPLICATE KEY UPDATE last_run_at=NOW(6), last_status=VALUES(last_status), last_error=VALUES(last_error)"
            )->execute([$build !== '' ? 'OK' : 'ERROR', $build]);
        } catch (\Throwable $e) {}

        return ['build' => $build, 'cached' => false];
    }

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
            if ($code === 200) {
                $cb = (string)($j['build'] ?? '');
                $lb = self::localBuild();
                $same = ($cb !== '' && \strtok($cb, ' ') === \strtok($lb, ' '));
                $add('Build match', $same,
                    $same ? "Both on $lb"
                          : "This computer: $lb  |  Cloud: " . ($cb !== '' ? $cb : 'unknown')
                            . " - deploy the latest build to the cloud and re-download the offline package");
                $add('Bulk sync support', !empty($j['features']['bulk_sync']),
                    !empty($j['features']['bulk_sync'])
                        ? 'Cloud supports fast bulk sync'
                        : 'Cloud is on an older build - sync will be slow and may time out');
            }
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
        $site  = $GLOBALS['sync_site_id'] ?? null;
        $cols  = self::columns($table);
        $args  = [$since];
        $where = "`$ts` > ?";
        if ($scope && in_array('tenant_id', $cols, true)) {
            $where .= " AND tenant_id = ?";
            $args[] = $scope;
        }
        // Branch scope: multi-branch business mein ek branch ko doosri
        // branch ka transactional data neeche nahi aana chahiye.
        if ($site && in_array('site_id', $cols, true)) {
            $where .= " AND site_id = ?";
            $args[] = $site;
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
    /**
     * Rows ko is DB mein daalta/update karta hai.
     *
     * @param string|null $forceTenantId Cloud par: token se authenticate hue
     *   tenant ka id. Har row par FORCE hota hai — ek node doosre tenant ka
     *   data likh hi nahi sakta.
     * @param bool $lastWriteWins Pull (cloud -> local) ke liye true: agar
     *   local row zyada nayi hai to use overwrite NahI kiya jata, warna
     *   abhi kaata gaya bill purani cloud copy se mit jata.
     */
    public static function applyRows(string $table, array $rows, ?string $forceTenantId = null, bool $lastWriteWins = false): int
    {
        if (!$rows || !self::tableExists($table)) return 0;
        $cols      = self::columns($table);
        $pdo       = DB::pdo();
        $hasTenant = in_array('tenant_id', $cols, true);
        $ts        = self::tsColumn($table);

        $applied = 0; $skipped = 0; $failed = 0; $conflicts = 0; $lastErr = '';
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($rows as $row) {
            $data = array_intersect_key((array)$row, array_flip($cols));
            if (!isset($data['id'])) continue;
            if ($forceTenantId !== null && $hasTenant) $data['tenant_id'] = $forceTenantId;

            try {
                // Last-write-wins: local copy nayi ho to chhoR do
                if ($lastWriteWins && $ts && !empty($data[$ts])) {
                    $q = $pdo->prepare("SELECT `$ts` FROM `$table` WHERE id=? LIMIT 1");
                    $q->execute([$data['id']]);
                    $localTs = $q->fetchColumn();
                    if ($localTs !== false && \strtotime((string)$localTs) > \strtotime((string)$data[$ts])) {
                        $skipped++; continue;
                    }
                }
                /* PEHLE: "INSERT ... ON DUPLICATE KEY UPDATE" chalti thi. Agar
                   doosre node ki row ka koi UNIQUE key (misal site+date+bill_no)
                   takra jata to wo MOJOODA row ko overwrite kar deti thi —
                   do alag bills mil kar ek ban jate the aur raqam badal jati
                   thi, phir bhi "applied" count barh jata tha. Ab:
                     - wahi id maujood  -> UPDATE by id
                     - nahi maujood     -> INSERT; koi doosri unique takra
                                           jaye to row REJECT + conflict log. */
                $ex = $pdo->prepare("SELECT 1 FROM `$table` WHERE id=? LIMIT 1");
                $ex->execute([$data['id']]);
                if ($ex->fetchColumn()) {
                    $upd = array_diff_key($data, ['id' => 1]);
                    if ($upd) {
                        $set = implode(',', array_map(fn($k) => "`$k`=?", array_keys($upd)));
                        $args = array_values($upd); $args[] = $data['id'];
                        $pdo->prepare("UPDATE `$table` SET $set WHERE id=?")->execute($args);
                    }
                    $applied++;
                } else {
                    $keys = array_keys($data);
                    $ph   = implode(',', array_fill(0, count($keys), '?'));
                    try {
                        $pdo->prepare("INSERT INTO `$table` (`" . implode('`,`', $keys) . "`) VALUES ($ph)")
                            ->execute(array_values($data));
                        $applied++;
                    } catch (\PDOException $pe) {
                        if ((int)$pe->getCode() === 23000) {
                            $conflicts++;
                            self::$lastConflicts[] = ['table' => $table, 'id' => (string)$data['id'],
                                'key' => isset($data['bill_no']) ? ('bill '.$data['bill_no']) : '',
                                'error' => \substr($pe->getMessage(), 0, 140)];
                            continue;
                        }
                        throw $pe;
                    }
                }
            } catch (\Throwable $e) {
                // Ek row ka masla (misal duplicate bill number) poori batch
                // ko na rokay — baqi rows chalti rahen.
                $failed++; $lastErr = \substr($e->getMessage(), 0, 160);
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        if ($failed > 0) {
            self::$lastRowErrors[$table] = "$failed row(s) skipped: $lastErr";
        }
        if ($conflicts > 0) {
            self::$lastRowErrors[$table] = ($self = self::$lastRowErrors[$table] ?? '')
                . ($self ? ' | ' : '')
                . "$conflicts row(s) rejected - duplicate key (another device already used that number)";
        }
        self::$lastSkipped += $skipped;
        return $applied;
    }

    /** @var array<string,string> per-table row errors (diagnostics) */
    public static array $lastRowErrors = [];
    /** @var array<int,array{table:string,id:string,key:string,error:string}> */
    public static array $lastConflicts = [];
    public static int $lastSkipped = 0;

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
        // DNS/network kabhi kabhi ek lamhe ke liye fail hota hai (aap ke
        // screenshot mein "Could not resolve host ... curl 6"). Pehle ek hi
        // koshish hoti thi aur poora sync gir jata tha. Ab 3 koshishen.
        $last = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return self::postOnce($action, $body);
            } catch (\Throwable $e) {
                $last = $e;
                $m = \strtolower($e->getMessage());
                $transient = (\strpos($m, 'resolve') !== false || \strpos($m, 'timed out') !== false
                    || \strpos($m, 'timeout') !== false || \strpos($m, 'connection reset') !== false
                    || \strpos($m, 'http 502') !== false || \strpos($m, 'http 503') !== false
                    || \strpos($m, 'http 504') !== false);
                if (!$transient || $attempt === 3) break;
                \usleep(700000 * $attempt);
            }
        }
        throw $last ?: new \RuntimeException('Cloud request failed');
    }

    private static function postOnce(string $action, array $body): array
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
        $payload = [];   // table => rows
        $meta    = [];   // table => [since, maxWm, sent]

        foreach (($c['push_tables'] ?? []) as $table) {
            try {
                $since = self::watermark("push:$table");
                $rows  = self::changedRows($table, $since, $batch);
                if (!$rows) { $summary[$table] = 0; continue; }
                $ts = self::tsColumn($table);
                $payload[$table] = $rows;
                $meta[$table] = ['since' => $since, 'wm' => end($rows)[$ts], 'sent' => count($rows)];
            } catch (\Throwable $e) {
                $summary[$table] = 0;
                self::$tableErrors[] = ['dir' => 'PUSH', 'table' => $table,
                                        'error' => \substr($e->getMessage(), 0, 200)];
            }
        }
        if (!$payload) return $summary;

        // Ek request mein 400 rows tak ke chunks - warna bara payload timeout karta hai
        foreach (self::chunkTables($payload, 400) as $chunk) {
            try {
                $resp = self::post('sync-push-bulk', [
                    'site_id' => $GLOBALS['config']['app']['site_id'] ?? null,
                    'tables'  => $chunk,
                ]);
            } catch (\Throwable $e) {
                $msg = \substr($e->getMessage(), 0, 200);
                foreach (\array_keys($chunk) as $t) {
                    $summary[$t] = 0;
                    self::$tableErrors[] = ['dir' => 'PUSH', 'table' => $t, 'error' => $msg];
                }
                if (self::isFatalError($msg)) { self::$abortedReason = $msg; break; }
                continue;
            }
            foreach ($chunk as $table => $rows) {
                $r       = ($resp['tables'][$table] ?? []);
                $sent    = (int)($r['sent'] ?? count($rows));
                $applied = (int)($r['applied'] ?? 0);
                $conf    = (int)($r['conflicts'] ?? 0);
                $summary[$table] = $applied;

                if (!empty($r['error'])) {
                    self::$tableErrors[] = ['dir' => 'PUSH', 'table' => $table, 'error' => (string)$r['error']];
                    self::setWatermark("push:$table", $meta[$table]['since'], 'ERROR', (string)$r['error'], 0);
                    continue;
                }
                if ($applied >= $sent) {
                    self::setWatermark("push:$table", $meta[$table]['wm'], 'OK', null, $sent);
                    continue;
                }
                /* Kuch rows qubool nahi huin. Agar sabab duplicate key hai to
                   dobara koshish se bhi kabhi qubool nahi hongi — watermark
                   rok dene se sync HAMESHA usi jagah atka rehta (aap ke
                   "2 rows to upload" ki wajah). Is liye: aage barh jao,
                   magar conflict ko permanently record kar do. */
                $why = $conf > 0
                    ? ($conf . ' row(s) rejected by cloud - duplicate number already used by another device')
                    : (($sent - $applied) . ' row(s) not accepted by cloud');
                self::$tableErrors[] = ['dir' => 'PUSH', 'table' => $table, 'error' => $why];
                foreach (($r['conflict_detail'] ?? []) as $cd) self::$lastConflicts[] = $cd;

                if ($conf > 0 && ($applied + $conf) >= $sent) {
                    self::setWatermark("push:$table", $meta[$table]['wm'], 'PARTIAL', $why, $applied);
                    self::quarantine($table, $conf, $why);
                } else {
                    self::setWatermark("push:$table", $meta[$table]['since'], 'ERROR', $why, $applied);
                }
            }
            if (self::$abortedReason !== '') break;
        }
        return $summary;
    }

    /** Payload ko rows ki tadaad ke hisab se chunks mein baanto. */
    private static function chunkTables(array $payload, int $maxRows): array
    {
        $chunks = []; $cur = []; $n = 0;
        foreach ($payload as $table => $rows) {
            foreach (\array_chunk($rows, $maxRows) as $part) {
                if ($n > 0 && $n + \count($part) > $maxRows) { $chunks[] = $cur; $cur = []; $n = 0; }
                $cur[$table] = isset($cur[$table]) ? \array_merge($cur[$table], $part) : $part;
                $n += \count($part);
            }
        }
        if ($cur) $chunks[] = $cur;
        return $chunks;
    }

    /** Jo rows cloud ne hamesha ke liye reject kar din — record rakho. */
    private static function quarantine(string $table, int $count, string $why): void
    {
        try {
            DB::pdo()->prepare(
                "INSERT INTO sync_activity (id,tenant_id,site_id,direction,table_name,rows_count,status,note,created_at)
                 VALUES (?,?,?,'PUSH',?,?,'REJECTED',?,NOW(6))"
            )->execute([
                \function_exists('uuid') ? uuid() : \bin2hex(\random_bytes(16)),
                (string)($GLOBALS['config']['app']['tenant_id'] ?? ''),
                (string)($GLOBALS['config']['app']['site_id'] ?? ''),
                $table, $count, \substr($why, 0, 300),
            ]);
        } catch (\Throwable $e) {}
    }

    /** @var array<int,array{dir:string,table:string,error:string}> */
    public static array $tableErrors = [];
    public static string $abortedReason = '';

    /**
     * Fatal = har table par wahi masla aayega, is liye foran ruk jao.
     * (Network down, ya token/permission reject.) Warna log 70 ek jaisi
     * errors se bhar jata hai aur asli baat chhup jati hai.
     */
    private static function isFatalError(string $m): bool
    {
        $m = \strtolower($m);
        foreach (['unreachable', 'could not resolve', 'connection refused', 'timed out',
                  'timeout', 'ssl', 'certificate', 'failed to open stream',
                  'http 401', 'http 403', 'invalid sync token', 'suspended',
                  'http 500', 'http 502', 'http 503'] as $n) {
            if (\strpos($m, $n) !== false) return true;
        }
        return false;
    }

    /** Aam aadmi ke liye saaf wajah. */
    public static function friendlyError(string $m): string
    {
        $l = \strtolower($m);
        if (\strpos($l, 'invalid sync token') !== false || \strpos($l, 'http 401') !== false)
            return 'The cloud rejected this installation (invalid sync token). Download the offline package again from the portal.';
        if (\strpos($l, 'suspended') !== false)
            return 'This business is suspended on the cloud. Contact support / renew the subscription.';
        if (\strpos($l, 'http 403') !== false)
            return 'The cloud refused this request (permission denied).';
        if (\strpos($l, 'certificate') !== false || \strpos($l, 'ssl') !== false)
            return 'HTTPS certificate could not be verified. Download a fresh package, or run INSTALL_OFFLINE.bat again.';
        if (\strpos($l, 'could not resolve') !== false)
            return 'Internet is on but the server name could not be resolved (DNS). Check WiFi or try a hotspot.';
        if (\strpos($l, 'connection refused') !== false || \strpos($l, 'unreachable') !== false
            || \strpos($l, 'failed to open stream') !== false)
            return 'Cannot reach the cloud server. Check the internet; Windows Firewall or antivirus may be blocking php.exe.';
        if (\strpos($l, 'timed out') !== false || \strpos($l, 'timeout') !== false)
            return 'The cloud did not respond in time. Slow connection - it will retry automatically.';
        if (\strpos($l, 'http 5') !== false)
            return 'The cloud server returned an error. It will retry automatically; contact support if it continues.';
        if (\strpos($l, 'duplicate') !== false)
            return 'Some records clashed with existing ones and were skipped. Everything else synced.';
        return $m;
    }

    public static function pull(): array
    {
        $c = self::cfg();
        $batch = (int)($c['batch'] ?? 300);
        $tables = (array)($c['pull_tables'] ?? []);
        $summary = [];
        if (!$tables) return $summary;

        // Saari tables ke watermarks ek hi request mein — pehle 56 alag
        // HTTP requests jati thin (60-90 sec, browser timeout).
        $want = [];
        foreach ($tables as $t) { $want[$t] = self::watermark("pull:$t"); $summary[$t] = 0; }

        foreach (\array_chunk($want, 20, true) as $slice) {
            try {
                $resp = self::post('sync-pull-bulk', [
                    'site_id' => $GLOBALS['config']['app']['site_id'] ?? null,
                    'tables'  => $slice,
                    'limit'   => $batch,
                ]);
            } catch (\Throwable $e) {
                $msg = \substr($e->getMessage(), 0, 200);
                foreach (\array_keys($slice) as $t) {
                    self::$tableErrors[] = ['dir' => 'PULL', 'table' => $t, 'error' => $msg];
                }
                if (self::isFatalError($msg)) { self::$abortedReason = $msg; break; }
                continue;
            }
            foreach ($slice as $table => $since) {
                $r = $resp['tables'][$table] ?? null;
                if (!$r) continue;
                if (!empty($r['error'])) {
                    self::$tableErrors[] = ['dir' => 'PULL', 'table' => $table, 'error' => (string)$r['error']];
                    self::setWatermark("pull:$table", $since, 'ERROR', (string)$r['error'], 0);
                    continue;
                }
                $rows = $r['rows'] ?? [];
                if ($rows) {
                    self::applyRows($table, $rows, null, true);
                    self::setWatermark("pull:$table", (string)($r['watermark'] ?? $since), 'OK', null, \count($rows));
                }
                $summary[$table] = \count($rows);
            }
        }
        return $summary;
    }



    /** One full sync run (push then pull). Never throws to the caller. */
    public static function run(string $triggeredBy = 'auto'): array
    {
        if (!self::enabled()) {
            self::touchState('engine', 'LOCAL_ONLY', 'cloud_api_url not set — running local-only');
            return ['ok' => true, 'skipped' => true, 'reason' => 'local-only'];
        }
        $runId = \function_exists('uuid') ? uuid() : \bin2hex(\random_bytes(16));
        $t0 = \microtime(true);
        self::$lastRowErrors = [];
        self::$lastSkipped = 0;
        self::$tableErrors = [];
        self::$abortedReason = '';
        self::logRunStart($runId, $triggeredBy);

        try {
            self::touchState('engine', 'SYNCING');
            $pushed = self::push();
            $pulled = self::pull();
            $total  = array_sum($pushed) + array_sum($pulled);
            self::touchState('engine', 'OK', null);

            $problems = [];
            foreach (self::$tableErrors as $te) $problems[] = $te['dir'].' '.$te['table'].': '.$te['error'];
            foreach (self::$lastRowErrors as $tb => $er) $problems[] = $tb.': '.$er;

            $status = $problems ? 'PARTIAL' : 'OK';
            if (self::$abortedReason !== '') $status = 'ERROR';

            $errText = self::$abortedReason
                ? (self::friendlyError(self::$abortedReason) . '  [' . self::$abortedReason . ']')
                : ($problems ? \implode(' | ', $problems) : null);
            self::logRunEnd($runId, $t0, $pushed, $pulled, $status, $errText);

            return ['ok' => empty(self::$abortedReason), 'run_id' => $runId,
                    'pushed' => $pushed, 'pulled' => $pulled, 'total' => $total,
                    'skipped_rows' => self::$lastSkipped,
                    'table_errors' => self::$tableErrors,
                    'row_errors' => self::$lastRowErrors,
                    'message' => self::$abortedReason ? self::friendlyError(self::$abortedReason) : null,
                    'raw_error' => self::$abortedReason ?: null];
        } catch (\Throwable $e) {
            self::touchState('engine', 'ERROR', $e->getMessage());
            self::logRunEnd($runId, $t0, [], [], 'ERROR',
                self::friendlyError($e->getMessage()) . '  [' . $e->getMessage() . ']');
            return ['ok' => false, 'run_id' => $runId,
                    'message' => self::friendlyError($e->getMessage()),
                    'raw_error' => $e->getMessage()];
        }
    }

    /* ------------------------- run logging ------------------------- */

    private static function logRunStart(string $runId, string $trigger): void
    {
        try {
            DB::pdo()->prepare(
                "INSERT INTO sync_runs (id,tenant_id,site_id,trigger_by,started_at,status)
                 VALUES (?,?,?,?,NOW(6),'OK')"
            )->execute([$runId,
                (string)($GLOBALS['config']['app']['tenant_id'] ?? ''),
                (string)($GLOBALS['config']['app']['site_id'] ?? ''),
                \substr($trigger, 0, 40)]);
        } catch (\Throwable $e) {}
    }

    private static function logRunEnd(string $runId, float $t0, array $pushed, array $pulled,
                                      string $status, ?string $err): void
    {
        try {
            $detail = [];
            foreach ($pushed as $t => $n) if ($n > 0) $detail[] = ['dir' => 'PUSH', 'table' => $t, 'rows' => $n];
            foreach ($pulled as $t => $n) if ($n > 0) $detail[] = ['dir' => 'PULL', 'table' => $t, 'rows' => $n];
            // NAKAAM tables bhi log mein — warna pata hi nahi chalta ke
            // kaun si table sync nahi hui aur kyun.
            foreach (self::$tableErrors as $te) {
                $detail[] = ['dir' => $te['dir'], 'table' => $te['table'], 'rows' => 0,
                             'failed' => true, 'error' => $te['error']];
            }
            foreach (self::$lastRowErrors as $tb => $er) {
                $detail[] = ['dir' => 'PUSH', 'table' => $tb, 'rows' => 0,
                             'failed' => true, 'error' => $er];
            }

            DB::pdo()->prepare(
                "UPDATE sync_runs SET finished_at=NOW(6), duration_ms=?, pushed_rows=?, pulled_rows=?,
                        tables_touched=?, status=?, detail_json=?, error_text=? WHERE id=?"
            )->execute([
                (int)((\microtime(true) - $t0) * 1000),
                (int)\array_sum($pushed), (int)\array_sum($pulled), \count($detail),
                $status, \json_encode($detail), $err !== null ? \substr($err, 0, 400) : null, $runId,
            ]);

            // per-table activity (local side)
            $ins = DB::pdo()->prepare(
                "INSERT INTO sync_activity (id,tenant_id,site_id,run_id,direction,table_name,rows_count,status,note,created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW(6))");
            foreach ($detail as $d) {
                $ins->execute([
                    \function_exists('uuid') ? uuid() : \bin2hex(\random_bytes(16)),
                    (string)($GLOBALS['config']['app']['tenant_id'] ?? ''),
                    (string)($GLOBALS['config']['app']['site_id'] ?? ''),
                    $runId, $d['dir'], $d['table'], $d['rows'],
                    !empty($d['failed']) ? 'FAILED' : 'OK',
                    isset($d['error']) ? \substr((string)$d['error'], 0, 300) : null,
                ]);
            }
            // purana log khud saaf: 60 din se purani entries hata do
            DB::pdo()->exec("DELETE FROM sync_activity WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
            DB::pdo()->exec("DELETE FROM sync_runs WHERE started_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
        } catch (\Throwable $e) {}
    }

    /** Log for the dashboard: recent runs with their per-table detail. */
    public static function runLog(int $limit = 20): array
    {
        try {
            $q = DB::pdo()->prepare(
                "SELECT id,trigger_by,started_at,finished_at,duration_ms,pushed_rows,pulled_rows,
                        tables_touched,status,detail_json,error_text
                   FROM sync_runs ORDER BY started_at DESC LIMIT $limit");
            $q->execute();
            $out = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['detail'] = $r['detail_json'] ? (\json_decode((string)$r['detail_json'], true) ?: []) : [];
                unset($r['detail_json']);
                $out[] = $r;
            }
            return $out;
        } catch (\Throwable $e) { return []; }
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
