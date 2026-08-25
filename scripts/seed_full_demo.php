<?php
declare(strict_types=1);

/*
 * V14 SAFE DEMO SEED
 *
 * Demo database records are OPTIONAL and must never prevent the restaurant
 * application from starting. Approved UI demo data is supplied independently
 * by the approved UI/local demo stores.
 */

$log = dirname(__DIR__) . '/storage/logs/demo-seed.log';

try {
    require __DIR__ . '/seed_full_demo_legacy.php';
    @file_put_contents(
        $log,
        '['.date('Y-m-d H:i:s')."] Full demo database seed completed.\n",
        FILE_APPEND
    );
} catch (Throwable $e) {
    $message = '['.date('Y-m-d H:i:s').'] Optional demo DB seed skipped: '
             . $e->getMessage() . PHP_EOL;

    @file_put_contents($log, $message, FILE_APPEND);

    echo PHP_EOL;
    echo "OPTIONAL_DEMO_DB_SEED_SKIPPED" . PHP_EOL;
    echo "Approved UI demo data will still load." . PHP_EOL;
    echo "Details saved to storage/logs/demo-seed.log" . PHP_EOL;
}

echo "DEMO_SEED_READY" . PHP_EOL;
exit(0);

// build: V17.1 build 2026-08-25
