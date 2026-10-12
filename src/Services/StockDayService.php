<?php
/**
 * StockDayService — rozana stock aana, aur din ka band karna.
 *
 * ============================================================
 * DO KAMIYAN JO YEH POORI KARTA HAI
 * ============================================================
 * 1. STOCK ANDAR AANE KA SEEDHA RAASTA NAHI THA.
 *    Stock sirf do tarah barh sakta tha: item banate waqt "opening
 *    stock", ya poora purchase order + GRN. "Aaj 20 Coke aa gaye" jaisi
 *    rozana ki baat ke liye yeh dono bohot lambe hain, is liye log stock
 *    daalte hi nahi thay — aur phir inventory ka number kabhi sahi nahi
 *    hota tha.
 *
 * 2. DIN KA CLOSING SIRF CASH KA THA.
 *    `shift-close` cash milata tha. Stock ka din-ka-hisaab kahin nahi
 *    tha: subah kitna tha, kitna aaya, kitna bika, kitna zaya hua, raat
 *    ko kitna bachna chahiye — aur ginti mein kitna NIKLA. Is farq ko
 *    dekhe baghair chori ya zaya pakra hi nahi ja sakta.
 *
 * Hisaab:
 *      opening + received - sold - wastage - transfers = expected
 *      counted - expected = difference
 *
 * Sab kuch `stock_transactions` + `stock_transaction_lines` se ginta
 * hai — koi nayi table nahi, is liye purana data bhi usi din se sahi
 * nazar aata hai.
 */
declare(strict_types=1);

namespace Aio\Services;

use Aio\DB;
use PDO;

final class StockDayService
{
    /** Ye types stock BARHATE hain (qty_change > 0 un mein aata hai). */
    private const IN_TYPES  = ['OPENING', 'PURCHASE', 'RECEIPT', 'TRANSFER_IN', 'RETURN'];
    private const OUT_SALE  = ['SALE'];
    private const OUT_WASTE = ['WASTAGE'];

    /* ------------------------------------------------------------
       STOCK IN — ek item, ek qty, ek rate. Bas.
       ------------------------------------------------------------ */
    public static function stockIn(array $d): array
    {
        $p    = DB::pdo();
        $item = trim((string)($d['item_id'] ?? ''));
        $qty  = (float)($d['qty'] ?? 0);
        $rate = (float)($d['rate'] ?? 0);
        $note = trim((string)($d['note'] ?? ''));

        if ($qty <= 0) throw new \RuntimeException('Quantity must be more than zero');

        $iq = $p->prepare("SELECT id,name,avg_cost_per_stock_unit FROM inventory_items
                            WHERE id=? AND site_id=? AND deleted_at IS NULL");
        $iq->execute([$item, site_id()]);
        $row = $iq->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Inventory item not found');

        /* Rate na diya jaye to maujooda average chalega — warna avg cost
           sifar ki taraf khisak jata hai aur food cost jhoota ho jata. */
        if ($rate <= 0) $rate = (float)$row['avg_cost_per_stock_unit'];

        $loc = self::location($p, (string)($d['location_id'] ?? ''));

        $tx = DB::tx(function (PDO $p) use ($item, $loc, $qty, $rate, $note) {
            return InventoryService::postMovement(
                $p, 'PURCHASE', 'STOCK_IN', null,
                $note !== '' ? $note : 'Stock In',
                [(object)['item_id' => $item, 'location_id' => $loc,
                          'qty' => $qty, 'unit_cost' => $rate, 'source_order_item_id' => null]],
                current_user()['id'] ?? null
            );
        });

        $bq = $p->prepare("SELECT qty_on_hand FROM stock_balances
                            WHERE stock_location_id=? AND inventory_item_id=?");
        $bq->execute([$loc, $item]);

        return ['tx' => $tx, 'name' => $row['name'],
                'qty' => $qty, 'rate' => $rate,
                'on_hand' => (float)$bq->fetchColumn()];
    }

    private static function location(PDO $p, string $wanted): string
    {
        if ($wanted !== '') {
            $q = $p->prepare("SELECT id FROM stock_locations WHERE id=? AND site_id=? AND is_active=1");
            $q->execute([$wanted, site_id()]);
            if ($id = $q->fetchColumn()) return (string)$id;
        }
        /* V146 — pehle yahan apni alag tarteeb thi (STORE pehle), jabke
           POS KITCHEN par post karta tha. Do location wale setup mein
           stock do khaton mein bat jata tha. Ab ek hi qanoon. */
        return InventoryService::defaultLocationId($p);
    }

    /* ------------------------------------------------------------
       DAY CLOSING — ek tareekh ka poora stock statement.
       ------------------------------------------------------------ */
    public static function day(string $date): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        $p = DB::pdo();

        /* Opening = us din se PEHLE ka sab kuch jama. */
        $ph  = fn(array $a) => implode(',', array_fill(0, count($a), '?'));
        $all = array_merge(self::IN_TYPES, self::OUT_SALE, self::OUT_WASTE);

        /* AHEM: yeh string SINGLE quotes mein hai. Double quotes mein
           PHP %in% jaisi jagahon ko khud variable samajh kar khali kar
           deta — SQL chup chaap `IN ()` ban jata aur har ginti sifar
           aati. Is liye placeholders %...% hain, aur neeche str_replace
           se bharte hain. */
        $sql = 'SELECT ii.id, ii.name,
                       u.code unit,
                       COALESCE(SUM(CASE WHEN st.business_date <  ? THEN l.qty_change END),0) opening,
                       COALESCE(SUM(CASE WHEN st.business_date =  ? AND st.transaction_type IN (%in%)   THEN l.qty_change END),0) received,
                       COALESCE(SUM(CASE WHEN st.business_date =  ? AND st.transaction_type IN (%sale%) THEN -l.qty_change END),0) sold,
                       COALESCE(SUM(CASE WHEN st.business_date =  ? AND st.transaction_type IN (%wst%)  THEN -l.qty_change END),0) wastage,
                       COALESCE(SUM(CASE WHEN st.business_date =  ? AND st.transaction_type NOT IN (%all%) THEN l.qty_change END),0) other,
                       ii.avg_cost_per_stock_unit cost
                  FROM inventory_items ii
                  LEFT JOIN units u ON u.id = ii.stock_unit_id
                  LEFT JOIN stock_transaction_lines l ON l.inventory_item_id = ii.id
                  LEFT JOIN stock_transactions st ON st.id = l.stock_transaction_id
                                                 AND st.business_date <= ?
                 WHERE ii.site_id = ? AND ii.deleted_at IS NULL AND ii.is_active = 1
                 GROUP BY ii.id, ii.name, u.code, ii.avg_cost_per_stock_unit
                 ORDER BY ii.name';

        $sql = str_replace(
            ['%in%', '%sale%', '%wst%', '%all%'],
            [$ph(self::IN_TYPES), $ph(self::OUT_SALE), $ph(self::OUT_WASTE), $ph($all)],
            $sql
        );

        $args = array_merge(
            [$date, $date], self::IN_TYPES,
            [$date], self::OUT_SALE,
            [$date], self::OUT_WASTE,
            [$date], $all,
            [$date, site_id()]
        );

        $q = $p->prepare($sql);
        $q->execute($args);

        $rows = []; $tOpen = $tIn = $tSold = $tWaste = $tClose = 0.0; $tValue = 0.0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $open  = (float)$r['opening'];
            $in    = (float)$r['received'];
            $sold  = (float)$r['sold'];
            $waste = (float)$r['wastage'];
            $other = (float)$r['other'];
            $close = $open + $in - $sold - $waste + $other;

            /* Jis item par us din kuch hua hi nahi aur stock bhi sifar hai,
               usay fehrist mein laana sirf shor hai. */
            if ($open == 0.0 && $in == 0.0 && $sold == 0.0 && $waste == 0.0 && $other == 0.0) continue;

            $cost = (float)$r['cost'];
            $rows[] = [
                'id' => $r['id'], 'name' => $r['name'], 'unit' => $r['unit'] ?: '',
                'opening' => $open, 'received' => $in, 'sold' => $sold,
                'wastage' => $waste, 'other' => $other, 'expected' => $close,
                'cost' => $cost, 'value' => $close * $cost,
            ];
            $tOpen += $open; $tIn += $in; $tSold += $sold; $tWaste += $waste;
            $tClose += $close; $tValue += $close * $cost;
        }

        return [
            'date'  => $date,
            'rows'  => $rows,
            'totals'=> ['opening' => $tOpen, 'received' => $tIn, 'sold' => $tSold,
                        'wastage' => $tWaste, 'expected' => $tClose, 'value' => $tValue,
                        'items' => count($rows)],
        ];
    }

