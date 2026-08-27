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

$cfg  = $GLOBALS['config']['sync'] ?? [];
$secs = (int)($cfg['interval_seconds'] ?? 0);
if ($secs <= 0) $secs = ((int)($cfg['interval_minutes'] ?? 1)) * 60;   // purani config
if ($secs < 10) $secs = 10;                                            // DB par raham

/* Watchdog ke liye nishan */
$pidFile = dirname(__DIR__).'/storage/logs/sync_loop.pid';
@file_put_contents($pidFile, (string)getmypid());
register_shutdown_function(function () use ($pidFile) { @unlink($pidFile); });

$beat = dirname(__DIR__).'/storage/logs/sync_loop.beat';

fwrite(STDERR, "[sync-loop] har {$secs} second\n");

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

    $spent = microtime(true) - $t0;
    $wait  = $secs - (int)$spent;
    sleep($wait > 1 ? $wait : 1);
}

// build: V64 build 2026-08-27
