<?php
/**
 * run_migrations.php — `migration_order.php` ki saari migrations chalata hai.
 *
 * Cloud ka `docker-entrypoint.sh` aur offline ka `tools/update.ps1` DONO
 * isay bulate hain, taake dono taraf bilkul wahi schema bane. Pehle offline
 * sirf 5 migrations chalata tha aur cloud 30+ — isi farq se offline customer
 * ka software update ho kar toot jata tha.
 *
 * Chalane ka tareeqa:
 *   php scripts/run_migrations.php              # sab
 *   php scripts/run_migrations.php --node       # offline node (cloud_only skip)
 *   php scripts/run_migrations.php --quiet      # sirf khulasa
 *   php scripts/run_migrations.php --list       # kuch chalaye baghair fehrist
 *
 * Exit: 0 = sab theek (ya sirf optional steps nakaam), 1 = koi LAZMI step nakaam.
 *
 * Idempotent: baar baar chal sakti hai. Har migration khud bhi idempotent hai.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

/* Sealed build mein $argv nahi hota — bootstrap ka cli_args() use hota hai,
   magar yeh script bootstrap se pehle bhi list dikha sakni chahiye. */
$argvSafe = $GLOBALS['argv'] ?? [];
$flag = function (string $n) use ($argvSafe): bool {
    foreach ($argvSafe as $a) if ($a === '--' . $n) return true;
    if (\function_exists('cli_args')) { $a = cli_args(); return isset($a[$n]); }
    return false;
};

$order = require $root . '/scripts/migration_order.php';
$isNode  = $flag('node');
$quiet   = $flag('quiet');
$listOnly= $flag('list');

/* --stage=boot (default) | background | all
   Cloud Apache se pehle sirf `boot` chalata hai aur `background` baad mein,
   warna bhaari migration healthcheck ko timeout kar deti hai (502). Offline
   node par yeh majboori nahi, is liye wo `all` chalata hai. */
$stage = 'boot';
foreach ($argvSafe as $a) {
    if (\strpos($a, '--stage=') === 0) $stage = \substr($a, 8);
}
if (\function_exists('cli_args')) { $ca = cli_args(); if (!empty($ca['stage'])) $stage = (string)$ca['stage']; }
if (!\in_array($stage, ['boot', 'background', 'all'], true)) $stage = 'boot';

/* ------------------------------------------------------------
   DRIFT CHECK — koi migration disk par ho magar fehrist mein na ho.

   Yehi woh soorat hai jisne `native_helper.ps1` wala bug paida kiya tha:
   nayi file bani, list mein daalna bhool gaye, aur kisi ko pata na chala.
   Ab woh khamoshi se nahi guzar sakti.
   ------------------------------------------------------------ */
$listed = [];
foreach ($order as $s) $listed[$s['script']] = true;

$onDisk = [];
foreach (\glob($root . '/scripts/migrate_*.php') as $f) $onDisk[] = \basename($f, '.php');

/* Yeh jaan bujh kar fehrist se bahar hain — inhen drift na samjha jaye. */
$deliberate = [
    'migrate_dl_tickets',   // kabhi istemal nahi hui
];

$missing = \array_values(\array_diff($onDisk, \array_keys($listed), $deliberate));
if ($missing) {
    echo "!! MIGRATION_NOT_LISTED: " . \implode(', ', $missing) . "\n";
    echo "   Yeh scripts/ mein mojood hain magar migration_order.php mein nahi.\n";
    echo "   Yani cloud par chalengi na offline par. Fehrist mein daalein.\n";
}

if ($listOnly) {
    foreach ($order as $i => $s) {
        $tags = [];
        if (!empty($s['optional']))   $tags[] = 'optional';
        if (!empty($s['cloud_only'])) $tags[] = 'cloud-only';
        if (!empty($s['check']))      $tags[] = 'check';
        if (($s['stage'] ?? 'boot') !== 'boot') $tags[] = 'stage:' . $s['stage'];
        \printf("  %2d. %-28s %s\n", $i + 1, $s['script'], $tags ? '(' . \implode(', ', $tags) . ')' : '');
    }
    echo "\nTotal: " . \count($order) . "\n";
    return;
}

/* ------------------------------------------------------------
   Har step ALAG process mein.

   Ek hi process mein chalane se do masle thay: (a) ek script ka fatal
   poori qatar ko le doobta tha, (b) `declare(strict_types=1)` aur
   dobara-dobara `require` aapas mein takrate the. Alag process mein har
   step apne paanv par khara hai.
   ------------------------------------------------------------ */
$sealed = \is_file($root . '/runtime/app.sealed');
$php    = \PHP_BINARY;
$ini    = \php_ini_loaded_file();

$ok = 0; $skipped = 0; $failed = 0; $failedNames = [];

foreach ($order as $step) {
    $name = $step['script'];

    if ($isNode && !empty($step['cloud_only'])) { $skipped++; continue; }

    $stepStage = (string)($step['stage'] ?? 'boot');
    if ($stage !== 'all' && $stepStage !== $stage) { continue; }

    $file = $root . '/scripts/' . $name . '.php';
    if (!\is_file($file)) {
        if (empty($step['optional'])) {
            echo "  !! $name — file not found\n";
            $failed++; $failedNames[] = $name;
        } else { $skipped++; }
        continue;
    }

    $cmd = \escapeshellarg($php);
    if ($ini) $cmd .= ' -c ' . \escapeshellarg($ini);
    if ($sealed) {
        /* Offline: asli script seal ke andar hai, disk wali sirf stub hai.
           Stub ko chalana hi theek hai — woh khud seal se asli chalata hai. */
        $cmd .= ' ' . \escapeshellarg($file);
    } else {
        $cmd .= ' ' . \escapeshellarg($file);
    }
    /* `--node` SIRF is runner ke liye hai (cloud_only steps skip karne ko).
       Pehle yeh har child script ko bhi bheja ja raha tha aur
       `install_schema.php` usay schema file ka naam samajh kar
       "Schema file not found: --node" de kar mar jati thi. */

    $out = []; $rc = 0;
    @\exec($cmd . ' 2>&1', $out, $rc);
    $tail = \trim(\implode("\n", \array_slice($out, -2)));

    if ($rc === 0) {
        $ok++;
        if (!$quiet) \printf("  ok   %-28s %s\n", $name, \substr(\str_replace("\n", ' | ', $tail), 0, 70));
    } elseif (!empty($step['optional'])) {
        $skipped++;
        if (!$quiet) \printf("  skip %-28s %s\n", $name, \substr(\str_replace("\n", ' | ', $tail), 0, 70));
    } else {
        $failed++; $failedNames[] = $name;
        \printf("  FAIL %-28s %s\n", $name, \substr(\str_replace("\n", ' | ', $tail), 0, 90));
    }
}

echo "\nMIGRATIONS_DONE stage=$stage ok=$ok skipped=$skipped failed=$failed\n";
if ($failed) {
    echo "FAILED: " . \implode(', ', $failedNames) . "\n";
    exit(1);
}
echo "MIGRATIONS_OK\n";
exit(0);
