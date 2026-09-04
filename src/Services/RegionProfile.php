<?php
namespace Aio\Services;

use Aio\Auth;

/**
 * RegionProfile — ek business Pakistan, UK ya USA mein ho, POS ki
 * MATH badal jati hai, sirf labels nahi:
 *
 *   PK / UK : shelf price mein tax SHAMIL hai   -> net = gross / (1+r)
 *   US      : tax price ke UPAR lagti hai       -> gross = net + net*r
 *
 * Isi liye tax ka hisaab ek hi jagah rehta hai. Agar har screen apna
 * hisaab lagaye to receipt aur report kabhi barabar nahi hotin —
 * restaurant mein yehi ghalti FBR invoice reject karwati thi.
 */
final class RegionProfile
{
    public const PROFILES = [
        'PK' => [
            'code' => 'PK', 'label' => 'Pakistan', 'flag' => "\u{1F1F5}\u{1F1F0}",
            'currency' => 'PKR', 'symbol' => 'Rs', 'locale' => 'en-PK', 'decimals' => 0,
            'price_mode' => 'INCLUSIVE',
            'tax_driver' => 'PK_FBR', 'tax_label' => 'Sales Tax', 'default_tax' => 17.0,
            'barcode' => 'EAN13', 'weight_unit' => 'kg', 'scale_prefix' => '20',
            'credit_label' => 'Khata / Udhaar', 'timezone' => 'Asia/Karachi',
        ],
        'UK' => [
            'code' => 'UK', 'label' => 'United Kingdom', 'flag' => "\u{1F1EC}\u{1F1E7}",
            'currency' => 'GBP', 'symbol' => "\u{00A3}", 'locale' => 'en-GB', 'decimals' => 2,
            'price_mode' => 'INCLUSIVE',
            'tax_driver' => 'UK_VAT', 'tax_label' => 'VAT', 'default_tax' => 20.0,
            'barcode' => 'EAN13', 'weight_unit' => 'kg', 'scale_prefix' => '20',
            'credit_label' => 'Account Customer', 'timezone' => 'Europe/London',
        ],
        'US' => [
            'code' => 'US', 'label' => 'United States', 'flag' => "\u{1F1FA}\u{1F1F8}",
            'currency' => 'USD', 'symbol' => '$', 'locale' => 'en-US', 'decimals' => 2,
            'price_mode' => 'EXCLUSIVE',
            'tax_driver' => 'US_SALESTAX', 'tax_label' => 'Sales Tax', 'default_tax' => 8.25,
            'barcode' => 'UPCA', 'weight_unit' => 'lb', 'scale_prefix' => '2',
            'credit_label' => 'Account Customer', 'timezone' => 'America/New_York',
        ],
    ];

    public static function get(?string $code = null): array
    {
        $code = \strtoupper($code ?: Auth::tenantRegion());
        return self::PROFILES[$code] ?? self::PROFILES['PK'];
    }

    public static function isExclusive(?string $code = null): bool
    {
        return self::get($code)['price_mode'] === 'EXCLUSIVE';
    }

    /**
     * Ek line ka tax nikalo.
     *
     * @return array{net:float,tax:float,gross:float}
     */
    public static function taxSplit(float $lineTotal, float $ratePct, ?string $code = null): array
    {
        $r = $ratePct / 100;
        if (self::isExclusive($code)) {
            $tax = $lineTotal * $r;
            return ['net' => $lineTotal, 'tax' => $tax, 'gross' => $lineTotal + $tax];
        }
        $net = $r > -1 ? $lineTotal / (1 + $r) : $lineTotal;
        return ['net' => $net, 'tax' => $lineTotal - $net, 'gross' => $lineTotal];
    }

    /**
     * Poore bill ka hisaab — SIRF YAHAN.
     *
     * Header ka tax lines ke jama se banta hai, alag se dobara nahi
     * ginta. Per-line rounding se jo mismatch paida hota tha (aur FBR
     * jis par invoice reject karta tha) woh isi tareeqe se khatam hota
     * hai — yehi usool FiscalService mein pehle se chal raha hai.
     *
     * @param array<int,array{qty:float,unit_price:float,discount?:float,tax_rate?:float}> $lines
     * @return array{subtotal:float,tax:float,discount:float,total:float,units:float}
     */
    public static function billTotals(array $lines, float $billDiscount = 0.0, ?string $code = null): array
    {
        $sub = 0.0; $tax = 0.0; $units = 0.0;
        $ex = self::isExclusive($code);

        foreach ($lines as $l) {
            $qty  = (float)($l['qty'] ?? 0);
            $rate = (float)($l['tax_rate'] ?? 0);
            $lt   = \max(0.0, $qty * (float)($l['unit_price'] ?? 0) - (float)($l['discount'] ?? 0));
            $sp   = self::taxSplit($lt, $rate, $code);
            $sub += $ex ? $sp['net'] : $sp['gross'];
            $tax += $sp['tax'];
            $units += $qty;
        }

        $billDiscount = \min($billDiscount, $sub);
        $after = \max(0.0, $sub - $billDiscount);
        /* Bill discount tax ko bhi utna hi kam karti hai — warna customer
           discount ke bawajood poora tax de raha hota hai. */
        if ($billDiscount > 0 && $sub > 0) $tax = $tax * ($after / $sub);

        return [
            'subtotal' => \round($sub, 4),
            'tax'      => \round($tax, 4),
            'discount' => \round($billDiscount, 4),
            'total'    => \round($ex ? $after + $tax : $after, 4),
            'units'    => $units,
        ];
    }

    /**
     * Scale ka chhapa hua label: <prefix><PLU 5><value 5><check 1>
     * Supermarket ki taraazu khud label nikalti hai; POS usay parse
     * karke weight nikal leta hai.
     *
     * @return array{plu:string,value:float}|null
     */
    public static function parseScaleBarcode(string $code, ?string $code2 = null): ?array
    {
        $p = self::get($code2);
        $code = \trim($code);
        $pre = (string)$p['scale_prefix'];
        if ($pre === '' || \strpos($code, $pre) !== 0) return null;
        if (\strlen($code) < 12) return null;
        if (!\ctype_digit($code)) return null;
        return [
            'plu'   => \substr($code, \strlen($pre), 5),
            'value' => ((float)\substr($code, \strlen($pre) + 5, 5)) / 1000,
        ];
    }

    /** Client ko bhejne ke liye — JS wala Region isi shape ko parhta hai. */
    public static function forClient(?string $code = null): array
    {
        return self::get($code);
    }
}
