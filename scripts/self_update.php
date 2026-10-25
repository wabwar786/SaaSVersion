<?php
/**
 * self_update.php — branch computer khud naya build le aata hai.
 *
 * MASLA: har chhoti tabdeeli par customer ko portal se poora offline
 * package download kar ke dobara install karna parta tha. Har baar.
 *
 * Ab: yeh script cloud se poochti hai ke naya build hai ya nahi. Naya
 * ho to package download kar ke `updates/` mein rakh deti hai aur
 * launcher agle start par ek line dikha deta hai. Install phir bhi
 * user ke DABANE par hota hai — chalte POS ko beech mein badalna
 * kabhi theek nahi.
 *
 *   php scripts/self_update.php            # sirf check
 *   php scripts/self_update.php --download # naya ho to le bhi aao
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use Aio\Services\Sync;
use Aio\Services\UpdateCheck;

/* V84 — sealed build mein $argv maujood nahi hota. */
$args = cli_args();
$doDownload = isset($args['download']);

/* ============================================================
   V165 — YEH FUNCTION KAHIN DEFINE HI NAHI THA.

   Neeche teen jaghon par `upderr(...)` bulaya jata hai — download
   na ho sake, disk bhari ho, ya portal ZIP ki jagah error page bhej
   de. Poore code mein is naam ka koi function maujood nahi tha, is
   liye in teen soorton mein PHP wahin mar jata tha:

       PHP Fatal error: Call to undefined function upderr()

   Yani jo "UPDATE_FAILED ..." ka paigham user ko wajah batane ke
   liye likha gaya tha, woh kabhi chhapta hi nahi tha. User ko sirf
   ek fatal error nazar aata tha.

   Ab: wajah `storage/logs/update.log` mein likhi jati hai aur neeche
   wala paigham theek se chhapta hai.
   ============================================================ */
if (!function_exists('upderr')) {
    function upderr(string $why): void
    {
        $f = dirname(__DIR__).'/storage/logs/update.log';
        @mkdir(dirname($f), 0775, true);
        @file_put_contents($f, date('Y-m-d H:i:s').'  '.$why."\n", FILE_APPEND);
    }
}

/* ============================================================
   V165 — poochhne ka kaam ab UpdateCheck service karti hai.

   Wajah: wahi sawal teen jaghon se poocha jata hai — yeh script
   (launcher ke start par), sync loop (har 6 ghante, chalti dukan par),
   aur POS ki patti. Teen copies rakhne ka matlab tha ke kal kisi ek
   mein tabdeeli ho aur baqi do purani reh jayen.

   Yahan sirf DOWNLOAD ka hissa reh gaya hai — kyunke download sirf
   yahin se hota hai, aur sirf `--download` ke sath.
   ============================================================ */
$r     = UpdateCheck::probe();
$here  = (string)($r['installed'] ?? '');
$there = (string)($r['build'] ?? '');

switch ((string)$r['state']) {
    case 'skipped':
        echo "UPDATE_SKIPPED ".(string)($r['message'] ?? '')."\n"; return;
    case 'offline':
        echo "UPDATE_OFFLINE could not reach the portal\n"; return;
    case 'unknown':
        echo "UPDATE_UNKNOWN portal did not report a build\n"; return;
    case 'none':
        echo "installed: $here\n";
        echo "available: $there\n";
        echo "UPDATE_NONE already up to date\n"; return;
}

echo "installed: $here\n";
echo "available: $there\n";
echo "UPDATE_AVAILABLE $there\n";

$cfg = $GLOBALS['config']['sync'] ?? [];
$dir = dirname(__DIR__).'/updates';
$base = trim((string)($cfg['cloud_api_url'] ?? ''));
if ($base === '' && !empty($GLOBALS['config']['app']['cloud_url'])) {
    $base = rtrim((string)$GLOBALS['config']['app']['cloud_url'], '/').'/api.php';
}

if (!$doDownload) { echo "Run with --download to fetch it.\n"; return; }

$ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);

/* Package sirf logged-in user ke liye milta hai, is liye sync token se
   maangte hain — wahi token jo har roz sync ke liye use hota hai. */
$tok = (string)($cfg['node_token'] ?? $cfg['token'] ?? '');
$url = $base.'?action=offline-package'.($tok !== '' ? ('&node_token='.rawurlencode($tok)) : '');
$tmp = $dir.'/update-'.preg_replace('/[^0-9A-Za-z._-]/','',$there).'.zip';

$in = @fopen($url, 'rb', false, $ctx);
if (!$in) { upderr("could not download the package"); echo "UPDATE_FAILED could not download the package\n"; return; }
$out = @fopen($tmp, 'wb');
if (!$out) { @fclose($in); upderr("could not write to updates/ (disk full or read-only?)"); echo "UPDATE_FAILED could not write to updates/\n"; return; }
$n = 0;
while (!feof($in)) { $b = fread($in, 65536); if ($b === false) break; $n += fwrite($out, $b); }
fclose($in); fclose($out);

/* ZIP hai bhi ya error page? Aadhi file rakh dena sab se bura hai. */
if ($n < 50000 || substr((string)@file_get_contents($tmp, false, null, 0, 2), 0, 2) !== 'PK') {
    @unlink($tmp);
    upderr("the portal did not return a package - node may no longer be linked");
    echo "UPDATE_FAILED the portal did not return a package (check that this node is still linked)\n";
    return;
}
@file_put_contents($dir.'/ready.txt', basename($tmp));
echo "UPDATE_DOWNLOADED ".basename($tmp)." (".round($n/1048576,1)." MB)\n";
echo "Stop SmartPOS (tray icon -> Stop), then run UPDATE.bat to apply it.\n";

// build: V165 build 2026-10-25
