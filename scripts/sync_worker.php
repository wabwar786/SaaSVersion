<?php
// One sync pass: push local changes to cloud, pull master data down.
// Safe to run repeatedly (idempotent). Prints a short JSON summary.
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\Services\Sync;
$r = Sync::run();
echo json_encode($r, JSON_UNESCAPED_SLASHES), "\n";
exit($r['ok'] ? 0 : 1);
