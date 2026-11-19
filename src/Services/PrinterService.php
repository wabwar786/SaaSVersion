<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * PrinterService — printer waqai chal raha hai ya nahi.
 *
 * Pehle Printers page par ek "Status" ka dropdown tha jise user KHUD
 * Online/Offline set karta tha. Yani woh status ek raye thi, haqeeqat
 * nahi — printer band pare hone par bhi "Online" likha rehta tha aur
 * bill kho jata tha.
 *
 * Ab do asli test:
 *   check()     — printer ke port par TCP connect (raw 9100 / IPP 631)
 *   testPrint() — asli ESC/POS test receipt bheji jati hai
 *
 * TCP connect ko ICMP ping par tarjeeh di gayi hai: ping ka jawab dena
 * yeh sabit nahi karta ke printer PRINT bhi kar sakta hai; port khula
 * hona zyada kaam ka jawab hai. Ping sirf tab aazmaya jata hai jab TCP
 * fail ho, taake farq bataya ja sake:
 *   "network par hai magar port band"  vs  "network par hai hi nahi"
 */
final class PrinterService
{
    public const DEFAULT_PORT = 9100;      // raw / JetDirect
    private const TIMEOUT     = 2.0;

    /** @return array{ok:bool,status:string,message:string,ms:int} */
    public static function check(string $ip, int $port = 0): array
    {
        $ip   = trim($ip);
        $port = $port > 0 ? $port : self::DEFAULT_PORT;

        if ($ip === '') {
            return ['ok' => false, 'status' => 'NO_IP', 'ms' => 0,
                    'message' => 'No IP address set for this printer.'];
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^[a-z0-9.\-]+$/i', $ip)) {
            return ['ok' => false, 'status' => 'BAD_IP', 'ms' => 0,
                    'message' => 'That does not look like a valid IP address or host name.'];
        }

        /* 1) TCP — asli sawaal yehi hai */
        $t0 = microtime(true);
        $errno = 0; $errstr = '';
        $sock = @fsockopen($ip, $port, $errno, $errstr, self::TIMEOUT);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        if ($sock) {
            fclose($sock);
            return ['ok' => true, 'status' => 'ONLINE', 'ms' => $ms,
                    'message' => 'Printer responded on ' . $ip . ':' . $port . ' in ' . $ms . ' ms.'];
        }

        /* 2) TCP nakaam — kya machine network par hai bhi?
              Ping har jagah maujood nahi hota (kuch hosting par exec band
              hota hai). Aisi soorat mein hum yeh DAWA nahi karenge ke
              printer network par hai hi nahi — sirf itna kehenge jitna
              hum waqai jante hain. */
        $ping = self::ping($ip);          // true | false | null (pata nahi)

        if ($ping === true) {
            return ['ok' => false, 'status' => 'PORT_CLOSED', 'ms' => $ms,
                    'message' => $ip . ' is on the network but port ' . $port . ' is not open. '
                               . 'Check the printer port setting (usually 9100), or the printer may be busy or asleep.'];
        }
        if ($ping === null) {
            return ['ok' => false, 'status' => 'NO_RESPONSE', 'ms' => $ms,
                    'message' => 'No response from ' . $ip . ':' . $port . '. '
                               . 'Check that the printer is switched on, on the same network, '
                               . 'and that the IP address and port are correct.'];
        }
        return ['ok' => false, 'status' => 'UNREACHABLE', 'ms' => $ms,
                'message' => $ip . ' did not respond to a network ping either. '
                           . 'Check that the printer is switched on, connected to the same network, '
                           . 'and that the IP address is correct.'];
    }

