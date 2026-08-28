<?php
namespace Aio\Services;

/**
 * BillTemplate — 80mm bill ke layouts.
 *
 * Pehle bill ka layout `api.php` ke `bill-pdf` case mein hardcoded tha:
 * ek hi shakl, badalne ka koi tareeqa nahi, aur header/footer lines jo
 * Settings mein likhi jati thin wo bill par aati hi nahi thin.
 *
 * Ab teen layouts hain aur customer Settings se chunta hai. Jo chunta hai
 * WAHI final bill par nikalta hai (aur preview mein bhi wahi dikhta hai —
 * dono ek hi function se bante hain, do alag copies nahi).
 *
 *   classic  — logo line, poora business info, tax breakup, footer
 *   compact  — chhota, kam kaghaz
 *   tax      — har line par tax rate/amount, aur FBR invoice + QR ki jagah
 *
 * Output: Pdf::receipt() wali lines array — [text, align(l|c|r|b), size]
 */
final class BillTemplate
{
    public const IDS = ['classic', 'compact', 'tax'];

    public static function label(string $id): string
    {
        return [
            'classic' => 'Classic (80mm)',
            'compact' => 'Compact (80mm)',
            'tax'     => 'Tax / FBR (80mm)',
        ][$id] ?? $id;
    }

    public static function options(): array
    {
        $o = [];
        foreach (self::IDS as $id) $o[] = ['id' => $id, 'label' => self::label($id)];
        return $o;
    }

    /* 80mm par ek line mein kitne characters aate hain — Pdf se poocha
       jata hai, andaza nahi. Pehle yahan 32 hardcoded tha aur rows kabhi
       size 8 kabhi 9 par bante the; monospace mein alag size = alag
       chaurai, is liye raqamein kabhi dayen kinare par nahi aati thin.
       AB: har column-aligned line SIRF size 9 par. */
    private const SZ = 9;
    private static function w(): int { return \Aio\Services\Pdf::cols(self::SZ); }

    private static function rule(string $c = '-'): array { return [str_repeat($c, self::w()), 'l', self::SZ]; }

    /** Bayen naam, dayen raqam — ek hi line par, beech mein spaces. */
    /** Bayen naam, dayen raqam — hamesha size 9, warna column bigar jata hai. */
    private static function row(string $left, string $right, bool $bold = false): array
    {
        $W = self::w();
        /* rtrim sirf — leading spaces indent hain, unhen maarna nahi.
           Pehle trim() se item ka indent gayab ho jata tha. */
        $left = rtrim($left); $right = trim($right);
        $room = $W - strlen($right) - 1;
        if ($room < 1) $room = 1;
        if (strlen($left) > $room) $left = substr($left, 0, max(1, $room - 1)) . '.';
        $pad = $W - strlen($left) - strlen($right);
        if ($pad < 1) $pad = 1;
        return [$left . str_repeat(' ', $pad) . $right, $bold ? 'b' : 'l', self::SZ];
    }

    /** Lamba naam kaat kar agli line par le jao (kinare se bahar na jaye). */
    private static function wrap(string $t, int $indent = 0): array
    {
        $W = self::w() - $indent; $out = [];
        foreach (explode("\n", wordwrap(trim($t), max(8, $W), "\n", true)) as $l) {
            $out[] = [str_repeat(' ', $indent) . $l, 'l', self::SZ];
        }
        return $out;
    }

    private static function money(float $n): string { return number_format($n, 0); }

    private static function qty(float $q): string
    {
        return rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
    }

    /**
     * @param array $o    orders row
     * @param array $items order_items rows (qty, unit_price, line_total, nm, tax_rate?)
     * @param array $ctx  ['site'=>, 'business'=>, 'address'=>, 'phone'=>, 'ntn'=>,
     *                     'header'=>, 'footer'=>, 'logo'=>bool,
     *                     'fbr_no'=>, 'tax_cash'=>, 'tax_card'=>, 'currency'=>]
     */
    public static function render(string $template, array $o, array $items, array $ctx): array
    {
        $template = in_array($template, self::IDS, true) ? $template : 'classic';
        return match ($template) {
            'compact' => self::compact($o, $items, $ctx),
            'tax'     => self::tax($o, $items, $ctx),
            default   => self::classic($o, $items, $ctx),
        };
    }

