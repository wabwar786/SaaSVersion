<?php
namespace Aio\Services;

use Aio\DB;

/**
 * ERROR LOG
 *
 * Har error ek hi jagah jata hai, business ke saath juda hua, aur Super
 * Admin se nazar aata hai.
 *
 * Do faisle jo yahan sab se ahem hain:
 *
 *  1. GROUPING. Ek hi error din mein sainkron dafa aa sakta hai. Har
 *     dafa nayi row banti to table shor ban jata aur koi nahi dekhta.
 *     `fingerprint` par ek row, `hits` barhta hai.
 *
 *  2. REQUEST BODY KABHI NAHI. Us mein password, card number, customer
 *     ka phone — kuch bhi ho sakta hai. Sirf action ka naam mehfooz
 *     hota hai. Error dhoondhne ke liye itna kaafi hai; poora payload
 *     rakhna ek aur masla khareedna hai.
 *
 * Yeh service khud kabhi exception nahi phenkti. Error log ka toot jana
 * asal kaam ko na roke — warna ek chhota sa masla poore software ko le
 * doobega.
 */
final class ErrorLog
{
    /** Aik hi request mein ek hi error baar baar na likha jaye. */
    private static array $seen = [];
    private static bool $busy = false;

    public static function record(
        string $message,
        string $level = 'ERROR',
        string $source = 'api',
        ?string $file = null,
        ?int $line = null,
        ?string $trace = null,
        ?string $action = null
    ): void {
        /* Recursion ka band: agar likhte waqt khud error aa jaye to
           dobara likhne ki koshish na ho. */
        if (self::$busy) return;
        self::$busy = true;

        try {
            $message = self::clean($message);
            if ($message === '') return;

            $file = $file ? self::shortPath($file) : null;
            $print = \sha1($message . '|' . ($file ?? '') . '|' . ($line ?? 0));
            if (isset(self::$seen[$print])) return;
            self::$seen[$print] = true;

            $tid = self::tenant();
            $pdo = DB::pdo();

            /* Pehle barhane ki koshish — aksar yehi hota hai. */
            $up = $pdo->prepare(
                "UPDATE app_errors
                    SET hits = hits + 1, last_seen = NOW(6), updated_at = NOW(6),
                        row_version = row_version + 1, resolved_at = NULL,
                        level = ?, action = COALESCE(?, action)
                  WHERE fingerprint = ? AND " . ($tid === null ? "tenant_id IS NULL" : "tenant_id = ?"));
            $args = [$level, $action ?: self::action(), $print];
            if ($tid !== null) $args[] = $tid;
            $up->execute($args);
            if ($up->rowCount() > 0) return;

            $u = self::user();
            $pdo->prepare(
                "INSERT INTO app_errors
                   (id, tenant_id, site_id, fingerprint, level, source, action, message,
                    file, line, trace, url, user_id, user_name, app_role, build,
                    hits, first_seen, last_seen, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(6),NOW(6),NOW(6))
                 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = NOW(6)"
            )->execute([
                \uuid(), $tid, self::site(), $print, $level, $source,
                $action ?: self::action(), self::cut($message, 2000),
                $file, $line, self::cut($trace, 2000),
                self::url(), $u['id'], $u['name'],
                (string)(cfg('app.role') ?: ''), self::build(),
            ]);
        } catch (\Throwable $e) {
            if (getenv('ERRLOG_DEBUG')) { echo "DEBUG: ".$e->getMessage()."\n"; }
        } finally {
            self::$busy = false;
        }
    }

    /** Browser se aaya hua JS error. */
    public static function fromClient(array $d): void
    {
        $msg = (string)($d['message'] ?? '');
        if ($msg === '') return;
        self::record(
            $msg, 'FATAL', 'js',
            (string)($d['file'] ?? ''), (int)($d['line'] ?? 0),
            (string)($d['stack'] ?? ''), (string)($d['page'] ?? '')
        );
    }

    /* ================= padhne ke liye ================= */

    /** @return array<int,array<string,mixed>> */
    public static function recent(array $f = []): array
    {
        $w = ['1=1']; $a = [];
        if (!empty($f['tenant_id'])) { $w[] = 'e.tenant_id = ?';  $a[] = $f['tenant_id']; }
        if (!empty($f['level']))     { $w[] = 'e.level = ?';      $a[] = $f['level']; }
        if (empty($f['show_resolved'])) $w[] = 'e.resolved_at IS NULL';
        if (!empty($f['q'])) {
            $w[] = '(e.message LIKE ? OR e.action LIKE ? OR e.file LIKE ?)';
            $like = '%' . $f['q'] . '%'; $a[] = $like; $a[] = $like; $a[] = $like;
        }
        $lim = \max(1, \min(500, (int)($f['limit'] ?? 200)));

        $q = DB::pdo()->prepare(
            "SELECT e.*, COALESCE(NULLIF(t.display_name,''), t.name) business
               FROM app_errors e
               LEFT JOIN tenants t ON t.id = e.tenant_id
              WHERE " . \implode(' AND ', $w) . "
              ORDER BY e.last_seen DESC
              LIMIT {$lim}");
        $q->execute($a);
        return $q->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function resolve(string $id, bool $on = true): bool
    {
        $q = DB::pdo()->prepare(
            "UPDATE app_errors SET resolved_at = " . ($on ? 'NOW(6)' : 'NULL') . ",
                    updated_at = NOW(6), row_version = row_version + 1
              WHERE id = ?");
        $q->execute([$id]);
        return $q->rowCount() > 0;
    }

    public static function counts(): array
    {
        try {
            $r = DB::pdo()->query(
                "SELECT
                   SUM(level='FATAL' AND resolved_at IS NULL) fatal,
                   SUM(level='ERROR' AND resolved_at IS NULL) error,
                   SUM(resolved_at IS NULL) open,
                   SUM(last_seen > DATE_SUB(NOW(), INTERVAL 1 HOUR) AND resolved_at IS NULL) last_hour
                 FROM app_errors")->fetch(\PDO::FETCH_ASSOC) ?: [];
            return \array_map(fn($v) => (int)$v, $r);
        } catch (\Throwable $e) { return ['fatal'=>0,'error'=>0,'open'=>0,'last_hour'=>0]; }
    }

    /* ================= chhoti madadgar ================= */


    /**
     * Text kaatna — mbstring ke baghair bhi.
     *
     * `mb_substr` har PHP build mein nahi hota (offline package ke chhote
     * runtime mein bhi nahi). Error log ka kaam hi ghalti pakarna hai;
     * uska khud kisi missing function par gir jana sab se bura nateeja
     * hai — errors chup chaap gayab hote rehte hain.
     */
    private static function cut(?string $s, int $len): ?string
    {
        if ($s === null) return null;
        if (\function_exists('mb_substr')) return \mb_substr($s, 0, $len);
        /* UTF-8 ko beech se na kaate — aakhri adhoora byte hata do. */
        $out = \substr($s, 0, $len);
        return \preg_replace('/[\x80-\xBF]+$/', '', $out) ?? $out;
    }

    /** Absolute paths mein server ka poora raasta hota hai — kaat do. */
    private static function shortPath(string $f): string
    {
        $root = \dirname(__DIR__, 2);
        return \str_replace([$root . '/', $root . '\\', $root], '', $f);
    }

    /**
     * Message se wo cheezein nikaal do jo mehfooz nahi rakhni chahiyen.
     * SQL errors mein aksar poori query aa jati hai, aur us mein values
     * hoti hain — email, phone, kabhi hash bhi.
     */
    private static function clean(string $m): string
    {
        $m = \trim($m);
        $m = \preg_replace('/\b[\w.+-]+@[\w.-]+\.\w{2,}\b/', '<email>', $m) ?? $m;
        $m = \preg_replace('/\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}/', '<hash>', $m) ?? $m;
        $m = \preg_replace('/\b\d{11,16}\b/', '<number>', $m) ?? $m;
        return (string)self::cut($m, 2000);
    }

    private static function tenant(): ?string
    {
        try { $t = (string)tenant_id(); return $t !== '' ? $t : null; }
        catch (\Throwable $e) { return null; }
    }

    private static function site(): ?string
    {
        try { $s = (string)site_id(); return $s !== '' ? $s : null; }
        catch (\Throwable $e) { return null; }
    }

    private static function user(): array
    {
        try {
            $u = $_SESSION['user'] ?? null;
            if (\is_array($u)) return ['id' => $u['id'] ?? null, 'name' => $u['full_name'] ?? null];
        } catch (\Throwable $e) { }
        return ['id' => null, 'name' => null];
    }

    private static function action(): ?string
    {
        $a = (string)($_GET['action'] ?? '');
        if ($a !== '') return self::cut($a, 120);
        $p = (string)($_SERVER['REQUEST_URI'] ?? '');
        return $p !== '' ? self::cut(\explode('?', $p)[0], 120) : null;
    }

    private static function url(): ?string
    {
        $u = (string)($_SERVER['REQUEST_URI'] ?? '');
        return $u !== '' ? self::cut($u, 255) : null;
    }

    private static function build(): ?string
    {
        static $b = null;
        if ($b === null) {
            $b = \trim((string)@\file_get_contents(\dirname(__DIR__, 2) . '/VERSION')) ?: '';
        }
        return $b !== '' ? self::cut($b, 40) : null;
    }
}
