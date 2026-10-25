<?php
/**
 * sync_loop.php — background sync.
 *
 * V64 — DO CHEEZEIN BADLI GAYIN:
 *
 * 1. WAQFA 5 MINUTE THA. `interval_minutes => 5` ka matlab tha ke local
 *    par kaata gaya bill cloud par 5 minute baad pohanchta tha. Ab default
 *    60 SECOND hai (`sync.interval_seconds`). Purani `interval_minutes`
 *    bhi chalti rahegi taake purani config na toote.
 *
 * 2. YEH LOOP EK ALAG OS PROCESS HAI, KOI PAGE NAHI. Screen badalne,
 *    POS par jane, dashboard band karne se is ka koi taluq nahi. Pehle
 *    asal kaam POS/dashboard ke JS timers bhi kar rahe the, aur wo har
 *    page load par SIFAR se shuru hote the — agar user har 90 second
 *    mein screen badalta to wo timer kabhi poora hota hi nahi tha.
 *
 * Aur: har pass ka natija `storage/logs/sync.log` mein, taake band ho
 * jane ki soorat mein wajah maloom ho. `sync_loop.pid` se launcher ka
 * watchdog dekhta hai ke loop zinda hai ya nahi.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\Services\Sync;
use Aio\Services\UpdateCheck;

$cfg  = $GLOBALS['config']['sync'] ?? [];
$secs = (int)($cfg['interval_seconds'] ?? 0);
if ($secs <= 0) $secs = ((int)($cfg['interval_minutes'] ?? 1)) * 60;   // old config
if ($secs < 10) $secs = 10;                                            // DB par raham

/* Watchdog ke liye nishan */
$pidFile = dirname(__DIR__).'/storage/logs/sync_loop.pid';
@file_put_contents($pidFile, (string)getmypid());
register_shutdown_function(function () use ($pidFile) { @unlink($pidFile); });

$beat = dirname(__DIR__).'/storage/logs/sync_loop.beat';

/* V155 — POS yeh file chhorta hai jab koi bill bane. Is loop ka kaam
   hai usay har second dekhna aur milte hi sync chala dena. POS khud
   kabhi sync nahi chalata — wahi us ki sustee ki jarh thi. */
$flagFile = dirname(__DIR__).'/storage/tmp/sync_now.flag';
@mkdir(dirname($flagFile), 0775, true);
@unlink($flagFile);                 /* purani nishani se shuruat nahi */

fwrite(STDERR, "[sync-loop] har {$secs} second (+ bill par foran)\n");

while (true) {
    $t0 = microtime(true);
    try {
        $r = Sync::run('auto');
        fwrite(STDERR, '[sync-loop] '.json_encode($r)."\n");
    } catch (\Throwable $e) {
        fwrite(STDERR, '[sync-loop] ERROR '.$e->getMessage()."\n");
    }
    /* Heartbeat — dashboard isse batata hai ke background sync zinda hai. */
    @file_put_contents($beat, (string)time());

    /* ============================================================
       V165 — HAR 6 GHANTE: NAYA BUILD AAYA HAI YA NAHI?

       Pehle yeh sawal sirf launcher poochta tha, start ke 45 second
       baad. Jo dukan subah computer chala kar raat ko band karti hai,
       us par theek chalta tha — magar jo hafton band hi nahi karti
       (aur aksar nahi karti), us par check KABHI nahi chalta tha.

       Yeh loop pehle se din raat chal raha hai, is liye sawal yahan
       se poochna sab se sasta hai. `probeIfDue()` khud dekh leti hai
       ke waqt hua hai ya nahi — aur agar khabar pehle hi mil chuki
       ho to portal ko tang bhi nahi karti.

       SIRF POOCHTA HAI. Download aur install dono aaj bhi insan ke
       dabane par hote hain (V90 ka faisla).
       ============================================================ */
    try {
        $u = UpdateCheck::probeIfDue();
        if ($u !== null) fwrite(STDERR, '[sync-loop] update-check '.json_encode($u)."\n");
    } catch (\Throwable $e) {
        /* Update ki khabar na mil sake to sync ka kaam nahi rukna chahiye. */
        fwrite(STDERR, '[sync-loop] update-check ERROR '.$e->getMessage()."\n");
    }

    /* ============================================================
       V155 — SONE KE DOURAN BHI KAAN KHULE.

       Pehle yahan `sleep($wait)` tha — ek hi dafa poore 60-120 second
       ka. Is liye bill ko cloud tak pohanchane ke liye POS ko KHUD
       sync chalani parti thi (`sync_nudge`), aur wahi counter ko ek
       minute tak jam deti thi.

       Ab POS sirf ek nishani file chhor deta hai aur apne kaam se kaam
       rakhta hai. Yeh loop us nishani ko har second dekhta hai, is
       liye bill phir bhi ~1 second mein upar chala jata hai — magar
       cashier ka koi intezar nahi.

       Sona 1-1 second ke tukron mein isi liye hai: `sleep(120)` ke
       doran nishani par nazar hi nahi par sakti thi.
       ============================================================ */
    $spent = microtime(true) - $t0;
    $wait  = $secs - (int)$spent;
    if ($wait < 1) $wait = 1;

    for ($i = 0; $i < $wait; $i++) {
        sleep(1);
        if (@is_file($flagFile)) {
            @unlink($flagFile);     /* pehle mitao, phir chalao — warna
                                       chalte chalte aane wali nayi
                                       nishani us hi run mein gum ho jati */
            break;
        }
    }
}

// build: V165 build 2026-10-25