    /* ---------------- shared bits ---------------- */

    /**
     * Sar-nama. AHEM: yahan har line ka matn PEHLE dekha jata hai ke
     * pehle chhap to nahi chuka. Bill par address DO DAFA chhap raha tha
     * kyunke branch ka naam, address aur receipt header teenon mein wahi
     * matn para tha — aur code bina dekhe teenon chhap deta tha.
     */
    private static function head(array $o, array $ctx, bool $full): array
    {
        $L = []; $seen = [];
        $put = function (string $t, int $size, string $al = 'c') use (&$L, &$seen) {
            $t = trim(preg_replace('/\s+/', ' ', $t));
            if ($t === '') return;
            /* mbstring har build par does not exist — fallback lazmi. */
            $k = function_exists('mb_strtolower') ? mb_strtolower($t) : strtolower($t);
            if (isset($seen[$k])) return;          // dobara nahi
            $seen[$k] = true;
            /* Choti font par ziada characters aate hain; us hisab se wrap
               taake line kaghaz se bahar na nikle. */
            $cols = \Aio\Services\Pdf::cols(max(6, $size));
            foreach (explode("\n", wordwrap($t, $cols, "\n", true)) as $piece) {
                $L[] = [$piece, $al, $size];
            }
        };

        $biz = trim((string)($ctx['business'] ?? '')) ?: (string)($ctx['site'] ?? 'Restaurant');
        $put($biz, 12);
        if ($full) {
            $put((string)($ctx['site'] ?? ''), 8);
            $put((string)($ctx['address'] ?? ''), 8);
            if (!empty($ctx['phone'])) $put('Ph: '.(string)$ctx['phone'], 8);
            if (!empty($ctx['ntn']))   $put('NTN: '.(string)$ctx['ntn'], 8);
        }
        $put((string)($ctx['header'] ?? ''), 8);
        return $L;
    }

    private static function meta(array $o, bool $full): array
    {
        $L = [];
        $when = date('d M Y  H:i', strtotime((string)($o['closed_at'] ?: $o['created_at'])));
        $L[] = self::row('Bill # '.(string)$o['bill_no'], $when);
        $modes = ['DINE_IN' => 'Dine In', 'TAKEAWAY' => 'Takeaway', 'TAKE_AWAY' => 'Takeaway',
                  'DELIVERY' => 'Delivery', 'QR' => 'QR Order'];
        $mode  = $modes[strtoupper((string)$o['service_mode'])] ?? (string)$o['service_mode'];
        $line2 = $mode . (!empty($o['tbl']) ? ('   /  '.$o['tbl']) : '');
        if (!empty($o['cashier'])) $line2 .= '  /  '.(string)$o['cashier'];
        $L[] = [$line2, 'l', self::SZ];
        if ($full && !empty($o['cust'])) {
            $L = array_merge($L, self::wrap('Customer: '.(string)$o['cust'].(!empty($o['cphone']) ? (' '.$o['cphone']) : '')));
        }
        return $L;
    }

    private static function totals(array $o, array $ctx): array
    {
        $cur = (string)($ctx['currency'] ?? 'PKR');
        $L = [];
        $L[] = self::row('Subtotal', self::money((float)$o['subtotal']));
        if ((float)$o['discount_amount'] > 0) $L[] = self::row('Discount', '-'.self::money((float)$o['discount_amount']));
        if ((float)$o['service_charge']  > 0) $L[] = self::row('Service Charge', self::money((float)$o['service_charge']));
        if ((float)$o['tax_amount']      > 0) $L[] = self::row('Sales Tax', self::money((float)$o['tax_amount']));
        $L[] = self::rule('=');
        $L[] = self::row('TOTAL '.$cur, self::money((float)$o['grand_total']), true);
        return $L;
    }

