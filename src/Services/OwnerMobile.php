<?php
/**
 * OwnerMobile — malik ke phone wale portal ka saara data, ek jagah.
 *
 * ============================================================
 * YEH ALAG SERVICE KYUN
 * ============================================================
 * `PageData::dashboard()` counter ki screen ke liye bana tha: wo
 * "abhi kya chal raha hai" batata hai. Malik ka sawal doosra hai —
 * "aaj kitna BACHA", "kal se behtar hai ya bad-tar", "kya khatam
 * honay ko hai", aur "kis ka account abhi tak khula hai".
 *
 * Dono ko ek hi function mein thoosne se wo function har screen ke
 * liye zaroorat se zyada queries chalata. Is liye alag.
 *
 * EK HI CALL. Phone ka internet kamzor hota hai; char alag requests
 * ka matlab char dafa intezar aur char dafa nakami ka imkaan. Poora
 * portal ek `owner-mobile-boot` se bharta hai.
 *
 * YEH SERVICE KUCH LIKHTI NAHI. Sirf `watch()` ek nishan badalta hai
 * (kis cheez par nazar rakhni hai) — stock ki qty ya koi bhi raqam
 * yahan se nahi badalti.
 */
declare(strict_types=1);

namespace Aio\Services;

use Aio\DB;
use PDO;

