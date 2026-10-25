<?php
namespace Aio\Services;

/**
 * UPDATE CHECK — kya cloud par naya build para hai?
 *
 * MASLA JO YEH HAL KARTI HAI
 * Node pehle bhi poochta tha ke naya build hai ya nahi (self_update.php),
 * magar us ka jawab SIRF launcher ki kaali window mein chhapta tha, aur
 * woh bhi sirf start ke waqt. V164 ke baad woh window tray mein chhup
 * jati hai — yani paigham dekhne ka imkan aur kam ho gaya. Aur jo dukan
 * hafton computer band hi nahi karti, us par check kabhi chalta hi nahi
 * tha.
 *
 * Ab yeh kaam teen jaghon se hota hai aur jawab ek hi jagah likha jata
 * hai — `updates/` ki nishani files, wahi jo pehle se thin:
 *
 *   available.txt   naya build ka naam (download NAHI hua)
 *   ready.txt       package utar chuka hai, lagane ka intezar
 *   offline.txt     portal tak pohanch hi nahi hui
 *
 *   probe()   cloud se poochta hai aur nishani likhta hai
 *             - self_update.php         (launcher, start ke 45 second baad)
 *             - sync_loop.php           (har 6 ghante, chalti dukan par bhi)
 *   status()  sirf nishani parhta hai — koi internet nahi
 *             - api.php `update-status` (POS ki screen par patti)
 *
 * JAAN BOOJH KAR: `probe()` DOWNLOAD NAHI KARTI. Woh faisla malik ka
 * hai (V90) — kisi ka internet mehnga hai, kisi ka slow. Download aur
 * install dono aaj bhi insan ke dabane par hote hain.
 */
final class UpdateCheck
{
    /** Chalti dukan par kitni der baad dobara poochna hai. */
    public const PROBE_EVERY = 21600;          // 6 ghante

    /* ============================================================
       PACKAGE KI JARH.

       SEALED node par `__DIR__` ka koi faida nahi — is file ka pata
       `sealed://src/Services/UpdateCheck.php` hota hai, aur
       `dirname(__DIR__, 2)` se `sealed://` nikalta hai, asli folder
       nahi. Is liye wahi tareeqa jo `Sync` istemal karti hai:
       sealed loader `APP_ROOT` define karta hai, aur source build par
       woh define nahi hota.

       Yeh ghalti pakar mein nahi aati: `@file_get_contents` khamoshi
       se false deta hai, aur patti chup-chaap kabhi nazar hi nahi
       aati — ya phir har waqt nazar aati rehti hai.
       ============================================================ */
    private static function root(): string
    {
        return defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
    }