    private static function foot(array $ctx, bool $full): array
    {
        $L = [];
        $L[] = ['', 'l', self::SZ];

        if (!empty($ctx['fbr_no'])) {
            $L[] = ['FBR Invoice', 'c', 8];
            $L[] = [(string)$ctx['fbr_no'], 'c', self::SZ];
            /* ASLI QR, on this computer bana hua. Pehle sirf "[ QR ]"
               likha aata tha, aur POS screen internet wale
               api.qrserver.com se image mangwata tha — net band hote hi
               QR khamoshi se gayab. FBR ke bill par yeh na-qabil-e-qabool
               hai. */
            $m = Qr::matrix((string)$ctx['fbr_no']);
            if ($m) $L[] = ['@qr', 'c', 0, $m];
        } elseif (!empty($ctx['fbr_pending'])) {
            $L[] = ['*** FBR: PENDING ***', 'c', self::SZ];
            $L[] = ['This bill has not been sent to FBR yet.', 'c', 7];
        }

        $L[] = ['', 'l', self::SZ];
        $L[] = [trim((string)($ctx['footer'] ?? '')) ?: 'Thank you! Visit again.', 'c', 8];
        if ($full) $L[] = ['Powered by Wabwar Software House', 'c', 7];
        return $L;
    }

    /* ---------------- shift closing report (80mm) ----------------
       Malik ko sirf cash ka farq nahi chahiye. Usay yeh dekhna hota hai
       ke us shift mein BIKA kya — category ke hisab se, har category ke
       andar item aur raqam, aur neeche discount aur total. Yehi cheez
       roz raat ko counter par chhapti hai. */

    public static function shiftReport(array $r, array $ctx): array
    {
        $L = self::head([], $ctx, true);
        $L[] = ['SHIFT CLOSING REPORT', 'c', 9];
        $L[] = self::rule();
        $L[] = self::row('Shift', (string)$r['shift']);
        if (!empty($r['counter'])) $L[] = self::row('Counter', (string)$r['counter']);
        $L[] = self::row('Cashier', (string)$r['cashier']);
        $L[] = self::row('Opened', (string)$r['opened']);
        $L[] = self::row('Closed', (string)($r['closed'] ?: '-'));
        $L[] = self::rule();

        /* ---- category ke hisab se sale ---- */
        if (!empty($r['categories'])) {
            $L[] = ['SALES BY CATEGORY', 'c', 8];
            $L[] = self::rule();
            foreach ($r['categories'] as $c) {
                $L[] = self::row(strtoupper((string)$c['name']), self::money((float)$c['amount']), true);
                foreach ($c['items'] as $it) {
                    $L[] = self::row('  ' . self::qty((float)$it['qty']) . ' x ' . (string)$it['name'],
                                     self::money((float)$it['amount']));
                }
                $L[] = ['', 'l', self::SZ];
            }
            $L[] = self::rule();
        } else {
            $L[] = ['No sales in this shift.', 'c', 8];
            $L[] = self::rule();
        }

        /* ---- totals ---- */
        $L[] = self::row('Bills', (string)(int)$r['bills']);
        $L[] = self::row('Subtotal', self::money((float)$r['subtotal']));
        if ((float)$r['discount'] > 0) $L[] = self::row('Discount', '-' . self::money((float)$r['discount']));
        if ((float)$r['service']  > 0) $L[] = self::row('Service Charge', self::money((float)$r['service']));
        if ((float)$r['tax']      > 0) $L[] = self::row('Sales Tax', self::money((float)$r['tax']));
        $L[] = self::rule('=');
        $L[] = self::row('TOTAL SALES', self::money((float)$r['total']), true);
        $L[] = self::rule();

        /* ---- payment mix ---- */
        if (!empty($r['payments'])) {
            $L[] = ['PAYMENTS', 'c', 8];
            foreach ($r['payments'] as $p) {
                $L[] = self::row((string)$p['method'], self::money((float)$p['amount']));
            }
            $L[] = self::rule();
        }

        /* ---- cash reconciliation ---- */
        $L[] = ['CASH IN TILL', 'c', 8];
        $L[] = self::row('Opening float', self::money((float)$r['opening']));
        if ((float)($r['expenses'] ?? 0) > 0) {
            /* Counter se nikla hua cash — iske baghair variance ghalat
               lagta hai aur cashier be-wajah shak mein aata hai. */
            $L[] = self::row('Less: cash expenses', '-' . self::money((float)$r['expenses']));
        }
        $L[] = self::row('Expected', self::money((float)$r['expected']));
        $L[] = self::row('Counted', self::money((float)$r['counted']));
        $var = (float)$r['variance'];
        $L[] = self::rule('=');
        $L[] = self::row($var == 0 ? 'BALANCED' : ($var > 0 ? 'CASH OVER' : 'CASH SHORT'),
                         ($var > 0 ? '+' : '') . self::money($var), true);

        if (!empty($r['note'])) {
            $L[] = ['', 'l', self::SZ];
            $L = array_merge($L, self::wrap('Note: ' . (string)$r['note']));
        }

        $L[] = ['', 'l', self::SZ];
        $L[] = ['Cashier signature: ______________', 'l', 8];
        $L[] = ['', 'l', self::SZ];
        $L[] = ['Manager signature: ______________', 'l', 8];
        $L[] = ['', 'l', self::SZ];
        $L[] = [date('d M Y  H:i'), 'c', 7];
        return $L;
    }

