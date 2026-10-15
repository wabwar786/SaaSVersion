<?php
/**
 * check_retail_wiring.php — RETAIL KI WIRING KA PEHRA.   (V154)
 *
 * KYUN BANI
 * V153 ke audit mein teen bug mile aur TEENO ki shakal ek thi: ek hi
 * fehrist DO jagah likhi hui thi, aur dono chup chaap alag ho gayin.
 *
 *   `barcode` (server)        ke muqabil  `barcodes[]` (client)
 *   `MODULE_OF` (client)      ke muqabil  RetailCatalog + ModuleBridge (server)
 *
 * Nateeja har dafa ek jaisa tha: screen khulti thi, form bharta tha,
 * "Saved" ka toast aata tha — aur peeche kuch nahi hota tha. KOI ERROR
 * NAHI. Suppliers ki list mahinon khali reh sakti thi aur kisi ko pata
 * na chalta.
 *
 * Yeh script wahi milan har deploy par kar deti hai. Teen baatein
 * dekhti hai:
 *
 *   1. har `MODULE_CONFIGS` entry ke paas `key` aur `storeKey` hon
 *   2. har screen jo router ki retail fehrist mein hai, uska koi
 *      config ya apna page mojood ho
 *   3. har module kis darje par hai — `rtl_*` asli table, bridge wali
 *      asli table, ya `ui_records` ka JSON blob
 *
 * Teesri baat FAIL nahi karti: `ui_records` par hona jaiz hai (WhatsApp
 * templates jaisi cheez ke liye kaafi hai). Magar wo deploy log mein
 * LIKHA aayega, taake "yeh module to bana hua hai" ka dhoka na ho.
 *
 * Deploy KABHI NAHI ROKTI (exit 0) — chetawni hai, deewar nahi.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use Aio\Services\RetailCatalog;
use Aio\Services\RetailDocs;
use Aio\Services\ModuleBridge;

$root   = dirname(__DIR__);
$cfgJs  = $root.'/approved_ui/retail/module_config.js';
$apiJs  = $root.'/approved_ui/retail/retail_api.js';
$router = $root.'/public/router.php';

if (!is_file($cfgJs)) { echo "RETAIL_WIRING_SKIPPED module_config.js not found\n"; return; }

$src = (string)file_get_contents($cfgJs);

/* ---------- 1. configs parho ---------- */
/* Indent 4 spaces hai, magar us par bharosa nahi karte: file kabhi
   re-format ho sakti hai aur checker khamoshi se "kuch nahi mila" keh
   kar guzar jata — jo bilkul wahi khamoshi hai jo yeh script rokne ke
   liye likhi gayi hai. */
$configs = [];
if (preg_match_all('/\n(\x20{2,8})([a-z_]+)\s*:\s*\{/', $src, $m, PREG_SET_ORDER)) {
    foreach ($m as $hit) {
        $ind  = $hit[1];
        $name = $hit[2];
        if (!preg_match('/\n'.$ind.preg_quote($name, '/').'\s*:\s*\{(.*?)\n'.$ind.'\}/s', $src, $b)) continue;
        $body = $b[1];
        preg_match("/\bkey\s*:\s*'([a-z_]+)'/", $body, $k);
        preg_match("/storeKey\s*:\s*'([a-z_]+)'/", $body, $s);
        $configs[$name] = ['key' => $k[1] ?? '', 'storeKey' => $s[1] ?? ''];
    }
}
if (!$configs) { echo "RETAIL_WIRING_SKIPPED could not read MODULE_CONFIGS\n"; return; }

/* ---------- 1b. document screens (V154) ----------
   GRN, Purchase Return, Returns, Stock Count, PO aur Wastage ab
   `doc_config.js` se bante hain aur `RetailDocs` par chalte hain. Yeh
   checker un se bekhabar rehta to wo hamesha "blob" ginay jate — yani
   deploy log mein wahi jhoot jo yeh script rokne ke liye hai. */
$docCfgJs = $root.'/approved_ui/retail/doc_config.js';
$docTypes = [];
if (is_file($docCfgJs)) {
    $dsrc = (string)file_get_contents($docCfgJs);
    if (preg_match_all("/\n\s{4}([a-z_]+)\s*:\s*\{\s*\n?\s*type\s*:\s*'([A-Z]+)'/", $dsrc, $dm, PREG_SET_ORDER)) {
        foreach ($dm as $hit) $docTypes[$hit[1]] = $hit[2];
    }
}

/* ---------- 2. client ka naqsha ab hath se likha hua to nahi? ---------- */
$problems = [];
$api = is_file($apiJs) ? (string)file_get_contents($apiJs) : '';
/* Comments nikal do: us file mein purani fehrist ki MISAAL likhi hui hai
   (taake aane wale ko pata chale masla kya tha). Us misaal par checker ka
   bharak jana khud ek jhoota alarm hai. */
$apiCode = preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $api) ?? $api;
if ($apiCode !== '' && preg_match('/var\s+MODULE_OF\s*=\s*\{/', $apiCode)) {
    $problems[] = 'retail_api.js mein haath se likhi MODULE_OF fehrist wapas aa gayi hai — '
                . 'naqsha MODULE_CONFIGS se banna chahiye (V153 ka Bug 2 isi se tha)';
}

