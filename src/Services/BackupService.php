<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * BackupService — shift close par khud-ba-khud backup, aur restore.
 *
 * TEEN USOOL:
 *
 * 1. BACKUP KABHI SHIFT CLOSE NAHI ROKEGA. Disk bhari ho, D: drive na
 *    ho, folder par ijazat na ho — shift phir bhi band hogi. Cashier ko
 *    counter par rok dena hal nahi hai. Nakami khamoshi se nahi jati:
 *    audit log mein jati hai aur Backup page par nazar aati hai.
 *
 * 2. RESTORE KHATARNAK HAI. Wo mojooda data par likh deta hai. Is liye:
 *    pehle KHUD-BA-KHUD safety backup, phir business ka naam likh kar
 *    tasdeeq, aur sirf Owner/Manager.
 *
 * 3. D: HAR PC PAR NAHI HOTA. Pehli koshish `D:\Backup\`, na mile to
 *    software ke apne `storage/backups`. Backup na hone se behtar hai
 *    kahin bhi ho jaye.
 */
final class BackupService
{
    public const KEEP_DAYS = 60;

    /* ---------------- kahan rakhein ---------------- */

    /** Backup ka folder — settings se, warna D:\Backup, warna local. */
    public static function dir(): string
    {
        $set = trim((string)self::setting('backup_path'));
        foreach ([$set, 'D:\\Backup', 'D:/Backup'] as $cand) {
            if ($cand === '') continue;
            if (@is_dir($cand) || @mkdir($cand, 0775, true)) {
                if (@is_writable($cand)) return rtrim($cand, '\\/');
            }
        }
        /* Fallback — kabhi khali haath nahi. */
        $local = dirname(__DIR__, 2) . '/storage/backups';
        @mkdir($local, 0775, true);
        return $local;
    }

    private static function setting(string $key): string
    {
        try {
            $q = DB::pdo()->prepare("SELECT value_json FROM site_settings
                                      WHERE site_id=? AND setting_group='backup' AND setting_key=? LIMIT 1");
            $q->execute([site_id(), $key]);
            $v = $q->fetchColumn();
            if ($v === false) return '';
            $d = json_decode((string)$v, true);
            return is_scalar($d) ? (string)$d : '';
        } catch (\Throwable $e) { return ''; }
    }

    public static function saveSetting(string $key, string $val): void
    {
        Scope::requireManagement('changing backup settings');
        $p = DB::pdo();
        $q = $p->prepare("SELECT id FROM site_settings WHERE site_id=? AND setting_group='backup' AND setting_key=? LIMIT 1");
        $q->execute([site_id(), $key]);
        $json = json_encode($val, JSON_UNESCAPED_UNICODE);
        if ($id = $q->fetchColumn()) {
            $p->prepare("UPDATE site_settings SET value_json=?, updated_at=NOW(6) WHERE id=?")->execute([$json, $id]);
        } else {
            $p->prepare("INSERT INTO site_settings(id,tenant_id,site_id,setting_group,setting_key,value_json,is_secret)
                         VALUES(?,?,?,'backup',?,?,0)")->execute([uuid(), tenant_id(), site_id(), $key, $json]);
        }
        Audit::log('BACKUP_SETTING', 'backup', ['new' => $key . '=' . $val]);
    }

    /* ---------------- banao ---------------- */

    /**
     * Backup banao aur disk par likho.
     *
     * @param string $reason  SHIFT_CLOSE | MANUAL | BEFORE_RESTORE
     * @return array{ok:bool,file:string,message:string}
     */
    public static function create(string $reason = 'MANUAL', string $label = ''): array
    {
        try {
            $b = AdminData::backup(tenant_id(), 'FULL');
        } catch (\Throwable $e) {
            Audit::log('BACKUP_FAILED', 'backup', ['desc' => 'could not read data: ' . substr($e->getMessage(), 0, 140)]);
            return ['ok' => false, 'file' => '', 'message' => 'Could not read the data: ' . substr($e->getMessage(), 0, 120)];
        }

        $biz  = preg_replace('/[^A-Za-z0-9]+/', '-', (string)(SettingsService::get()['business_name'] ?? 'business'));
        $biz  = trim($biz, '-') ?: 'business';
        $name = sprintf('%s_%s_%s.json', $biz, date('Y-m-d_H-i'), strtolower($reason));
        if ($label !== '') $name = sprintf('%s_%s_%s.json', $biz, date('Y-m-d_H-i'),
                                            preg_replace('/[^a-z0-9]+/i', '-', $label));

        $dir  = self::dir();
        $path = $dir . DIRECTORY_SEPARATOR . $name;

        $written = @file_put_contents($path, $b['json']);
        if ($written === false) {
            Audit::log('BACKUP_FAILED', 'backup', ['desc' => 'could not write to ' . $dir]);
            return ['ok' => false, 'file' => '', 'dir' => $dir,
                    'message' => 'Could not write the backup to ' . $dir . '. Check the folder and free space.'];
        }

        self::record($name, $path, $reason, (int)$b['meta']['rows'], (int)$b['meta']['bytes'],
                     (string)$b['meta']['checksum']);
        self::prune($dir);

        Audit::log('BACKUP_CREATED', 'backup', ['label' => $name,
                   'desc' => $reason . ' - ' . $b['meta']['rows'] . ' rows, ' . round($written / 1024) . ' KB']);

        return ['ok' => true, 'file' => $name, 'dir' => $dir,
                'rows' => (int)$b['meta']['rows'], 'bytes' => $written,
                'message' => 'Backup saved: ' . $path];
    }

    /**
     * Shift close ke baad. Yahan se koi exception BAHAR nahi jati —
     * shift band ho chuki hai, use ab kuch nahi rok sakta.
     */
    public static function afterShiftClose(string $shiftNo = ''): void
    {
        try {
            if (self::setting('auto_on_close') === 'No') return;
            self::create('SHIFT_CLOSE', $shiftNo);
        } catch (\Throwable $e) {
            try { Audit::log('BACKUP_FAILED', 'backup', ['desc' => substr($e->getMessage(), 0, 160)]); }
            catch (\Throwable $e2) {}
        }
    }

    private static function record(string $name, string $path, string $reason,
                                   int $rows, int $bytes, string $sum): void
    {
        try {
            DB::pdo()->prepare(
                "INSERT INTO backup_log(id,tenant_id,site_id,file_name,file_path,reason,
                                        row_count,byte_size,checksum,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?,NOW(6))")
              ->execute([uuid(), tenant_id(), site_id(), $name, $path, $reason, $rows, $bytes, $sum]);
        } catch (\Throwable $e) {}
    }

    /** Purane backups hatao — warna disk bhar jati hai aur koi nahi dekhta. */
    private static function prune(string $dir): void
    {
        try {
            $cut = time() - self::KEEP_DAYS * 86400;
            foreach ((glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: []) as $f) {
                if (@filemtime($f) < $cut) @unlink($f);
            }
            DB::pdo()->prepare("DELETE FROM backup_log WHERE tenant_id=? AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)")
              ->execute([tenant_id(), self::KEEP_DAYS]);
        } catch (\Throwable $e) {}
    }

    /* ---------------- fehrist ---------------- */

    public static function listBackups(int $limit = 100): array
    {
        $dir = self::dir();
        $rows = [];
        try {
            $q = DB::pdo()->prepare(
                "SELECT file_name, file_path, reason, row_count, byte_size, created_at
                   FROM backup_log WHERE tenant_id=? ORDER BY created_at DESC LIMIT $limit");
            $q->execute([tenant_id()]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}

        return ['dir' => $dir,
                'auto' => self::setting('auto_on_close') !== 'No',
                'writable' => @is_writable($dir),
                'rows' => array_map(fn($r) => [
                    'file'    => (string)$r['file_name'],
                    'path'    => (string)$r['file_path'],
                    'reason'  => (string)$r['reason'],
                    'records' => (int)$r['row_count'],
                    'size'    => (int)$r['byte_size'],
                    'when'    => substr((string)$r['created_at'], 0, 16),
                    'exists'  => @is_file((string)$r['file_path']),
                ], $rows)];
    }

    public static function read(string $file): string
    {
        /* Sirf apne folder se, aur sirf file ka NAAM — warna koi
           `../../config/local.php` maang kar server ki file parh lega. */
        $safe = basename($file);
        $path = self::dir() . DIRECTORY_SEPARATOR . $safe;
        if (!is_file($path)) throw new \RuntimeException('That backup file is not there any more.');
        $s = @file_get_contents($path);
        if ($s === false) throw new \RuntimeException('Could not read that backup file.');
        return $s;
    }

    /* ---------------- restore ---------------- */

    /**
     * Backup se data wapas. YEH MOJOODA DATA PAR LIKH DETA HAI.
     *
     * @param string $json     backup ka matn
     * @param string $confirm  business ka naam, hu-ba-hu
     */
    public static function restore(string $json, string $confirm): array
    {
        Scope::requireManagement('restoring a backup');

        $d = json_decode($json, true);
        if (!is_array($d) || ($d['format'] ?? '') !== 'smartpos-backup') {
            throw new \RuntimeException('That file is not a SmartPOS backup.');
        }
        if (empty($d['tables']) || !is_array($d['tables'])) {
            throw new \RuntimeException('That backup has no data in it.');
        }

        $me = (string)(SettingsService::get()['business_name'] ?? '');
        if (trim($confirm) === '' || strcasecmp(trim($confirm), trim($me)) !== 0) {
            throw new \RuntimeException('Type the business name exactly to confirm: ' . $me);
        }

        /* Restore se PEHLE mojooda data ka backup — agar restore ghalat
           file se hua to wapasi ka rasta khula rahe. */
        $safety = self::create('BEFORE_RESTORE');

        $p = DB::pdo();
        $sites = [];
        try {
            $sq = $p->prepare("SELECT id FROM sites WHERE tenant_id=?");
            $sq->execute([tenant_id()]);
            $sites = array_column($sq->fetchAll(), 'id');
        } catch (\Throwable $e) {}

        $restored = []; $skipped = [];
        $p->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($d['tables'] as $table => $rows) {
                if (!is_array($rows) || !$rows) continue;
                if (!preg_match('/^[a-z_]+$/', (string)$table)) { $skipped[] = $table; continue; }

                $cols = self::cols($p, (string)$table);
                if (!$cols) { $skipped[] = $table; continue; }

                /* Purani rows sirf ISI business ki hatao. */
                try {
                    if (in_array('tenant_id', $cols, true)) {
                        $p->prepare("DELETE FROM `$table` WHERE tenant_id=?")->execute([tenant_id()]);
                    } elseif (in_array('site_id', $cols, true) && $sites) {
                        $p->prepare("DELETE FROM `$table` WHERE site_id IN ("
                                    . implode(',', array_fill(0, count($sites), '?')) . ")")->execute($sites);
                    }
                } catch (\Throwable $e) {}

                $n = 0;
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $use = array_intersect_key($row, array_flip($cols));
                    if (!$use) continue;
                    $names = array_keys($use);
                    $sql = "INSERT IGNORE INTO `$table` (`" . implode('`,`', $names) . "`) VALUES ("
                         . implode(',', array_fill(0, count($names), '?')) . ")";
                    try { $p->prepare($sql)->execute(array_values($use)); $n++; }
                    catch (\Throwable $e) {}
                }
                if ($n) $restored[$table] = $n;
            }
        } finally {
            $p->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        Audit::log('RESTORE', 'backup', [
            'desc'  => 'from backup dated ' . (string)($d['created_at'] ?? '?'),
            'new'   => array_sum($restored) . ' rows into ' . count($restored) . ' tables',
        ]);

        return ['tables' => count($restored), 'rows' => array_sum($restored),
                'skipped' => $skipped,
                'safety_backup' => $safety['file'] ?? '',
                'message' => 'Restored ' . array_sum($restored) . ' record(s) into '
                           . count($restored) . ' table(s). Your previous data was saved first as '
                           . ($safety['file'] ?: 'a safety backup') . '.'];
    }

    private static function cols(PDO $p, string $t): array
    {
        static $c = [];
        if (isset($c[$t])) return $c[$t];
        try {
            $q = $p->prepare("SELECT column_name AS c FROM information_schema.columns
                               WHERE table_schema=DATABASE() AND table_name=?");
            $q->execute([$t]);
            return $c[$t] = array_column($q->fetchAll(), 'c');
        } catch (\Throwable $e) { return $c[$t] = []; }
    }
}

// build: V88 build 2026-09-01
