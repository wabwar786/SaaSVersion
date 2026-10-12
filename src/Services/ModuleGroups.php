<?php
/**
 * ModuleGroups — left menu ki GROUPING ek jagah.
 *
 * MASLA JO YEH HAL KARTA HAI:
 * Super Admin ka "Modules for this business" wala safha 39 checkbox ki ek
 * lambi, bey-tarteeb fehrist tha. Customer ka left menu us se bilkul mail
 * nahi khata: wahan OPERATIONS / INVENTORY & MENU / ... ke under module
 * baithte hain. Is liye yeh faisla karna mushkil tha ke kis heading ke
 * neeche se kya hide ho raha hai.
 *
 * Ab wohi tarteeb, wohi headings — aur server par ek hi jagah, taake
 * sidebar aur Super Admin ka safha kabhi alag na hon.
 *
 * AHEM: yeh fehrist `public/shell.js` (restaurant) aur
 * `approved_ui/retail/shell.js` (retail) ke GROUPS se HARF BA HARF milni
 * chahiye. Is ki jaanch ke liye:
 *     php scripts/check_module_groups.php
 * Woh script dono shell.js parh kar in se milati hai aur farq par fail
 * hoti hai — drift khamoshi se nahi guzar sakti.
 */
declare(strict_types=1);

namespace Aio\Services;

use Aio\DB;

final class ModuleGroups
{
    /** Dashboard kabhi hide nahi hota — wahi ghar ka safha hai. */
    public const ALWAYS_ON = ['dashboard'];

    /** industry => [ [title, [module_key,...]], ... ] */
    private const LAYOUT = [
        'RESTAURANT' => [
            ['Operations',            ['shift','pos','tablet','kds','tables','orders','online']],
            ['Inventory & Menu',      ['inventory','purchasing','po','recipe','menu','wastage','transfer','count','dayclose','suppliers']],
            ['Customers & Delivery',  ['customers','customer_app','customer_web','delivery','riders','reservations','loyalty','whatsapp']],
            ['Finance & Reports',     ['expenses','accounting','promotions','staff','void','closing','reports','activity','fbr']],
            ['System',                ['printers','branches','offline','users','settings']],
        ],
        'RETAIL' => [
            ['Sale Counter',          ['shift','rpos','counters','sales','void','khata']],
            ['Catalog & Pricing',     ['products','departments','brands','uom','pricing','scale','labels']],
            ['Stock & Purchasing',    ['inventory','batches','purchasing','po','grn','preturn','suppliers','transfer','count','dayclose','wastage']],
            ['Customers & Marketing', ['customers','loyalty','promotions','whatsapp']],
            ['Finance & Reports',     ['expenses','accounting','closing','reports','fbr']],
            ['System',                ['staff','users','printers','branches','offline','activity','settings']],
        ],
    ];

    public static function industries(): array { return array_keys(self::LAYOUT); }

    /** Sirf tarteeb (DB ke baghair) — check script isi ko use karti hai. */
    public static function layout(string $industry): array
    {
        $ind = strtoupper($industry) ?: 'RESTAURANT';
        return self::LAYOUT[$ind] ?? self::LAYOUT['RESTAURANT'];
    }

    /**
     * Super Admin ke safhe ke liye poora dhancha: headings, un ke modules,
     * har module ka asli naam (platform_modules se) aur uski halat.
     *
     * Jo module catalog mein hai magar kisi group mein nahi aaya, woh
     * "Other" ke neeche chala jata hai — kabhi gayab nahi hota, warna
     * Super Admin usay tick/untick hi na kar pata.
     */
    public static function forIndustry(string $industry): array
    {
        $ind = strtoupper($industry) ?: 'RESTAURANT';

        $names = [];
        try {
            $q = DB::pdo()->prepare(
                "SELECT module_key, name
                   FROM platform_modules
                  WHERE is_active=1
                    AND industry_code IN (?, 'COMMON')
                  ORDER BY sort_order, name"
            );
            $q->execute([$ind]);
            foreach ($q->fetchAll() as $r) $names[(string)$r['module_key']] = (string)$r['name'];
        } catch (\Throwable $e) { $names = []; }

        $groups = []; $placed = [];
        foreach (self::layout($ind) as [$title, $keys]) {
            $items = [];
            foreach ($keys as $k) {
                if (!isset($names[$k])) continue;      // is industry ka module nahi
                $placed[$k] = true;
                $items[] = [
                    'key'    => $k,
                    'title'  => $names[$k],
                    'status' => self::status($k),
                    'locked' => in_array($k, self::ALWAYS_ON, true),
                ];
            }
            if ($items) $groups[] = ['title' => $title, 'items' => $items];
        }

        $rest = [];
        foreach ($names as $k => $n) {
            if (isset($placed[$k]) || in_array($k, self::ALWAYS_ON, true)) continue;
            $rest[] = ['key'=>$k,'title'=>$n,'status'=>self::status($k),'locked'=>false];
        }
        if ($rest) $groups[] = ['title' => 'Other', 'items' => $rest];

        /* Dashboard apne alag, "always on" khane mein — Super Admin ko
           nazar aaye ke yeh hamesha chalta hai, magar tick na kar sake. */
        $always = [];
        foreach (self::ALWAYS_ON as $k) {
            if (isset($names[$k])) $always[] = ['key'=>$k,'title'=>$names[$k],'status'=>'working','locked'=>true];
        }
        if ($always) array_unshift($groups, ['title' => 'Always on', 'items' => $always]);

        return $groups;
    }

    /** Sab module keys jo is industry ke groups mein aate hain. */
    public static function keysFor(string $industry): array
    {
        $out = self::ALWAYS_ON;
        foreach (self::forIndustry($industry) as $g)
            foreach ($g['items'] as $it) $out[] = $it['key'];
        return array_values(array_unique($out));
    }

    /* ------------------------------------------------------------
       Module ki ASLI halat — yeh pehle super_admin.html ke andar
       hard-coded parhi thi (READY / PARTIAL / DEMO). Server par aane
       se dono verticals ek hi sach dekhte hain.
       ------------------------------------------------------------ */
    private const READY = ['dashboard','pos','rpos','tablet','menu','tables','customers','suppliers',
        'expenses','wastage','printers','users','settings','reports','offline','kds','shift','orders',
        'void','promotions','reservations','riders','staff','branches','loyalty','transfer','count',
        'accounting','online','whatsapp','delivery','closing','activity','fbr','sales','counters',
        'khata','products','departments','brands','uom','pricing','labels','batches','grn','po','dayclose'];
    private const PARTIAL = ['inventory','purchasing','recipe','customer_web','scale','preturn'];
    private const DEMO    = ['customer_app'];

    public static function status(string $key): string
    {
        if (in_array($key, self::READY, true))   return 'working';
        if (in_array($key, self::PARTIAL, true)) return 'partial';
        if (in_array($key, self::DEMO, true))    return 'demo';
        return 'screen';
    }

    /** "Working only" button ke liye. */
    public static function workingKeys(string $industry): array
    {
        $out = [];
        foreach (self::keysFor($industry) as $k) if (self::status($k) === 'working') $out[] = $k;
        return $out;
    }
}
