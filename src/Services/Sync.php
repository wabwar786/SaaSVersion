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
            $cc = self::cfg();
            [$code, $body] = self::httpPost(self::endpoint('sync-ping'),
                ['Content-Type: application/json', 'X-Sync-Token: ' . ($cc['token'] ?? ''),
                 'X-Node-Build: ' . self::localBuild(),
                 'X-Node-Code: '  . (string)($GLOBALS['config']['app']['node_code'] ?? '')], '{}', 4, 12);
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
            $cx = self::cfg();
            [$code, $res] = self::httpPost(self::endpoint('sync-ping'),
                ['Content-Type: application/json', 'X-Sync-Token: ' . ($cx['token'] ?? ''),
                 'X-Node-Build: ' . self::localBuild()], '{}', 6, 15);
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
                $diff = self::schemaDiff();
                $bad = \array_slice($diff, 0, 4);
                $add('Schema match', empty($diff), empty($diff)
                    ? 'Cloud database matches this computer'
                    : \implode(' | ', \array_map(
                        fn($d) => $d['table'] . '.' . $d['column'] . ': ' . $d['issue'], $bad))
                      . (\count($diff) > 4 ? (' (+' . (\count($diff) - 4) . ' more)') : ''));
                $add('Bulk sync support', !empty($j['features']['bulk_sync']),
                    !empty($j['features']['bulk_sync'])
                        ? 'Cloud supports fast bulk sync'
                        : 'Cloud is on an older build - sync will be slow and may time out');

                /* V62.2 — MODULE IDS.
                   `platform_modules.id` pehle har installation par random
                   thi. Node ki `user_module_access` rows cloud par kisi
                   module se match hi nahi karti thin, is liye online har
                   user "0 Modules" dikhata tha — bina kisi error ke.
                   Ab yeh mismatch SAAF NAZAR AATA hai. */
                try {
                    $cf = (string)(self::post('sync-schema', ['tables' => []])['module_fingerprint'] ?? '');
                    $lf = self::moduleFingerprint();
                    $mok = ($cf !== '' && $lf !== '' && $cf === $lf);
                    $add('Module IDs match', $mok, $mok
                        ? 'Cloud aur is computer par module ids ek jaisi hain'
                        : 'MISMATCH - permissions sync NahI hongi (har user online "0 Modules" dikhega). '
                          . 'Dono taraf `php scripts/migrate_module_ids.php` chalayein.  '
                          . 'This: ' . substr($lf, 0, 8) . '  |  Cloud: ' . (($cf !== '') ? substr($cf, 0, 8) : 'unknown'));
                } catch (\Throwable $e) {
                    $add('Module IDs match', false, 'Check nahi chal saka: ' . substr($e->getMessage(), 0, 90));
                }
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
        /* V62 — DELETE CHANNEL.
           `sync_tombstones` har installation mein sync honi CHAHIYE, chahe
           config purani ho. Pehle sync sirf "kya badla" bhejti thi; "kya mit
           gaya" ka koi rasta nahi tha, is liye cloud par delete/reset hua
           data branch computer par zinda reh jata tha. Config edit karne ka
           intezar nahi kar sakte — yahan zabardasti daal rahe hain.

           V62.2 — wahi kahani PERMISSIONS ki thi. `user_module_access`
           kisi list mein thi hi nahi, is liye node par assign kiye hue
           modules kabhi cloud tak pohanchte hi nahi the aur online har
           user "0 Modules" dikhata tha. Purani config wale nodes ko bhi
           yeh milna chahiye. */
        $force = [
            'sync_tombstones',
            'users', 'user_roles', 'roles', 'role_modules',
            'user_module_access', 'user_form_permissions',
        ];
        foreach (['push_tables', 'pull_tables'] as $k) {
            $list = (array)($c[$k] ?? []);
            if ($list) {
                foreach ($force as $t) if (!in_array($t, $list, true)) $list[] = $t;
            }
            $c[$k] = $list;
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
        /* Schema ka naam ASLI connection se lo, config se nahi.
           Railway/managed hosting par config ka database naam aksar us
           schema se mukhtalif hota hai jis se connection bana hota hai
           (misal env alag, ya alias). Us soorat mein information_schema
           khali lautata tha, tableExists() false ho jata tha aur har
           table "cloud par mojood nahi" keh kar reject ho jati thi. */
        $cols = [];
        try {
            $q = DB::pdo()->prepare(
                "SELECT column_name AS column_name FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ?"
            );
            $q->execute([$table]);
            $cols = array_column($q->fetchAll(PDO::FETCH_ASSOC), 'column_name');
        } catch (\Throwable $e) { $cols = []; }

        if (!$cols) {
            // Fallback: config wala naam bhi try kar lo
            try {
                $db = (string)($GLOBALS['config']['db']['database'] ?? '');
                if ($db !== '') {
                    $q = DB::pdo()->prepare(
                        "SELECT column_name AS column_name FROM information_schema.columns
                          WHERE table_schema = ? AND table_name = ?"
                    );
                    $q->execute([$db, $table]);
                    $cols = array_column($q->fetchAll(PDO::FETCH_ASSOC), 'column_name');
                }
            } catch (\Throwable $e) {}
        }
        if (!$cols) {
            // Aakhri koshish: seedha table se poochho
            try {
                foreach (DB::pdo()->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $cols[] = $r['Field'] ?? ($r['field'] ?? null);
                }
                $cols = array_values(array_filter($cols));
            } catch (\Throwable $e) { $cols = []; }
        }
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

    /**
     * platform_modules ka fingerprint — cloud aur node par HAMESHA barabar
     * hona chahiye. Alag ho to permissions sync ho kar bhi be-asar rehti
     * hain (join match hi nahi karta).
     */
    public static function moduleFingerprint(): string
    {
        try {
            $q = DB::pdo()->query(
                "SELECT MD5(GROUP_CONCAT(CONCAT(module_key,':',id) ORDER BY module_key SEPARATOR '|')) AS fp
                   FROM platform_modules WHERE is_active=1");
            return (string)($q->fetchColumn() ?: '');
        } catch (\Throwable $e) { return ''; }
    }

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
            /* NULL site_id = tenant-level data (misal customers jo kisi ek
               branch se bandhe nahi). Pehle sirf "site_id = ?" tha, is liye
               aisi saari rows kabhi neeche NahI aati thin. */
            $where .= " AND (site_id = ? OR site_id IS NULL)";
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
        if (!$rows) return 0;
        if (!self::tableExists($table)) {
            // Yeh bhi ek KHAMOSH rasta tha: table cloud par mojood na ho to
            // chupchaap 0 wapas aata tha - node ko "rejected" dikhta, wajah
            // kahin nahi.
            self::$lastRowErrors[$table] = 'table does not exist on the cloud database (run the migrations there)';
            return 0;
        }
        $cols      = self::columns($table);
        $pdo       = DB::pdo();
        $hasTenant = in_array('tenant_id', $cols, true);
        $ts        = self::tsColumn($table);

        $applied = 0; $skipped = 0; $failed = 0; $conflicts = 0; $noId = 0; $lastErr = '';
        self::$lastAudit = [];   // har row ka anjaam (diagnostics ke liye)
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($rows as $row) {
            $data = array_intersect_key((array)$row, array_flip($cols));
            if (!isset($data['id'])) {
                // PEHLE: yeh row CHUPCHAAP gir jati thi - na applied, na
                // failed, na conflict. Node ko sirf "rejected by cloud"
                // dikhta tha aur wajah kahin darj hi nahi hoti thi.
                $noId++;
                $rid = (string)(((array)$row)['id'] ?? '');
                self::$lastAudit[] = ['id' => $rid, 'status' => 'SKIPPED',
                    'reason' => $rid === ''
                        ? 'row has no id'
                        : 'the cloud table has no "id" column for this row'];
                continue;
            }
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
                    self::$lastAudit[] = ['id' => (string)$data['id'], 'status' => 'UPDATED', 'reason' => ''];
                } else {
                    $keys = array_keys($data);
                    $ph   = implode(',', array_fill(0, count($keys), '?'));
                    try {
                        $pdo->prepare("INSERT INTO `$table` (`" . implode('`,`', $keys) . "`) VALUES ($ph)")
                            ->execute(array_values($data));
                        $applied++;
                        self::$lastAudit[] = ['id' => (string)$data['id'], 'status' => 'INSERTED', 'reason' => ''];
                    } catch (\PDOException $pe) {
                        if ((int)$pe->getCode() === 23000) {
                            $conflicts++;
                            self::$lastConflicts[] = ['table' => $table, 'id' => (string)$data['id'],
                                'key' => isset($data['bill_no']) ? ('bill '.$data['bill_no']) : '',
                                'error' => \substr($pe->getMessage(), 0, 140)];
                            self::$lastAudit[] = ['id' => (string)$data['id'], 'status' => 'CONFLICT',
                                'reason' => \substr(\preg_replace('/\s+/', ' ', $pe->getMessage()) ?? '', 0, 180)];
                            continue;
                        }
                        throw $pe;
                    }
                }
            } catch (\Throwable $e) {
                // Ek row ka masla (misal duplicate bill number) poori batch
                // ko na rokay — baqi rows chalti rahen.
                $failed++;
                $lastErr = \substr(\preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 220);
                self::$lastAudit[] = ['id' => (string)($data['id'] ?? '?'), 'status' => 'FAILED',
                    'reason' => $lastErr];
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        if ($failed > 0) {
            self::$lastRowErrors[$table] = "$failed row(s) skipped: $lastErr";
        }
        if ($noId > 0) {
            self::$lastRowErrors[$table] = ($p0 = self::$lastRowErrors[$table] ?? '')
                . ($p0 ? ' | ' : '')
                . "$noId row(s) skipped - the cloud table is missing columns this row needs (schema is older on the cloud)";
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
    /** @var array<int,array{id:string,status:string,reason:string}> har row ka anjaam */
    public static array $lastAudit = [];
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
        $headers = ['Content-Type: application/json', 'X-Sync-Token: ' . ($c['token'] ?? ''),
                    'X-Node-Build: ' . self::localBuild(),
                    'X-Node-Code: '  . (string)($GLOBALS['config']['app']['node_code'] ?? '')];
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
            $c = self::cfg();
            [$code, $body] = self::httpPost(self::endpoint('sync-ping'),
                ['Content-Type: application/json', 'X-Sync-Token: ' . ($c['token'] ?? ''),
                 'X-Node-Build: ' . self::localBuild(),
                 'X-Node-Code: '  . (string)($GLOBALS['config']['app']['node_code'] ?? '')], '{}', 4, 10);
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
                    self::clearRetry("push:$table");
                    continue;
                }
                /* Kuch rows qubool nahi huin. Agar sabab duplicate key hai to
                   dobara koshish se bhi kabhi qubool nahi hongi — watermark
                   rok dene se sync HAMESHA usi jagah atka rehta (aap ke
                   "2 rows to upload" ki wajah). Is liye: aage barh jao,
                   magar conflict ko permanently record kar do. */
                $detail = (string)($r['row_error'] ?? '');
                if ($detail === '' && !empty($r['rejected'])) {
                    $bits = [];
                    foreach (\array_slice((array)$r['rejected'], 0, 2) as $b) {
                        $bits[] = \substr((string)($b['id'] ?? '?'), 0, 8) . ': '
                                . (string)($b['status'] ?? '') . ' - ' . (string)($b['reason'] ?? '');
                    }
                    $detail = \implode(' | ', $bits);
                }
                if ($detail === '') {
                    // Cloud ne wajah nahi batayi -> aksar cloud par purana
                    // build hai jo row_error lautata hi nahi.
                    $cb = self::cloudBuild()['build'];
                    $lb = self::localBuild();
                    if ($cb !== '' && \strtok($cb, ' ') !== \strtok($lb, ' ')) {
                        $detail = "cloud is on an older build ($cb) and cannot report the reason - "
                                . "deploy $lb to the server";
                    } else {
                        $detail = 'run "Check" for a schema comparison';
                    }
                }
                $why = $conf > 0
                    ? ($conf . ' row(s) rejected - number already used by another device')
                    : (($sent - $applied) . ' row(s) rejected by cloud - ' . $detail);
                self::$tableErrors[] = ['dir' => 'PUSH', 'table' => $table, 'error' => $why];
                foreach (($r['conflict_detail'] ?? []) as $cd) self::$lastConflicts[] = $cd;

                /* Kuch masle dobara koshish se hal ho jate hain (network,
                   parent row abhi nahi pohanchi). Kuch kabhi hal nahi hote
                   (duplicate key, kharab data). Pehle inhen alag nahi kiya
                   jata tha, is liye sync HAMESHA usi jagah atka rehta tha —
                   aap ke log mein wahi 6 tables baar baar. Ab: 3 koshishon
                   ke baad aage barh jao aur un rows ko quarantine kar do. */
                // Purane bills (prefix se pehle banaye gaye) cloud ke apne
                // bill numbers se takra jate hain. Aise rows ko yahin par
                // node-prefix de kar dobara bhej dete hain — warna wo
                // hamesha ke liye reject rehte.
                if ($table === 'orders' && $conf > 0) {
                    $fixed = self::rebrandConflictingBills($r['conflict_detail'] ?? []);
                    if ($fixed > 0) {
                        self::$tableErrors[] = ['dir' => 'PUSH', 'table' => $table,
                            'error' => "$fixed old bill(s) renumbered with this computer's prefix - they will upload on the next sync"];
                        self::setWatermark("push:$table", $meta[$table]['since'], 'PARTIAL',
                            "$fixed bill(s) renumbered", $applied);
                        self::clearRetry("push:$table");
                        continue;
                    }
                }

                $tries = self::bumpRetry("push:$table");
                $permanent = ($conf > 0 && ($applied + $conf) >= $sent);
                if ($permanent || $tries >= 3) {
                    $note = $permanent ? $why : ($why . ' (gave up after ' . $tries . ' attempts)');
                    self::setWatermark("push:$table", $meta[$table]['wm'], 'PARTIAL', $note, $applied);
                    self::quarantine($table, $sent - $applied, $note);
                    self::clearRetry("push:$table");
                } else {
                    self::setWatermark("push:$table", $meta[$table]['since'], 'ERROR',
                        $why . ' (attempt ' . $tries . ' of 3)', $applied);
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

    /**
     * Local aur cloud ke schema ka farq. Row rejection ki sab se aam wajah
     * yehi hoti hai: cloud par column ghayab hai, ya chhota hai, ya NOT NULL
     * hai jabke local uske baghair row bhej raha hai.
     * @return array<int,array{table:string,column:string,issue:string}>
     */
    public static function schemaDiff(array $tables = []): array
    {
        $c = self::cfg();
        if (!$tables) $tables = \array_slice((array)($c['push_tables'] ?? []), 0, 25);
        $out = [];
        try {
            $resp = self::post('sync-schema', ['tables' => \array_values($tables)]);
        } catch (\Throwable $e) {
            return [['table' => '-', 'column' => '-', 'issue' => 'Cloud schema not readable: ' . $e->getMessage()]];
        }
        $cloud = (array)($resp['schema'] ?? []);
        if (!$cloud) {
            return [['table' => '-', 'column' => '-',
                     'issue' => 'Cloud did not return its schema (older build on the server).']];
        }
        $pdo = DB::pdo();
        foreach ($tables as $t) {
            if (!isset($cloud[$t])) continue;
            try {
                $q = $pdo->prepare("SELECT column_name c,column_type t,is_nullable n
                                      FROM information_schema.columns
                                     WHERE table_schema=DATABASE() AND table_name=?");
                $q->execute([$t]);
                $local = [];
                foreach ($q->fetchAll() as $r) $local[$r['c']] = ['type' => $r['t'], 'null' => $r['n']];
            } catch (\Throwable $e) { continue; }

            foreach ($local as $col => $def) {
                if (!isset($cloud[$t][$col])) {
                    $out[] = ['table' => $t, 'column' => $col, 'issue' => 'missing on cloud (data in this column will be dropped)'];
                    continue;
                }
                $cd = $cloud[$t][$col];
                if ($def['type'] !== $cd['type']) {
                    $lm = self::typeLen($def['type']); $cm = self::typeLen($cd['type']);
                    if ($lm > 0 && $cm > 0 && $cm < $lm) {
                        $out[] = ['table' => $t, 'column' => $col,
                                  'issue' => "cloud column is smaller ({$cd['type']} vs {$def['type']}) - rows can be rejected"];
                    }
                }
            }
            foreach ($cloud[$t] as $col => $cd) {
                if (isset($local[$col])) continue;
                if ($cd['null'] === 'NO' && $cd['default'] === null) {
                    $out[] = ['table' => $t, 'column' => $col,
                              'issue' => 'cloud requires this column but this computer does not have it - rows will be rejected'];
                }
            }
        }
        return $out;
    }

    private static function typeLen(string $t): int
    {
        return \preg_match('/\((\d+)/', $t, $m) ? (int)$m[1] : 0;
    }

    /** Lagatar nakaam koshishon ki ginti (scope ke against). */
    private static function bumpRetry(string $scope): int
    {
        try {
            $p = DB::pdo();
            $p->exec("CREATE TABLE IF NOT EXISTS sync_retries (
                scope VARCHAR(120) NOT NULL PRIMARY KEY,
                tries INT NOT NULL DEFAULT 0,
                updated_at DATETIME(6) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $p->prepare("INSERT INTO sync_retries(scope,tries,updated_at) VALUES(?,1,NOW(6))
                         ON DUPLICATE KEY UPDATE tries=tries+1, updated_at=NOW(6)")->execute([$scope]);
            $q = $p->prepare("SELECT tries FROM sync_retries WHERE scope=?");
            $q->execute([$scope]);
            return (int)$q->fetchColumn();
        } catch (\Throwable $e) { return 3; }
    }

    private static function clearRetry(string $scope): void
    {
        try { DB::pdo()->prepare("DELETE FROM sync_retries WHERE scope=?")->execute([$scope]); }
        catch (\Throwable $e) {}
    }

    /**
     * Jo bills duplicate number ki wajah se reject huay, unhen is node ka
     * prefix de do (0007 -> L2-0007) taake agli sync mein chale jayen.
     * Sirf wahi rows chhui jati hain jo cloud ne reject ki hain.
     */
    private static function rebrandConflictingBills(array $details): int
    {
        $pre = '';
        try { $pre = \Aio\Services\PageData::billPrefix(); } catch (\Throwable $e) {}
        if ($pre === '') return 0;

        $pdo = DB::pdo();
        $fixed = 0;
        foreach ($details as $d) {
            $id = (string)($d['id'] ?? '');
            if ($id === '') continue;
            try {
                $q = $pdo->prepare("SELECT bill_no, site_id, business_date FROM orders WHERE id=? LIMIT 1");
                $q->execute([$id]);
                $row = $q->fetch();
                if (!$row) continue;
                $bill = (string)$row['bill_no'];
                if ($bill === '' || \strpos($bill, $pre) === 0) continue;   // pehle se prefixed

                $new = $pre . $bill;
                // local par bhi takrao na ho
                $c = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE site_id=? AND business_date=? AND bill_no=? AND id<>?");
                $c->execute([$row['site_id'], $row['business_date'], $new, $id]);
                if ((int)$c->fetchColumn() > 0) $new = $pre . $bill . '-' . \substr($id, 0, 4);

                $pdo->prepare("UPDATE orders SET bill_no=?, updated_at=NOW(6) WHERE id=?")->execute([$new, $id]);
                $fixed++;
            } catch (\Throwable $e) {}
        }
        return $fixed;
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



    /* ==================================================================
       TOMBSTONES — "kya mit gaya" ka channel.

       Yeh V62 ka sab se bara fix hai. Sync engine sirf upsert karti thi
       (updated_at watermark + INSERT/UPDATE). Jab cloud par `DELETE FROM
       orders ...` chalta tha (factory reset / purge / force delete), row
       bina koi nishan chhore ghayab ho jati thi. Node agli pull par
       poochta: "mere watermark ke baad kya naya hai?" — cloud kehta
       "kuch nahi". Node ko khabar hi nahi hoti thi ke hazaron rows mit
       chuki hain, aur wo apna purana data pakde baitha rehta tha.

       Ab har hard delete `sync_tombstones` mein nishan chhorta hai. Wo
       table ek normal syncable table hai, is liye dono taraf pohanchti
       hai; yeh function usay parh kar local rows uda deta hai.

       Do modes:
         ROW  — ek row (row_id se)
         WIPE — us table ki poori tenant/site scope (`before_ts` tak).
                Isi se factory reset ab node tak pohanchta hai.

       `before_ts` guard is liye hai ke wipe idempotent rahe: reset ke
       BAAD banaya gaya naya data kabhi na mite, chahe wohi tombstone
       dobara process ho jaye.
       ================================================================== */

    /** Yeh tables kabhi tombstone se delete nahi hotin. */
    private const TOMBSTONE_DENY = [
        'sync_tombstones', 'sync_tombstones_applied', 'sync_state', 'sync_runs',
        'sync_activity', 'sync_nodes', 'sync_retries', 'deletion_log',
        'platform_users', 'platform_modules', 'tenants', 'organizations',
        'subscription_plans', 'tenant_subscriptions', 'subscription_payments',
    ];

    /**
     * Aayi hui tombstones apply karo.
     * @return array{applied:int,rows:int,errors:array<int,string>}
     */
    public static function applyTombstones(int $limit = 500): array
    {
        $out = ['applied' => 0, 'rows' => 0, 'errors' => []];
        if (!self::tableExists('sync_tombstones')) return $out;
        if (!self::tableExists('sync_tombstones_applied')) return $out;

        $p = DB::pdo();
        try {
            $q = $p->prepare(
                "SELECT t.id, t.tenant_id, t.site_id, t.table_name, t.row_id,
                        t.scope_mode, t.before_ts, t.reason
                   FROM sync_tombstones t
                   LEFT JOIN sync_tombstones_applied a ON a.tombstone_id = t.id
                  WHERE a.tombstone_id IS NULL
                  ORDER BY t.created_at ASC
                  LIMIT $limit"
            );
            $q->execute();
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $out['errors'][] = 'tombstone read: '.substr($e->getMessage(), 0, 140);
            return $out;
        }
        if (!$rows) return $out;

        foreach ($rows as $t) {
            $table = (string)$t['table_name'];
            $n = 0;

            if ($table === '' || in_array($table, self::TOMBSTONE_DENY, true) || !self::tableExists($table)) {
                self::markTombstoneApplied((string)$t['id'], 0);
                $out['applied']++;
                continue;
            }

            try {
                $cols = self::columns($table);
                $where = []; $args = [];

                if (($t['scope_mode'] ?? 'ROW') === 'WIPE' && (string)$t['row_id'] === 'ALL') {
                    /* poori scope — magar sirf us waqt tak ka data */
                    $where[] = '1=1';
                    if (!empty($t['before_ts'])) {
                        $tsCol = in_array('created_at', $cols, true) ? 'created_at'
                               : (in_array('updated_at', $cols, true) ? 'updated_at' : null);
                        if ($tsCol) { $where[] = "`$tsCol` <= ?"; $args[] = $t['before_ts']; }
                    }
                } else {
                    if (!in_array('id', $cols, true)) {
                        self::markTombstoneApplied((string)$t['id'], 0);
                        $out['applied']++;
                        continue;
                    }
                    $where[] = 'id = ?'; $args[] = (string)$t['row_id'];
                }

                /* Tenant/branch scope — ek business ka tombstone doosre ka
                   data kabhi na chhoo sake. */
                if (!empty($t['tenant_id']) && in_array('tenant_id', $cols, true)) {
                    $where[] = 'tenant_id = ?'; $args[] = $t['tenant_id'];
                }
                if (!empty($t['site_id']) && in_array('site_id', $cols, true)) {
                    $where[] = '(site_id = ? OR site_id IS NULL)'; $args[] = $t['site_id'];
                }

                $p->exec('SET FOREIGN_KEY_CHECKS=0');
                $st = $p->prepare("DELETE FROM `$table` WHERE ".implode(' AND ', $where));
                $st->execute($args);
                $n = $st->rowCount();
                $p->exec('SET FOREIGN_KEY_CHECKS=1');

                self::markTombstoneApplied((string)$t['id'], $n);
                $out['applied']++;
                $out['rows'] += $n;

                if ($n > 0) {
                    /* Wipe ke baad us table ka watermark reset — warna node
                       ka purana watermark aage hone ki wajah se wo rows
                       dobara kabhi push/pull hi nahi hotin. */
                    try {
                        $p->prepare("DELETE FROM sync_state WHERE scope IN (?,?)")
                          ->execute(['push:'.$table, 'pull:'.$table]);
                    } catch (\Throwable $e) {}
                }
            } catch (\Throwable $e) {
                try { $p->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (\Throwable $e2) {}
                /* KHAMOSH NAHI. Wajah run log tak jayegi. */
                $out['errors'][] = $table.': '.substr($e->getMessage(), 0, 140);
            }
        }

        /* 90 din se purani tombstones + unke markers saaf */
        try {
            if (random_int(1, 20) === 1) {
                $p->exec("DELETE FROM sync_tombstones_applied WHERE applied_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
                $p->exec("DELETE FROM sync_tombstones WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            }
        } catch (\Throwable $e) {}

        return $out;
    }

    private static function markTombstoneApplied(string $id, int $rows): void
    {
        try {
            DB::pdo()->prepare(
                "INSERT INTO sync_tombstones_applied (tombstone_id, rows_deleted, applied_at)
                 VALUES (?,?,NOW(6))
                 ON DUPLICATE KEY UPDATE rows_deleted = VALUES(rows_deleted), applied_at = NOW(6)"
            )->execute([$id, $rows]);
        } catch (\Throwable $e) {}
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

            /* Pull ke BAAD — pehle tombstones neeche aani hain, phir apply.
               Yeh wo step hai jis ke baghair cloud par delete kiya hua data
               node par hamesha zinda rehta tha. */
            $tomb = self::applyTombstones();

            $total  = array_sum($pushed) + array_sum($pulled);
            self::touchState('engine', 'OK', null);

            $problems = [];
            foreach (self::$tableErrors as $te) $problems[] = $te['dir'].' '.$te['table'].': '.$te['error'];
            foreach (self::$lastRowErrors as $tb => $er) $problems[] = $tb.': '.$er;
            foreach ($tomb['errors'] as $er) $problems[] = 'DELETE '.$er;

            $status = $problems ? 'PARTIAL' : 'OK';
            if (self::$abortedReason !== '') $status = 'ERROR';

            $errText = self::$abortedReason
                ? (self::friendlyError(self::$abortedReason) . '  [' . self::$abortedReason . ']')
                : ($problems ? \implode(' | ', $problems) : null);
            self::logRunEnd($runId, $t0, $pushed, $pulled, $status, $errText);

            return ['ok' => empty(self::$abortedReason), 'run_id' => $runId,
                    'pushed' => $pushed, 'pulled' => $pulled, 'total' => $total,
                    'deleted_rows' => $tomb['rows'], 'tombstones' => $tomb['applied'],
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

// build: V62 build 2026-08-26
