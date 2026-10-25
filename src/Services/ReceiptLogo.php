<?php
namespace Aio\Services;

/**
 * ReceiptLogo — business ka logo thermal bill par.
 *
 * MASLA
 * Logo `tenants.logo_url` mein ek data URL (PNG/JPG) ki shakl mein para
 * hota hai — wahi jo Super Admin ki Branding screen se upload hota hai.
 * Magar bill ka PDF engine sirf matn aur rectangles chhapta hai; us mein
 * tasveer daalne ka koi rasta tha hi nahi.
 *
 * HAL
 * Logo ko ek dafa 1-bit (kaala/safed) bitmap mein badal lo, aur PDF mein
 * usay RECTANGLES se chhapo — bilkul waise jaise QR chhapta hai. Faida:
 *   • koi nayi dependency nahi, koi PDF image object nahi
 *   • thermal printer waise bhi sirf kaala chhapta hai, is liye 1-bit
 *     mein kuch "khota" nahi — yehi us ki asli shakl hai
 *   • har row ke lagatar kaale pixel EK rectangle bante hain
 *     (run-length), warna ek logo hazaron rectangles bana deta
 *
 * GD NA HO TO BILL PHIR BHI CHHAPTA HAI. Offline node ke bundled PHP
 * mein GD na ho to yeh `null` lauta deta hai aur bill bina logo ke nikal
 * jata hai — bill rokna kisi soorat qabool nahi.
 *
 * CACHE. Logo ek dafa badalta hai, bill hazaron dafa chhapte hain. Natija
 * `storage/tmp` mein logo ke hash par mehfooz hota hai, is liye har bill
 * par dobara mehnat nahi hoti.
 */
final class ReceiptLogo
{
    /** Bill par logo ki hadd — 80mm roll par itna hi theek baithta hai. */
    private const MAX_W = 200;
    private const MAX_H = 70;

    /** Is se chhota (ya khali) logo chhapne ke qabil nahi. */
    private const MIN_INK = 20;

    /**
     * @return array{w:int,h:int,rows:array<int,array<int,int>>}|null
     */
    public static function bitmap(?string $dataUrl, bool $dither = false): ?array
    {
        $dataUrl = \trim((string)$dataUrl);
        if ($dataUrl === '') return null;

        $cacheFile = self::cachePath($dataUrl, $dither);
        if ($cacheFile !== null && \is_file($cacheFile)) {
            $j = \json_decode((string)@\file_get_contents($cacheFile), true);
            if (\is_array($j) && !empty($j['rows'])) return $j;
        }

        $bm = self::build($dataUrl, $dither);

        if ($cacheFile !== null) {
            $dir = \dirname($cacheFile);
            if (!\is_dir($dir)) @\mkdir($dir, 0775, true);
            /* null bhi cache hota hai (khali file), warna har bill par
               dobara koshish hoti rahegi. */
            @\file_put_contents($cacheFile, $bm === null ? '{}' : \json_encode($bm));
        }
        return $bm;
    }

    /**
     * Is business ka logo (data URL) — `tenants.logo_url` se.
     *
     * Wahi logo jo Super Admin ki Branding screen se lagta hai; bill ke
     * liye koi alag jagah nahi banai. Ek request mein ek hi dafa parha
     * jata hai.
     */
    public static function tenantLogo(): string
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = '';
        try {
            $q = \Aio\DB::pdo()->prepare("SELECT logo_url FROM tenants WHERE id=? LIMIT 1");
            $q->execute([tenant_id()]);
            $cache = (string)($q->fetchColumn() ?: '');
        } catch (\Throwable $e) { $cache = ''; }
        return $cache;
    }

    private static function cachePath(string $dataUrl, bool $dither): ?string
    {
        try {
            $root = \dirname(__DIR__, 2);
            return $root . '/storage/tmp/logo_' . \sha1($dataUrl) . ($dither ? '_d' : '') . '.json';
        } catch (\Throwable $e) { return null; }
    }

    /**
     * @return array{w:int,h:int,rows:array<int,array<int,int>>}|null
     */
    private static function build(string $dataUrl, bool $dither): ?array
    {
        /* GD na ho to chhoR do — bill bina logo ke chhapega. */
        if (!\function_exists('imagecreatefromstring')) return null;
        if (!\preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $dataUrl)) return null;

        $comma = \strpos($dataUrl, ',');
        if ($comma === false) return null;
        $raw = \base64_decode(\substr($dataUrl, $comma + 1), true);
        if ($raw === false || $raw === '') return null;

        $src = @\imagecreatefromstring($raw);
        if (!$src) return null;

        $sw = \imagesx($src); $sh = \imagesy($src);
        if ($sw < 1 || $sh < 1) { \imagedestroy($src); return null; }

        /* Nisbat qayam rakho; bara karna kabhi nahi (scale 1.0 se ooper nahi). */
        $scale = \min(self::MAX_W / $sw, self::MAX_H / $sh, 1.0);
        $tw = \max(1, (int)\round($sw * $scale));
        $th = \max(1, (int)\round($sh * $scale));

        $dst = \imagecreatetruecolor($tw, $th);
        $white = \imagecolorallocate($dst, 255, 255, 255);
        \imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
        /* Shaffaf (transparent) hissa safed ban jata hai — thermal par
           waise bhi "kuch na chhapna" safed hi hai. */
        \imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
        \imagedestroy($src);

        /* 4x4 Bayer — sirf dither wali soorat mein. Aam (line-art) logo par
           saada threshold behtar hai: lambe run bante hain, is liye PDF
           mein rectangles kai guna kam. */
        static $bayer = [[0,8,2,10],[12,4,14,6],[3,11,1,9],[15,7,13,5]];

        $rows = []; $ink = 0;
        for ($y = 0; $y < $th; $y++) {
            $row = [];
            for ($x = 0; $x < $tw; $x++) {
                $c = \imagecolorat($dst, $x, $y);
                $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
                $lum = 0.299*$r + 0.587*$g + 0.114*$b;
                $t   = $dither ? ($bayer[$y % 4][$x % 4] + 0.5) * (255 / 16) : 170.0;
                $on  = $lum < $t ? 1 : 0;
                $row[] = $on;
                $ink  += $on;
            }
            $rows[] = $row;
        }
        \imagedestroy($dst);

        if ($ink < self::MIN_INK) return null;   /* bilkul khali / safed nikla */
        return ['w' => $tw, 'h' => $th, 'rows' => $rows];
    }
}

// build: V163 build 2026-10-25
