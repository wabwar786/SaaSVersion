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
 */
final class RetailPos
{
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

    /* ================= sale ================= */

    /**
     * Bill mukammal karo.
     *
     * @param array $d  lines[], customer_id, price_level, bill_discount,
     *                  payment_method, paid_cash, paid_card, counter_name
     */
    public static function saveSale(array $d): array
    {
        $lines = \array_values((array)($d['lines'] ?? []));
        if (!$lines) throw new \RuntimeException('Bill is empty');

        $method = \strtoupper((string)($d['payment_method'] ?? 'CASH'));
        if (!\in_array($method, ['CASH','CARD','MIXED','CREDIT'], true)) $method = 'CASH';

        $customerId = ($d['customer_id'] ?? '') ?: null;
        if ($method === 'CREDIT' && !$customerId) {
            throw new \RuntimeException('Credit sale ke liye customer zaroori hai');
        }

        /* Har line ki keemat DB se dobara li jati hai — client sirf
           product_id aur qty bhejta hai. Price client se lena maani yeh
           ke koi bhi 5000 ka item 5 rupay mein le jaye. */
        $priceLevel = ($d['price_level'] ?? 'Retail') === 'Wholesale' ? 'Wholesale' : 'Retail';
        $clean = [];
        foreach ($lines as $l) {
            $pid = (string)($l['product_id'] ?? '');
            $qty = (float)($l['qty'] ?? 0);
            if ($pid === '' || $qty <= 0) continue;
            $prod = RetailCatalog::product($pid);
            if (!$prod) throw new \RuntimeException('Product not found: ' . $pid);

            $unitPrice = $priceLevel === 'Wholesale'
                ? ((float)$prod['wholesale_price'] ?: (float)$prod['retail_price'])
                : (float)$prod['retail_price'];
            /* Pack scan ka apna rate aata hai — us par bharosa tabhi jab
               woh product ke apne uom mein maujood ho. */
            if (!empty($l['uom_id'])) {
                $uq = DB::pdo()->prepare("SELECT retail_price FROM rtl_product_uom WHERE id=? AND product_id=? AND tenant_id=?");
                $uq->execute([$l['uom_id'], $pid, tenant_id()]);
                $up = $uq->fetchColumn();
                if ($up !== false) $unitPrice = (float)$up;
            }

            $clean[] = [
                'product_id' => $pid,
                'name'       => (string)$prod['name'],
                'unit_code'  => (string)($l['unit_code'] ?? ''),
                'qty'        => $qty,
                'unit_price' => $unitPrice,
                'discount'   => \max(0.0, (float)($l['discount'] ?? 0)),
                'tax_rate'   => (float)$prod['tax_rate'],
            ];
        }
        if (!$clean) throw new \RuntimeException('Bill is empty');

        $tot = RegionProfile::billTotals($clean, (float)($d['bill_discount'] ?? 0));

        $cash = (float)($d['paid_cash'] ?? 0);
        $card = (float)($d['paid_card'] ?? 0);
        if ($method === 'CREDIT') { $cash = 0; $card = 0; }
        elseif ($method === 'CARD') { $cash = 0; }
        elseif ($method === 'CASH') { $card = 0; }

        $paid = $method === 'CREDIT' ? $tot['total'] : ($cash + $card);
        if ($method !== 'CREDIT' && $paid + 0.009 < $tot['total']) {
            throw new \RuntimeException('Payment is short by ' . \number_format($tot['total'] - $paid, 2));
        }
        $change = $method === 'CREDIT' ? 0.0 : \max(0.0, $paid - $tot['total']);

        $user = Auth::user() ?: [];

        return DB::tx(function (PDO $pdo) use ($clean, $tot, $d, $method, $cash, $card, $change, $customerId, $priceLevel, $user) {

            /* Credit limit — bill banane se PEHLE. */
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

            $billNo = self::nextBillNo($pdo);
            $saleId = \uuid();

            $pdo->prepare(
                "INSERT INTO rtl_sales(id,tenant_id,site_id,bill_no,counter_name,cashier_user_id,cashier_name,
                    customer_id,customer_name,price_level,line_count,subtotal,discount,tax_amount,total,
                    paid_cash,paid_card,change_amount,payment_method,status,sold_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(6))")
                ->execute([$saleId, tenant_id(), site_id(), $billNo,
                    (string)($d['counter_name'] ?? 'Counter 1'),
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
                   masla hai. Ghalti reports mein nazar aa jayegi. */
                $pdo->prepare("UPDATE rtl_products SET stock_qty = stock_qty - ? WHERE id=? AND tenant_id=?")
                    ->execute([$l['qty'], $l['product_id'], tenant_id()]);

                self::consumeBatches($pdo, $l['product_id'], $l['qty']);
            }

            /* Khata: balance aur ledger dono. Sirf balance barhana kaafi
               nahi — recovery ke waqt customer poochta hai "kis bill ka?" */
            if ($method === 'CREDIT' && $customerId) {
                $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id=? AND tenant_id=?")
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

            try { Audit::log('CREATE', 'rpos', ['record_id' => $saleId, 'bill_no' => $billNo, 'total' => $tot['total']]); }
            catch (\Throwable $e) {}

            return [
                'id' => $saleId, 'bill_no' => $billNo,
                'subtotal' => $tot['subtotal'], 'discount' => $tot['discount'],
                'tax' => $tot['tax'], 'total' => $tot['total'],
                'paid_cash' => $cash, 'paid_card' => $card, 'change' => $change,
                'status' => $method === 'CREDIT' ? 'Credit' : 'Completed',
                'customer_name' => $custName, 'price_level' => $priceLevel,
                'counter_name' => (string)($d['counter_name'] ?? 'Counter 1'),
                'cashier_name' => (string)($user['full_name'] ?? 'Cashier'),
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

    public static function sales(int $limit = 100): array
    {
        $q = DB::pdo()->prepare(
            "SELECT * FROM rtl_sales WHERE tenant_id=? AND site_id=? AND deleted_at IS NULL
              ORDER BY sold_at DESC LIMIT " . (int)$limit);
        $q->execute([tenant_id(), site_id()]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
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
            /* Reprint audit_log mein bhi jata hai — activity screen par
               manager ko nazar aana chahiye, sirf rtl_bill_reprints mein
               dafn nahi hona chahiye. */
            try { Audit::log('REPRINT', 'rpos', ['record_id' => (string)$sale['id'],
                             'bill_no' => (string)$sale['bill_no'], 'copy' => $copy, 'reason' => $reason]); }
            catch (\Throwable $e) {}

            $sale['reprint_count'] = $copy;
            $sale['last_reprint_at'] = \date('Y-m-d H:i');
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
        }
        return $rows;
    }

    public static function hold(array $d): string
    {
        $id = \uuid();
        $user = Auth::user() ?: [];
        DB::pdo()->prepare(
            "INSERT INTO rtl_held_bills(id,tenant_id,site_id,bill_no,counter_name,customer_id,customer_name,
                price_level,line_count,total,cart_json,held_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id, tenant_id(), site_id(),
                (string)($d['bill_no'] ?? ''), (string)($d['counter_name'] ?? 'Counter 1'),
                ($d['customer_id'] ?? null) ?: null, (string)($d['customer_name'] ?? 'Walk-in'),
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

    /** Udhaar ki wapsi. */
    public static function receivePayment(string $customerId, float $amount, string $method = 'CASH', string $note = ''): array
    {
        if ($amount <= 0) throw new \RuntimeException('Amount must be greater than zero');
        return DB::tx(function (PDO $pdo) use ($customerId, $amount, $method, $note) {
            $cq = $pdo->prepare("SELECT balance FROM customers WHERE id=? AND tenant_id=? FOR UPDATE");
            $cq->execute([$customerId, tenant_id()]);
            $bal = $cq->fetchColumn();
            if ($bal === false) throw new \RuntimeException('Customer not found');

            $pdo->prepare("UPDATE customers SET balance = balance - ? WHERE id=? AND tenant_id=?")
                ->execute([$amount, $customerId, tenant_id()]);
            $after = (float)$bal - $amount;
            $pdo->prepare(
                "INSERT INTO rtl_customer_ledger(id,tenant_id,site_id,customer_id,entry_type,
                    ref_table,ref_id,ref_no,debit,credit,balance_after,note,entry_at)
                 VALUES(?,?,?,?, 'RECEIPT',NULL,NULL,NULL,0,?,?,?,NOW(6))")
                ->execute([\uuid(), tenant_id(), site_id(), $customerId, $amount, $after,
                           \trim($method . ' ' . $note)]);
            return ['balance' => $after, 'received' => $amount];
        });
    }
}
