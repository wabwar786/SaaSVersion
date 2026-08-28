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

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[strtolower($m[1])] = $m[2] ?? '1';
}
$doDownload = isset($args['download']);

if ((string)cfg('app.role') === 'cloud') {
    echo "UPDATE_SKIPPED this is the cloud server\n";
    return;
}

$here = trim((string)@file_get_contents(dirname(__DIR__).'/VERSION'));
$cfg  = $GLOBALS['config']['sync'] ?? [];
$base = (string)($cfg['cloud_api_url'] ?? '');
if ($base === '' && !empty($GLOBALS['config']['app']['cloud_url'])) {
    $base = rtrim((string)$GLOBALS['config']['app']['cloud_url'], '/').'/api.php';
}
if ($base === '') { echo "UPDATE_SKIPPED no cloud url configured\n"; return; }

$ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
$raw = @file_get_contents($base.'?action=update-check', false, $ctx);
if ($raw === false) { echo "UPDATE_OFFLINE could not reach the portal\n"; return; }

$j = json_decode((string)$raw, true);
$there = trim((string)($j['build'] ?? ''));
if ($there === '') { echo "UPDATE_UNKNOWN portal did not report a build\n"; return; }

echo "installed: $here\n";
echo "available: $there\n";

if ($here === $there) { echo "UPDATE_NONE already up to date\n"; return; }
echo "UPDATE_AVAILABLE $there\n";

$dir = dirname(__DIR__).'/updates';
@mkdir($dir, 0775, true);
@file_put_contents($dir.'/available.txt', $there);

if (!$doDownload) { echo "Run with --download to fetch it.\n"; return; }

/* Package sirf logged-in user ke liye milta hai, is liye sync token se
   maangte hain — wahi token jo har roz sync ke liye use hota hai. */
$tok = (string)($cfg['node_token'] ?? $cfg['token'] ?? '');
$url = $base.'?action=offline-package'.($tok !== '' ? ('&node_token='.rawurlencode($tok)) : '');
$tmp = $dir.'/update-'.preg_replace('/[^0-9A-Za-z._-]/','',$there).'.zip';

$in = @fopen($url, 'rb', false, $ctx);
if (!$in) { echo "UPDATE_FAILED could not download the package\n"; return; }
$out = @fopen($tmp, 'wb');
if (!$out) { @fclose($in); echo "UPDATE_FAILED could not write to updates/\n"; return; }
$n = 0;
while (!feof($in)) { $b = fread($in, 65536); if ($b === false) break; $n += fwrite($out, $b); }
fclose($in); fclose($out);

/* ZIP hai bhi ya error page? Aadhi file rakh dena sab se bura hai. */
if ($n < 50000 || substr((string)@file_get_contents($tmp, false, null, 0, 2), 0, 2) !== 'PK') {
    @unlink($tmp);
    echo "UPDATE_FAILED the portal did not return a package (check that this node is still linked)\n";
    return;
}
@file_put_contents($dir.'/ready.txt', basename($tmp));
echo "UPDATE_DOWNLOADED ".basename($tmp)." (".round($n/1048576,1)." MB)\n";
echo "Close the software and run INSTALL_UPDATE.bat to apply it.\n";

// build: V75 build 2026-08-28
