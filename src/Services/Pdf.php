<?php
namespace Aio\Services;

/**
 * Pdf — chhota, dependency-free PDF writer (sirf text receipts ke liye).
 * Koi composer package nahi is required; Railway par as-is chalta hai.
 * 80mm thermal-ish page: 226pt chaura, content ke hisab se lamba.
 */
final class Pdf
{
    /** Courier monospace: har character ki chaurai = 0.6 x font size. */
    public const CW = 0.6;

    /**
     * @param array $lines  har line: [text, align(l|c|r|b), size]
     *   ya in mein se koi ek:
     *     ['@qr',   'c', 0, matrix]            QR, beech mein
     *     ['@rule', thickness]                 THOS baareek lakeer
     *     ['@gap',  points]                    khali jagah
     *     ['@band', bayan, dayan, size]        bhara hua kaala band, safed likhai
     *     ['@boxstart'] ... ['@boxend']        beech ki lines ke gird frame
     *     ['@img',  bitmap, heightPt]          1-bit tasveer (logo), beech mein
     *     ['@qrleft', matrix, sidePt]          QR bayen, agli lines daayen
     *     ['@qrend']                           QR ke bayen wala hissa khatam
     *
     * V163 — yeh saari nayi cheezein RECTANGLES se banti hain, wahi primitive
     * jo QR pehle se use karta hai. Koi nayi dependency nahi, koi PDF image
     * object nahi — thermal printer par rectangles hi sab se saaf chhapte hain.
     */
    public static function receipt(array $lines, float $width = 226.0): string
    {
        $margin = 12.0; $lh = 13.0;

        /* Height pehle naapo — QR aur logo kai lines ki jagah lete hain. */
        $height = $margin * 2 + 16;
        foreach ($lines as $ln) {
            $k = (string)($ln[0] ?? '');
            if ($k === '@qr')          $height += self::qrSide($width, $margin) + 8;
            elseif ($k === '@rule')    $height += 7;
            elseif ($k === '@gap')     $height += (float)($ln[1] ?? 6);
            elseif ($k === '@band')    $height += $lh + 10;
            elseif ($k === '@img')     $height += (float)($ln[2] ?? 34) + 8;
            elseif ($k === '@qrleft')  $height += 24;
            elseif ($k === '@boxstart' || $k === '@boxend' || $k === '@qrend') { /* jagah nahi lete */ }
            else                       $height += $lh;
        }

        $text = "BT\n"; $draw = '';
        $y = $height - $margin - 10;
        $boxTop = null;     /* frame ka upari kinara */
        $indent = 0.0;      /* @qrleft ke baad agli lines ka khiskao */
        $qrBottom = null;

        foreach ($lines as $ln) {
            /* ---- QR ---- */
            if (($ln[0] ?? '') === '@qr') {
                $matrix = $ln[3] ?? null;
                $side = self::qrSide($width, $margin);
                if (is_array($matrix) && $matrix) {
                    $n   = count($matrix);
                    $mod = $side / $n;
                    $x0  = ($width - $side) / 2;
                    $y0  = $y - $side + $lh;
                    /* Har dark module ek murabba. Vector hai, is liye
                       thermal printer par bhi saaf chhapta hai. */
                    $draw .= "0 g\n";
                    for ($r = 0; $r < $n; $r++) {
                        for ($c = 0; $c < $n; $c++) {
                            if (empty($matrix[$r][$c])) continue;
                            $draw .= sprintf("%.2f %.2f %.2f %.2f re f\n",
                                $x0 + $c * $mod,
                                $y0 + ($n - 1 - $r) * $mod,
                                $mod + 0.15, $mod + 0.15);   // hairline overlap
                        }
                    }
                }
                $y -= $side + 8;
                continue;
            }

            $k = (string)($ln[0] ?? '');

            /* ---- THOS lakeer ----
               Apni jagah leti hai: upar wali line ke descender se neeche,
               aur agli line ke sar se upar. Seedha $y par daalne se yeh
               theek agli line ke matn par se guzar jati thi. */
            if ($k === '@rule') {
                $th = (float)($ln[1] ?? 0.6);
                $y -= 2;
                $draw .= sprintf("0 g\n%.2f %.2f %.2f %.2f re f\n",
                                 $margin, $y + $lh - 4, $width - 2*$margin, $th);
                $y -= 5;
                continue;
            }

            if ($k === '@gap') { $y -= (float)($ln[1] ?? 6); continue; }

            /* ---- frame (item table ke gird) ---- */
            if ($k === '@boxstart') { $boxTop = $y + $lh - 3; continue; }
            if ($k === '@boxend') {
                if ($boxTop !== null) {
                    $bot = $y + $lh - 3;
                    $x0 = $margin; $x1 = $width - $margin; $t2 = 0.6;
                    $draw .= sprintf("0 g\n%.2f %.2f %.2f %.2f re f\n", $x0, $bot, $x1-$x0, $t2);
                    $draw .= sprintf("%.2f %.2f %.2f %.2f re f\n", $x0, $boxTop, $x1-$x0, $t2);
                    $draw .= sprintf("%.2f %.2f %.2f %.2f re f\n", $x0, $bot, $t2, $boxTop-$bot);
                    $draw .= sprintf("%.2f %.2f %.2f %.2f re f\n", $x1-$t2, $bot, $t2, $boxTop-$bot);
                    $boxTop = null;
                }
                continue;
            }

            /* ---- bhara hua band: safed likhai kaale par ---- */
            if ($k === '@band') {
                $tl   = (string)($ln[1] ?? '');
                $tr   = (string)($ln[2] ?? '');
                $size = (int)($ln[3] ?? 12);
                $y -= 4;
                $bh = $lh + 2;
                $draw .= sprintf("0 g\n%.2f %.2f %.2f %.2f re f\n",
                                 $margin, $y - 3, $width - 2*$margin, $bh);
                $base = $y + 1;
                $text .= sprintf("1 g\n/F2 %d Tf\n1 0 0 1 %.2f %.2f Tm\n(%s) Tj\n",
                                 $size, $margin + 5, $base, self::esc($tl));
                if ($tr !== '') {
                    $wr = $size * self::CW * strlen($tr);
                    $text .= sprintf("1 0 0 1 %.2f %.2f Tm\n(%s) Tj\n",
                                     $width - $margin - 5 - $wr, $base, self::esc($tr));
                }
                $text .= "0 g\n";
                $y -= $bh + 4;
                continue;
            }

            /* ---- 1-bit tasveer (logo) ----
               Har row ke lagatar kaale pixel EK rectangle ban jate hain
               (run-length), warna ek logo hazaron rectangles bana deta. */
            if ($k === '@img') {
                $bm = $ln[1] ?? null;
                $ph = (float)($ln[2] ?? 34);
                if (!is_array($bm) || empty($bm['rows'])) continue;
                $bw = (int)($bm['w'] ?? 0); $bh2 = (int)($bm['h'] ?? 0);
                if ($bw < 1 || $bh2 < 1) continue;
                $mod = min($ph / $bh2, ($width - 2*$margin) / $bw);
                $iw  = $bw * $mod; $ih = $bh2 * $mod;
                $x0  = ($width - $iw) / 2;
                $y0  = $y - $ih + $lh;
                $draw .= "0 g\n";
                foreach ($bm['rows'] as $ry => $row) {
                    $run = 0;
                    for ($cx = 0; $cx <= $bw; $cx++) {
                        $on = $cx < $bw ? (int)($row[$cx] ?? 0) : 0;
                        if ($on) { $run++; continue; }
                        if ($run) {
                            $draw .= sprintf("%.2f %.2f %.2f %.2f re f\n",
                                $x0 + ($cx - $run) * $mod,
                                $y0 + ($bh2 - 1 - $ry) * $mod,
                                $run * $mod + 0.12, $mod + 0.12);
                            $run = 0;
                        }
                    }
                }
                $y -= $ih + 8;
                continue;
            }

            /* ---- QR bayen taraf, raqamein daayen ---- */
            if ($k === '@qrleft') {
                $matrix = $ln[1] ?? null;
                $side   = (float)($ln[2] ?? 84);
                if (is_array($matrix) && $matrix) {
                    $n = count($matrix); $mod = $side / $n;
                    $x0 = $margin; $y0 = $y - $side + $lh;
                    $draw .= "0 g\n";
                    for ($r = 0; $r < $n; $r++)
                        for ($c = 0; $c < $n; $c++)
                            if (!empty($matrix[$r][$c]))
                                $draw .= sprintf("%.2f %.2f %.2f %.2f re f\n",
                                    $x0 + $c*$mod, $y0 + ($n-1-$r)*$mod, $mod+0.15, $mod+0.15);
                    $indent   = $side + 10;
                    $qrBottom = $y0;
                }
                continue;
            }
            if ($k === '@qrend') {
                $indent = 0.0;
                /* QR raqamon se lamba ho to agli cheez us ke NEECHE se — warna
                   band QR par chadh jata hai. */
                if ($qrBottom !== null && $y > $qrBottom - 8) $y = $qrBottom - 8;
                $qrBottom = null;
                continue;
            }

            /* ---- text ---- */
            $t     = (string)($ln[0] ?? '');
            $align = (string)($ln[1] ?? 'l');
            $size  = (int)($ln[2] ?? 9);
            $font  = ($align === 'b' || $align === 'c') ? '/F2' : '/F1';
            if ($align === 'b') $align = 'l';
            $len   = function_exists('mb_strlen') ? mb_strlen($t) : strlen($t);
            /* Courier ki asli chaurai 0.6 hai. Pehle yahan 0.5 tha, is liye
               centered aur right-aligned lines hamesha thori bayen khisak
               jati thin - bill ki poori alignment isi se bigri hui thi. */
            $w = $size * self::CW * $len;
            /* @qrleft ke baad wali lines QR ke daayen se shuru hoti hain. */
            $x = $margin + $indent;
            if ($align === 'c') $x = max($margin, ($width - $w) / 2);
            if ($align === 'r') $x = max($margin, $width - $margin - $w);
            $text .= sprintf("%s %d Tf\n1 0 0 1 %.2f %.2f Tm\n(%s) Tj\n", $font, $size, $x, $y, self::esc($t));
            $y -= $lh;
        }
        $text .= "ET";
        $stream = $draw . $text;

        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ".sprintf('%.2f %.2f', $width, $height)."] "
                 . "/Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>";
        $objs[4] = "<< /Length ".strlen($stream)." >>\nstream\n".$stream."\nendstream";
        $objs[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>";
        $objs[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $n = count($objs) + 1;
        $pdf .= "xref\n0 $n\n0000000000 65535 f \n";
        foreach ($objs as $num => $_) $pdf .= sprintf("%010d 00000 n \n", $offsets[$num]);
        $pdf .= "trailer\n<< /Size $n /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }

    private static function qrSide(float $width, float $margin): float
    {
        return min(120.0, $width - 2 * $margin - 40);
    }

    /** 80mm par ek line mein kitne monospace characters aate hain. */
    public static function cols(int $size = 9, float $width = 226.0, float $margin = 12.0): int
    {
        return (int)floor(($width - 2 * $margin) / ($size * self::CW));
    }

    private static function esc(string $s): string
    {
        // PDF Courier = WinAnsi; non-latin chars ko safe ASCII mein badlo
        $s = preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }
}

// build: V163 build 2026-10-25
