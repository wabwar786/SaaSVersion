<?php
// Background loop: runs a sync pass every sync.interval_minutes.
// Started by the launcher; keeps local and cloud in step automatically.
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\Services\Sync;
$mins = (int)($GLOBALS['config']['sync']['interval_minutes'] ?? 5);
if ($mins < 1) $mins = 1;
fwrite(STDERR, "[sync-loop] every {$mins} min\n");
while (true) {
    try { $r = Sync::run(); fwrite(STDERR, '[sync-loop] '.json_encode($r)."\n"); }
    catch (\Throwable $e) { fwrite(STDERR, '[sync-loop] ERROR '.$e->getMessage()."\n"); }
    sleep($mins * 60);
}

// build: V17.1 build 2026-08-25
