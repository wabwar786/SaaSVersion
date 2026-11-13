<?php
namespace Aio\Services;

use Aio\Auth;
use Aio\DB;
use PDO;

/**
 * RetailPos — supermarket counter ka server side.
 *
 * TEEN USOOL:
 *
 * 1. PAISE KA HISAAB SIRF SERVER PAR.
 *    Client jo total bheje us par bharosa nahi kiya jata. Lines aa kar
 *    dobara RegionProfile::billTotals() se guzarti hain. Warna browser
 *    ka console khol kar koi bhi total badal sakta hai.
 *
 * 2. EK BILL = EK TRANSACTION.
 *    Sale, lines, stock decrement aur khata entry — sab ek DB::tx() mein.
 *    Beech mein bijli jaye to ya poora bill banta hai ya kuch bhi nahi;
 *    aisa kabhi nahi hota ke stock kat jaye aur bill na bane.
 *
 * 3. DUPLICATE BILL GINTI MEIN AATA HAI.
 *    Reprint cash chori ka aam raasta hai. Har copy ka apna record
 *    (`rtl_bill_reprints`) banta hai: kis ne, kab, kaunse counter se.
 *
 * V191 — RETAIL AUDIT KE FIXES (sab yahin, restaurant ko haath nahi):
 *  - Card par zyada raqam se CASH change banta tha (200 ke bill par card
 *    10,000 -> change 9,800). Ab card bill se zyada ho hi nahi sakta;
 *    change sirf cash se.
 *  - Cashier server par 100% discount de sakta tha. Ab manager ke ilawa
 *    discount ki had hai (Settings: retail / cashier_max_discount_pct,
 *    default 10%).
 *  - 6 counter ek saath bill banayein to 4 ko kachi SQL "Duplicate entry"
 *    milti thi. Ab bill number takraye to khud dobara koshish hoti hai.
 *  - Pack barcode ka rate: pehle screen 24 x pack rate dikhati thi aur
 *    server 24 x piece rate leta tha. Ab dono jagah ek qanoon:
 *    qty base unit mein, rate = pack rate / factor.
 *  - Inactive item nahi bikta; ginti wala item (PCS) aadha nahi bikta.
 *  - Har bill khuli shift se jurta hai (RetailShift) — shift na ho to
 *    khud khul jati hai, bill kabhi nahi rukta.
 *  - `quote()` — payment se pehle server ka total screen par aata hai,
 *    is liye screen aur parchi ka total kabhi alag nahi hota.
 */
final class RetailPos
{
    /** Manager ke ilawa discount ki had (bill ka %) — Settings se badalti hai. */
    private const DEFAULT_CASHIER_MAX_DISC = 10.0;

    /* ================= bill number ================= */