/* ---------- 3. router ki screens ka config mojood hai? ---------- */
$screens = [];
if (is_file($router) && preg_match('/\$retailModule\s*=\s*\[(.*?)\];/s', (string)file_get_contents($router), $r)) {
    preg_match_all("/'([a-z0-9_]+\.html)'\s*=>\s*'([a-z0-9_]+)'/", $r[1], $rm, PREG_SET_ORDER);
    foreach ($rm as $hit) $screens[$hit[1]] = $hit[2];
}

/* ---------- 4. har module ka darja ---------- */
$tier = [];            // module => retail | bridge | blob
$seen = [];
foreach ($configs as $name => $c) {
    if ($c['key'] === '' || $c['storeKey'] === '') {
        $problems[] = "config `$name` mein ".($c['key'] === '' ? 'key' : 'storeKey')." nahi hai";
        continue;
    }
    $mod = $c['key'];
    if (isset($seen[$mod])) continue;
    $seen[$mod] = true;
    try {
        if (RetailCatalog::handles($mod))      $tier[$mod] = 'retail';
        elseif (ModuleBridge::handles($mod))   $tier[$mod] = 'bridge';
        else                                   $tier[$mod] = 'blob';
    } catch (\Throwable $e)                  { $tier[$mod] = 'blob'; }
}

/* storeKey -> module ka wahi naqsha jo client banata hai, taake dono
   ka natija ek hi nikle. */
$map = [];
foreach ($configs as $name => $c) {
    $sk = $c['storeKey']; $mod = $c['key'] ?: $name;
    if ($sk === '') continue;
    if (!isset($map[$sk]) || $mod === $sk) $map[$sk] = $mod;
}
/* POS ke apne raaste — yeh records-list se nahi aate. */
$special = ['customers' => 'retail-customers', 'sales' => 'rpos-sales', 'held_bills' => 'rpos-held'];
foreach ($map as $sk => $mod) {
    if (isset($special[$sk])) continue;
    if (!isset($tier[$mod])) {
        $problems[] = "storeKey `$sk` -> module `$mod`, magar us naam ka koi config nahi";
    }
}

/* ---------- 5. har screen ka config mojood hai? ----------
   File ka size dekhna ghalat paimana tha (chhoti file ka matlab adhoora
   nahi hota). Ab seedha page se poocha jata hai: wo kis config ko render
   karti hai. Jo `RetailModule.render(MODULE_CONFIGS['x'])` likhti hai,
   us ka `x` config mein hona chahiye. Baqi screens apna code chalati
   hain — un ka koi config hona hi nahi chahiye. */
foreach (glob($root.'/approved_ui/retail/*.html') as $f) {
    $page = basename($f);
    $html = (string)file_get_contents($f);
    if (preg_match("/RetailModule\.render\(\s*MODULE_CONFIGS\[\s*'([a-z_]+)'\s*\]/", $html, $hit)) {
        if (!isset($configs[$hit[1]])) {
            $problems[] = "screen `$page` `MODULE_CONFIGS['{$hit[1]}']` maangti hai magar wo config mojood nahi";
        }
        continue;
    }
    if (preg_match("/RetailDoc\.render\(\s*DOC_CONFIGS\[\s*'([a-z_]+)'\s*\]/", $html, $hit)) {
        if (!isset($docTypes[$hit[1]])) {
            $problems[] = "screen `$page` `DOC_CONFIGS['{$hit[1]}']` maangti hai magar wo config mojood nahi";
        } elseif (!\Aio\Services\RetailDocs::isType($docTypes[$hit[1]])) {
            $problems[] = "screen `$page` ka doc_type `{$docTypes[$hit[1]]}` server (RetailDocs) ko maloom nahi";
        }
    }
}

/* ---------- natija ---------- */
/* POS ke apne endpoints wale modules blob nahi hain — wo `records-list`
   se guzarte hi nahi. */
$posOwn = ['sales' => 1, 'customers' => 1];
$n = ['retail' => 0, 'bridge' => 0, 'blob' => 0];
$blobs = [];
foreach ($tier as $mod => $t) {
    if (isset($posOwn[$mod])) { $n['retail']++; continue; }
    $n[$t]++;
    if ($t === 'blob') $blobs[] = $mod;
}

if ($problems) {
    echo "RETAIL_WIRING_PROBLEMS ".count($problems)."\n";
    foreach ($problems as $p) echo "  - $p\n";
} else {
    echo "RETAIL_WIRING_OK configs=".count($configs)." docs=".count($docTypes)
       ." screens=".count($screens)
       ." real={$n['retail']}+{$n['bridge']}+".count($docTypes)." blob={$n['blob']}\n";
}
if ($blobs) {
    sort($blobs);
    echo "  ui_records par (jaiz hai, magar in ka koi stock/hisaab se taalluq nahi): "
       . implode(', ', $blobs)."\n";
}

// build: V154 build 2026-10-13
