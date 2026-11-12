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
    /**
     * @param float $margin Kinare ki khali jagah (pt). Closing ki parchi
     *                      chhoti rakhti hai taake matn bara aa sake.
     * @param float $lh     Do lineon ka faasla (pt) — font se bara ho.
     */
    /**
     * @param float $maxH Ek safhe ki sab se ziada lambai (pt). Is se lambi
     *                    parchi KAI SAFHON mein tooth jati hai.
     */
    public static function receipt(array $lines, float $width = 226.0,
                                   float $margin = 12.0, float $lh = 13.0,
                                   float $maxH = 780.0): string
    {
        /* ============================================================
           V188 — LAMBI PARCHI AB KAI SAFHON MEIN.

           Malik ne closing ki parchi bheji: 128 lineen, sab PDF ke andar
           maujood, ek bhi line safhe se bahar nahi — magar printer se
           sirf SALAD/RAITA tak nikli, aage kuch nahi.

           WAJAH PDF MEIN NAHI, PRINTER MEIN THI. Parchi EK safha thi,
           1954pt = 689mm lamba. Thermal driver ka apna "page length"
           hota hai — un ke printer par 279mm. Us se aage ka hissa driver
           khamoshi se phenk deta hai:

               safha           1954pt  (689mm)
               driver ki hadd   791pt  (279mm)  -> kaat y = 1163 par
               SALAD/RAITA                         y = 1075

           Ab parchi `$maxH` se lambi ho to kai safhon mein tooth jati
           hai. Thermal roll par safhe ek ke baad ek bah kar nikalte hain
           — kagaz par koi khala nahi aata — aur har driver par poori
           chhapti hai, chahe jitni lambi ho.

           CHHOTI PARCHI JAISI THI WAISI HI: jo `$maxH` ke andar aa jaye
           wo pehle ki tarah EK hi safha rehti hai, bilkul utni hi lambi
           jitna us ka matn. Customer ka bill kabhi tootta nahi.
           ============================================================ */

        /* ---- 1) har line ki apni oonchai ---- */
        $hOf = function (array $ln) use ($width, $margin, $lh): float {
            $k = (string)($ln[0] ?? '');
            if ($k === '@qr')          return self::qrSide($width, $margin) + 8;
            if ($k === '@rule')        return 7;
            if ($k === '@gap')         return (float)($ln[1] ?? 6);
            if ($k === '@band')        return $lh + 10;
            if ($k === '@img')         return (float)($ln[2] ?? 34) + 8;
            if ($k === '@qrleft')      return 24;
            if ($k === '@boxstart' || $k === '@boxend' || $k === '@qrend') return 0;
            return $lh;
        };

        $chrome = $margin * 2 + 16;          /* upar-neeche ki khali jagah */
        $total  = $chrome;
        foreach ($lines as $ln) $total += $hOf($ln);

        /* ---- 2) safhon mein baanto ---- */
        $pages = [];
        if ($total <= $maxH) {
            $pages[] = ['lines' => $lines, 'h' => $total];   /* pehle jaisa */
        } else {
            /* V189 — HAR SAFHA APNE MATN JITNA LAMBA, `$maxH` JITNA NAHI.

               V188 mein bhara hua safha poora `$maxH` (780pt) ka likha
               jata tha, chahe us ka matn 700pt par khatam ho jaye. Roll
               par wo bacha hua hissa KHALI KAGAZ ban kar nikalta tha —
               har safhe ke aakhir mein. Malik ki shikayat: "page end
               hone par bohat ziada space blank chhorta hai."

               Ab har safhe ki lambai us ke apne matn se banti hai. Hadd
               wahi rehti hai (koi safha `$maxH` se lamba nahi hota), bas
               khali jagah nahi bachti. */
            $cur = []; $acc = $chrome;
            foreach ($lines as $ln) {
                $h = $hOf($ln);
                if ($cur && $acc + $h > $maxH) {
                    $pages[] = ['lines' => $cur, 'h' => $acc];
                    $cur = []; $acc = $chrome;
                }
                $cur[] = $ln; $acc += $h;
            }
            if ($cur) $pages[] = ['lines' => $cur, 'h' => $acc];
        }

        /* ---- 3) har safhe ka apna content stream ---- */
        $streams = [];
        $n = count($pages);
        foreach ($pages as $i => $pg) {
            $streams[] = self::page($pg['lines'], $width, $margin, $lh, $pg['h'],
                                    $i + 1, $n);
        }
        return self::assemble($streams, $pages, $width);
    }

    /** Ek safhe ka content stream. */
    private static function page(array $lines, float $width, float $margin,
                                 float $lh, float $height, int $pageNo, int $pages): string
    {
        $text = "BT\n"; $draw = '';
        $y = $height - $margin - 10;
        $boxTop = null;     /* frame ka upari kinara */
        $indent = 0.0;      /* @qrleft ke baad agli lines ka khiskao */
        $qrBottom = null;

        /* Doosre safhe se aage: upar chhota sa nishan, taake dekhne wale ko
           pata rahe ke parchi aage bhi hai aur koi hissa gum nahi hua. */
        if ($pages > 1 && $pageNo > 1) {
            $tag = sprintf('- %d / %d -', $pageNo, $pages);
            $w8  = 8 * self::CW * strlen($tag);
            $text .= sprintf("/F1 8 Tf\n1 0 0 1 %.2f %.2f Tm\n(%s) Tj\n",
                             max($margin, ($width - $w8) / 2), $y, self::esc($tag));
            $y -= $lh;
        }

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
        return $draw . $text;
    }

    /** Safhon ko ek PDF mein joro — font sab safhe mil kar istemal karte hain. */
    private static function assemble(array $streams, array $pages, float $width): string
    {
        /* ============================================================
           V186 — PARCHI KA FONT AB PDF KE ANDAR HI.

           Pehle PDF ke apne Courier / Courier-Bold istemal hote thay. Wo
           PDF mein shamil NAHI hote: har PC ka reader apni Courier dikhata
           hai aur har thermal printer apni. Isi liye shakal har jagah alag
           aur patli-dhundli aati thi.

           Ab Liberation Mono PDF ke andar hi chala jata hai — har PC, har
           printer par bilkul ek jaisi aur mote, saaf harf.

           QATAREIN NAHI TOOTIN: Liberation Mono ki chaurai Courier ke
           barabar hai — 600/1000 per harf, yani wahi `self::CW = 0.6`. Is
           liye `row()` ki space-padding jaisi thi waisi hi theek baithti
           hai. Yeh ittefaq nahi, isi wajah se yeh font chuna gaya.
           ============================================================ */
        [$ttfR, $ttfB] = ReceiptFont::ttf();
        $wid = '[' . rtrim(str_repeat(ReceiptFont::WIDTH . ' ', 95)) . ']';   /* 32..126 */

        $objs = [];
        /* Object ki tarteeb:
             1 catalog · 2 pages · 5,6 font · 7,8 descriptor · 9,10 font file
             11.. har safhe ka /Page aur us ka content, jore mein.          */
        $first = 11;
        $kids = [];
        foreach ($streams as $i => $_) $kids[] = ($first + $i * 2) . ' 0 R';

        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Kids [".implode(' ', $kids)."] /Count ".count($streams)." >>";
        foreach ($streams as $i => $st) {
            $pn = $first + $i * 2;      /* page object */
            $cn = $pn + 1;              /* content     */
            $objs[$pn] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 "
                       . sprintf('%.2f %.2f', $width, (float)$pages[$i]['h']) . "] "
                       . "/Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents $cn 0 R >>";
            $objs[$cn] = "<< /Length ".strlen($st)." >>\nstream\n".$st."\nendstream";
        }
        $objs[5] = "<< /Type /Font /Subtype /TrueType /BaseFont /LiberationMono "
                 . "/FirstChar 32 /LastChar 126 /Widths $wid "
                 . "/Encoding /WinAnsiEncoding /FontDescriptor 7 0 R >>";
        $objs[6] = "<< /Type /Font /Subtype /TrueType /BaseFont /LiberationMono-Bold "
                 . "/FirstChar 32 /LastChar 126 /Widths $wid "
                 . "/Encoding /WinAnsiEncoding /FontDescriptor 8 0 R >>";
        /* Flags 33 = FixedPitch (1) + Nonsymbolic (32) */
        $objs[7] = "<< /Type /FontDescriptor /FontName /LiberationMono /Flags 33 "
                 . "/FontBBox ".ReceiptFont::BBOX." /ItalicAngle 0 /Ascent ".ReceiptFont::ASCENT
                 . " /Descent ".ReceiptFont::DESCENT." /CapHeight ".ReceiptFont::CAPHEIGHT
                 . " /StemV 80 /FontFile2 9 0 R >>";
        $objs[8] = "<< /Type /FontDescriptor /FontName /LiberationMono-Bold /Flags 33 "
                 . "/FontBBox ".ReceiptFont::BBOX." /ItalicAngle 0 /Ascent ".ReceiptFont::ASCENT
                 . " /Descent ".ReceiptFont::DESCENT." /CapHeight ".ReceiptFont::CAPHEIGHT
                 . " /StemV 140 /FontFile2 10 0 R >>";
        $objs[9]  = "<< /Length ".strlen($ttfR)." /Length1 ".strlen($ttfR)." >>\nstream\n".$ttfR."\nendstream";
        $objs[10] = "<< /Length ".strlen($ttfB)." /Length1 ".strlen($ttfB)." >>\nstream\n".$ttfB."\nendstream";

        /* ============================================================
           V188 — xref ab asli object numbers par banta hai.

           Pehle yeh maan liya jata tha ke object 1 se N tak lagatar hain:
           `$n = count($objs) + 1` aur phir seedha har object ki offset
           likh di jati thi. Jab tak ek hi safha tha, yeh sach tha.

           Ab safhe 11, 13, 15 ... par hain aur 3-4 khali reh gaye hain.
           Purane tareeqe se xref ki qatar khisak jati aur PDF ya to
           kharab hoti ya reader us ki marammat kar ke kholta — kuch
           printer aise PDF chupchap phenk dete hain.

           Ab: sab object number ki tarteeb se, gaps ke liye "free"
           entry, aur /Size sab se bare number se ek zyada.
           ============================================================ */
        ksort($objs);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $max  = max(array_keys($objs));
        $n    = $max + 1;
        $pdf .= "xref\n0 $n\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= isset($offsets[$i])
                  ? sprintf("%010d 00000 n \n", $offsets[$i])
                  : "0000000000 65535 f \n";          /* khali jagah */
        }
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
