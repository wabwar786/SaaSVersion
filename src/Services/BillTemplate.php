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

    /** 80mm par ~32 characters aate hain (Courier 9pt). */
    private const W = 32;

    private static function rule(string $c = '-'): array { return [str_repeat($c, self::W), 'l', 8]; }

    /** Bayen naam, dayen raqam — ek hi line par, beech mein spaces. */
    private static function row(string $left, string $right, int $size = 9, bool $bold = false): array
    {
        $left  = trim($left);
        $right = trim($right);
        $room  = self::W - strlen($right);
        if ($room < 1) $room = 1;
        if (strlen($left) > $room - 1) $left = substr($left, 0, max(1, $room - 2)) . '.';
        $pad = self::W - strlen($left) - strlen($right);
        if ($pad < 1) $pad = 1;
        return [$left . str_repeat(' ', $pad) . $right, $bold ? 'b' : 'l', $size];
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

    private static function head(array $o, array $ctx, bool $full): array
    {
        $L = [];
        $biz = trim((string)($ctx['business'] ?? '')) ?: (string)($ctx['site'] ?? 'Restaurant');
        $L[] = [$biz, 'c', 12];
        if ($full) {
            if (!empty($ctx['site']) && $ctx['site'] !== $biz) $L[] = [(string)$ctx['site'], 'c', 8];
            if (!empty($ctx['address'])) $L[] = [(string)$ctx['address'], 'c', 8];
            if (!empty($ctx['phone']))   $L[] = ['Ph: '.(string)$ctx['phone'], 'c', 8];
            if (!empty($ctx['ntn']))     $L[] = ['NTN: '.(string)$ctx['ntn'], 'c', 8];
        }
        if (!empty($ctx['header'])) $L[] = [(string)$ctx['header'], 'c', 8];
        return $L;
    }

    private static function meta(array $o, bool $full): array
    {
        $L = [];
        $when = date('d M Y  H:i', strtotime((string)($o['closed_at'] ?: $o['created_at'])));
        $L[] = self::row('Bill # '.(string)$o['bill_no'], $when, 8);
        $line2 = (string)$o['service_mode'] . (!empty($o['tbl']) ? ('  '.$o['tbl']) : '');
        if (!empty($o['cashier'])) $line2 .= '  /  '.(string)$o['cashier'];
        $L[] = [$line2, 'l', 8];
        if ($full && !empty($o['cust'])) {
            $L[] = ['Customer: '.(string)$o['cust'].(!empty($o['cphone']) ? (' '.$o['cphone']) : ''), 'l', 8];
        }
        return $L;
    }

    private static function totals(array $o, array $ctx, int $size = 9): array
    {
        $cur = (string)($ctx['currency'] ?? 'PKR');
        $L = [];
        $L[] = self::row('Subtotal', self::money((float)$o['subtotal']), $size);
        if ((float)$o['discount_amount'] > 0) $L[] = self::row('Discount', '-'.self::money((float)$o['discount_amount']), $size);
        if ((float)$o['service_charge']  > 0) $L[] = self::row('Service Charge', self::money((float)$o['service_charge']), $size);
        if ((float)$o['tax_amount']      > 0) $L[] = self::row('Sales Tax', self::money((float)$o['tax_amount']), $size);
        $L[] = self::rule('=');
        $L[] = self::row('TOTAL '.$cur, self::money((float)$o['grand_total']), 11, true);
        return $L;
    }

    private static function foot(array $ctx, bool $full): array
    {
        $L = [];
        $L[] = ['', 'l', 8];
        if (!empty($ctx['fbr_no'])) {
            $L[] = ['FBR Invoice: '.(string)$ctx['fbr_no'], 'c', 8];
            $L[] = ['[ QR ]', 'c', 8];
        } elseif (!empty($ctx['fbr_pending'])) {
            /* Khamosh nahi. Agar FBR tak nahi pohancha to bill par likha ho. */
            $L[] = ['FBR: PENDING', 'c', 8];
        }
        $L[] = [trim((string)($ctx['footer'] ?? '')) ?: 'Thank you! Visit again.', 'c', 8];
        if ($full) $L[] = ['Powered by Wabwar Software House', 'c', 7];
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
        $L[] = self::row('Item', 'Amount', 8, true);
        $L[] = self::rule();
        foreach ($items as $it) {
            $L[] = [(string)$it['nm'], 'l', 9];
            $L[] = self::row('   '.self::qty((float)$it['qty']).' x '.self::money((float)$it['unit_price']),
                             self::money((float)$it['line_total']), 8);
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
                             self::money((float)$it['line_total']), 8);
        }
        $L[] = self::rule();
        $L = array_merge($L, self::totals($o, $ctx, 8));
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
        $L[] = self::row('Item / Qty x Rate', 'Tax   Amount', 8, true);
        $L[] = self::rule();
        foreach ($items as $it) {
            $rate = isset($it['tax_rate']) ? (float)$it['tax_rate'] : (float)($ctx['tax_rate'] ?? 0);
            $tax  = isset($it['tax_amount']) ? (float)$it['tax_amount'] : 0.0;
            $L[] = [(string)$it['nm'], 'l', 9];
            $L[] = self::row('   '.self::qty((float)$it['qty']).' x '.self::money((float)$it['unit_price'])
                             .'  @'.rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.').'%',
                             self::money($tax).'  '.self::money((float)$it['line_total']), 8);
        }
        $L[] = self::rule();
        $L = array_merge($L, self::totals($o, $ctx));
        $L[] = ['', 'l', 8];
        $L[] = ['Payment: '.(string)($o['pay_mode'] ?? '-'), 'l', 8];
        return array_merge($L, self::foot($ctx, true));
    }
}

// build: V64 build 2026-08-27