    private static function dir(): string
    {
        $d = self::root().'/updates';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    /* Build ka naam. `Sync::localBuild()` pehle se yeh kaam karti hai
       aur sealed package ka khayal rakhti hai (wahan VERSION seal ke
       andar bhi hoti hai), is liye apni copy banane ka koi faida nahi. */
    private static function installed(): string
    {
        $v = trim(Sync::localBuild());
        return ($v === 'unknown') ? '' : $v;
    }

    private static function isCloud(): bool
    {
        return (string)($GLOBALS['config']['app']['role'] ?? 'local') === 'cloud';
    }

    /** Cloud ka api.php ka poora pata, ya khali string agar set hi nahi. */
    private static function apiBase(): string
    {
        $cfg  = $GLOBALS['config']['sync'] ?? [];
        $base = trim((string)($cfg['cloud_api_url'] ?? ''));
        if ($base === '' && !empty($GLOBALS['config']['app']['cloud_url'])) {
            $base = rtrim((string)$GLOBALS['config']['app']['cloud_url'], '/').'/api.php';
        }
        return $base;
    }

    /** Pehli line parho — nishani files mein ek hi line hoti hai. */
    private static function firstLine(string $file): string
    {
        $s = (string)@file_get_contents($file);
        $s = strtok($s, "\r\n");
        return trim((string)$s);
    }

    /* ============================================================
       STATUS — sirf parhna. Koi internet, koi intezar.

       `update-status` ko har page load par bulaya jata hai, is liye
       yahan ek bhi network call nahi honi chahiye — warna poori POS
       screen portal ke jawab ka intezar karti.
       ============================================================ */
    public static function status(): array
    {
        $inst = self::installed();
        $out  = ['state' => 'none', 'installed' => $inst, 'build' => '', 'checked_at' => 0];

        /* Cloud server khud update nahi hota — wahan UPDATE.bat jaisa
           kuch hai hi nahi. Is liye patti kabhi nahi dikhni chahiye. */
        if (self::isCloud()) return $out;

        /* Apna build maloom na ho to kisi bhi doosre build se muqabla
           be-maani hai — aur muqabla ghalat hone ka natija yeh hota ke
           patti HAMESHA nazar aati rehti. Khamoshi behtar hai. */
        if ($inst === '') return $out;

        $d = self::dir();

        $ready = $d.'/ready.txt';
        $avail = $d.'/available.txt';
        $off   = $d.'/offline.txt';

        if (is_file($ready)) {
            /* ready.txt mein zip ka naam hota hai: update-V165build2026-10-25.zip
               Us mein se build nikalna theek nahi (spaces hat jati hain), is
               liye asal naam available.txt se lete hain jab maujood ho. */
            $b = is_file($avail) ? self::firstLine($avail) : '';
            if ($b === '') $b = self::firstLine($ready);
            $out['state']      = 'ready';
            $out['build']      = $b;
            $out['checked_at'] = (int)@filemtime($ready);
        } elseif (is_file($avail)) {
            $out['state']      = 'available';
            $out['build']      = self::firstLine($avail);
            $out['checked_at'] = (int)@filemtime($avail);
        } elseif (is_file($off)) {
            $out['state']      = 'offline';
            $out['checked_at'] = (int)@filemtime($off);
        }

        /* ============================================================
           PURANI NISHANI KA PEHRA.

           Agar koi package HAATH se extract kar le (UPDATE.bat chalaye
           baghair), to nishani files wahin pari reh jati hain — kyunke
           unhein mitane ka kaam UPDATE.bat karta hai. Natija: POS par
           hamesha "naya version aaya hai" ki patti, jab ke woh pehle hi
           lag chuka hai.

           Is liye: jo build nishani mein likha hai aur jo chal raha hai,
           dono ek hi hon to patti nahi dikhani.
           ============================================================ */
        if (($out['state'] === 'available' || $out['state'] === 'ready')
            && $out['build'] !== '' && $out['build'] === $inst) {
            $out['state'] = 'none';
            $out['build'] = '';
        }

        /* Portal tak pohanch na hona koi khabar nahi hai — POS par is ka
           koi faida nahi, sirf shor hai. UI isay nazarandaz karti hai;
           yahan sirf diagnostics ke liye bheja ja raha hai. */
        return $out;
    }

    /* ============================================================
       PROBE — cloud se poochho. Download NAHI.

       Jawab return bhi hota hai (script usay chhapti hai) aur nishani
       files mein likh bhi diya jata hai (launcher aur POS parhte hain).
       ============================================================ */
    public static function probe(): array
    {
        if (self::isCloud()) {
            return ['ok' => false, 'state' => 'skipped', 'message' => 'this is the cloud server'];
        }

        $inst = self::installed();
        if ($inst === '') {
            /* VERSION parhi hi nahi ja saki. Aise haal mein "naya build
               hai" kehna ghalat hai — har dafa available likh dete. */
            return ['ok' => false, 'state' => 'unknown', 'installed' => '',
                    'message' => 'could not read the installed build (VERSION)'];
        }

        $base = self::apiBase();
        if ($base === '') {
            return ['ok' => false, 'state' => 'skipped', 'message' => 'no cloud url configured',
                    'installed' => $inst];
        }

        $d = self::dir();
        @unlink($d.'/available.txt');
        @unlink($d.'/offline.txt');
        /* ready.txt ko haath nahi lagate — us ka matlab hai ke package
           pehle se utar chuka hai aur lagne ka intezar kar raha hai. */

        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $raw = @file_get_contents($base.'?action=update-check', false, $ctx);

        if ($raw === false) {
            /* Launcher ko batao ke masla "koi update nahi" nahi, balki
               portal tak pohanch hi nahi hui — warna ghalat tasalli. */
            @file_put_contents($d.'/offline.txt', $base);
            return ['ok' => false, 'state' => 'offline', 'installed' => $inst,
                    'message' => 'could not reach the portal'];
        }

        $j     = json_decode((string)$raw, true);
        $there = trim((string)($j['build'] ?? ''));
        if ($there === '') {
            return ['ok' => false, 'state' => 'unknown', 'installed' => $inst,
                    'message' => 'portal did not report a build'];
        }

        if ($there === $inst) {
            return ['ok' => true, 'state' => 'none', 'installed' => $inst, 'build' => $there];
        }

        @file_put_contents($d.'/available.txt', $there);
        return ['ok' => true, 'state' => 'available', 'installed' => $inst, 'build' => $there];
    }

    /* ============================================================
       6 GHANTE KA PEHRA — sync loop isay bulata hai.

       Loop har 60 second chalta hai; har chakkar par portal se poochna
       be-maani hai (aur sasta internet bhi nahi). Is liye aakhri probe
       ka waqt ek file mein likha jata hai.

       Agar `available.txt` ya `ready.txt` pehle se para ho to dobara
       poochna bilkul fuzool hai — khabar pehle hi mil chuki hai.
       ============================================================ */
    public static function probeIfDue(): ?array
    {
        if (self::isCloud()) return null;

        $d = self::dir();
        if (is_file($d.'/available.txt') || is_file($d.'/ready.txt')) return null;

        $stamp = $d.'/last_check.txt';
        $last  = is_file($stamp) ? (int)self::firstLine($stamp) : 0;
        if ($last > 0 && (time() - $last) < self::PROBE_EVERY) return null;

        /* Pehle waqt likho, phir poochho. Agar probe mein kuch bigre to
           woh har 60 second dobara na chale. */
        @file_put_contents($stamp, (string)time());
        return self::probe();
    }
}

// build: V165 build 2026-10-25
