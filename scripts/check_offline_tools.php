<?php
/**
 * check_offline_tools.php — offline package ki tools\ dependencies ka check.
 *
 * MASLA JO YEH HAL KARTA HAI (V142 mein asal customer par laga):
 * `public/api.php` mein offline package banate waqt tools\*.ps1 ke naam
 * HATH SE likhe hue thay. V142 mein ek nayi file bani —
 * `tools\native_helper.ps1` — aur us list mein daalna reh gaya. Package
 * mein nayi `start_offline.ps1` chali gayi (jo us helper ko dot-source
 * karti hai) magar helper khud nahi gaya, aur customer ka POS khulna
 * band ho gaya:
 *
 *     The term 'D:\MOB\tools\native_helper.ps1' is not recognized...
 *     At D:\MOB\tools\start_offline.ps1:21 char:3
 *
 * Ab packager glob use karta hai, magar yeh script us par bharosa nahi
 * karti — woh khud parh kar dekhti hai ke:
 *   1) har .ps1 / .bat jis file ko bula raha hai, woh disk par maujood hai
 *   2) packager ki list (ya glob) us file ko waqai shamil karegi
 *
 * DB ki zaroorat nahi — build ke waqt bhi chal sakti hai.
 *
 *   php scripts/check_offline_tools.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;

/* ---------- 1) packager kya bhejta hai? ---------- */
$api = (string)@file_get_contents($root . '/public/api.php');
$packaged = [];
$mode = 'unknown';

if (preg_match("#foreach\s*\(\s*glob\(\s*\\\$root\s*\.\s*'/tools/\*\.ps1'\s*\)#", $api)) {
    $mode = 'glob';
    foreach (glob($root . '/tools/*.ps1') as $f) $packaged[] = basename($f);
} elseif (preg_match("#foreach\(\[((?:'[^']+\.ps1',?\s*)+)\]\s*as\s*\\\$ps\)#", $api, $m)) {
    $mode = 'hard-coded list';
    preg_match_all("#'([^']+\.ps1)'#", $m[1], $mm);
    $packaged = $mm[1];
} else {
    echo "!! Could not work out how public/api.php packages tools\\*.ps1\n";
    $fail++;
}

echo "packaging mode : $mode\n";
echo "ps1 in package : " . count($packaged) . "\n";
if ($mode === 'hard-coded list') {
    echo "   NOTE: a hard-coded list is how V142 broke. glob() is safer.\n";
}

$onDisk = array_map('basename', glob($root . '/tools/*.ps1'));
$notShipped = array_values(array_diff($onDisk, $packaged));
if ($notShipped) {
    echo "   on disk but NOT packaged: " . implode(', ', $notShipped) . "\n";
}

/* ---------- 2) kaun kis ko bula raha hai? ---------- */
$refs = [];   // caller => [callee.ps1, ...]
$scan = array_merge(glob($root . '/tools/*.ps1'), glob($root . '/*.bat'));
foreach ($scan as $f) {
    $txt = (string)file_get_contents($f);
    $self = basename($f);
    $hits = [];

    // . (Join-Path $PSScriptRoot 'x.ps1')   /  -File "...\tools\x.ps1"
    if (preg_match_all("#'([A-Za-z0-9_\-]+\.ps1)'#", $txt, $m1))
        foreach ($m1[1] as $h) $hits[] = $h;
    if (preg_match_all('#tools[\\\\/]([A-Za-z0-9_\-]+\.ps1)#i', $txt, $m2))
        foreach ($m2[1] as $h) $hits[] = $h;

    $hits = array_values(array_unique(array_filter($hits, fn($h) => $h !== $self)));
    if ($hits) $refs[$self] = $hits;
}

echo "\nreferences found in " . count($refs) . " file(s)\n";
foreach ($refs as $caller => $callees) {
    foreach ($callees as $c) {
        $exists   = is_file($root . '/tools/' . $c);
        $shipped  = in_array($c, $packaged, true);
        $ok       = $exists && $shipped;
        printf("  %-26s -> %-24s %s\n", $caller, $c,
            $ok ? 'ok'
               : (!$exists ? '!! FILE DOES NOT EXIST'
                           : '!! EXISTS BUT IS NOT PACKAGED'));
        if (!$ok) $fail++;
    }
}

/* ---------- 3) package mein jo .bat jati hain, unke .ps1 bhi jayen ---------- */
if (preg_match("#foreach\(\[((?:'[^']+\.bat',?\s*)+)\]\s*as\s*\\\$b\)#", $api, $mb)) {
    preg_match_all("#'([^']+\.bat)'#", $mb[1], $bb);
    echo "\nlaunchers in package: " . count($bb[1]) . "\n";
    foreach ($bb[1] as $bat) {
        if (!is_file($root . '/' . $bat)) {
            echo "  !! $bat is packaged but missing on disk\n"; $fail++; continue;
        }
        foreach (($refs[$bat] ?? []) as $c) {
            if (!in_array($c, $packaged, true)) {
                echo "  !! $bat needs tools\\$c, which is NOT in the package\n";
                $fail++;
            }
        }
    }
}

echo $fail ? "\nOFFLINE_TOOLS_FAIL problems=$fail\n" : "\nOFFLINE_TOOLS_OK\n";
exit($fail ? 1 : 0);
