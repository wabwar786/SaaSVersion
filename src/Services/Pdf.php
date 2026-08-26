<?php
namespace Aio\Services;

/**
 * Pdf — chhota, dependency-free PDF writer (sirf text receipts ke liye).
 * Koi composer package nahi chahiye; Railway par as-is chalta hai.
 * 80mm thermal-ish page: 226pt chaura, content ke hisab se lamba.
 */
final class Pdf
{
    /** @param array<int,array{0:string,1:string,2:int}> $lines [text, align(l|c|r|b), size] */
    public static function receipt(array $lines, float $width = 226.0): string
    {
        $margin = 12.0; $lh = 14.0;
        $height = $margin * 2 + count($lines) * $lh + 20;

        $stream = "BT\n";
        $y = $height - $margin - 10;
        foreach ($lines as $ln) {
            $text = (string)($ln[0] ?? '');
            $align = (string)($ln[1] ?? 'l');
            $size = (int)($ln[2] ?? 9);
            $font = ($align === 'b' || $align === 'c') ? '/F2' : '/F1';
            if ($align === 'b') $align = 'l';
            $w = $size * 0.5 * (function_exists("mb_strlen")?mb_strlen($text):strlen($text));
            $x = $margin;
            if ($align === 'c') $x = max($margin, ($width - $w) / 2);
            if ($align === 'r') $x = max($margin, $width - $margin - $w);
            $stream .= sprintf("%s %d Tf\n1 0 0 1 %.2f %.2f Tm\n(%s) Tj\n", $font, $size, $x, $y, self::esc($text));
            $y -= $lh;
        }
        $stream .= "ET";

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

    private static function esc(string $s): string
    {
        // PDF Courier = WinAnsi; non-latin chars ko safe ASCII mein badlo
        $s = preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }
}
