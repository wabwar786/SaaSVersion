<?php
/**
 * build_update_patch.php — UPDATE_PATCH zip banata hai.
 *
 * KIS KAAM KA:
 * Purane offline installs par purani `INSTALL_UPDATE.bat` hai, jo sirf
 * PAANCH migrations chalati hai aur khud ko chalte hue overwrite kar leti
 * hai. Un customers ko poora offline version DOBARA bhejna parta — wahi
 * cheez jis se bachna hai.
 *
 * Is ke bajaye: yeh chhota zip. Customer isay apne purane software ke
 * folder mein copy kare (sirf 6 files), phir UPDATE.bat. Us ke baad har
 * update khud ba khud GET_UPDATE / UPDATE se aata rahega — offline
 * version dobara download karne ki zaroorat kabhi nahi.
 *
 * AHEM — is mein SIRF wo files ja sakti hain jo node par DISK par hoti
 * hain. `src/`, `scripts/` aur `approved_ui/` seal (`runtime/app.sealed`)
 * ke andar hote hain; unhen alag se copy karna be-faida hai. Isi liye
 * `run_migrations.php` yahan NAHI hai — woh seal ke sath aayega, aur
 * `update.ps1` mein purane seal ke liye fallback mojood hai.
 *
 *   php tools/build_update_patch.php [out.zip]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$out  = $argv[1] ?? ($root . '/UPDATE_PATCH.zip');
$ver  = trim((string)@file_get_contents($root . '/VERSION'));

/* Node par disk par rehne wali files — sirf yehi patch ho sakti hain. */
$files = [
    'UPDATE.bat'              => 'UPDATE.bat',
    'GET_UPDATE.bat'          => 'GET_UPDATE.bat',
    'INSTALL_UPDATE.bat'      => 'INSTALL_UPDATE.bat',
    'tools/update.ps1'        => 'tools/update.ps1',
    'tools/native_helper.ps1' => 'tools/native_helper.ps1',
];

$missing = [];
foreach ($files as $src => $dst) if (!is_file($root . '/' . $src)) $missing[] = $src;
if ($missing) {
    fwrite(STDERR, "PATCH_FAIL missing: " . implode(', ', $missing) . "\n");
    exit(1);
}

/* Sanity: `.bat` sirf stub hona chahiye (saara kaam update.ps1 karti hai).
   Agar kisi .bat mein phir se logic aa gayi to woh khud ko overwrite karne
   wala purana masla wapas le aayegi. */
foreach (['UPDATE.bat', 'GET_UPDATE.bat', 'INSTALL_UPDATE.bat'] as $b) {
    $t = (string)file_get_contents($root . '/' . $b);
    if (!str_contains($t, 'tools\\update.ps1')) {
        fwrite(STDERR, "PATCH_FAIL $b does not call tools\\update.ps1\n"); exit(1);
    }
    if (preg_match('/\bphp\b.*-r\b/i', $t)) {
        fwrite(STDERR, "PATCH_FAIL $b has logic in it; keep it a stub\n"); exit(1);
    }
}

@unlink($out);
$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "PATCH_FAIL could not create $out\n"); exit(1);
}
foreach ($files as $src => $dst) $zip->addFile($root . '/' . $src, $dst);

$zip->addFromString('READ_ME_FIRST.txt', <<<TXT
============================================================
 UPDATE PATCH  —  $ver
 Wabwar Software House
============================================================

YEH KIS LIYE HAI
----------------
Is ke baad aap ka software har naya update KHUD le aayega.
Offline version dobara download karne ki zaroorat kabhi nahi paregi.

Yeh patch SIRF EK DAFA lagana hai.


LAGANE KA TAREEQA  (2 minute)
-----------------------------
1) Software band kar dein (agar khula hai).

2) Is zip ki SAARI files apne software ke folder mein copy karein —
   wahi folder jahan START_OFFLINE.bat hai (misal  D:\\MOB ).

   Windows poochega "Replace the files in the destination?"
   -> REPLACE / "Replace the files" chunein.

   Copy hone wali files:
       UPDATE.bat
       GET_UPDATE.bat
       INSTALL_UPDATE.bat
       tools\\update.ps1
       tools\\native_helper.ps1

3) UPDATE.bat par DOUBLE-CLICK karein.

   Wo khud: naya build check karega -> download karega ->
   purani files ka backup lega -> nayi files lagayega ->
   database update karega.

4) Khatam. START_OFFLINE.bat se software kholein.


AAGE SE
--------
Jab bhi naya update ho, bas UPDATE.bat chalayein. Bas.

  UPDATE.bat          = sab kuch ek click mein (yehi istemal karein)
  GET_UPDATE.bat      = sirf download (install baad mein)
  INSTALL_UPDATE.bat  = jo download ho chuka hai wo lagana


AAP KA DATA MEHFOOZ REHTA HAI
------------------------------
Sirf program ki files badalti hain. Yeh NAHI chhuee jatin:
    data\\       (aap ka database)
    config\\     (aap ki settings)
    storage\\    (logs, backups)
    runtime\\php, runtime\\mariadb

Har update se pehle purani files  backup\\before-<date>  mein
mehfooz ho jati hain.


MASLA AAYE TO
-------------
Software ke folder mein, Command Prompt (CMD) kholein aur chalayein:

    powershell -NoProfile -ExecutionPolicy Bypass -File tools\\diagnose.ps1

Us ka output humein bhej dein.

------------------------------------------------------------
 Wabwar Software House   www.wabwar.pk   0342-5095104
------------------------------------------------------------
TXT);

$zip->close();

$n = 0; $sz = 0;
$z2 = new ZipArchive(); $z2->open($out);
$n = $z2->numFiles; $z2->close();
$sz = (int)filesize($out);

echo "PATCH_OK $out\n";
echo "  build : $ver\n";
echo "  files : $n\n";
echo "  size  : " . round($sz / 1024, 1) . " KB\n";