    private static function nextBillNo(PDO $pdo): string
    {
        $q = $pdo->prepare(
            "SELECT bill_no FROM rtl_sales
              WHERE tenant_id=? AND site_id=? ORDER BY created_at DESC LIMIT 1");
        $q->execute([tenant_id(), site_id()]);
        $last = (string)($q->fetchColumn() ?: '');
        $n = ($last && \preg_match('/(\d+)$/', $last, $m)) ? ((int)$m[1] + 1) : 1001;
        return 'INV-' . $n;
    }

    /** Screen ke liye agla bill number (sirf dikhane ko — asal number save par banta hai). */
    public static function peekBillNo(): string
    {
        try { return self::nextBillNo(DB::pdo()); } catch (\Throwable $e) { return 'INV-1001'; }
    }

    /* ================= lines ================= */

    /** Base unit ka type (COUNT / WEIGHT / VOLUME / PACK) — ginti ki shart ke liye. */
    private static function unitType(?string $unitId): string
    {
        if (!$unitId) return 'COUNT';
        static $cache = [];
        if (isset($cache[$unitId])) return $cache[$unitId];
        $q = DB::pdo()->prepare("SELECT unit_type FROM units WHERE id=? AND (tenant_id=? OR tenant_id IS NULL) LIMIT 1");
        $q->execute([$unitId, tenant_id()]);
        return $cache[$unitId] = \strtoupper((string)($q->fetchColumn() ?: 'COUNT'));
    }

    /**
     * Client ki lines ko saaf karo. Rate hamesha DB se.
     * `saveSale()` aur `quote()` dono yahi bulate hain — hisaab ek jagah.
     */
    private static function cleanLines(array $lines, string $priceLevel): array
    {
        $clean = [];
        foreach ($lines as $l) {
            $pid = (string)($l['product_id'] ?? '');
            $qty = (float)($l['qty'] ?? 0);
            if ($pid === '' || $qty <= 0) continue;
            $prod = RetailCatalog::productLite($pid);   /* barcodes ki zaroorat nahi */
            if (!$prod) throw new \RuntimeException('Product not found: ' . $pid);
            if (\strcasecmp((string)$prod['status'], 'Inactive') === 0) {
                throw new \RuntimeException($prod['name'] . ' is inactive and cannot be sold');
            }

            $unitPrice = $priceLevel === 'Wholesale'
                ? ((float)$prod['wholesale_price'] ?: (float)$prod['retail_price'])
                : (float)$prod['retail_price'];
            $uomId = '';
            /* Pack scan: qty BASE unit mein aati hai (carton = 24 pcs), is
               liye rate bhi ek piece ka hona chahiye: pack rate / factor.
               Pehle pack ka poora rate har piece par lagta tha (screen par
               24 x 4,560) aur server 24 x 200 leta tha. */
            if (!empty($l['uom_id'])) {
                $uq = DB::pdo()->prepare("SELECT retail_price, factor FROM rtl_product_uom
                                           WHERE id=? AND product_id=? AND tenant_id=? AND deleted_at IS NULL");
                $uq->execute([$l['uom_id'], $pid, tenant_id()]);
                $u = $uq->fetch(PDO::FETCH_ASSOC);
                if ($u && (float)$u['factor'] > 0 && (float)$u['retail_price'] > 0) {
                    $unitPrice = \round((float)$u['retail_price'] / (float)$u['factor'], 4);
                    $uomId = (string)$l['uom_id'];
                }
            }

            /* Ginti wala item (PCS) aadha nahi bikta. Taraazu wale (KG) ka
               qanoon alag hai. */
            if (self::unitType($prod['base_unit_id'] ?? null) === 'COUNT'
                && \abs($qty - \round($qty)) > 0.0001) {
                throw new \RuntimeException($prod['name'] . ' is sold in whole units only');
            }

            $gross = $qty * $unitPrice;
            $clean[] = [
                'product_id' => $pid,
                'name'       => (string)$prod['name'],
                'unit_code'  => (string)($l['unit_code'] ?? ''),
                'uom_id'     => $uomId,
                'qty'        => $qty,
                'unit_price' => $unitPrice,
                'discount'   => \min($gross, \max(0.0, (float)($l['discount'] ?? 0))),
                'tax_rate'   => (float)$prod['tax_rate'],
            ];
        }
        if (!$clean) throw new \RuntimeException('Bill is empty');
        return $clean;
    }

    private static function cashierMaxDiscPct(): float
    {
        try {
            $v = SiteSetting::one('retail', 'cashier_max_discount_pct', null);
            if ($v !== null && $v !== '' && \is_numeric($v)) return \max(0.0, \min(100.0, (float)$v));
        } catch (\Throwable $e) {}
        return self::DEFAULT_CASHIER_MAX_DISC;
    }

    /** Manager ke ilawa discount ki had. */
    private static function checkDiscount(array $clean, array $tot): void
    {
        if (Auth::isManager()) return;
        $gross = 0.0; $lineDisc = 0.0;
        foreach ($clean as $l) { $gross += $l['qty'] * $l['unit_price']; $lineDisc += $l['discount']; }
        if ($gross <= 0) return;
        $pct = ($lineDisc + (float)$tot['discount']) / $gross * 100;
        $max = self::cashierMaxDiscPct();
        if ($pct > $max + 0.0001) {
            throw new \RuntimeException('Discount ' . \number_format($pct, 1) . '% is above the cashier limit of '
                . \number_format($max, 0) . '%. A manager must give this discount.');
        }
    }

    /**
     * Payment se pehle server ka hisaab — kuch save nahi hota.
     * Screen isi se apni keemat aur total durust karti hai.
     */
    public static function quote(array $d): array
    {
        $priceLevel = ($d['price_level'] ?? 'Retail') === 'Wholesale' ? 'Wholesale' : 'Retail';
        $clean = self::cleanLines(\array_values((array)($d['lines'] ?? [])), $priceLevel);
        $tot = RegionProfile::billTotals($clean, (float)($d['bill_discount'] ?? 0));
        self::checkDiscount($clean, $tot);   /* paisa lene SE PEHLE batao */
        return ['lines' => $clean, 'subtotal' => $tot['subtotal'], 'discount' => $tot['discount'],
                'tax' => $tot['tax'], 'total' => $tot['total']];
    }

    /* ================= sale ================= */

    /**
     * Bill mukammal karo.
     *
     * @param array $d  lines[], customer_id, price_level, bill_discount,
     *                  payment_method, paid_cash, paid_card, exact_cash, counter_name
     */
    public static function saveSale(array $d): array
    {
        $lines = \array_values((array)($d['lines'] ?? []));
        if (!$lines) throw new \RuntimeException('Bill is empty');

        $method = \strtoupper((string)($d['payment_method'] ?? 'CASH'));
        if (!\in_array($method, ['CASH','CARD','MIXED','CREDIT'], true)) $method = 'CASH';

        $customerId = ($d['customer_id'] ?? '') ?: null;
        if ($method === 'CREDIT' && !$customerId) {
            throw new \RuntimeException('A customer is required for a credit sale');
        }

        /* Har line ki keemat DB se dobara li jati hai — client sirf
           product_id aur qty bhejta hai. Price client se lena maani yeh
           ke koi bhi 5000 ka item 5 rupay mein le jaye. */
        $priceLevel = ($d['price_level'] ?? 'Retail') === 'Wholesale' ? 'Wholesale' : 'Retail';
        $clean = self::cleanLines($lines, $priceLevel);

        $tot = RegionProfile::billTotals($clean, (float)($d['bill_discount'] ?? 0));
        self::checkDiscount($clean, $tot);

        $cash = \max(0.0, (float)($d['paid_cash'] ?? 0));
        $card = \max(0.0, (float)($d['paid_card'] ?? 0));
        if ($method === 'CREDIT') { $cash = 0; $card = 0; }
        elseif ($method === 'CARD') { $cash = 0; }
        elseif ($method === 'CASH') { $card = 0; }

        /* Ctrl+Enter "exact cash": raqam server ka total hi hai. Pehle
           client apna total bhejta tha; keemat beech mein badli ho to
           "Payment is short" aata aur cashier atak jata. */
        if ($method === 'CASH' && !empty($d['exact_cash'])) $cash = (float)$tot['total'];

        /* CARD se change nahi banta. Card machine par wohi raqam katti hai
           jo bill hai — zyada likhi raqam drawer se cash nikalne ka raasta
           thi (200 ke bill par card 10,000 -> change 9,800). */
        if ($method === 'CARD' && $card <= 0) $card = (float)$tot['total'];
        if (($method === 'CARD' || $method === 'MIXED') && $card > $tot['total'] + 0.009) {
            throw new \RuntimeException('Card amount (' . \number_format($card, 2) . ') is more than the bill ('
                . \number_format($tot['total'], 2) . ')');
        }

        $paid = $method === 'CREDIT' ? $tot['total'] : ($cash + $card);
        if ($method !== 'CREDIT' && $paid + 0.009 < $tot['total']) {
            throw new \RuntimeException('Payment is short by ' . \number_format($tot['total'] - $paid, 2));
        }
        $change = $method === 'CREDIT' ? 0.0 : \max(0.0, \min($cash, $paid - $tot['total']));

        $user = Auth::user() ?: [];
        $counter = \substr(\trim((string)($d['counter_name'] ?? '')) ?: 'Counter 1', 0, 80);

        /* Do counter ek hi waqt agla number utha lein to UNIQUE (bill_no)
           doosre ko rok deti hai. Pehle cashier ke samne kachi SQL error
           aati thi. Ab poora transaction dobara chalta hai — naya number. */
        for ($try = 1; ; $try++) {
            try {
                return self::writeSale($clean, $tot, $method, $cash, $card, $change, $customerId,
                                       $priceLevel, $user, $counter);
            } catch (\PDOException $e) {
                $dup = ($e->errorInfo[1] ?? 0) == 1062 && \stripos($e->getMessage(), 'uq_rs_bill') !== false;
                if (!$dup || $try >= 8) throw $e;
                \usleep(\random_int(5000, 40000));
            }
        }
    }

    private static function writeSale(array $clean, array $tot, string $method, float $cash, float $card,
                                      float $change, ?string $customerId, string $priceLevel,
                                      array $user, string $counter): array
    {
        return DB::tx(function (PDO $pdo) use ($clean, $tot, $method, $cash, $card, $change, $customerId, $priceLevel, $user, $counter) {

            /* Credit limit — bill banane se PEHLE. Limit 0 = koi had nahi. */
            $custName = 'Walk-in Customer';
            if ($customerId) {
                $cq = $pdo->prepare("SELECT full_name, credit_limit, balance FROM customers WHERE id=? AND tenant_id=? FOR UPDATE");
                $cq->execute([$customerId, tenant_id()]);
                $cust = $cq->fetch(PDO::FETCH_ASSOC);
                if (!$cust) throw new \RuntimeException('Customer not found');
                $custName = (string)$cust['full_name'];
                if ($method === 'CREDIT') {
                    $lim = (float)$cust['credit_limit'];
                    $bal = (float)$cust['balance'];
                    if ($lim > 0 && ($bal + $tot['total']) > $lim + 0.009) {
                        throw new \RuntimeException(
                            'Credit limit exceeded — limit ' . \number_format($lim, 2) .
                            ', already ' . \number_format($bal, 2));
                    }
                }
            }

            /* Shift: khuli ho to wohi, warna khud khul jati hai. */
            $shift = RetailShift::ensure($pdo, $counter);

            $billNo = self::nextBillNo($pdo);
            $saleId = \uuid();

            $pdo->prepare(
                "INSERT INTO rtl_sales(id,tenant_id,site_id,bill_no,counter_name,cashier_user_id,cashier_name,
                    customer_id,customer_name,price_level,line_count,subtotal,discount,tax_amount,total,
                    paid_cash,paid_card,change_amount,payment_method,status,sold_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(6))")
                ->execute([$saleId, tenant_id(), site_id(), $billNo, $counter,
                    ($user['id'] ?? null), (string)($user['full_name'] ?? 'Cashier'),
                    $customerId, $custName, $priceLevel, \count($clean),
                    $tot['subtotal'], $tot['discount'], $tot['tax'], $tot['total'],
                    $cash, $card, $change, $method,
                    $method === 'CREDIT' ? 'Credit' : 'Completed']);

            foreach ($clean as $l) {
                $lt = \max(0.0, $l['qty'] * $l['unit_price'] - $l['discount']);
                $pdo->prepare(
                    "INSERT INTO rtl_sale_items(id,tenant_id,sale_id,product_id,product_name,unit_code,
                        qty,unit_price,discount,tax_rate,line_total)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([\uuid(), tenant_id(), $saleId, $l['product_id'], $l['name'],
                               $l['unit_code'], $l['qty'], $l['unit_price'], $l['discount'],
                               $l['tax_rate'], $lt]);

                /* Stock base unit mein girta hai. Stock zero se neeche bhi
                   ja sakta hai — counter par bill rok dena is se bara
                   masla hai. Har harkat `RetailStock` se guzarti hai aur
                   `rtl_stock_moves` par apna nishan chhorti hai. */
                RetailStock::move($pdo, $l['product_id'], -$l['qty'], RetailStock::SALE, [
                    'table' => 'rtl_sales', 'id' => $saleId, 'no' => $billNo,
                ]);

                self::consumeBatches($pdo, $l['product_id'], $l['qty']);
            }

            /* Khata: balance aur ledger dono. */
            if ($method === 'CREDIT' && $customerId) {
                $pdo->prepare("UPDATE customers SET balance = balance + ?, updated_at=NOW(6) WHERE id=? AND tenant_id=?")
                    ->execute([$tot['total'], $customerId, tenant_id()]);
                $bq = $pdo->prepare("SELECT balance FROM customers WHERE id=? AND tenant_id=?");
                $bq->execute([$customerId, tenant_id()]);
                $pdo->prepare(
                    "INSERT INTO rtl_customer_ledger(id,tenant_id,site_id,customer_id,entry_type,
                        ref_table,ref_id,ref_no,debit,credit,balance_after,note,entry_at)
                     VALUES(?,?,?,?, 'SALE','rtl_sales',?,?,?,0,?,?,NOW(6))")
                    ->execute([\uuid(), tenant_id(), site_id(), $customerId, $saleId, $billNo,
                               $tot['total'], (float)$bq->fetchColumn(), 'Credit sale']);
            }

            try { Audit::log('CREATE', 'rpos', ['record_id' => $saleId, 'bill_no' => $billNo, 'total' => $tot['total'],
                                               'discount' => $tot['discount'], 'level' => $priceLevel]); }
            catch (\Throwable $e) {}

            return [
                'id' => $saleId, 'bill_no' => $billNo,
                'subtotal' => $tot['subtotal'], 'discount' => $tot['discount'],
                'tax' => $tot['tax'], 'total' => $tot['total'],
                'tax_rate' => (function() use ($clean) {
                    $rates = \array_unique(\array_map(fn($l) => (float)($l['tax_rate'] ?? 0), $clean));
                    return \count($rates) === 1 ? (float)\reset($rates) : null;
                })(),
                'paid_cash' => $cash, 'paid_card' => $card, 'change' => $change,
                'payment_method' => $method,
                'status' => $method === 'CREDIT' ? 'Credit' : 'Completed',
                'customer_name' => $custName, 'price_level' => $priceLevel,
                'counter_name' => $counter,
                'cashier_name' => (string)($user['full_name'] ?? 'Cashier'),
                'shift' => (string)($shift['shift_seq'] ?? $shift['shift_no'] ?? ''),
                'sold_at' => \date('Y-m-d H:i'),
                'next_bill_no' => 'INV-' . ((int)\preg_replace('/\D/', '', $billNo) + 1),
                'items' => $clean,
            ];
        });
    }

    /** FIFO: sab se pehle expire hone wala batch pehle khatam hota hai. */
    private static function consumeBatches(PDO $pdo, string $productId, float $qty): void
    {
        $q = $pdo->prepare(
            "SELECT id, qty FROM rtl_batches
              WHERE product_id=? AND tenant_id=? AND deleted_at IS NULL AND qty > 0
              ORDER BY (expiry_date IS NULL), expiry_date ASC, received_on ASC");
        $q->execute([$productId, tenant_id()]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $b) {
            if ($qty <= 0) break;
            $take = \min($qty, (float)$b['qty']);
            $pdo->prepare("UPDATE rtl_batches SET qty = qty - ? WHERE id=? AND tenant_id=?")
                ->execute([$take, $b['id'], tenant_id()]);
            $qty -= $take;
        }
    }

    /* ================= sales list / receipt ================= */

    /**
     * Bills ki fehrist. `q` ho to bill number ya customer ke shuru ke
     * harfon se dhoondta hai (index par chalta hai — `%q%` nahi).
     * Pehle Duplicate Bill sirf aakhri 100 bill dikha sakta tha.
     */
    public static function sales(int $limit = 100, string $q = ''): array
    {
        $limit = \max(1, \min(500, $limit));
        $sql = "SELECT * FROM rtl_sales WHERE tenant_id=? AND site_id=? AND deleted_at IS NULL";
        $args = [tenant_id(), site_id()];
        $q = \trim($q);
        if ($q !== '') {
            $num = \preg_replace('/\D/', '', $q);
            $sql .= " AND (bill_no LIKE ? OR bill_no = ? OR customer_name LIKE ?)";
            $args[] = \strtoupper($q) . '%';
            $args[] = $num !== '' ? 'INV-' . $num : '';
            $args[] = $q . '%';
        }
        $sql .= " ORDER BY sold_at DESC LIMIT " . $limit;
        $st = DB::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function sale(string $idOrBill): ?array
    {
        $q = DB::pdo()->prepare(
            "SELECT * FROM rtl_sales WHERE tenant_id=? AND (id=? OR bill_no=?) LIMIT 1");
        $q->execute([tenant_id(), $idOrBill, $idOrBill]);
        $s = $q->fetch(PDO::FETCH_ASSOC);
        if (!$s) return null;
        $i = DB::pdo()->prepare("SELECT * FROM rtl_sale_items WHERE sale_id=? AND tenant_id=? ORDER BY created_at");
        $i->execute([$s['id'], tenant_id()]);
        $s['items'] = $i->fetchAll(PDO::FETCH_ASSOC);
        return $s;
    }

    /**
     * Ek bill mein se abhi kitna wapas ho sakta hai, aur kis rate par.
     *
     * Rate = jo customer ne asal mein diya: line ka discount nikal kar,
     * aur bill discount ka hissa bhi. Is se zyada refund kabhi nahi.
     * Pehle return ka rate haath se likha jata tha (2,450 ke item ka
     * refund 9,000) aur ek hi bill do dafa wapas ho jata tha.
     *
     * @return array product_id => [name, sold, returned, left, rate, unit_code, tax_rate]
     */
    public static function returnable(string $saleId, ?PDO $pdo = null, string $exceptDocId = ''): array
    {
        $pdo = $pdo ?: DB::pdo();
        $s = $pdo->prepare("SELECT id, subtotal, discount FROM rtl_sales WHERE id=? AND tenant_id=? AND deleted_at IS NULL");
        $s->execute([$saleId, tenant_id()]);
        $sale = $s->fetch(PDO::FETCH_ASSOC);
        if (!$sale) return [];

        $i = $pdo->prepare("SELECT product_id, product_name, unit_code, qty, line_total, tax_rate
                              FROM rtl_sale_items WHERE sale_id=? AND tenant_id=?");
        $i->execute([$saleId, tenant_id()]);
        $out = [];
        $lineSum = 0.0;
        $rows = $i->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $lineSum += (float)$r['line_total'];
        /* Bill discount ka hissa har line par us ke hisse ke mutabiq. */
        $billDisc = (float)$sale['discount'];
        $factor = $lineSum > 0 ? \max(0.0, ($lineSum - \min($billDisc, $lineSum)) / $lineSum) : 1.0;

        foreach ($rows as $r) {
            $pid = (string)$r['product_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = ['product_id' => $pid, 'name' => (string)$r['product_name'],
                              'unit_code' => (string)$r['unit_code'], 'sold' => 0.0, 'value' => 0.0,
                              'returned' => 0.0, 'tax_rate' => (float)$r['tax_rate']];
            }
            $out[$pid]['sold']  += (float)$r['qty'];
            $out[$pid]['value'] += (float)$r['line_total'] * $factor;
        }

        $rq = $pdo->prepare(
            "SELECT l.product_id, SUM(l.qty) q FROM rtl_doc_lines l
               JOIN rtl_docs d ON d.id = l.doc_id AND d.tenant_id = l.tenant_id
              WHERE d.tenant_id=? AND d.doc_type='RET' AND d.source_sale_id=? AND d.id<>?
                AND d.status='POSTED' AND d.deleted_at IS NULL
              GROUP BY l.product_id");
        $rq->execute([tenant_id(), $saleId, $exceptDocId]);
        foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($out[$r['product_id']])) $out[$r['product_id']]['returned'] = (float)$r['q'];
        }
        foreach ($out as &$o) {
            $o['rate'] = $o['sold'] > 0 ? \round($o['value'] / $o['sold'], 4) : 0.0;
            $o['left'] = \max(0.0, \round($o['sold'] - $o['returned'], 4));
            unset($o['value']);
        }
        return $out;
    }

    /**
     * Duplicate bill.
     *
     * Har copy ka apna record banta hai. `reprint_count` receipt par bhi
     * chhapta hai, taake haath mein pakri parchi khud bata de ke yeh
     * asal nahi copy hai.
     */
    public static function reprint(string $idOrBill, string $reason = ''): array
    {
        $sale = self::sale($idOrBill);
        if (!$sale) throw new \RuntimeException('Bill not found');
        $user = Auth::user() ?: [];

        return DB::tx(function (PDO $pdo) use ($sale, $reason, $user) {
            $copy = (int)$sale['reprint_count'] + 1;
            $pdo->prepare("UPDATE rtl_sales SET reprint_count=?, last_reprint_at=NOW(6) WHERE id=? AND tenant_id=?")
                ->execute([$copy, $sale['id'], tenant_id()]);
            $pdo->prepare(
                "INSERT INTO rtl_bill_reprints(id,tenant_id,site_id,sale_id,bill_no,copy_no,
                    user_id,user_name,counter_name,reason)
                 VALUES(?,?,?,?,?,?,?,?,?,?)")
                ->execute([\uuid(), tenant_id(), site_id(), $sale['id'], $sale['bill_no'], $copy,
                           ($user['id'] ?? null), (string)($user['full_name'] ?? ''),
                           (string)$sale['counter_name'], \substr($reason, 0, 250)]);
            try { Audit::log('REPRINT', 'rpos', ['record_id' => (string)$sale['id'],
                             'bill_no' => (string)$sale['bill_no'], 'copy' => $copy, 'reason' => $reason]); }
            catch (\Throwable $e) {}

            $sale['reprint_count'] = $copy;
            $sale['last_reprint_at'] = \date('Y-m-d H:i');
            $sale['reprinted_by'] = (string)($user['full_name'] ?? '');
            $sale['is_duplicate'] = true;
            return $sale;
        });
    }

    /* ================= held bills ================= */

    public static function heldBills(): array
    {
        $q = DB::pdo()->prepare(
            "SELECT * FROM rtl_held_bills WHERE tenant_id=? AND site_id=? AND deleted_at IS NULL
              ORDER BY created_at DESC");
        $q->execute([tenant_id(), site_id()]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['cart'] = \json_decode((string)$r['cart_json'], true) ?: [];
            unset($r['cart_json']);
            $r['total'] = (float)$r['total'];
            $r['customer'] = (string)$r['customer_name'];
            $r['lines'] = (int)$r['line_count'];
        }
        return $rows;
    }

    public static function hold(array $d): string
    {
        $id = \uuid();
        $user = Auth::user() ?: [];
        /* Customer sirf isi business ka ho sakta hai. */
        $cid = ($d['customer_id'] ?? null) ?: null;
        if ($cid) {
            $c = DB::pdo()->prepare("SELECT COUNT(*) FROM customers WHERE id=? AND tenant_id=?");
            $c->execute([$cid, tenant_id()]);
            if (!(int)$c->fetchColumn()) $cid = null;
        }
        DB::pdo()->prepare(
            "INSERT INTO rtl_held_bills(id,tenant_id,site_id,bill_no,counter_name,customer_id,customer_name,
                price_level,line_count,total,cart_json,held_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id, tenant_id(), site_id(),
                (string)($d['bill_no'] ?? ''), (string)($d['counter_name'] ?? 'Counter 1'),
                $cid, (string)($d['customer_name'] ?? 'Walk-in'),
                (string)($d['price_level'] ?? 'Retail'), (int)($d['line_count'] ?? 0),
                (float)($d['total'] ?? 0), \json_encode($d['cart'] ?? []),
                (string)($user['full_name'] ?? '')]);
        return $id;
    }

    public static function releaseHold(string $id): void
    {
        DB::pdo()->prepare("UPDATE rtl_held_bills SET deleted_at=NOW(6) WHERE id=? AND tenant_id=?")
                 ->execute([$id, tenant_id()]);
    }

    /* ================= khata ================= */

    public static function ledger(string $customerId, int $limit = 100): array
    {
        $q = DB::pdo()->prepare(
            "SELECT * FROM rtl_customer_ledger WHERE tenant_id=? AND customer_id=?
              ORDER BY entry_at DESC LIMIT " . (int)$limit);
        $q->execute([tenant_id(), $customerId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Udhaar ki wapsi.
     * V191 — kis ne li aur kis tareeqe se, ledger par likha jata hai
     * (ref_table='users', ref_id=user, ref_no=CASH/CARD/BANK). Shift ka
     * expected cash isi se khate ki CASH wasooli ginta hai.
     */
    public static function receivePayment(string $customerId, float $amount, string $method = 'CASH', string $note = ''): array
    {
        if ($amount <= 0) throw new \RuntimeException('Amount must be greater than zero');
        $method = \strtoupper(\trim($method));
        if (!\in_array($method, ['CASH', 'CARD', 'BANK'], true)) $method = 'CASH';
        $uid = (string)((Auth::user() ?: [])['id'] ?? '');
        return DB::tx(function (PDO $pdo) use ($customerId, $amount, $method, $note, $uid) {
            $cq = $pdo->prepare("SELECT balance FROM customers WHERE id=? AND tenant_id=? FOR UPDATE");
            $cq->execute([$customerId, tenant_id()]);
            $bal = $cq->fetchColumn();
            if ($bal === false) throw new \RuntimeException('Customer not found');

            /* Cash wasooli bhi shift mein jati hai — shift khuli na ho to khol do. */
            if ($method === 'CASH') RetailShift::ensure($pdo, '');

            $pdo->prepare("UPDATE customers SET balance = balance - ?, updated_at=NOW(6) WHERE id=? AND tenant_id=?")
                ->execute([$amount, $customerId, tenant_id()]);
            $after = (float)$bal - $amount;
            $pdo->prepare(
                "INSERT INTO rtl_customer_ledger(id,tenant_id,site_id,customer_id,entry_type,
                    ref_table,ref_id,ref_no,debit,credit,balance_after,note,entry_at)
                 VALUES(?,?,?,?, 'RECEIPT','users',?,?,0,?,?,?,NOW(6))")
                ->execute([\uuid(), tenant_id(), site_id(), $customerId, $uid ?: null, $method, $amount, $after,
                           \trim($method . ' ' . $note)]);
            return ['balance' => $after, 'received' => $amount];
        });
    }

    /* ================= dashboard ================= */

    /** Aaj ka asli hisaab — dashboard ke liye. Koi number gadha hua nahi. */
    public static function dashboard(): array
    {
        $pdo = DB::pdo();
        $tid = tenant_id(); $sid = site_id();
        $day = function (string $d) use ($pdo, $tid, $sid) {
            $q = $pdo->prepare(
                "SELECT COUNT(*) bills, COALESCE(SUM(total),0) total,
                        COALESCE(SUM(CASE WHEN payment_method<>'CREDIT' THEN paid_cash - change_amount ELSE 0 END),0) cash
                   FROM rtl_sales
                  WHERE tenant_id=? AND site_id=? AND deleted_at IS NULL AND status<>'Refunded'
                    AND sold_at >= ? AND sold_at < ? + INTERVAL 1 DAY");
            $q->execute([$tid, $sid, $d, $d]);
            return $q->fetch(PDO::FETCH_ASSOC);
        };
        $today = \date('Y-m-d');
        $t = $day($today);
        $y = $day(\date('Y-m-d', \strtotime('-1 day')));

        $h = $pdo->prepare(
            "SELECT HOUR(sold_at) h, COUNT(*) n FROM rtl_sales
              WHERE tenant_id=? AND site_id=? AND deleted_at IS NULL
                AND sold_at >= ? AND sold_at < ? + INTERVAL 1 DAY
              GROUP BY HOUR(sold_at)");
        $h->execute([$tid, $sid, $today, $today]);
        $hours = [];
        foreach ($h->fetchAll(PDO::FETCH_ASSOC) as $r) $hours[(int)$r['h']] = (int)$r['n'];

        $u = $pdo->prepare(
            "SELECT COALESCE(SUM(i.qty),0) units,
                    COALESCE(SUM(i.line_total - i.qty * p.cost_price),0) margin
               FROM rtl_sale_items i
               JOIN rtl_sales s ON s.id = i.sale_id AND s.tenant_id = i.tenant_id
               LEFT JOIN rtl_products p ON p.id = i.product_id AND p.tenant_id = i.tenant_id
              WHERE s.tenant_id=? AND s.site_id=? AND s.deleted_at IS NULL
                AND s.sold_at >= ? AND s.sold_at < ? + INTERVAL 1 DAY");
        $u->execute([$tid, $sid, $today, $today]);
        $um = $u->fetch(PDO::FETCH_ASSOC) ?: ['units' => 0, 'margin' => 0];

        $r = $pdo->prepare(
            "SELECT COALESCE(SUM(total),0) FROM rtl_docs
              WHERE tenant_id=? AND site_id=? AND doc_type='RET' AND status='POSTED'
                AND posted_at >= ? AND posted_at < ? + INTERVAL 1 DAY");
        $r->execute([$tid, $sid, $today, $today]);
        $returns = (float)$r->fetchColumn();

        $sh = $pdo->prepare("SELECT COUNT(*) FROM cashier_shifts WHERE tenant_id=? AND site_id=? AND status='OPEN' AND deleted_at IS NULL");
        $sh->execute([$tid, $sid]);

        $tt = (float)$t['total']; $yt = (float)$y['total'];
        return [
            'today_total'   => \round($tt, 2),
            'today_bills'   => (int)$t['bills'],
            'today_cash'    => \round((float)$t['cash'], 2),
            'yesterday'     => \round($yt, 2),
            'vs_yesterday'  => $yt > 0 ? \round(($tt - $yt) / $yt * 100, 1) : null,
            'returns'       => \round($returns, 2),
            'units'         => (float)$um['units'],
            'margin'        => \round((float)$um['margin'], 2),
            'hours'         => $hours,
            'open_shifts'   => (int)$sh->fetchColumn(),
        ];
    }
}

// build: V191 build 2026-11-13
