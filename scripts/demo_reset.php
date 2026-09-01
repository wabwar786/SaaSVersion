<?php
/**
 * demo_reset.php — demo businesses ka customer data saaf karo.
 *
 * Har boot par aur roz chalta hai. Sirf UN businesses ko chhoota hai jin
 * par `is_demo=1` hai aur jinka aakhri reset 5 din se purana hai.
 *
 *   php scripts/demo_reset.php              # jinka waqt aa gaya
 *   php scripts/demo_reset.php --tenant=ID  # foran, ek business
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\Services\DemoBusiness;

/* V84 — sealed build mein $argv maujood nahi hota. */
$args = cli_args();

/* Ek business foran — testing aur support ke liye. */
if (!empty($args['tenant'])) {
    $n = DemoBusiness::resetCustomerData((string)$args['tenant']);
    echo "DEMO_RESET tenant=".substr((string)$args['tenant'],0,8)." rows=".array_sum($n)."\n";
    foreach ($n as $t => $c) echo "  $t: $c\n";
    return;
}

$done=DemoBusiness::runDueResets();
if(!$done){ echo "DEMO_RESET_NONE nothing due\n"; return; }
foreach($done as $name=>$rows) echo "  $name: $rows rows cleared\n";
echo "DEMO_RESET_DONE businesses=".count($done)."\n";

// build: V79 build 2026-08-28
