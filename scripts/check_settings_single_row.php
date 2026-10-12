<?php
/**
 * check_settings_single_row.php — SETTINGS KI EK HI ROW, EK HI RASTA.
 *
 * V148 ka bug yaad rakhne ke liye yeh check hai. Bug code mein ghalat
 * SQL nahi tha — DO jagah SQL thi:
 *
 *     padhne wali:  ... ORDER BY created_at DESC LIMIT 1
 *     likhne wali:  ... LIMIT 1            (koi order nahi)
 *
 * Aur `ui_records` par unique key na hone ki wajah se do row ban sakti
 * thin. Save purani row par lagta, screen nayi dikhati. Mahinon tak
 * pata na chalta — kyunke `ok=true` hi aata raha.
 *
 * YEH SCRIPT TEEN CHEEZEIN DEKHTI HAI
 *   1) Tax rates ka `ui_records/pos_settings` wala purana rasta kahin
 *      zinda na ho (sirf migration aur legacy-adopt ko ijazat hai).
 *   2) `site_settings` par har INSERT derive kiye hue id (`setting_uuid`)
 *      se ho — warna branch aur cloud ke id alag honge aur sync us row
 *      ko conflict keh kar reject kar dega.
 *   3) `site_settings` sync ki force list aur cloud ki allow-list dono
 *      mein mojood ho — warna portal par ki hui settings branch tak
 *      pohanchti hi nahi.
 *
 * Chalane ka tareeqa (server shell / Railway, ya local PC CMD):
 *     php scripts/check_settings_single_row.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$bad  = [];
$warn = [];

/** Comments nikaal do — PHP ke liye tokenizer se, baqi ke liye regex se. */
function strip_comments_for_check(string $file, string $src): string
{
    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php') {
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $out .= $t[1];
            } else $out .= $t;
        }
        return $out;
    }
    $src = preg_replace('#/\*.*?\*/#s', ' ', $src) ?? $src;
    return preg_replace('#^\s*//.*$#m', ' ', $src) ?? $src;
}

/* ---------- 1) purana pos_settings rasta ---------- */
$allowed = [
    'scripts/migrate_tax_settings.php',        // migration — isay dekhna hi hai
    'scripts/check_settings_single_row.php',   // khud yeh file
    'src/Services/SettingsService.php',        // legacy adopt + retire
];
$scan = [];
foreach (['src', 'public', 'approved_ui', 'scripts', 'views'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $ext = strtolower(pathinfo((string)$f, PATHINFO_EXTENSION));
        if (!in_array($ext, ['php','js','html'], true)) continue;
        $scan[] = (string)$f;
    }
}
foreach ($scan as $file) {
    $rel = ltrim(str_replace($root, '', $file), '/\\');
    $rel = str_replace('\\', '/', $rel);
    if (in_array($rel, $allowed, true)) continue;
    $src = (string)file_get_contents($file);
    if (strpos($src, 'pos_settings') === false) continue;
    /* Comment mein naam aana theek hai (V148 ki wajah wahan likhi hui
       hai); SQL ya API payload mein nahi. Is liye pehle comments nikaal
       dete hain — line-by-line andaza lagana ghalat nikla tha, kyunke
       multi-line comment ki beech wali line kisi nishani se shuru nahi
       hoti. */
    $code = strip_comments_for_check($file, $src);
    if (strpos($code, 'pos_settings') === false) continue;
    $bad[] = "$rel: tax ka purana `pos_settings` rasta — SettingsService::taxes()/saveTaxes() istemal karein";
}

/* ---------- 2) site_settings par likhne wala SIRF EK file ----------
   Pehle paanch files is table par apni apni SQL chalati thin. Behtari
   chaar jagah lagti aur paanchwin bhool jati — wahi purani bimari. Ab
   likhne ka ijazat-nama sirf SiteSetting.php ke paas hai. */
$writer = 'src/Services/SiteSetting.php';
$writeSql = '/\b(INSERT\s+INTO|UPDATE|REPLACE\s+INTO|DELETE\s+FROM)\s+site_settings\b/i';
foreach ($scan as $file) {
    $rel = str_replace('\\', '/', ltrim(str_replace($root, '', $file), '/\\'));
    if ($rel === $writer || $rel === 'scripts/check_settings_single_row.php') continue;
    /* Migrations ko chhoot hai — wo data move karti hain, rozana ka rasta nahi. */
    if (str_starts_with($rel, 'scripts/migrate_')) continue;
    $src = (string)file_get_contents($file);
    if (!preg_match_all($writeSql, $src, $mm, PREG_OFFSET_CAPTURE)) continue;
    foreach ($mm[0] as $hit) {
        $line = substr_count(substr($src, 0, (int)$hit[1]), "\n") + 1;
        $bad[] = "$rel line $line: `site_settings` par seedhi SQL — SiteSetting::put() istemal karein";
    }
}
/* Aur likhne wale file mein id derive hona lazmi hai. */
$w = (string)@file_get_contents($root.'/'.$writer);
if (strpos($w, 'setting_uuid(') === false) {
    $bad[] = "$writer: `setting_uuid()` nahi mila — random id wali row sync par reject hoti hai";
}

/* ---------- 3) sync ki fehristein ---------- */
$sync = (string)@file_get_contents($root.'/src/Services/Sync.php');
if (!preg_match('/\$force\s*=\s*\[(.*?)\];/s', $sync, $m) || strpos($m[1], "'site_settings'") === false) {
    $bad[] = "src/Services/Sync.php: `site_settings` force list mein nahi — purane nodes par settings sync nahi hongi";
}
$api = (string)@file_get_contents($root.'/public/api.php');
if (!preg_match('/function syncTableAllowed.*?\];/s', $api, $m2) || strpos($m2[0], "'site_settings'") === false) {
    $bad[] = "public/api.php: `site_settings` syncTableAllowed() ki list mein nahi — cloud har row ko \"not allowed\" kahega";
}
if (strpos($sync, "in_array('is_secret', \$cols, true)") === false) {
    $warn[] = "src/Services/Sync.php: `is_secret` filter nahi mila — FBR access key network par ja sakti hai";
}

/* ---------- natija ---------- */
foreach ($warn as $w) echo "  WARN  $w\n";
if ($bad) {
    foreach ($bad as $b) echo "  FAIL  $b\n";
    echo "SETTINGS_CHECK_FAILED problems=".count($bad)."\n";
    exit(1);
}
echo "SETTINGS_CHECK_OK files=".count($scan)." warnings=".count($warn)."\n";