    /* ---------------- 1. classic ---------------- */

    private static function classic(array $o, array $items, array $ctx): array
    {
        $L = self::head($o, $ctx, true);
        $L[] = ['SALES INVOICE', 'c', 9];
        $L[] = self::rule();
        $L = array_merge($L, self::meta($o, true));
        $L[] = self::rule();
        $L[] = self::row('ITEM', 'AMOUNT', true);
        $L[] = self::rule();
        foreach ($items as $it) {
            $L = array_merge($L, self::wrap((string)$it['nm']));
            $L[] = self::row('   '.self::qty((float)$it['qty']).' x '.self::money((float)$it['unit_price']),
                             self::money((float)$it['line_total']));
        }
        $L[] = self::rule();
        $L = array_merge($L, self::totals($o, $ctx));
        return array_merge($L, self::foot($ctx, true));
    }

    /* ---------------- 2. compact ---------------- */

    private static function compact(array $o, array $items, array $ctx): array
    {
        $L = self::head($o, $ctx, false);
        $L = array_merge($L, self::meta($o, false));
        $L[] = self::rule();
        foreach ($items as $it) {
            $L[] = self::row(self::qty((float)$it['qty']).' '.(string)$it['nm'],
                             self::money((float)$it['line_total']));
        }
        $L[] = self::rule();
        $L = array_merge($L, self::totals($o, $ctx));
        return array_merge($L, self::foot($ctx, false));
    }

    /* ---------------- 3. tax / FBR ---------------- */

    private static function tax(array $o, array $items, array $ctx): array
    {
        $L = self::head($o, $ctx, true);
        $L[] = ['SALES TAX INVOICE', 'c', 9];
        $L[] = self::rule();
        $L = array_merge($L, self::meta($o, true));
        $L[] = self::rule();
        $L[] = self::row('ITEM  /  QTY x RATE @TAX', 'AMOUNT', true);
        $L[] = self::rule();
        foreach ($items as $it) {
            $rate = isset($it['tax_rate']) ? (float)$it['tax_rate'] : (float)($ctx['tax_rate'] ?? 0);
            $L = array_merge($L, self::wrap((string)$it['nm']));
            $L[] = self::row('   '.self::qty((float)$it['qty']).' x '.self::money((float)$it['unit_price'])
                             .' @'.rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.').'%',
                             self::money((float)$it['line_total']));
        }
        $L[] = self::rule();
        $L = array_merge($L, self::totals($o, $ctx));
        $L[] = self::row('Payment', (string)($o['pay_mode'] ?? '-'));
        return array_merge($L, self::foot($ctx, true));
    }

}

// build: V64 build 2026-08-27