    /* ------------------------------------------------------------
       CLOSE — ginti daal kar farq ko ADJUSTMENT se barabar karna.

       Sirf un items ko chhuta hai jin ki ginti di gayi ho. Jo khana
       khali chhoda gaya, us ka stock waise ka waisa rehta hai — khali
       khane ko sifar samajh lena ek poore item ka stock uda deta.
       ------------------------------------------------------------ */
    public static function close(string $date, array $counts, string $note = ''): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new \RuntimeException('A valid date is required');
        $p   = DB::pdo();
        $day = self::day($date);
        $by  = [];
        foreach ($day['rows'] as $r) $by[$r['id']] = $r;

        $lines = []; $applied = [];
        $loc = self::location($p, '');

        foreach ($counts as $c) {
            $id = (string)($c['id'] ?? '');
            if (!isset($by[$id])) continue;
            if (!array_key_exists('counted', $c) || $c['counted'] === '' || $c['counted'] === null) continue;

            $counted = (float)$c['counted'];
            $diff    = $counted - (float)$by[$id]['expected'];
            if (abs($diff) < 0.000001) continue;

            $lines[]   = (object)['item_id' => $id, 'location_id' => $loc, 'qty' => $diff,
                                  'unit_cost' => (float)$by[$id]['cost'], 'source_order_item_id' => null];
            $applied[] = ['name' => $by[$id]['name'], 'expected' => (float)$by[$id]['expected'],
                          'counted' => $counted, 'difference' => $diff];
        }

        if (!$lines) return ['adjusted' => 0, 'lines' => [], 'date' => $date];

        $tx = DB::tx(fn(PDO $p) => InventoryService::postMovement(
            $p, 'ADJUSTMENT', 'DAY_CLOSE', null,
            'Day closing ' . $date . ($note !== '' ? (' - ' . $note) : ''),
            $lines, current_user()['id'] ?? null
        ));

        try {
            Audit::log('DAY_CLOSE', 'inventory',
                ['label' => $date, 'count' => count($lines)]);
        } catch (\Throwable $e) {}

        return ['adjusted' => count($lines), 'lines' => $applied, 'date' => $date, 'tx' => $tx];
    }
}
