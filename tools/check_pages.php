<?php
// GUARD: approved_ui ki har HTML page ke inline <script> blocks mein koi
// closing head/body/script tag literal nahi hona chahiye. Aisa literal router
// ki script-injection ko us string ke andar khainch leta hai aur page ki poori
// JS syntax-error se mar jati hai (POS v20 par exactly yehi hua tha).
declare(strict_types=1);
$dir = dirname(__DIR__).'/approved_ui';
$bad = 0; $checked = 0;
$needles = ['</head'.'>', '</body'.'>', '</scr'.'ipt>'];
foreach (glob($dir.'/*.html') as $f) {
    $html = file_get_contents($f);
    if (!preg_match_all('#<script\b[^>]*>(.*?)</scr'.'ipt>#is', $html, $m)) continue;
    $checked++;
    foreach ($m[1] as $js) {
        foreach ($needles as $n) {
            if (stripos($js, $n) !== false) {
                echo "  FAIL ".basename($f).": inline script contains literal ".htmlspecialchars($n)."\n";
                $bad++;
            }
        }
    }
}
echo $bad ? "PAGE_CHECK_FAILED files_with_scripts=$checked issues=$bad\n" : "PAGE_CHECK_OK files_with_scripts=$checked\n";
exit($bad ? 1 : 0);