final class OwnerMobile
{
    /* ------------------------------------------------------------
       chhote madadgar
       ------------------------------------------------------------ */
    private static function q(string $sql, array $args = []): array
    {
        $st = DB::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    private static function one(string $sql, array $args = [])
    {
        $st = DB::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchColumn();
    }
    private static function f($v): float { return (float)$v; }

    /**
     * Gram aur millilitre andar rakhe jate hain, magar malik KG aur
     * litre mein sochta hai. 42,000 likha dekh kar wo rupay samajh
     * baith sakta hai. (Wahi qanoon jo Inventory screen aur stock
     * report chalati hai — teeno jagah ek jaisa.)
     *
     * AHEM: paimana EK DAFA, POORE ITEM par tay hota hai — har qadar
     * ke liye alag nahi. Pehle yeh ghalti ho chuki hai: stock 42,002 g
     * ko "42 KG" bana diya jata, magar usi din aaya hua 5 g "5" hi
     * reh jata — aur screen par "42 KG hai, aaj 5 aaya" likha nazar
     * aata. Paanch kya? Us lafz ka koi matlab hi nahi tha.
     *
     * Faisla sab se BARI qadar se hota hai, sirf mojooda stock se
     * nahi: jis cheez ka stock aaj khatam ho chuka ho us ka "aaj laga"
     * phir bhi hazaron mein hota hai.
     *
     * @return array{0:float,1:string}  [taqseem ka adad, unit ka naam]
     */
    private static function scale(array $vals, string $unit): array
    {
        $u = strtoupper($unit);
        $max = 0.0;
        foreach ($vals as $v) $max = max($max, abs((float)$v));
        if ($max >= 1000 && ($u === 'G' || $u === 'ML')) {
            return [1000.0, $u === 'G' ? 'KG' : 'L'];
        }
        return [1.0, $u === 'PCS' ? 'pcs' : ($u ?: '')];
    }

    /* ============================================================
       BOOT — poora portal ek call mein
       ============================================================ */
    public static function boot(): array
    {
        return [
            'biz'    => self::biz(),
            'today'  => self::today(),
            'sale'   => self::sale(30),
            'stock'  => self::stock(),
            'acct'   => self::acct(),
            'at'     => date('c'),
        ];
    }

    private static function biz(): array
    {
        $st = SettingsService::get();
        $u  = current_user() ?: [];
        $name = (string)($st['business_name'] ?? '') ?: (string)($st['branch_name'] ?? 'Business');
        $ini  = '';
        foreach (preg_split('/\s+/', trim($name)) as $w) { if ($w !== '') $ini .= strtoupper($w[0]); }
        return [
            'name'   => $name,
            'branch' => (string)($st['branch_name'] ?? ''),
            'ini'    => substr($ini, 0, 2) ?: 'B',
            'user'   => (string)($u['full_name'] ?? ''),
            'cur'    => (string)($st['currency'] ?? 'PKR'),
        ];
    }

    /* ============================================================
       AAJ
       ============================================================ */
    private static function today(): array
    {
        $sid = site_id();
        $d   = today();
        $y   = date('Y-m-d', strtotime('-1 day', strtotime($d)));

        $head = self::q(
            "SELECT COUNT(*) bills, COALESCE(SUM(grand_total),0) net,
                    COALESCE(SUM(discount_amount),0) disc,
                    COALESCE(SUM(guest_count),0) guests
               FROM orders
              WHERE site_id=? AND business_date=? AND order_status='CLOSED'", [$sid, $d])[0]
            ?? ['bills'=>0,'net'=>0,'disc'=>0,'guests'=>0];

        /* ============================================================
           "KAL IS WAQT TAK" — poore kal se moqabla nahi.

           Dopahar 2 baje aaj ki aadhi sale ko kal ke POORE din se
           milana hamesha "kam" dikhata hai, chahe aaj behtar ja raha
           ho. Is liye kal ka bhi utna hi hissa liya jata hai jitna
           abhi guzra hai.
           ============================================================ */
        $yest = self::q(
            "SELECT COUNT(*) bills, COALESCE(SUM(grand_total),0) net,
                    COALESCE(SUM(guest_count),0) guests
               FROM orders
              WHERE site_id=? AND business_date=? AND order_status='CLOSED'
                AND TIME(COALESCE(closed_at,created_at)) <= TIME(NOW())", [$sid, $y])[0]
            ?? ['bills'=>0,'net'=>0,'guests'=>0];

        $pay = ['cash'=>0.0,'card'=>0.0,'online'=>0.0];
        foreach (self::q(
            "SELECT pm.method_type t, COALESCE(SUM(py.amount),0) a
               FROM payments py
               JOIN payment_methods pm ON pm.id=py.payment_method_id
               JOIN orders o ON o.id=py.order_id
              WHERE py.site_id=? AND py.status='COMPLETED' AND o.business_date=?
              GROUP BY pm.method_type", [$sid, $d]) as $r) {
            $t = strtoupper((string)$r['t']);
            if ($t === 'CASH')      $pay['cash']   += self::f($r['a']);
            elseif ($t === 'CARD')  $pay['card']   += self::f($r['a']);
            else                    $pay['online'] += self::f($r['a']);
        }

        /* Ghante — sirf wo ghante jin mein kuch hua. Khali 00-10 ki
           qataar malik ko kuch nahi batati, sirf jagah khati hai. */
        $hours = [];
        foreach (self::q(
            "SELECT HOUR(closed_at) h, COALESCE(SUM(grand_total),0) a
               FROM orders WHERE site_id=? AND business_date=? AND order_status='CLOSED'
              GROUP BY HOUR(closed_at) ORDER BY h", [$sid, $d]) as $r) {
            $hours[] = ['h' => (int)$r['h'], 'a' => self::f($r['a'])];
        }

        $items = [];
        foreach (self::q(
            "SELECT oi.item_name_snapshot n, SUM(oi.qty) q, SUM(oi.line_total) a
               FROM order_items oi JOIN orders o ON o.id=oi.order_id
              WHERE o.site_id=? AND o.business_date=? AND o.order_status='CLOSED'
              GROUP BY oi.item_name_snapshot ORDER BY a DESC LIMIT 6", [$sid, $d]) as $r) {
            $items[] = ['n'=>(string)$r['n'],'q'=>self::f($r['q']),'a'=>self::f($r['a'])];
        }

        $staff = [];
        foreach (self::q(
            "SELECT COALESCE(u.full_name,'—') n, COUNT(*) bills,
                    COALESCE(SUM(o.grand_total),0) a
               FROM orders o LEFT JOIN users u ON u.id=o.created_by_user_id
              WHERE o.site_id=? AND o.business_date=? AND o.order_status='CLOSED'
              GROUP BY o.created_by_user_id, u.full_name ORDER BY a DESC LIMIT 6", [$sid, $d]) as $r) {
            $staff[] = ['n'=>(string)$r['n'],'bills'=>(int)$r['bills'],'a'=>self::f($r['a'])];
        }

        $cost = self::f(self::one(
            "SELECT COALESCE(SUM(ABS(stl.value_change)),0)
               FROM stock_transaction_lines stl
               JOIN stock_transactions st ON st.id=stl.stock_transaction_id
              WHERE st.site_id=? AND st.business_date=? AND st.transaction_type='SALE'", [$sid, $d]));
        $exp = self::f(self::one(
            "SELECT COALESCE(SUM(amount),0) FROM expenses
              WHERE site_id=? AND expense_date=? AND status='APPROVED'", [$sid, $d]));

        /* Abhi kya chal raha hai */
        $live = [
            'running' => (int)self::one("SELECT COUNT(*) FROM orders WHERE site_id=? AND order_status<>'CLOSED'", [$sid]),
            'kitchen' => (int)self::one("SELECT COUNT(*) FROM kitchen_tickets WHERE site_id=? AND ticket_status IN ('NEW','PREPARING')", [$sid]),
            'late'    => (int)self::one("SELECT COUNT(*) FROM kitchen_tickets WHERE site_id=? AND ticket_status IN ('NEW','PREPARING') AND TIMESTAMPDIFF(MINUTE,sent_at,NOW())>=18", [$sid]),
            'tables'  => (int)self::one("SELECT COUNT(*) FROM dining_tables WHERE site_id=? AND is_active=1", [$sid]),
            'busy'    => (int)self::one("SELECT COUNT(*) FROM dining_tables WHERE site_id=? AND is_active=1 AND status='OCCUPIED'", [$sid]),
        ];

        $net   = self::f($head['net']);
        $bills = (int)$head['bills'];

        return [
            'date'    => $d,
            'net'     => $net,
            'bills'   => $bills,
            'avg'     => $bills ? round($net / $bills) : 0,
            'guests'  => (int)$head['guests'],
            'disc'    => self::f($head['disc']),
            'cost'    => $cost,
            'exp'     => $exp,
            'left'    => $net - $cost - $exp,
            'yest'    => ['net'=>self::f($yest['net']),'bills'=>(int)$yest['bills'],'guests'=>(int)$yest['guests']],
            'pay'     => $pay,
            'hours'   => $hours,
            'items'   => $items,
            'staff'   => $staff,
            'live'    => $live,
        ];
    }

    /* ============================================================
       SALE — arse ka hisaab
       ============================================================ */
    public static function sale(int $days = 30): array
    {
        $sid  = site_id();
        $days = max(1, min(366, $days));
        $to   = today();
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' day', strtotime($to)));
        /* Isi lambai ka pichhla arsa — moqable ke liye */
        $pTo   = date('Y-m-d', strtotime('-' . $days . ' day', strtotime($to)));
        $pFrom = date('Y-m-d', strtotime('-' . ($days * 2 - 1) . ' day', strtotime($to)));

        $sum = fn(string $a, string $b) => self::q(
            "SELECT COUNT(*) bills, COALESCE(SUM(grand_total),0) net
               FROM orders WHERE site_id=? AND business_date BETWEEN ? AND ?
                AND order_status='CLOSED'", [$sid, $a, $b])[0] ?? ['bills'=>0,'net'=>0];

        $cur  = $sum($from, $to);
        $prev = $sum($pFrom, $pTo);

        $daily = [];
        foreach (self::q(
            "SELECT business_date d, COALESCE(SUM(grand_total),0) a
               FROM orders WHERE site_id=? AND business_date BETWEEN ? AND ?
                AND order_status='CLOSED' GROUP BY business_date ORDER BY business_date",
            [$sid, $from, $to]) as $r) {
            $daily[] = ['d'=>(string)$r['d'],'a'=>self::f($r['a'])];
        }

        $mode = ['dine'=>0.0,'take'=>0.0,'deliver'=>0.0];
        foreach (self::q(
            "SELECT service_mode m, COALESCE(SUM(grand_total),0) a
               FROM orders WHERE site_id=? AND business_date BETWEEN ? AND ?
                AND order_status='CLOSED' GROUP BY service_mode", [$sid, $from, $to]) as $r) {
            $m = strtoupper((string)$r['m']);
            if ($m === 'DINE_IN')        $mode['dine']    += self::f($r['a']);
            elseif ($m === 'TAKEAWAY')   $mode['take']    += self::f($r['a']);
            else                         $mode['deliver'] += self::f($r['a']);
        }

        $cats = [];
        foreach (self::q(
            "SELECT COALESCE(mc.name,'Other') n, SUM(oi.qty) q, SUM(oi.line_total) a
               FROM order_items oi
               JOIN orders o ON o.id=oi.order_id
               LEFT JOIN menu_items mi ON mi.id=oi.menu_item_id
               LEFT JOIN menu_categories mc ON mc.id=mi.category_id
              WHERE o.site_id=? AND o.business_date BETWEEN ? AND ? AND o.order_status='CLOSED'
              GROUP BY mc.name ORDER BY a DESC LIMIT 8", [$sid, $from, $to]) as $r) {
            $cats[] = ['n'=>(string)$r['n'],'q'=>self::f($r['q']),'a'=>self::f($r['a'])];
        }

        /* ============================================================
           NAFA — bikne wali cheez ki lagat us ki recipe se.

           Jo sab se zyada bikta hai zaroori nahi wohi sab se zyada
           deta ho. Naan 90 ka bikta hai aur 34 ka parta hai; fish
           tikka 850 ka bikta hai magar 492 ka parta hai.

           Lagat do raaston se aati hai: ya to item seedha kisi
           inventory cheez se juda hai (DIRECT_INVENTORY), ya us ki
           recipe hai. Jin ka koi rasta nahi, un ka nafa nahi dikhaya
           jata — ghalat number se koi number behtar nahi.
           ============================================================ */
        $margin = [];
        foreach (self::q(
            "SELECT mi.name n, mi.base_price price,
                    SUM(oi.qty) q, SUM(oi.line_total) a,
                    CASE
                      WHEN mi.consumption_type='DIRECT_INVENTORY' AND mi.direct_inventory_item_id IS NOT NULL
                        THEN COALESCE(mi.direct_inventory_qty,1) * COALESCE(di.avg_cost_per_stock_unit,0)
                      WHEN r.id IS NOT NULL
                        THEN COALESCE((SELECT SUM(ri.qty_per_yield * COALESCE(ii.avg_cost_per_stock_unit,0))
                                         FROM recipe_ingredients ri
                                         JOIN inventory_items ii ON ii.id=ri.inventory_item_id
                                        WHERE ri.recipe_id=r.id),0)
                           / GREATEST(COALESCE(r.yield_qty,1),1)
                      ELSE NULL
                    END AS cost
               FROM order_items oi
               JOIN orders o ON o.id=oi.order_id
               JOIN menu_items mi ON mi.id=oi.menu_item_id
               LEFT JOIN inventory_items di ON di.id=mi.direct_inventory_item_id
               LEFT JOIN recipes r ON r.menu_item_id=mi.id AND r.is_current=1
              WHERE o.site_id=? AND o.business_date BETWEEN ? AND ? AND o.order_status='CLOSED'
              GROUP BY mi.id, mi.name, mi.base_price, mi.consumption_type,
                       mi.direct_inventory_item_id, mi.direct_inventory_qty,
                       di.avg_cost_per_stock_unit, r.id, r.yield_qty
              HAVING cost IS NOT NULL AND cost > 0
              ORDER BY a DESC LIMIT 40", [$sid, $from, $to]) as $r) {
            $price = self::f($r['price']); $cost = self::f($r['cost']);
            if ($price <= 0 || $cost <= 0 || $cost >= $price) continue;
            $margin[] = ['n'=>(string)$r['n'],'price'=>$price,'cost'=>round($cost),
                         'pct'=>round((1 - $cost / $price) * 100),'a'=>self::f($r['a'])];
        }
        usort($margin, fn($x, $y) => $y['pct'] <=> $x['pct']);
        $margin = array_slice($margin, 0, 6);

        $cost = self::f(self::one(
            "SELECT COALESCE(SUM(ABS(stl.value_change)),0)
               FROM stock_transaction_lines stl
               JOIN stock_transactions st ON st.id=stl.stock_transaction_id
              WHERE st.site_id=? AND st.business_date BETWEEN ? AND ? AND st.transaction_type='SALE'",
            [$sid, $from, $to]));
        $exp = self::f(self::one(
            "SELECT COALESCE(SUM(amount),0) FROM expenses
              WHERE site_id=? AND expense_date BETWEEN ? AND ? AND status='APPROVED'", [$sid, $from, $to]));
        $void = self::q(
            "SELECT COUNT(*) n, COALESCE(SUM(grand_total),0) a FROM orders
              WHERE site_id=? AND business_date BETWEEN ? AND ? AND order_status='VOID'",
            [$sid, $from, $to])[0] ?? ['n'=>0,'a'=>0];

        $net = self::f($cur['net']);
        return [
            'days'   => $days, 'from' => $from, 'to' => $to,
            'net'    => $net,
            'bills'  => (int)$cur['bills'],
            'perDay' => $days ? round($net / $days) : 0,
            'prev'   => self::f($prev['net']),
            'daily'  => $daily,
            'mode'   => $mode,
            'cats'   => $cats,
            'margin' => $margin,
            'cost'   => $cost,
            'costPct'=> $net > 0 ? round($cost / $net * 100) : 0,
            'exp'    => $exp,
            'void'   => ['n'=>(int)$void['n'],'a'=>self::f($void['a'])],
        ];
    }

    /* ============================================================
       STOCK — abhi ka, har cheez
       ============================================================ */
    public static function stock(): array
    {
        $sid = site_id();
        $d   = today();

        $rows = self::q(
            "SELECT ii.id, ii.name, COALESCE(ic.name,'') cat, COALESCE(u.code,'') unit,
                    COALESCE(ii.reorder_level,0) min_qty,
                    COALESCE(ii.avg_cost_per_stock_unit,0) rate,
                    COALESCE(ii.is_tracked,0) watch,
                    COALESCE((SELECT SUM(sb.qty_on_hand) FROM stock_balances sb
                               WHERE sb.inventory_item_id=ii.id AND sb.site_id=?),0) qty,
                    COALESCE((SELECT SUM(stl.qty_change) FROM stock_transaction_lines stl
                               JOIN stock_transactions st ON st.id=stl.stock_transaction_id
                              WHERE stl.inventory_item_id=ii.id AND st.site_id=?
                                AND st.business_date=? AND stl.qty_change>0),0) in_today,
                    COALESCE((SELECT -SUM(stl.qty_change) FROM stock_transaction_lines stl
                               JOIN stock_transactions st ON st.id=stl.stock_transaction_id
                              WHERE stl.inventory_item_id=ii.id AND st.site_id=?
                                AND st.business_date=? AND stl.qty_change<0),0) out_today
               FROM inventory_items ii
               LEFT JOIN inventory_categories ic ON ic.id=ii.category_id
               LEFT JOIN units u ON u.id=ii.stock_unit_id
              WHERE ii.site_id=? AND ii.is_active=1 AND ii.deleted_at IS NULL
              ORDER BY ii.name", [$sid, $sid, $d, $sid, $d, $sid]);

        $items = []; $value = 0.0; $low = 0;
        foreach ($rows as $r) {
            $rawQty = self::f($r['qty']);
            $rawMin = self::f($r['min_qty']);
            $rate   = self::f($r['rate']);
            $val    = $rawQty * $rate;
            $value += $val;

            $rawIn  = self::f($r['in_today']);
            $rawOut = self::f($r['out_today']);

            /* Ek hi paimana, poore item par. "Khatam honay ko" ka
               faisla KACHI qadron par hota hai — paimana badalne se
               nateeja nahi badalna chahiye. */
            [$div, $u] = self::scale([$rawQty, $rawMin, $rawIn, $rawOut], (string)$r['unit']);
            if ($rawMin > 0 && $rawQty <= $rawMin) $low++;

            $items[] = [
                'id'    => (string)$r['id'],
                'n'     => (string)$r['name'],
                'c'     => (string)$r['cat'],
                'q'     => round($rawQty / $div, 3),
                'u'     => $u,
                'min'   => round($rawMin / $div, 3),
                'val'   => round($val),
                'watch' => (int)$r['watch'] === 1 ? 1 : 0,
                'in'    => round($rawIn / $div, 3),
                'out'   => round($rawOut / $div, 3),
                'open'  => round(($rawQty - $rawIn + $rawOut) / $div, 3),
            ];
        }

        $waste = self::f(self::one(
            "SELECT COALESCE(SUM(ABS(stl.value_change)),0)
               FROM stock_transaction_lines stl
               JOIN stock_transactions st ON st.id=stl.stock_transaction_id
              WHERE st.site_id=? AND st.business_date=? AND st.transaction_type='WASTAGE'", [$sid, $d]));
        $inVal = self::f(self::one(
            "SELECT COALESCE(SUM(stl.value_change),0)
               FROM stock_transaction_lines stl
               JOIN stock_transactions st ON st.id=stl.stock_transaction_id
              WHERE st.site_id=? AND st.business_date=? AND stl.qty_change>0", [$sid, $d]));
        $outVal = self::f(self::one(
            "SELECT COALESCE(SUM(ABS(stl.value_change)),0)
               FROM stock_transaction_lines stl
               JOIN stock_transactions st ON st.id=stl.stock_transaction_id
              WHERE st.site_id=? AND st.business_date=? AND stl.qty_change<0
                AND st.transaction_type='SALE'", [$sid, $d]));

        return ['value'=>round($value), 'count'=>count($items), 'low'=>$low,
                'inVal'=>round($inVal), 'outVal'=>round($outVal), 'waste'=>round($waste),
                'items'=>$items];
    }

    /**
     * Kisi cheez par nazar rakhni hai ya nahi.
     *
     * Yeh wohi nishan hai jo raat ki closing parchi par "tracked
     * inventory" ka hissa banta hai. Stock ki qty is se nahi badalti —
     * sirf yeh ke malik is par nazar rakhna chahta hai.
     */
    public static function watch(string $itemId, bool $on): array
    {
        $p = DB::pdo();
        $q = $p->prepare("SELECT id,name FROM inventory_items
                           WHERE id=? AND site_id=? AND deleted_at IS NULL");
        $q->execute([$itemId, site_id()]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Item not found');

        $p->prepare("UPDATE inventory_items SET is_tracked=?, updated_at=NOW(6) WHERE id=?")
          ->execute([$on ? 1 : 0, $itemId]);

        return ['id'=>$itemId, 'name'=>(string)$row['name'], 'watch'=>$on ? 1 : 0];
    }

    /* ============================================================
       ACCOUNT — daraz, shift aur kharcha
       ============================================================ */
    private static function acct(): array
    {
        $sid = site_id();
        $d   = today();

        /* Khule account. Har ek ka hisaab USI SHIFT se — tareekh se
           nahi — warna do din se khula account aaj ke bills hi
           dikhata hai (V159 ka wohi sabaq). */
        $open = [];
        $drawer = 0.0;
        foreach (self::q(
            "SELECT cs.id, cs.shift_no, cs.opened_at, COALESCE(cs.opening_cash,0) opening,
                    COALESCE(u.full_name,'—') who
               FROM cashier_shifts cs
               LEFT JOIN users u ON u.id=cs.cashier_user_id
              WHERE cs.site_id=? AND cs.status='OPEN'
              ORDER BY cs.opened_at", [$sid]) as $s) {
            $t = self::q(
                "SELECT COUNT(*) bills, COALESCE(SUM(grand_total),0) net
                   FROM orders
                  WHERE site_id=? AND order_status='CLOSED'
                    AND (shift_id=? OR (shift_id IS NULL AND closed_at>=?))",
                [$sid, $s['id'], $s['opened_at']])[0] ?? ['bills'=>0,'net'=>0];
            $cash = self::f(self::one(
                "SELECT COALESCE(SUM(py.amount),0)
                   FROM payments py
                   JOIN payment_methods pm ON pm.id=py.payment_method_id
                   JOIN orders o ON o.id=py.order_id
                  WHERE py.site_id=? AND py.status='COMPLETED' AND pm.method_type='CASH'
                    AND (o.shift_id=? OR (o.shift_id IS NULL AND o.closed_at>=?))",
                [$sid, $s['id'], $s['opened_at']]));

            $days = (int)floor((time() - strtotime((string)$s['opened_at'])) / 86400);
            $drawer += self::f($s['opening']) + $cash;
            $open[] = [
                'who'   => (string)$s['who'],
                'no'    => (string)$s['shift_no'],
                'since' => date('d M, g:i A', strtotime((string)$s['opened_at'])),
                'days'  => $days,
                'bills' => (int)$t['bills'],
                'net'   => self::f($t['net']),
            ];
        }

        $closed = [];
        foreach (self::q(
            "SELECT cs.shift_no, cs.opened_at, cs.closed_at,
                    COALESCE(cs.variance_amount,0) var,
                    COALESCE(u.full_name,'—') who,
                    (SELECT COALESCE(SUM(o.grand_total),0) FROM orders o
                      WHERE o.shift_id=cs.id AND o.order_status='CLOSED') net
               FROM cashier_shifts cs
               LEFT JOIN users u ON u.id=cs.cashier_user_id
              WHERE cs.site_id=? AND cs.status='CLOSED' AND cs.closed_at IS NOT NULL
              ORDER BY cs.closed_at DESC LIMIT 6", [$sid]) as $s) {
            $closed[] = [
                'who'  => (string)$s['who'],
                'when' => date('d M', strtotime((string)$s['closed_at'])),
                'span' => date('g:i A', strtotime((string)$s['opened_at'])) . ' – '
                        . date('g:i A', strtotime((string)$s['closed_at'])),
                'net'  => self::f($s['net']),
                'var'  => self::f($s['var']),
            ];
        }

        $exp = [];
        foreach (self::q(
            /* `expenses` par koi `title` nahi — category ka naam asal
               pehchan hai ("Sabzi mandi", "Gas"), aur description us ki
               tafseel. Dono na hon to kam se kam "Expense".

               Yeh lafz malik ki screen par chhapta hai, is liye English
               mein — poora owner portal English hai. */
            "SELECT COALESCE(NULLIF(ec.name,''), NULLIF(e.description,''), 'Expense') label,
                    e.amount, e.created_at, COALESCE(u.full_name,'—') who
               FROM expenses e
               LEFT JOIN expense_categories ec ON ec.id=e.category_id
               LEFT JOIN users u ON u.id=e.created_by_user_id
              WHERE e.site_id=? AND e.expense_date=? AND e.status='APPROVED'
              ORDER BY e.created_at DESC LIMIT 8", [$sid, $d]) as $r) {
            $exp[] = ['n'=>(string)$r['label'],
                      'a'=>self::f($r['amount']),
                      'at'=>$r['created_at'] ? date('g:i A', strtotime((string)$r['created_at'])) : '',
                      'who'=>(string)$r['who']];
        }

        return ['drawer'=>round($drawer), 'open'=>$open, 'closed'=>$closed,
                'exp'=>$exp, 'expTotal'=>round(array_sum(array_column($exp, 'a')))];
    }
}

// build: V160 build 2026-10-20
