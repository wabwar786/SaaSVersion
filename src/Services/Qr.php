<?php
namespace Aio\Services;

/**
 * Qr — chhota, dependency-free QR encoder.
 *
 * KYUN: FBR ka QR bill par chhapna hai, aur bill OFFLINE computer par
 * chhapta hai. POS abhi `api.qrserver.com` (internet) se QR ki image
 * mangwa raha tha — yani net band hote hi QR gayab, aur wo bhi khamoshi
 * se (`onerror="this.remove()"`). FBR ke bill par yeh na-qabil-e-qabool
 * hai. Ab QR isi computer par banta hai, internet ki koi zaroorat nahi.
 *
 * Daira (jaan-boojh kar mehdood, sirf utna jitna chahiye):
 *   - Byte mode (ISO-8859-1 / ASCII payload)
 *   - Error correction level M
 *   - Version 1..10  (~150 bytes tak - FBR invoice ke liye kaafi)
 *   - Sab 8 masks aazma kar behtareen chuna jata hai (penalty rules)
 *
 * Output: 0/1 ka matrix. `Pdf::receipt()` usay chhote murabbon se
 * bana deta hai (vector, is liye thermal printer par saaf).
 */
final class Qr
{
    /** Har version ke liye [total codewords, ecc codewords per block, blocks] — ECC level M */
    private const M_SPECS = [
        1  => [26,   10, 1,  0,  0],
        2  => [44,   16, 1,  0,  0],
        3  => [70,   26, 1,  0,  0],
        4  => [100,  18, 2,  0,  0],
        5  => [134,  24, 2,  0,  0],
        6  => [172,  16, 4,  0,  0],
        7  => [196,  18, 4,  0,  0],
        8  => [242,  22, 2,  2,  0],
        9  => [292,  22, 3,  2,  0],
        10 => [346,  26, 4,  1,  0],
    ];
    /** version => [data codewords total] ECC-M */
    private const M_DATA = [1=>16,2=>28,3=>44,4=>64,5=>86,6=>108,7=>124,8=>154,9=>182,10=>216];
    /** version => alignment pattern centres */
    private const ALIGN = [
        1=>[], 2=>[6,18], 3=>[6,22], 4=>[6,26], 5=>[6,30],
        6=>[6,34], 7=>[6,22,38], 8=>[6,24,42], 9=>[6,26,46], 10=>[6,28,50],
    ];

    private static array $exp = [];
    private static array $log = [];

    private static function initGf(): void
    {
        if (self::$exp) return;
        $x = 1;
        for ($i = 0; $i < 256; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= 0x11D;
        }
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) return 0;
        return self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    /** Reed-Solomon generator polynomial */
    private static function rsPoly(int $n): array
    {
        $g = [1];
        for ($i = 0; $i < $n; $i++) {
            $ng = array_fill(0, count($g) + 1, 0);
            foreach ($g as $k => $c) {
                $ng[$k]     ^= self::gfMul($c, 1);
                $ng[$k + 1] ^= self::gfMul($c, self::$exp[$i]);
            }
            $g = $ng;
        }
        return $g;
    }

    private static function rsEcc(array $data, int $n): array
    {
        $g = self::rsPoly($n);
        $res = array_merge($data, array_fill(0, $n, 0));
        for ($i = 0; $i < count($data); $i++) {
            $f = $res[$i];
            if ($f === 0) continue;
            for ($j = 0; $j < count($g); $j++) {
                $res[$i + $j] ^= self::gfMul($g[$j], $f);
            }
        }
        return array_slice($res, count($data), $n);
    }

