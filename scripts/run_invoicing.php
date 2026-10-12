<?php
/**
 * run_invoicing.php — jin ki expiry qareeb hai, un ka parcha bana do.
 *
 * Super Admin console khulte hi yeh khud chal jata hai, magar agar aap
 * console na kholein to bhi parcha waqt par banna chahiye. Is liye yeh
 * script: ise rozana ek dafa chalayein (cron / Railway scheduled job).
 *
 *     php scripts/run_invoicing.php
 *
 * Mehfooz hai: ek period ka ek hi invoice banta hai, dobara chalane se
 * naya nahi banta.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

if ((string)cfg('app.role') !== 'cloud') { echo "INVOICING_SKIP cloud par hi chalti hai\n"; return; }

$r = \Aio\Services\InvoiceService::runAutoInvoicing();
echo "INVOICING_OK checked={$r['checked']} created={$r['created']}\n";
