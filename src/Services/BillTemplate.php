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
    public const IDS = ['classic', 'compact', 'tax', 'khaata'];

    public static function label(string $id): string
    {
        return [
            'classic' => 'Classic (80mm)',
            'compact' => 'Compact (80mm)',
            'tax'     => 'Tax / FBR (80mm)',
            'khaata'  => 'Khaata — framed + logo (80mm)',
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
            'khaata'  => self::khaata($o, $items, $ctx),
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
            if (isset($seen[$k])) return;          // again nahi
            $seen[$k] = true;
            /* Choti font par ziada characters aate hain; us hisab se wrap
               taake line kaghaz se bahar na nikle. */
            $cols = \Aio\Services\Pdf::cols(max(6, $size));
            foreach (explode("\n", wordwrap($t, $cols, "\n", true)) as $piece) {
                $L[] = [$piece, $al, $size];
            }
        };

        $biz = trim((string)($ctx['business'] ?? '')) ?: (string)($ctx['site'] ?? 'Restaurant');
        /* V93 — business ka naam sab se numaya. Pehle 12 tha, yani
           address (8) se muskil se bara. Bill haath mein aate hi sab se
           pehle yeh nazar aana chahiye ke bill KIS KA hai.
           `head()` dobara aane wali line khud rok deta hai, is liye
           branch ka naam business ke naam jaisa ho to chhap nahi hoga. */
        $put(strtoupper($biz), 15);
        if ($full) {
            $put((string)($ctx['site'] ?? ''), 8);
            $put((string)($ctx['address'] ?? ''), 8);
            /* Ph aur NTN apni apni line par — 80mm par jor dene se toot
               jate hain aur parhe nahi jate. */
            if (!empty($ctx['phone'])) $put('Ph : '.(string)$ctx['phone'], 8);
            if (!empty($ctx['ntn']))   $put('NTN : '.(string)$ctx['ntn'], 8);
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
        /* V93 — har key `??` ke saath. POS to sab bhejta hai, magar
           purane orders ya doosre raste (shift report, duplicate print)
           se aaya order adhoora ho sakta hai — us par bill warning se
           bharna nahi chahiye. */
        $L[] = self::row('Subtotal', self::money((float)($o['subtotal'] ?? 0)));
        if ((float)($o['discount_amount'] ?? 0) > 0) $L[] = self::row('Discount', '-'.self::money((float)$o['discount_amount']));
        if ((float)($o['service_charge']  ?? 0) > 0) $L[] = self::row('Service Charge', self::money((float)$o['service_charge']));
        if ((float)($o['tax_amount']      ?? 0) > 0) $L[] = self::row('Sales Tax', self::money((float)$o['tax_amount']));
        $L[] = self::rule('=');
        $L[] = self::row('TOTAL '.$cur, self::money((float)($o['grand_total'] ?? 0)), true);
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

    /**
     * Bayen naam, dayen raqam — magar naam lamba ho to KAATTA nahi.
     *
     * V162 — `row()` lambe naam ko kaat kar ek nuqta laga deti thi:
     *     "Cashi. Muhammad Abdul Rehman Siddiqui"
     *     "17 x Garlic Naan with Extra . 1,163"
     * Parchi yeh batati bhi nahi ke kuch kata hai, is liye jis dukan ke
     * item ya cashier ke naam lambe hain us ki poori closing adhuri
     * chhapti thi. Ab naam agli line par chala jata hai aur raqam aakhri
     * line par dayen — kuch gum nahi hota.
     *
     * @return array<int,array> ek ya ziada lines
     */
    private static function rows(string $left, string $right, bool $bold = false, int $indent = 0): array
    {
        $W     = self::w();
        $left  = rtrim($left);
        $right = trim($right);

        if (strlen($left) <= $W - strlen($right) - 1) {
            return [self::row($left, $right, $bold)];
        }

        $pre   = str_repeat(' ', $indent);
        $parts = explode("\n", wordwrap(trim($left), max(8, $W - $indent), "\n", true));
        $last  = $pre . array_pop($parts);

        $out = [];
        foreach ($parts as $p) $out[] = [$pre . $p, $bold ? 'b' : 'l', self::SZ];

        if (strlen($last) + strlen($right) + 1 > $W) {
            $out[] = [$last, $bold ? 'b' : 'l', self::SZ];
            $out[] = [str_repeat(' ', max(1, $W - strlen($right))) . $right, $bold ? 'b' : 'l', self::SZ];
        } else {
            $out[] = [$last . str_repeat(' ', $W - strlen($last) - strlen($right)) . $right,
                      $bold ? 'b' : 'l', self::SZ];
        }
        return $out;
    }

    /** @param array<int,array> $rows */
    private static function put(array &$L, array $rows): void { foreach ($rows as $x) $L[] = $x; }

    /**
     * Ek item ke SAB numbers ek hi paimane par.
     *
     * Divisor poore item ke sab se bare number se nikalta hai, har number
     * ka apna nahi — warna "2.4 kg" ke neeche "0.005 kg" ke bajaye "5 g"
     * chhap jata hai aur dono ka moqabla nahi hota. (Wahi usool jo owner
     * app mein hai.)
     *
     * @param array<int,float> $vals
     * @return array{0:float,1:string} [divisor, unit label]
     */
    private static function paimana(array $vals, string $unit): array
    {
        $u = strtoupper(trim($unit));
        $max = 0.0;
        foreach ($vals as $v) $max = max($max, abs((float)$v));
        if ($max >= 1000 && ($u === 'G' || $u === 'ML')) {
            return [1000.0, $u === 'G' ? 'kg' : 'L'];
        }
        return [1.0, $u !== '' ? strtolower($u) : ''];
    }

    /* ---------------- daily closing report (80mm) ----------------
       Malik ko sirf cash ka farq nahi chahiye. Usay yeh dekhna hota hai
       ke us shift mein BIKA kya — category ke hisab se, har category ke
       andar item aur raqam, aur neeche discount aur total. Yehi cheez
       roz raat ko counter par chhapti hai.

       V162 — parchi parhne ke qabil banai gayi:
         • lamba naam kaata nahi jata (upar `rows()`)
         • tracked stock par PAIMANA likha aata hai, aur har cheez ki
           teen alag line — teen number ek line par thunse nahi
         • shift khuli ho to "Expected 0 / BALANCED" jhooti tasalli ke
           bajaye daraz ka asal hisaab: float + cash sales - kharcha
         • unwan matn ke barabar (size 9) aur bayen taraf — thermal roll
           par size 8 sab se pehle dhundla hota hai
         • sar-nama par business date */

    public static function shiftReport(array $r, array $ctx): array
    {
        $isOpen = ((string)($r['closed'] ?? '') === '');

        $L = self::head([], $ctx, true);
        $L[] = ['DAILY CLOSING', 'c', 11];
        if (!empty($r['date'])) {
            $d = strtotime((string)$r['date']);
            if ($d) $L[] = [date('d M Y', $d), 'c', 9];
        }
        $L[] = self::rule();
        self::put($L, self::rows('Shift', (string)$r['shift']));
        if (!empty($r['counter'])) self::put($L, self::rows('Counter', (string)$r['counter']));
        self::put($L, self::rows('Cashier', (string)$r['cashier']));
        self::put($L, self::rows('Opened', (string)$r['opened']));
        self::put($L, self::rows('Closed', $isOpen ? 'still open' : (string)$r['closed']));
        $L[] = self::rule();

        /* ---- category ke hisab se sale ---- */
        if (!empty($r['categories'])) {
            $L[] = ['SALES BY CATEGORY', 'b', self::SZ];
            $L[] = self::rule();
            $n = count($r['categories']);
            foreach (array_values($r['categories']) as $i => $c) {
                self::put($L, self::rows(strtoupper((string)$c['name']),
                                         self::money((float)$c['amount']), true));
                foreach ($c['items'] as $it) {
                    self::put($L, self::rows('  ' . self::qty((float)$it['qty']) . ' x ' . (string)$it['name'],
                                             self::money((float)$it['amount']), false, 4));
                }
                /* Aakhri category ke baad khali line nahi — us ke neeche
                   waise bhi lakeer hai, do khali jagah roll zaya karti hai. */
                if ($i < $n - 1) $L[] = ['', 'l', 4];
            }
            $L[] = self::rule();
        } else {
            $L[] = ['No sales in this shift.', 'c', 8];
            $L[] = self::rule();
        }

        /* ---- totals ---- */
        self::put($L, self::rows('Bills', (string)(int)$r['bills']));
        self::put($L, self::rows('Subtotal', self::money((float)$r['subtotal'])));
        if ((float)$r['discount'] > 0) self::put($L, self::rows('Discount', '-' . self::money((float)$r['discount'])));
        if ((float)$r['service']  > 0) self::put($L, self::rows('Service charge', self::money((float)$r['service'])));
        if ((float)$r['tax']      > 0) self::put($L, self::rows('Sales tax', self::money((float)$r['tax'])));
        $L[] = self::rule('=');
        $L[] = ['', 'l', 3];
        self::put($L, self::rows('TOTAL SALES', self::money((float)$r['total']), true));
        $L[] = ['', 'l', 3];
        $L[] = self::rule();

        /* ---- payment mix ---- */
        if (!empty($r['payments'])) {
            $L[] = ['HOW IT WAS PAID', 'b', self::SZ];
            foreach ($r['payments'] as $p) {
                self::put($L, self::rows((string)$p['method'], self::money((float)$p['amount'])));
            }
            $L[] = self::rule();
        }

        /* ---- cash reconciliation ----
           Shift khuli ho to `expected_cash` / `actual_cash` shift ki row
           par likhe hi nahi gaye hote (wo band karte waqt bharte hain).
           Pehle wahi sifar chhap jate thay aur neeche "BALANCED 0" —
           jabke din bhar ki sale ho chuki hoti. Ab khuli shift par daraz
           ka asal hisaab chhapta hai aur saaf likha hota hai ke ginti
           abhi hui hi nahi. */
        $L[] = ['CASH IN TILL', 'b', self::SZ];
        /* Purani band shifts ka snapshot V162 se pehle ka hai — us mein
           `cash_sales` hai hi nahi. Us soorat mein yeh line chhapti hi
           nahi; purani report jaisi thi waisi hi rehti hai. */
        $hasCash = array_key_exists('cash_sales', $r);
        $cash = (float)($r['cash_sales'] ?? 0);
        $exp  = (float)($r['expenses'] ?? 0);
        self::put($L, self::rows('Opening float', self::money((float)$r['opening'])));
        if ($hasCash) self::put($L, self::rows('Cash sales', self::money($cash)));
        if ($exp > 0) self::put($L, self::rows('Less cash expenses', '-' . self::money($exp)));

        $expected = $isOpen ? ((float)$r['opening'] + $cash - $exp) : (float)$r['expected'];
        self::put($L, self::rows('Expected in till', self::money($expected), true));

        if ($isOpen) {
            $L[] = self::rule();
            $L[] = ['Shift is still open', 'c', 8];
            $L[] = ['Cash is counted at closing', 'c', 8];
        } else {
            self::put($L, self::rows('Counted', self::money((float)$r['counted'])));
            $var = (float)$r['variance'];
            $L[] = self::rule('=');
            $L[] = ['', 'l', 3];
            self::put($L, self::rows($var == 0 ? 'BALANCED' : ($var > 0 ? 'CASH OVER' : 'CASH SHORT'),
                                     ($var > 0 ? '+' : '') . self::money($var), true));
            $L[] = ['', 'l', 3];
        }

        /* Tracked inventory — sirf wo items jinpar tracking on hai.
           Malik ko raat ko yeh dekhna hota hai ke qeemti cheez kitni
           bik gayi aur kitni bachi.

           Pehle teenon number EK line par thunse hote thay aur PAIMANA
           likha hi nahi jata tha — "opening 2399.5" se yeh pata hi nahi
           chalta tha ke gram hain, kilo ya pcs. */
        if (!empty($r['tracked'])) {
            $L[] = self::rule();
            $L[] = ['TRACKED STOCK', 'b', self::SZ];
            foreach ($r['tracked'] as $t) {
                $op = (float)$t['opening']; $so = (float)$t['sold']; $le = (float)$t['remaining'];
                [$div, $u] = self::paimana([$op, $so, $le], (string)($t['unit'] ?? ''));
                $su = $u !== '' ? ' ' . $u : '';
                $L[] = [(string)$t['name'], 'b', self::SZ];
                self::put($L, self::rows('  opening', self::qty($op / $div) . $su));
                self::put($L, self::rows('  sold',    self::qty($so / $div) . $su));
                self::put($L, self::rows('  left',    self::qty($le / $div) . $su));
                $L[] = ['', 'l', 3];
            }
        }

        if (!empty($r['note'])) {
            $L[] = self::rule();
            $L = array_merge($L, self::wrap('Note: ' . (string)$r['note']));
        }

        $L[] = self::rule();
        $L[] = ['', 'l', 6];
        $L[] = ['Cashier  ____________________', 'l', 8];
        $L[] = ['', 'l', 6];
        $L[] = ['Manager  ____________________', 'l', 8];
        $L[] = ['', 'l', 8];
        $L[] = ['Printed ' . date('d M Y  H:i'), 'c', 7];
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
            $L = array_merge($L, self::wrap((string)($it['nm'] ?? $it['name'] ?? '-')));
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
            $L = array_merge($L, self::wrap((string)($it['nm'] ?? $it['name'] ?? '-')));
            $L[] = self::row('   '.self::qty((float)$it['qty']).' x '.self::money((float)$it['unit_price'])
                             .' @'.rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.').'%',
                             self::money((float)$it['line_total']));
        }
        $L[] = self::rule();
        $L = array_merge($L, self::totals($o, $ctx));
        $L[] = self::row('Payment', (string)($o['pay_mode'] ?? '-'));
        return array_merge($L, self::foot($ctx, true));
    }

    /* ---------------- 4. khaata (V163) ----------------
       Customer ko jo kaghaz haath mein milta hai, wo sab se pehle do
       cheezein dhoondta hai: kya liya, aur kitna dena hai. Yeh template
       wahi do cheezein sab se numaya rakhta hai:

         • item ka khaata ek FRAME ke andar — nazar kahin bhatakti nahi
         • dena kitna hai, wo BHARAY HUE BAND mein — door se parh liya jata hai
         • FBR ka QR totals ke BAYEN us khali jagah mein jo waise bhi
           bachi hoti hai — is se har bill par taqreeban 30% roll bachta hai
         • business ka logo sab se ooper, agar us ne lagaya ho

       Nuqta-dar lakeeron (`- - - -`) ki jagah thos baareek lakeerein.
       Teenon purane template jaise ke waise hain; yeh chautha hai. */

    private static function khaata(array $o, array $items, array $ctx): array
    {
        $cur = (string)($ctx['currency'] ?? 'PKR');
        $L   = [];

        /* ---- logo (ho to) ---- */
        $bm = ReceiptLogo::bitmap((string)($ctx['logo'] ?? ''));
        if ($bm) { $L[] = ['@img', $bm, 38]; }

        /* ---- sar-nama ---- */
        $biz = trim((string)($ctx['business'] ?? '')) ?: (string)($ctx['site'] ?? 'Restaurant');
        $L[] = [strtoupper($biz), 'c', 13];
        if (!empty($ctx['address'])) {
            foreach (self::wrapAt((string)$ctx['address'], 6) as $x) $L[] = [$x, 'c', 6];
        }
        $idLine = [];
        if (!empty($ctx['phone'])) $idLine[] = 'PH '.$ctx['phone'];
        if (!empty($ctx['ntn']))   $idLine[] = 'NTN '.$ctx['ntn'];
        if ($idLine) $L[] = [implode('   .   ', $idLine), 'c', 7];

        $L[] = ['@gap', 4];
        $L[] = ['@rule', 1.2];
        $L[] = [!empty($ctx['ntn']) ? 'SALES TAX INVOICE' : 'SALES INVOICE', 'c', 9];
        $L[] = ['@rule', 1.2];
        $L[] = ['@gap', 3];

        /* ---- bill ki pehchan ---- */
        $modes = ['DINE_IN' => 'DINE IN', 'TAKEAWAY' => 'TAKE AWAY', 'TAKE_AWAY' => 'TAKE AWAY',
                  'DELIVERY' => 'DELIVERY', 'QR' => 'QR ORDER'];
        $mode  = $modes[strtoupper((string)$o['service_mode'])] ?? strtoupper((string)$o['service_mode']);
        $when  = date('d-m-Y  H:i', strtotime((string)($o['closed_at'] ?: $o['created_at'])));
        $L[] = self::row('Invoice  '.(string)$o['bill_no'], $mode, false);
        $right = (string)($o['cashier'] ?? '');
        if (!empty($o['tbl'])) $right = trim((string)$o['tbl'].($right !== '' ? ' / '.$right : ''));
        $L[] = self::row($when, $right, false);
        if (!empty($o['cust'])) {
            $L = array_merge($L, self::wrap('Customer: '.(string)$o['cust']
                 .(!empty($o['cphone']) ? (' '.$o['cphone']) : '')));
        }
        $L[] = ['@gap', 5];

        /* ---- item ka khaata, frame ke andar ---- */
        /* Frame ke andar size 8 — 42 harf aate hain (size 9 par sirf 37),
           is liye zyada tar item ke naam ek hi line mein poore aa jate
           hain aur raqam un ke saamne rehti hai. */
        $IW = \Aio\Services\Pdf::cols(8);
        $L[] = ['@boxstart'];
        $L[] = self::roww(' ITEM', str_pad('QTY', 3).str_pad('AMOUNT', 7, ' ', STR_PAD_LEFT), $IW, true, 8);
        $L[] = ['@rule', 0.6];
        foreach ($items as $it) {
            $nm  = (string)($it['nm'] ?? $it['name'] ?? '-');
            $amt = self::money((float)$it['line_total']);
            $q   = self::qty((float)$it['qty']);
            $tail = str_pad($q, 3).str_pad($amt, 7, ' ', STR_PAD_LEFT);
            /* Naam lamba ho to kaata nahi jata — agli line par, aur raqam
               aakhri line par. (Wahi usool jo closing parchi par hai.) */
            $L = array_merge($L, self::rowsAt(' '.$nm, $tail, $IW, 8, 2));
        }
        $L[] = ['@boxend'];
        $L[] = ['@gap', 11];

        /* ---- QR bayen, raqamein daayen ---- */
        $QRW = 84.0; $QRGAP = 10.0;
        $qrOn = false;
        if (!empty($ctx['fbr_no'])) {
            $m = Qr::matrix((string)$ctx['fbr_no']);
            if ($m) { $L[] = ['@qrleft', $m, $QRW]; $qrOn = true; }
        }
        /* QR ke saath kitni jagah bachti hai — GINI jati hai, andaza nahi.
           (Pehle yahan 23 likha tha aur raqamein kaghaz se bahar nikal
           jati thin.) */
        if ($qrOn) {
            $availPt = (226.0 - 2 * 12.0) - ($QRW + $QRGAP);
            $cw = (int)floor($availPt / (self::SZ * \Aio\Services\Pdf::CW));
        } else {
            $cw = self::w();
        }

        $sub  = (float)($o['subtotal'] ?? 0);
        $disc = (float)($o['discount_amount'] ?? 0);
        $svc  = (float)($o['service_charge'] ?? 0);
        $tax  = (float)($o['tax_amount'] ?? 0);
        $paid = (float)($o['paid_amount'] ?? 0);
        $chg  = (float)($o['change_amount'] ?? 0);

        $L[] = self::roww('Total amount', self::money($sub), $cw);
        if ($disc > 0) $L[] = self::roww('Discount', '-'.self::money($disc), $cw);
        if ($svc  > 0) $L[] = self::roww('Service charge', self::money($svc), $cw);
        if ($tax  > 0) $L[] = self::roww('Sales tax', self::money($tax), $cw);
        if ($paid > 0) $L[] = self::roww('Received', self::money($paid), $cw);
        if ($chg  > 0) $L[] = self::roww('Change', self::money($chg), $cw);
        if ($qrOn) $L[] = ['@qrend'];

        $L[] = ['@gap', 4];
        $L[] = ['@band', 'PAYABLE', $cur.' '.self::money((float)$o['grand_total']), 12];

        /* ---- FBR ---- */
        $L[] = ['@gap', 4];
        if (!empty($ctx['fbr_no'])) {
            $fb = 'FBR '.(string)$ctx['fbr_no'];
            if (!empty($ctx['pos_id'])) $fb .= '  .  POS '.$ctx['pos_id'];
            foreach (self::wrapAt($fb, 7) as $x) $L[] = [$x, 'c', 7];
        } elseif (!empty($ctx['fbr_pending'])) {
            $L[] = ['*** FBR: PENDING ***', 'c', self::SZ];
            $L[] = ['This bill has not been sent to FBR yet.', 'c', 7];
        }

        $L[] = ['@gap', 6];
        $L[] = [trim((string)($ctx['footer'] ?? '')) ?: 'Thank you! Visit again.', 'c', 8];
        $L[] = ['Powered by Wabwar Software House', 'c', 7];
        return $L;
    }

    /** Chhoti font par wrap — sar-nama ki lines ke liye. */
    private static function wrapAt(string $t, int $size): array
    {
        $c = max(10, \Aio\Services\Pdf::cols($size));
        return explode("\n", wordwrap(trim(preg_replace('/\s+/', ' ', $t)), $c, "\n", true));
    }

    /** Bayen naam, dayen raqam — magar chaurai batai hui (QR ke saath kam). */
    private static function roww(string $l, string $r, int $chars, bool $bold = false, ?int $size = null): array
    {
        $size = $size ?? self::SZ;
        $l = rtrim($l); $r = trim($r);
        if (strlen($l) + strlen($r) + 1 > $chars) {
            $l = substr($l, 0, max(1, $chars - strlen($r) - 2)).'.';
        }
        $pad = max(1, $chars - strlen($l) - strlen($r));
        return [$l.str_repeat(' ', $pad).$r, $bold ? 'b' : 'l', $size];
    }

    /**
     * Wahi `rows()` ka kaam — naam lamba ho to kaatne ke bajaye agli line
     * par — magar chaurai aur font size batai hui. Frame ke andar size 8
     * chalta hai (42 harf), baahar 9 (37 harf).
     *
     * @return array<int,array>
     */
    private static function rowsAt(string $left, string $right, int $chars, int $size, int $indent = 0): array
    {
        $left = rtrim($left); $right = trim($right);
        /* Bilkul theek baithne wala naam bhi ek hi line par rehna chahiye —
           pehle yahan ek harf ki ziyadti thi aur aisa naam be-wajah wrap
           ho kar raqam ko agli line par le jata tha. */
        if (strlen($left) + strlen($right) + 1 <= $chars) {
            $pad = max(1, $chars - strlen($left) - strlen($right));
            return [[$left.str_repeat(' ', $pad).$right, 'l', $size]];
        }
        $pre   = str_repeat(' ', $indent);
        $parts = explode("\n", wordwrap(trim($left), max(8, $chars - $indent), "\n", true));
        $last  = $pre.array_pop($parts);
        $out   = [];
        foreach ($parts as $p) $out[] = [$pre.$p, 'l', $size];
        if (strlen($last) + strlen($right) + 1 > $chars) {
            $out[] = [$last, 'l', $size];
            $out[] = [str_repeat(' ', max(1, $chars - strlen($right))).$right, 'l', $size];
        } else {
            $out[] = [$last.str_repeat(' ', $chars - strlen($last) - strlen($right)).$right, 'l', $size];
        }
        return $out;
    }

    /**
     * V159 — KOI BHI REPORT, 80mm (3 inch) KAGHAZ PAR.
     *
     * ============================================================
     * YEH KYUN
     * ============================================================
     * Reports ka safha sirf A4 par chhapta tha (`@page{margin:14mm}`).
     * Magar counter par jo printer laga hota hai wo 80mm thermal hai —
     * A4 ka printer wahan hota hi nahi. Natija: sale ya closing ki
     * report dekhni ho to kaghaz par nikal hi nahi sakti thi, chahe
     * screen par maujood ho.
     *
     * 80mm ka poora engine pehle se maujood tha (`Pdf::receipt` +
     * yehi class) — bill aur shift closing isi se chhapte hain. Yeh
     * function usi engine ko har report ke liye khol deta hai.
     *
     * ============================================================
     * SHAKL KA FAISLA — COLUMN SAATH SAATH KYUN NAHI
     * ============================================================
     * 80mm par ek line mein sirf 37 characters aate hain. "Opening /
     * closing stock" ke 7 columns saath saath kabhi nahi samayenge;
     * zabardasti karne par har column 4-5 characters ka reh jata aur
     * number kat jate — jo report na hone se bhi bura hai.
     *
     * Is liye har row ka apna chhota block banta hai: pehla column
     * (naam) apni line par, baqi columns neeche "label ....... value"
     * ki shakl mein. Jahan sirf do columns hain (misal Payment
     * methods) wahan yeh khud ba khud ek hi line par aa jata hai, is
     * liye chhoti reports phoolti nahi.
     *
     * @param array $rep  ReportService::shape() ka natija
     *                    (title, columns[{k,l,n}], rows, totals, note)
     * @param array $ctx  wohi business context jo bill istemal karta hai
     * @param string $range  "01 Oct 2026 - 19 Oct 2026" jaisi line
     */
    public static function report(array $rep, array $ctx, string $range = ''): array
    {
        $cols = (array)($rep['columns'] ?? []);
        $rows = (array)($rep['rows'] ?? []);
        $tot  = (array)($rep['totals'] ?? []);

        $L = self::head([], $ctx, true);
        $L[] = [strtoupper((string)($rep['title'] ?? 'REPORT')), 'c', 9];
        if ($range !== '') $L[] = [$range, 'c', 7];
        $L[] = self::rule();

        if (!$rows) {
            $L[] = ['Is arse mein koi record nahi.', 'c', 8];
            $L[] = self::rule();
        } else {
            $first = $cols[0] ?? null;
            $rest  = array_slice($cols, 1);

            foreach ($rows as $r) {
                /* Pehla column = row ka naam. Bold, apni line par. */
                if ($first) {
                    $L[] = self::row(self::cell($r, $first), '', true);
                }
                foreach ($rest as $c) {
                    $v = self::cell($r, $c);
                    if ($v === '') continue;
                    $L[] = self::row('  ' . (string)($c['l'] ?? ''), $v);
                }
                $L[] = ['', 'l', self::SZ];
            }
            $L[] = self::rule();
        }

        /* Totals — sirf wo jo report ne waqai bheje. (Jis report ne
           jaan boojh kar totals nahi diye, misal stock ki report jahan
           PCS aur G ko jorna be-maani hai, wahan kuch nahi chhapta.) */
        if ($tot) {
            $L[] = ['TOTAL', 'c', 8];
            foreach ($cols as $c) {
                $k = (string)($c['k'] ?? '');
                if ($k === '' || !array_key_exists($k, $tot)) continue;
                $L[] = self::row((string)($c['l'] ?? $k), self::num($tot[$k]), true);
            }
            $L[] = self::rule('=');
        }

        if (!empty($rep['note'])) {
            $L[] = ['', 'l', self::SZ];
            $L = array_merge($L, self::wrap('Note: ' . (string)$rep['note']));
        }

        $L[] = ['', 'l', self::SZ];
        $L[] = ['Printed ' . date('d M Y  H:i'), 'c', 7];
        return $L;
    }

    /** Report ki ek khaane ki qadar, chhapne ke qabil shakl mein. */
    private static function cell(array $row, array $col): string
    {
        $k = (string)($col['k'] ?? '');
        if ($k === '' || !array_key_exists($k, $row)) return '';
        $v = $row[$k];
        if ($v === null) return '';
        return !empty($col['n']) ? self::num($v) : trim((string)$v);
    }

    /** Numbers par hazaron ka comma, magar paisay tabhi jab waqai hon. */
    private static function num($v): string
    {
        if (!is_numeric($v)) return trim((string)$v);
        $f = (float)$v;
        return (floor($f) == $f) ? number_format($f, 0) : number_format($f, 2);
    }

}

// build: V163 build 2026-10-25
