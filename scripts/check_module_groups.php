<?php
/**
 * check_module_groups.php — sidebar aur Super Admin ki fehrist ka milan.
 *
 * Teen fehristein hain jo hamesha ek hi honi chahiyen:
 *   1. `public/shell.js`                 -> restaurant ka left menu
 *   2. `approved_ui/retail/shell.js`     -> retail ka left menu
 *   3. `src/Services/ModuleGroups.php`   -> Super Admin ka features safha
 *
 * Agar koi inhein alag kar de (naya module sirf sidebar mein daal diya,
 * ya sirf ModuleGroups mein), to Super Admin se us module ko hide karna
 * kaam nahi karega — ya ulta, hide kiya hua module menu mein baitha
 * rahega. Theek wahi bug jo customer ne pakra tha.
 *
 * Yeh script DB ke baghair chalti hai (sirf files parhti hai), is liye
 * build ke waqt bhi chal sakti hai.
 *
 *   php scripts/check_module_groups.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;

/** shell.js ke GROUPS se [title => [keys]] nikalo. */
function parseShell(string $file): array
{
    $src = (string)file_get_contents($file);
    $start = strpos($src, 'GROUPS');
    if ($start === false) { echo "  !! GROUPS not found in $file\n"; return []; }

    // GROUPS = [ ... ];  — bracket counting, kyunke andar nested arrays hain.
    $i = strpos($src, '[', $start);
    $depth = 0; $end = $i;
    for ($j = $i, $n = strlen($src); $j < $n; $j++) {
        if ($src[$j] === '[') $depth++;
        elseif ($src[$j] === ']') { $depth--; if ($depth === 0) { $end = $j; break; } }
    }
    $block = substr($src, $i, $end - $i + 1);

    // /* ... */ comments nikal do, warna un ke andar likhe keys bhi match ho jayen.
    $block = preg_replace('#/\*.*?\*/#s', '', $block);

    $out = [];
    // {title:'X',items:[ ['key',...], ... ]}
    if (preg_match_all("/title\s*:\s*'([^']+)'\s*,\s*items\s*:\s*\[(.*?)\]\s*\}/s", $block, $m, PREG_SET_ORDER)) {
        foreach ($m as $g) {
            $keys = [];
            if (preg_match_all("/\[\s*'([a-z_]+)'\s*,/", $g[2], $km))
                foreach ($km[1] as $k) if (!in_array($k, $keys, true)) $keys[] = $k;
            $out[$g[1]] = $keys;
        }
    }
    return $out;
}

require $root . '/src/Services/ModuleGroups.php';
use Aio\Services\ModuleGroups;

$targets = [
    'RESTAURANT' => $root . '/public/shell.js',
    'RETAIL'     => $root . '/approved_ui/retail/shell.js',
];

foreach ($targets as $industry => $file) {
    echo "== $industry  (" . basename(dirname($file)) . '/' . basename($file) . ")\n";
    if (!is_file($file)) { echo "  !! file missing\n"; $fail++; continue; }

    $shell = parseShell($file);
    $php   = [];
    foreach (ModuleGroups::layout($industry) as [$t, $k]) $php[$t] = $k;

    if (!$shell) { echo "  !! could not parse GROUPS\n"; $fail++; continue; }

    // 1) Headings ka naam aur tarteeb
    $sT = array_keys($shell); $pT = array_keys($php);
    if ($sT !== $pT) {
        echo "  !! group titles differ\n     shell : " . implode(' | ', $sT) . "\n     php   : " . implode(' | ', $pT) . "\n";
        $fail++;
    }

    // 2) Har heading ke andar wahi keys, wahi tarteeb
    foreach ($php as $title => $keys) {
        $have = $shell[$title] ?? null;
        if ($have === null) continue;                 // upar report ho chuka
        if ($have !== $keys) {
            echo "  !! '$title' items differ\n";
            $missShell = array_values(array_diff($keys, $have));
            $missPhp   = array_values(array_diff($have, $keys));
            if ($missShell) echo "     in php, not in sidebar : " . implode(', ', $missShell) . "\n";
            if ($missPhp)   echo "     in sidebar, not in php : " . implode(', ', $missPhp) . "\n";
            if (!$missShell && !$missPhp) echo "     same keys, different order\n";
            $fail++;
        }
    }

    $n = 0; foreach ($php as $k) $n += count($k);
    echo "  groups=" . count($php) . " modules=$n\n";
}

/* 3) Catalog ke saath milan — seed script se keys parho (DB ke baghair). */
$seed = (string)file_get_contents($root . '/scripts/seed_industry_modules.php');
function seedKeys(string $seed, string $var): array
{
    if (!preg_match('/\$' . $var . '\s*=\s*\[(.*?)\n\];/s', $seed, $m)) return [];
    preg_match_all("/'([a-z_]+)'\s*=>/", $m[1], $km);
    return $km[1];
}
$common = seedKeys($seed, 'COMMON');
$rest   = seedKeys($seed, 'RESTAURANT');
$retail = seedKeys($seed, 'RETAIL');

echo "== catalog (scripts/seed_industry_modules.php)\n";
echo '  COMMON=' . count($common) . ' RESTAURANT=' . count($rest) . ' RETAIL=' . count($retail) . "\n";

foreach (['RESTAURANT' => $rest, 'RETAIL' => $retail] as $ind => $own) {
    $catalog = array_merge($common, $own);
    $inGroups = ModuleGroups::ALWAYS_ON;
    foreach (ModuleGroups::layout($ind) as [$t, $k]) $inGroups = array_merge($inGroups, $k);

    $unreachable = array_values(array_diff($catalog, $inGroups));
    $ghost       = array_values(array_diff($inGroups, $catalog));

    if ($unreachable) {
        echo "  !! $ind: catalog mein hai magar kisi menu group mein nahi (hide/show ka koi asar nahi): "
            . implode(', ', $unreachable) . "\n";
        $fail++;
    }
    if ($ghost) {
        echo "  !! $ind: menu mein hai magar catalog mein nahi (Super Admin mein nazar hi nahi aayega): "
            . implode(', ', $ghost) . "\n";
        $fail++;
    }
    if (!$unreachable && !$ghost) echo "  $ind: catalog and menu match (" . count($catalog) . " modules)\n";
}

echo $fail ? "\nMODULE_GROUPS_FAIL problems=$fail\n" : "\nMODULE_GROUPS_OK\n";
exit($fail ? 1 : 0);
