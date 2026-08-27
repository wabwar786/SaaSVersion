<?php
namespace Aio\Services;

/**
 * Pdf — chhota, dependency-free PDF writer (sirf text receipts ke liye).
 * Koi composer package nahi chahiye; Railway par as-is chalta hai.
 * 80mm thermal-ish page: 226pt chaura, content ke hisab se lamba.
 */
final class Pdf
{
    /** Courier monospace: har character ki chaurai = 0.6 x font size. */
    public const CW = 0.6;

    /**
     * @param array $lines  har line: [text, align(l|c|r|b), size]
     *                      ya QR ke liye: ['@qr', 'c', moduleCount, matrix]
     */
    public static function receipt(array $lines, float $width = 226.0): string
    {
        $margin = 12.0; $lh = 13.0;

        /* Height pehle naapo — QR block kai lines ki jagah leta hai. */
        $height = $margin * 2 + 16;
        foreach ($lines as $ln) {
            $height += (($ln[0] ?? '') === '@qr') ? self::qrSide($width, $margin) + 8 : $lh;
        }

        $text = "BT\n"; $draw = '';
        $y = $height - $margin - 10;

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
            $x = $margin;
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