    /**
     * @return array<int,array<int,int>>|null  matrix[row][col] = 0|1, ya null agar payload bara ho
     */
    public static function matrix(string $text): ?array
    {
        self::initGf();
        $bytes = array_values(unpack('C*', $text));
        $len   = count($bytes);

        /* 1) version chuno */
        $ver = 0;
        foreach (self::M_DATA as $v => $cap) {
            $lenBits = $v <= 9 ? 8 : 16;
            $need = 4 + $lenBits + $len * 8;
            if ($need <= $cap * 8) { $ver = $v; break; }
        }
        if (!$ver) return null;   // bara payload — bill par text hi kaafi

        $capBytes = self::M_DATA[$ver];
        $lenBits  = $ver <= 9 ? 8 : 16;

        /* 2) bit stream */
        $bits = '';
        $bits .= '0100';                                            // byte mode
        $bits .= str_pad(decbin($len), $lenBits, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        $cap = $capBytes * 8;
        $bits .= str_repeat('0', min(4, $cap - strlen($bits)));      // terminator
        while (strlen($bits) % 8 !== 0) $bits .= '0';
        $pad = ['11101100', '00010001']; $i = 0;
        while (strlen($bits) < $cap) { $bits .= $pad[$i % 2]; $i++; }

        $dataCw = [];
        for ($i = 0; $i < strlen($bits); $i += 8) $dataCw[] = bindec(substr($bits, $i, 8));

        /* 3) blocks + ECC */
        [$totalCw, $eccPer, $b1, $b2] = self::M_SPECS[$ver];
        $blocks = $b1 + $b2;
        $d1 = intdiv($capBytes, $blocks);
        $extra = $capBytes - $d1 * $blocks;      // aakhri $extra blocks mein ek zyada
        $dBlocks = []; $eBlocks = []; $off = 0;
        for ($i = 0; $i < $blocks; $i++) {
            $n = $d1 + ($i >= $blocks - $extra ? 1 : 0);
            $blk = array_slice($dataCw, $off, $n); $off += $n;
            $dBlocks[] = $blk;
            $eBlocks[] = self::rsEcc($blk, $eccPer);
        }
        /* interleave */
        $final = [];
        $maxD = max(array_map('count', $dBlocks));
        for ($i = 0; $i < $maxD; $i++) foreach ($dBlocks as $b) if (isset($b[$i])) $final[] = $b[$i];
        for ($i = 0; $i < $eccPer; $i++) foreach ($eBlocks as $b) $final[] = $b[$i];

        /* 4) matrix */
        $size = 17 + 4 * $ver;
        $m = array_fill(0, $size, array_fill(0, $size, -1));   // -1 = khali
        self::finder($m, 0, 0); self::finder($m, $size - 7, 0); self::finder($m, 0, $size - 7);
        self::reserve($m, $size, $ver);
        self::timing($m, $size);
        self::alignment($m, $size, $ver);
        $m[$size - 8][8] = 1;                                   // dark module

        /* 5) data placement */
        $stream = '';
        foreach ($final as $cw) $stream .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        $bi = 0; $up = true;
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) $col--;                             // timing column skip
            for ($k = 0; $k < $size; $k++) {
                $row = $up ? ($size - 1 - $k) : $k;
                foreach ([$col, $col - 1] as $c) {
                    if ($m[$row][$c] !== -1) continue;
                    $m[$row][$c] = ($bi < strlen($stream)) ? (int)$stream[$bi] : 0;
                    $bi++;
                }
            }
            $up = !$up;
        }