    /**
     * ICMP ping — sirf farq batane ke liye; faisla hamesha TCP karta hai.
     * @return bool|null  true = jawab diya, false = nahi diya,
     *                    null  = ping chalaya hi nahi ja saka
     */
    private static function ping(string $host): ?bool
    {
        if (!function_exists('exec')) return null;
        $win = stripos(PHP_OS_FAMILY, 'Windows') !== false;
        $bin = $win ? 'ping' : 'ping';
        /* ping maujood hai bhi ya nahi — warna hum ghalat natija nikalte
           ("network par hai hi nahi") jo bilkul gumrah-kun hai. */
        if (!$win) {
            $w = []; $wc = 1;
            @exec('command -v ping 2>/dev/null', $w, $wc);
            if ($wc !== 0 || !$w) return null;
        }
        $cmd = $win
            ? $bin . ' -n 1 -w 1000 ' . escapeshellarg($host)
            : $bin . ' -c 1 -W 1 ' . escapeshellarg($host);
        $out = []; $code = 1;
        try { @exec($cmd . ' 2>&1', $out, $code); } catch (\Throwable $e) { return null; }
        return $code === 0;
    }

    /** ESC/POS test receipt — printer se asli kaghaz nikalta hai. */
    public static function testPrint(string $printerId): array
    {
        $q = DB::pdo()->prepare("SELECT * FROM printers WHERE id=? AND tenant_id=? AND site_id=? LIMIT 1");
        $q->execute([$printerId, tenant_id(), site_id()]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        if (!$p) return ['ok' => false, 'message' => 'Printer not found.'];

        $ip   = (string)($p['ip_address'] ?? '');
        $port = (int)($p['port_no'] ?? 0) ?: self::DEFAULT_PORT;

        if (strtoupper((string)($p['connection_type'] ?? '')) === 'WINDOWS' && $ip === '') {
            return ['ok' => false, 'message' =>
                'This printer is set to a Windows printer name, not a network address. '
                . 'Test print from the POS screen instead, or set its IP address to test from here.'];
        }

        $chk = self::check($ip, $port);
        if (!$chk['ok']) return ['ok' => false, 'message' => $chk['message'], 'status' => $chk['status']];

        $errno = 0; $errstr = '';
        $sock = @fsockopen($ip, $port, $errno, $errstr, self::TIMEOUT);
        if (!$sock) return ['ok' => false, 'message' => 'Could not open a connection to the printer.'];

        try {
            @stream_set_timeout($sock, 3);
            fwrite($sock, self::escposTest($p));
            fclose($sock);
        } catch (\Throwable $e) {
            @fclose($sock);
            return ['ok' => false, 'message' => 'Connected, but sending the test page failed: '
                                              . substr($e->getMessage(), 0, 120)];
        }

        return ['ok' => true, 'message' => 'Test page sent to ' . $p['name']
                                         . ' (' . $ip . ':' . $port . '). Check the printer for paper.'];
    }

    /** Chhota ESC/POS test page — aam thermal printers samajhte hain. */
    private static function escposTest(array $p): string
    {
        $ESC = "\x1B"; $GS = "\x1D";
        $out  = $ESC . '@';                    // reset
        $out .= $ESC . 'a' . "\x01";           // centre
        $out .= $ESC . '!' . "\x30";           // double width+height
        $out .= "TEST PRINT\n";
        $out .= $ESC . '!' . "\x00";           // normal
        $out .= str_repeat('-', 32) . "\n";
        $out .= $ESC . 'a' . "\x00";           // left
        $out .= 'Printer : ' . substr((string)$p['name'], 0, 22) . "\n";
        $out .= 'Type    : ' . substr((string)$p['printer_type'], 0, 22) . "\n";
        $out .= 'Address : ' . (string)$p['ip_address'] . ':' . ((int)$p['port_no'] ?: self::DEFAULT_PORT) . "\n";
        $out .= 'Time    : ' . date('d M Y  H:i:s') . "\n";
        $out .= str_repeat('-', 32) . "\n";
        $out .= "If you can read this, the printer\n";
        $out .= "is set up correctly.\n";
        $out .= $ESC . 'a' . "\x01";
        $out .= "Wabwar Software House\n";
        $out .= "\n\n\n";
        $out .= $GS . 'V' . "\x42" . "\x00";   // partial cut
        return $out;
    }

    /* ---------- category -> printer routing ---------- */

    public static function routes(): array
    {
        try {
            $q = DB::pdo()->prepare(
                "SELECT r.id, r.category_id, r.printer_id, r.print_rule,
                        mc.name AS category, pr.name AS printer
                   FROM menu_category_printer_routes r
                   JOIN menu_categories mc ON mc.id = r.category_id
                   JOIN printers pr        ON pr.id = r.printer_id
                  WHERE r.tenant_id=? AND r.site_id=?
                  ORDER BY mc.sort_order, mc.name");
            $q->execute([tenant_id(), site_id()]);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { return []; }
    }

    /** Ek category ka printer set/hataana. */
    public static function setRoute(string $categoryId, string $printerId): array
    {
        $p = DB::pdo();
        $p->prepare("DELETE FROM menu_category_printer_routes
                      WHERE tenant_id=? AND site_id=? AND category_id=?")
          ->execute([tenant_id(), site_id(), $categoryId]);

        if ($printerId !== '') {
            $p->prepare("INSERT INTO menu_category_printer_routes
                          (id,tenant_id,site_id,category_id,printer_id,is_primary,route_priority,print_rule,is_active)
                         VALUES(?,?,?,?,?,1,1,'PENDING_QTY_ONLY',1)")
              ->execute([uuid(), tenant_id(), site_id(), $categoryId, $printerId]);
        }
        return ['ok' => true];
    }

    /* ================================================================
       V205 — KITCHEN KI PARCHIYAN ASAL MEIN PRINT HON.

       Pehle server har KOT / tablet-QR KOT / cancel parchi sirf
       `printer_jobs` mein PENDING likh deta tha — use printer tak koi
       bhejta hi nahi tha (test: 43 pending, 0 printed). Jis shop mein
       KDS screen nahi, wahan tablet ke order aur cancel kitchen tak
       pohanchte hi nahi the.

       Ab counter ka POS har 10 second `kitchen-poll` chalata hai:
         - Shop PC (offline node) + printer ka IP: server seedha printer
           (ESC/POS, port 9100) par bhejta hai.
         - Cloud, ya printer ka IP nahi: parchi POS ko milti hai, POS
           apne printer par chhapta hai, phir `kitchen-done`.
       Ek parchi sirf EK counter uthata hai (atomic claim). 30 minute se
       purani parchi kabhi print nahi hoti (deploy par purana dher na
       nikle). Bill banne ki raftar par asar nahi: yeh kaam sale ki
       request mein nahi, alag poll mein hota hai.
       ================================================================ */
    private static function deadFile(string $pid): string { return sys_get_temp_dir().'/aio_prn_'.preg_replace('/[^a-z0-9-]/i','',$pid); }

    public static function sendRaw(string $ip, int $port, string $text, string $pid = ''): bool
    {
        if ($pid !== '' && is_file(self::deadFile($pid)) && (time() - (int)@filemtime(self::deadFile($pid))) < 60) return false;
        $errno = 0; $errstr = '';
        $sock = @fsockopen($ip, $port ?: self::DEFAULT_PORT, $errno, $errstr, 1.5);
        if (!$sock) { if ($pid !== '') @touch(self::deadFile($pid)); return false; }
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($t === false) $t = preg_replace('/[^\x09\x0A\x20-\x7E]/', '?', $text);
        $ok = @fwrite($sock, "\x1b@" . $t . "\n\n\n\n\x1dV\x01") !== false;
        @fclose($sock);
        if ($ok && $pid !== '') @unlink(self::deadFile($pid));
        return $ok;
    }

    public static function kitchenPoll(bool $direct): array
    {
        $p = DB::pdo(); $sid = site_id();
        foreach (['PENDING','PRINTING','FAILED'] as $__st) $p->prepare("UPDATE printer_jobs SET status='EXPIRED' WHERE site_id=? AND status=? AND queued_at < NOW(6) - INTERVAL 30 MINUTE")->execute([$sid, $__st]);   /* V206 — index wala */
        /* POS ne utha li thi magar 2 minute mein "done" nahi aaya: dobara qatar mein */
        $p->prepare("UPDATE printer_jobs SET status='PENDING' WHERE site_id=? AND status='PRINTING' AND printed_at < NOW(6) - INTERVAL 2 MINUTE")->execute([$sid]);
        $q = $p->prepare("SELECT pj.id, pj.payload_text, pj.job_type, pr.name, pr.ip_address, pr.port_no
                            FROM printer_jobs pj LEFT JOIN printers pr ON pr.id = pj.printer_id
                           WHERE pj.site_id=? AND pj.status='PENDING' ORDER BY pj.queued_at LIMIT 10");
        $q->execute([$sid]);
        $claim = $p->prepare("UPDATE printer_jobs SET status='PRINTING', printed_at=NOW(6), attempts=attempts+1 WHERE id=? AND status='PENDING'");
        $done  = $p->prepare("UPDATE printer_jobs SET status=?, printed_at=NOW(6), last_error=? WHERE id=?");
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $j) {
            $claim->execute([$j['id']]);
            if ($claim->rowCount() !== 1) continue;           // doosre counter ne utha li
            $ip = trim((string)($j['ip_address'] ?? ''));
            if ($direct && $ip !== '') {
                $ok = self::sendRaw($ip, (int)($j['port_no'] ?? 0), (string)$j['payload_text'], md5($ip.':'.(int)($j['port_no'] ?? 0)));
                $done->execute([$ok ? 'PRINTED' : 'FAILED', $ok ? 'sent to '.$ip : 'printer '.$ip.' not reachable', $j['id']]);
                continue;
            }
            $out[] = ['id' => $j['id'], 'printer' => (string)($j['name'] ?? 'Kitchen'), 'text' => (string)$j['payload_text']];
        }
        return ['print' => $out] + self::kitchenStatus();
    }

    public static function kitchenStatus(): array
    {
        /* V206 — index (site_id, status, queued_at) ke saath: pehle har poll
           saari purani parchiyan parhta tha (3 lakh par 0.2 second, har
           counter har 10 second). */
        $q = DB::pdo()->prepare("SELECT
               (SELECT COUNT(*) FROM printer_jobs WHERE site_id=? AND status='FAILED'  AND queued_at > NOW(6) - INTERVAL 30 MINUTE) failed,
               (SELECT COUNT(*) FROM printer_jobs WHERE site_id=? AND status='PENDING' AND queued_at > NOW(6) - INTERVAL 30 MINUTE AND queued_at < NOW(6) - INTERVAL 60 SECOND) waiting");
        $q->execute([site_id(), site_id()]); $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['failed' => (int)($r['failed'] ?? 0), 'waiting' => (int)($r['waiting'] ?? 0)];
    }

    public static function kitchenDone(array $ids): int
    {
        $n = 0; $u = DB::pdo()->prepare("UPDATE printer_jobs SET status='PRINTED', printed_at=NOW(6), last_error='printed at counter' WHERE id=? AND site_id=? AND status='PRINTING'");
        foreach (array_slice($ids, 0, 20) as $id) { $u->execute([(string)$id, site_id()]); $n += $u->rowCount(); }
        return $n;
    }

    public static function kitchenRetry(): int
    {
        foreach (glob(sys_get_temp_dir().'/aio_prn_*') ?: [] as $f) @unlink($f);
        $u = DB::pdo()->prepare("UPDATE printer_jobs SET status='PENDING' WHERE site_id=? AND status='FAILED' AND queued_at > NOW(6) - INTERVAL 30 MINUTE");
        $u->execute([site_id()]); return $u->rowCount();
    }

    /* POS ne apni KOT khud chhap di (counter par) — wahi parchi dobara na nikle. */
    public static function markCounterPrinted(string $orderId, string $since): void
    {
        DB::pdo()->prepare("UPDATE printer_jobs pj JOIN kitchen_tickets kt ON kt.id = pj.reference_id
                               SET pj.status='PRINTED', pj.printed_at=NOW(6), pj.last_error='printed at counter (KOT button)'
                             WHERE kt.order_id=? AND pj.site_id=? AND pj.status='PENDING' AND pj.reference_type='KITCHEN_TICKET' AND pj.queued_at >= ?")
          ->execute([$orderId, site_id(), $since]);
    }
}

// build: V206 build 2026-11-18
