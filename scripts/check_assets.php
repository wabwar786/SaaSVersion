<?php
/**
 * public/ aur approved_ui/ mein ek hi naam ki files — kaunsi alag hain?
 *
 * Production ka document root `public/` hai, is liye har .js/.css SEEDHA
 * wahan se serve hoti hai; `approved_ui/` wali copy us soorat mein kabhi
 * nahi chalti. Dono jagah ek hi naam hone se yeh jaal banta hai: aap
 * `approved_ui/` wali file theek karte hain, deploy karte hain, aur kuch
 * nahi badalta — kyunke browser ko `public/` wali mil rahi hoti hai.
 *
 * Yeh script sirf batati hai ke kaunsi jodi alag ho chuki hai. Kuch
 * badalti nahi — kyunke har jodi mein "sahi" copy alag ho sakti hai
 * (misaal: shell.js public/ mein nayi hai, module.js approved_ui/ mein thi).
 *
 *   php scripts/check_assets.php
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$diff = [];
foreach (glob($root . '/public/*.{js,css}', GLOB_BRACE) as $pub) {
    $name = basename($pub);
    $alt  = $root . '/approved_ui/' . $name;
    if (!is_file($alt)) continue;
    if (md5_file($pub) === md5_file($alt)) continue;
    $diff[] = [$name, filesize($pub), filesize($alt),
               date('Y-m-d H:i', (int)filemtime($pub)), date('Y-m-d H:i', (int)filemtime($alt))];
}
if (!$diff) { echo "ASSETS_IN_SYNC — no duplicate file differs.\n"; return; }
echo "These files exist in BOTH places and differ.\n";
echo "The browser always gets the public/ copy.\n\n";
printf("  %-22s %-10s %-10s %-17s %s\n", 'file', 'public', 'approved', 'public changed', 'approved changed');
foreach ($diff as $d) printf("  %-22s %-10s %-10s %-17s %s\n", $d[0], $d[1], $d[2], $d[3], $d[4]);
echo "\nEdit the public/ copy for anything the browser must see.\n";