        /* 6) best mask */
        $best = null; $bestPen = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $c = self::applyMask($m, $size, $mask);
            self::format($c, $size, $mask);
            $p = self::penalty($c, $size);
            if ($p < $bestPen) { $bestPen = $p; $best = $c; }
        }
        return $best;
    }

    private static function finder(array &$m, int $x, int $y): void
    {
        for ($r = -1; $r <= 7; $r++) for ($c = -1; $c <= 7; $c++) {
            $rr = $y + $r; $cc = $x + $c;
            if ($rr < 0 || $cc < 0 || $rr >= count($m) || $cc >= count($m)) continue;
            $on = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
               || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6))
               || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
            $m[$rr][$cc] = $on ? 1 : 0;
        }
    }

    private static function reserve(array &$m, int $size, int $ver): void
    {
        /* BUG THA: index 6 skip nahi hota tha, is liye timing modules
           (6,8) aur (8,6) yahan 0 ho jate the aur timing() unhen chhoti
           nahi thi (wo sirf -1 bharti hai). QR scanner ke liye timing
           pattern buniyadi hai. */
        for ($i = 0; $i < 9; $i++) {
            if ($i === 6) continue;
            if ($m[8][$i] === -1) $m[8][$i] = 0;
            if ($m[$i][8] === -1) $m[$i][8] = 0;
        }
        for ($i = 0; $i < 8; $i++) {
            if ($m[8][$size - 1 - $i] === -1) $m[8][$size - 1 - $i] = 0;
            if ($m[$size - 1 - $i][8] === -1) $m[$size - 1 - $i][8] = 0;
        }
    }

    private static function timing(array &$m, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $v = ($i % 2 === 0) ? 1 : 0;
            if ($m[6][$i] === -1) $m[6][$i] = $v;
            if ($m[$i][6] === -1) $m[$i][6] = $v;
        }
    }

    private static function alignment(array &$m, int $size, int $ver): void
    {
        $cs = self::ALIGN[$ver] ?? [];
        foreach ($cs as $r) foreach ($cs as $c) {
            /* finder patterns ke oopar nahi */
            if (($r <= 8 && $c <= 8) || ($r <= 8 && $c >= $size - 9) || ($r >= $size - 9 && $c <= 8)) continue;
            for ($dr = -2; $dr <= 2; $dr++) for ($dc = -2; $dc <= 2; $dc++) {
                $on = (abs($dr) === 2 || abs($dc) === 2 || ($dr === 0 && $dc === 0));
                $m[$r + $dr][$c + $dc] = $on ? 1 : 0;
            }
        }
    }

    private static function maskBit(int $mask, int $r, int $c): bool
    {
        return match ($mask) {
            0 => ($r + $c) % 2 === 0,
            1 => $r % 2 === 0,
            2 => $c % 3 === 0,
            3 => ($r + $c) % 3 === 0,
            4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
            5 => (($r * $c) % 2 + ($r * $c) % 3) === 0,
            6 => ((($r * $c) % 2 + ($r * $c) % 3) % 2) === 0,
            default => ((($r + $c) % 2 + ($r * $c) % 3) % 2) === 0,
        };
    }

    /** Data modules par mask; function patterns ko haath nahi lagta. */
    private static function applyMask(array $m, int $size, int $mask): array
    {
        $fn = self::functionMap($size, count($m));
        for ($r = 0; $r < $size; $r++) for ($c = 0; $c < $size; $c++) {
            if ($fn[$r][$c]) continue;
            if (self::maskBit($mask, $r, $c)) $m[$r][$c] ^= 1;
        }
        return $m;
    }

    private static array $fnCache = [];

    private static function functionMap(int $size, int $_): array
    {
        if (isset(self::$fnCache[$size])) return self::$fnCache[$size];
        $ver = ($size - 17) / 4;
        $f = array_fill(0, $size, array_fill(0, $size, false));
        $mark = function (array &$f, int $r0, int $c0, int $h, int $w) use ($size) {
            for ($r = $r0; $r < $r0 + $h; $r++) for ($c = $c0; $c < $c0 + $w; $c++)
                if ($r >= 0 && $c >= 0 && $r < $size && $c < $size) $f[$r][$c] = true;
        };
        $mark($f, 0, 0, 9, 9);
        $mark($f, 0, $size - 8, 9, 8);
        $mark($f, $size - 8, 0, 8, 9);
        for ($i = 0; $i < $size; $i++) { $f[6][$i] = true; $f[$i][6] = true; }
        $cs = self::ALIGN[$ver] ?? [];
        foreach ($cs as $r) foreach ($cs as $c) {
            if (($r <= 8 && $c <= 8) || ($r <= 8 && $c >= $size - 9) || ($r >= $size - 9 && $c <= 8)) continue;
            $mark($f, $r - 2, $c - 2, 5, 5);
        }
        return self::$fnCache[$size] = $f;
    }

    /** Format info (ECC M + mask), BCH 15,5 */
    private static function format(array &$m, int $size, int $mask): void
    {
        $data = (0b00 << 3) | $mask;          // 00 = level M
        $v = $data << 10;
        for ($i = 4; $i >= 0; $i--) if ($v & (1 << ($i + 10))) $v ^= 0b10100110111 << $i;
        $fmt = (($data << 10) | $v) ^ 0b101010000010010;

        /* BUG THA: bits ULTI tarteeb mein lag rahe the. Spec ke mutabiq
           sab se ahem bit (bit 14) (8,0) par aata hai, LSB nahi. Is se
           poori format info ghalat parhi jati thi. */
        $bit = fn(int $n): int => ($fmt >> $n) & 1;

        /* copy 1 */
        for ($i = 0; $i <= 5; $i++) $m[8][$i] = $bit(14 - $i);
        $m[8][7] = $bit(8);
        $m[8][8] = $bit(7);
        $m[7][8] = $bit(6);
        for ($i = 0; $i <= 5; $i++) $m[5 - $i][8] = $bit(5 - $i);

        /* copy 2 */
        for ($i = 0; $i <= 6; $i++) $m[$size - 1 - $i][8] = $bit(14 - $i);
        for ($i = 0; $i <= 7; $i++) $m[8][$size - 8 + $i]  = $bit(7 - $i);

        $m[$size - 8][8] = 1;   // dark module
    }

    private static function penalty(array $m, int $size): int
    {
        $p = 0;
        /* rule 1: 5+ same in a row/col */
        for ($r = 0; $r < $size; $r++) {
            $run = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($m[$r][$c] === $m[$r][$c - 1]) { $run++; if ($run === 5) $p += 3; elseif ($run > 5) $p++; }
                else $run = 1;
            }
        }
        for ($c = 0; $c < $size; $c++) {
            $run = 1;
            for ($r = 1; $r < $size; $r++) {
                if ($m[$r][$c] === $m[$r - 1][$c]) { $run++; if ($run === 5) $p += 3; elseif ($run > 5) $p++; }
                else $run = 1;
            }
        }
        /* rule 2: 2x2 blocks */
        for ($r = 0; $r < $size - 1; $r++) for ($c = 0; $c < $size - 1; $c++) {
            $v = $m[$r][$c];
            if ($v === $m[$r][$c+1] && $v === $m[$r+1][$c] && $v === $m[$r+1][$c+1]) $p += 3;
        }
        /* rule 3: finder-like pattern */
        $pat1 = [1,0,1,1,1,0,1,0,0,0,0];
        $pat2 = [0,0,0,0,1,0,1,1,1,0,1];
        for ($r = 0; $r < $size; $r++) for ($c = 0; $c <= $size - 11; $c++) {
            $ok1 = true; $ok2 = true;
            for ($k = 0; $k < 11; $k++) {
                if ($m[$r][$c+$k] !== $pat1[$k]) $ok1 = false;
                if ($m[$r][$c+$k] !== $pat2[$k]) $ok2 = false;
            }
            if ($ok1 || $ok2) $p += 40;
        }
        for ($c = 0; $c < $size; $c++) for ($r = 0; $r <= $size - 11; $r++) {
            $ok1 = true; $ok2 = true;
            for ($k = 0; $k < 11; $k++) {
                if ($m[$r+$k][$c] !== $pat1[$k]) $ok1 = false;
                if ($m[$r+$k][$c] !== $pat2[$k]) $ok2 = false;
            }
            if ($ok1 || $ok2) $p += 40;
        }
        /* rule 4: dark ratio */
        $dark = 0;
        foreach ($m as $row) $dark += array_sum($row);
        $pct = $dark * 100 / ($size * $size);
        $p += 10 * (int)floor(abs($pct - 50) / 5);
        return $p;
    }
}

// build: V64.2 build 2026-08-27
