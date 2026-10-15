<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * RetailDocs — retail ke saare documents, ek engine.   (V154)
 *
 * KYUN BANI
 * V153 tak GRN, purchase return, stock count, returns aur purchase
 * orders — paanchon `ui_records` ke JSON blob mein row daal kar "Saved"
 * keh dete the. Stock ko koi haath nahi lagata tha. Audit mein 20 pcs
 * ka GRN post kiya: `ok:true` aaya aur stock 37 ka 37 raha.
 *
 * EK TABLE, PAANCH DOCUMENT
 * In paanchon ki shakal bilkul ek hai: header (number, tareekh, party,
 * total, halat) + lines (product, qty, rate). Paanch alag engine likhne
 * ka matlab tha posting ka hisaab paanch jagah — aur paanch mein se ek
 * kabhi na kabhi alag ho jati.
 *
 * DO HALATEIN, EK DARWAZA
 *
 *     DRAFT  ->  POSTED      (yahan stock hilta hai)
 *       |
 *       +----->  CANCELLED   (sirf DRAFT se)
 *
 * DRAFT kuch nahi karta. Adhoori entry, ghalat qty, aadha bhara hua
 * form — in mein se koi stock kharab nahi kar sakta.
 *
 * POSTED DOCUMENT WAPAS NAHI HOTA. Yeh sakhti jaan boojh kar hai: post
 * hone ke baad stock hil chuka hota hai, cost badal chuki hoti hai aur
 * supplier ka khata chal chuka hota hai. Us sab ko "undo" karna aisa
 * hisaab banata hai jis par kabhi bharosa na ho. Ghalti ho to ulta
 * document post karein — wahi tareeqa dukan ke register ka bhi hai, aur
 * dono entries nazar aati hain.
 *
 * SAB KUCH EK TRANSACTION MEIN. Stock hile aur ledger na chale — ya
 * ulta — to hisaab chup chaap kharab hota rehta. Ya poora, ya kuch bhi
 * nahi.
 *
 * RESTAURANT KO IS SE KOI TAALLUQ NAHI. Yeh class sirf `rtl_*` tables
 * par chalti hai; restaurant ka `PurchaseService` aur `inventory_items`
 * chhue bhi nahi jate.
 */
final class RetailDocs
{
    public const GRN   = 'GRN';    // maal aaya
    public const PRET  = 'PRET';   // supplier ko wapas
    public const RET   = 'RET';    // customer se wapas (bill return / void)
    public const CNT   = 'CNT';    // physical stock count
    public const PO    = 'PO';     // purchase order — stock nahi hilta
    public const WST   = 'WST';    // kharab / expired maal nikala

    public const DRAFT     = 'DRAFT';
    public const POSTED    = 'POSTED';
    public const CANCELLED = 'CANCELLED';

    /** doc_type => [number ka prefix, screen ka naam] */
    private const TYPES = [
        self::GRN  => ['GRN',  'Goods Receipt'],
        self::PRET => ['PRET', 'Purchase Return'],
        self::RET  => ['RET',  'Return / Refund'],
        self::CNT  => ['CNT',  'Stock Count'],
        self::PO   => ['PO',   'Purchase Order'],
        /* Wastage ko alag ad-hoc endpoint bhi diya ja sakta tha, magar
           wo phir doosri jagah wahi posting ka hisaab hoti. Wastage bhi
           ek document hai: tareekh, wajah, aur lines. Isi engine se. */
        self::WST  => ['WST',  'Damage / Expiry Write-off'],
    ];

    public static function types(): array { return \array_keys(self::TYPES); }

    public static function isType(string $t): bool { return isset(self::TYPES[\strtoupper($t)]); }

    /* ==================== BANANA ==================== */

    /**
     * Naya document (DRAFT) ya mojooda DRAFT ki tarmeem.
     *
     * @param array $d doc_type, doc_date?, supplier_id?, ref_no?, note?,
     *                 source_sale_id?, lines[] { product_id, qty, unit_cost?,
     *                 unit_price?, counted_qty?, batch_no?, expiry_date?, reason? }
     */
    public static function save(array $d, string $id = ''): array
    {
        $type = \strtoupper(\trim((string)($d['doc_type'] ?? '')));
        if (!self::isType($type)) throw new \RuntimeException('Unknown document type');

        $lines = \is_array($d['lines'] ?? null) ? $d['lines'] : [];
        $tid   = tenant_id();

        return DB::tx(function (PDO $p) use ($d, $id, $type, $lines, $tid) {

            /* Post ho chuka document kabhi na badle — warna bheja hua
               parcha aur apna record alag ho jate. */
            if ($id !== '') {
                $q = $p->prepare("SELECT status FROM rtl_docs WHERE id=? AND tenant_id=?");
                $q->execute([$id, $tid]);
                $st = (string)$q->fetchColumn();
                if ($st === '')                 throw new \RuntimeException('Document not found');
                if ($st !== self::DRAFT)        throw new \RuntimeException(
                    'A posted document cannot be changed. Post a correcting document instead.');
            }

            /* ---- lines saaf karo ---- */
            $clean = []; $sub = 0.0; $tax = 0.0; $disc = 0.0;
            foreach ($lines as $l) {
                $pid = \trim((string)($l['product_id'] ?? ''));
                if ($pid === '') continue;

                $pq = $p->prepare("SELECT name, cost_price, retail_price, tax_rate, stock_qty
                                     FROM rtl_products WHERE id=? AND tenant_id=? AND deleted_at IS NULL");
                $pq->execute([$pid, $tid]);
                $prod = $pq->fetch(PDO::FETCH_ASSOC);
                if (!$prod) throw new \RuntimeException('Product not found on one of the lines');

                if ($type === self::CNT) {
                    /* Count mein qty ka matlab "ginti ka farq" hai, jo
                       counted aur system se khud nikalta hai — cashier se
                       ghataana karwana ghalti ki jagah hai. */
                    $counted = (float)($l['counted_qty'] ?? 0);
                    $system  = \array_key_exists('system_qty', $l)
                             ? (float)$l['system_qty'] : (float)$prod['stock_qty'];
                    $qty     = $counted - $system;
                } else {
                    $qty = (float)($l['qty'] ?? 0);
                    if ($qty <= 0) throw new \RuntimeException('Quantity must be greater than zero');
                    $counted = null; $system = null;
                }

                $cost  = \array_key_exists('unit_cost', $l)  ? (float)$l['unit_cost']  : (float)$prod['cost_price'];
                $price = \array_key_exists('unit_price', $l) ? (float)$l['unit_price'] : (float)$prod['retail_price'];
                $ld    = (float)($l['discount'] ?? 0);
                $rate  = \array_key_exists('tax_rate', $l) ? (float)$l['tax_rate'] : (float)$prod['tax_rate'];

                /* GRN / PRET / PO kharid ki qeemat par chalte hain,
                   RET bikri ki qeemat par, CNT sirf cost par. */
                $unit  = \in_array($type, [self::RET], true) ? $price : $cost;
                $lt    = \max(0.0, \abs($qty) * $unit - $ld);

                $clean[] = [
                    'product_id' => $pid, 'name' => (string)$prod['name'],
                    'unit_code'  => (string)($l['unit_code'] ?? ''),
                    'qty' => $qty, 'counted' => $counted, 'system' => $system,
                    'cost' => $cost, 'price' => $price, 'discount' => $ld,
                    'tax_rate' => $rate, 'line_total' => $lt,
                    'batch_no' => \trim((string)($l['batch_no'] ?? '')),
                    'expiry'   => self::dateOrNull($l['expiry_date'] ?? ''),
                    'reason'   => \trim((string)($l['reason'] ?? '')),
                ];
                $sub  += $lt;
                $disc += $ld;
                $tax  += \round($lt * $rate / 100, 4);
            }
            if (!$clean) throw new \RuntimeException('Add at least one line before saving');

            $total = \round($sub + $tax, 4);
            $date  = self::dateOrNull($d['doc_date'] ?? '') ?: \date('Y-m-d');
            $supId = \trim((string)($d['supplier_id'] ?? '')) ?: null;
            $supNm = '';
            if ($supId) {
                $sq = $p->prepare("SELECT name FROM suppliers WHERE id=? AND tenant_id=?");
                $sq->execute([$supId, $tid]);
                $supNm = (string)($sq->fetchColumn() ?: '');
            }
            $user = current_user()['id'] ?? null;

            if ($id === '') {
                $id = \uuid();
                $p->prepare(
                    "INSERT INTO rtl_docs(id,tenant_id,site_id,doc_type,doc_no,doc_date,status,
                         supplier_id,supplier_name,customer_id,source_sale_id,source_doc_id,ref_no,
                         line_count,subtotal,discount,tax_amount,total,note,created_by)
                     VALUES(?,?,?,?,?,?, 'DRAFT', ?,?,?,?,?,?, ?,?,?,?,?,?,?)")
                    ->execute([$id, $tid, site_id(), $type, self::nextNo($p, $type), $date,
                               $supId, $supNm,
                               \trim((string)($d['customer_id'] ?? '')) ?: null,
                               \trim((string)($d['source_sale_id'] ?? '')) ?: null,
                               \trim((string)($d['source_doc_id'] ?? '')) ?: null,
                               \trim((string)($d['ref_no'] ?? '')),
                               \count($clean), $sub, $disc, $tax, $total,
                               \trim((string)($d['note'] ?? '')), $user]);
            } else {
                $p->prepare(
                    "UPDATE rtl_docs SET doc_date=?, supplier_id=?, supplier_name=?, ref_no=?,
                         line_count=?, subtotal=?, discount=?, tax_amount=?, total=?, note=?,
                         updated_at=NOW(6)
                     WHERE id=? AND tenant_id=?")
                    ->execute([$date, $supId, $supNm, \trim((string)($d['ref_no'] ?? '')),
                               \count($clean), $sub, $disc, $tax, $total,
                               \trim((string)($d['note'] ?? '')), $id, $tid]);
                $p->prepare("DELETE FROM rtl_doc_lines WHERE doc_id=? AND tenant_id=?")->execute([$id, $tid]);
            }

            foreach ($clean as $l) {
                $p->prepare(
                    "INSERT INTO rtl_doc_lines(id,tenant_id,doc_id,product_id,product_name,unit_code,
                         qty,counted_qty,system_qty,unit_cost,unit_price,discount,tax_rate,line_total,
                         batch_no,expiry_date,reason)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([\uuid(), $tid, $id, $l['product_id'], $l['name'], $l['unit_code'],
                               $l['qty'], $l['counted'], $l['system'], $l['cost'], $l['price'],
                               $l['discount'], $l['tax_rate'], $l['line_total'],
                               $l['batch_no'] ?: null, $l['expiry'], $l['reason'] ?: null]);
            }

            return self::get($id);
        });
    }

    /* ==================== POSTING ==================== */

    /**
     * Yahi wo lamha hai jab stock hilta hai.
     * Dobara post karne par kuch nahi hota (sirf wahi document wapas).
     */
    public static function post(string $id): array
    {
        $tid = tenant_id();
        return DB::tx(function (PDO $p) use ($id, $tid) {
            $q = $p->prepare("SELECT * FROM rtl_docs WHERE id=? AND tenant_id=? FOR UPDATE");
            $q->execute([$id, $tid]);
            $doc = $q->fetch(PDO::FETCH_ASSOC);
            if (!$doc)                              throw new \RuntimeException('Document not found');
            if ($doc['status'] === self::POSTED)    return self::get($id);
            if ($doc['status'] === self::CANCELLED) throw new \RuntimeException('A cancelled document cannot be posted');

            $lq = $p->prepare("SELECT * FROM rtl_doc_lines WHERE doc_id=? AND tenant_id=?");
            $lq->execute([$id, $tid]);
            $lines = $lq->fetchAll(PDO::FETCH_ASSOC);
            if (!$lines) throw new \RuntimeException('This document has no lines to post');

            $type = (string)$doc['doc_type'];
            $no   = (string)$doc['doc_no'];
            $ref  = ['table' => 'rtl_docs', 'id' => $id, 'no' => $no];

            foreach ($lines as $l) {
                $pid = (string)$l['product_id'];
                $qty = (float)$l['qty'];

                switch ($type) {
                    case self::GRN:
                        /* Cost pehle, stock baad mein — warna naya maal
                           khud apne average mein gin liya jata. */
                        RetailStock::applyAverageCost($p, $pid, $qty, (float)$l['unit_cost']);
                        RetailStock::move($p, $pid, $qty, RetailStock::GRN,
                            $ref + ['cost' => (float)$l['unit_cost']]);
                        self::addBatch($p, $l, $doc);
                        if (!empty($doc['supplier_id'])) {
                            $p->prepare("UPDATE rtl_products SET last_supplier_id=? WHERE id=? AND tenant_id=?")
                              ->execute([$doc['supplier_id'], $pid, $tid]);
                        }
                        break;

                    case self::PRET:
                        RetailStock::move($p, $pid, -$qty, RetailStock::PURCH_RET,
                            $ref + ['cost' => (float)$l['unit_cost']]);
                        break;

                    case self::RET:
                        RetailStock::move($p, $pid, $qty, RetailStock::SALE_RETURN, $ref);
                        break;

                    case self::CNT:
                        /* qty = counted - system. Sifar ho to kuch nahi
                           hua — RetailStock khud chup chaap chhor deta hai. */
                        RetailStock::move($p, $pid, $qty, RetailStock::COUNT,
                            $ref + ['note' => 'Counted '.(float)$l['counted_qty']
                                            .' vs system '.(float)$l['system_qty']]);
                        break;

                    case self::WST:
                        /* `ModuleBridge` ka wastage restaurant ke
                           `inventory_items` mein item dhoondhta hai;
                           supermarket ke product `rtl_products` mein hote
                           hain. Is liye retail kabhi apna koi maal
                           write-off kar hi nahi sakta tha — har koshish
                           par "Inventory item not found". Yeh raasta
                           retail ka apna hai aur ModuleBridge ko chhuta
                           bhi nahi. */
                        RetailStock::move($p, $pid, -$qty, RetailStock::WASTAGE,
                            $ref + ['note' => (string)($l['reason'] ?: ($doc['ref_no'] ?: 'Write-off'))]);
                        break;

                    case self::PO:
                        /* Purchase order stock ko haath nahi lagata — wo
                           sirf ek waada hai. Maal GRN par aata hai. */
                        break;
                }
            }

            /* Supplier ka khata */
            if (!empty($doc['supplier_id']) && \in_array($type, [self::GRN, self::PRET], true)) {
                self::supplierEntry($p, (string)$doc['supplier_id'],
                    $type === self::GRN ? 'GRN' : 'PURCH_RET',
                    $type === self::GRN ? (float)$doc['total'] : 0.0,
                    $type === self::GRN ? 0.0 : (float)$doc['total'],
                    $id, $no);
            }

            /* Udhaar par bika hua bill wapas aaye to khata bhi halka ho */
            if ($type === self::RET && !empty($doc['customer_id'])) {
                self::customerRefund($p, (string)$doc['customer_id'], (float)$doc['total'], $id, $no);
            }
            if ($type === self::RET && !empty($doc['source_sale_id'])) {
                $p->prepare("UPDATE rtl_sales SET status='Returned', updated_at=NOW(6)
                              WHERE id=? AND tenant_id=?")
                  ->execute([$doc['source_sale_id'], $tid]);
            }

            $p->prepare("UPDATE rtl_docs SET status='POSTED', posted_at=NOW(6), posted_by=?, updated_at=NOW(6)
                          WHERE id=? AND tenant_id=?")
              ->execute([current_user()['id'] ?? null, $id, $tid]);

            try { Audit::log('POST', 'retail_docs', ['record_id' => $id, 'label' => $no,
                             'new' => $type.' / '.$doc['total']]); } catch (\Throwable $e) {}

            return self::get($id);
        });
    }

    /** Sirf DRAFT cancel hota hai. Post ho chuka ho to ulta document banayein. */
    public static function cancel(string $id, string $why = ''): array
    {
        $doc = self::get($id);
        if (!$doc) throw new \RuntimeException('Document not found');
        if ($doc['status'] === self::POSTED) throw new \RuntimeException(
            'A posted document cannot be cancelled. Post a correcting document instead.');
        DB::pdo()->prepare("UPDATE rtl_docs SET status='CANCELLED',
                                note=CONCAT(COALESCE(note,''),?), updated_at=NOW(6)
                             WHERE id=? AND tenant_id=?")
                 ->execute([$why !== '' ? ' / cancelled: '.$why : ' / cancelled', $id, tenant_id()]);
        return self::get($id);
    }

    /* ==================== PARHNA ==================== */

    public static function get(string $id): ?array
    {
        $q = DB::pdo()->prepare("SELECT * FROM rtl_docs WHERE id=? AND tenant_id=? LIMIT 1");
        $q->execute([$id, tenant_id()]);
        $d = $q->fetch(PDO::FETCH_ASSOC);
        if (!$d) return null;
        $l = DB::pdo()->prepare("SELECT * FROM rtl_doc_lines WHERE doc_id=? AND tenant_id=? ORDER BY created_at");
        $l->execute([$id, tenant_id()]);
        $d['lines'] = $l->fetchAll(PDO::FETCH_ASSOC);
        return self::shape($d);
    }

    public static function listOf(string $type, string $status = '', int $limit = 300): array
    {
        $sql = "SELECT * FROM rtl_docs WHERE tenant_id=? AND doc_type=? AND deleted_at IS NULL";
        $arg = [tenant_id(), \strtoupper($type)];
        if ($status !== '' && \strtoupper($status) !== 'ALL') { $sql .= " AND status=?"; $arg[] = \strtoupper($status); }
        $sql .= " ORDER BY doc_date DESC, created_at DESC LIMIT ".\max(1, \min(1000, $limit));
        $q = DB::pdo()->prepare($sql);
        $q->execute($arg);
        return \array_map([self::class, 'shape'], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Supplier ka balance ledger se GINA jata hai, kahin likha nahi hota. */
    public static function supplierBalance(string $supplierId): float
    {
        $q = DB::pdo()->prepare("SELECT COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0)
                                   FROM rtl_supplier_ledger
                                  WHERE tenant_id=? AND supplier_id=? AND deleted_at IS NULL");
        $q->execute([tenant_id(), $supplierId]);
        return (float)$q->fetchColumn();
    }

    public static function supplierLedger(string $supplierId, int $limit = 200): array
    {
        $q = DB::pdo()->prepare("SELECT * FROM rtl_supplier_ledger
                                  WHERE tenant_id=? AND supplier_id=? AND deleted_at IS NULL
                                  ORDER BY entry_at DESC LIMIT ".\max(1, \min(1000, $limit)));
        $q->execute([tenant_id(), $supplierId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Supplier ko paisay diye. */
    public static function paySupplier(string $supplierId, float $amount, string $note = '', string $refNo = ''): array
    {
        if ($amount <= 0) throw new \RuntimeException('Payment amount must be greater than zero');
        return DB::tx(function (PDO $p) use ($supplierId, $amount, $note, $refNo) {
            self::supplierEntry($p, $supplierId, 'PAYMENT', 0.0, $amount, null, $refNo, $note);
            return ['balance' => self::supplierBalance($supplierId)];
        });
    }

    /* ==================== andaroon ==================== */

    private static function shape(array $d): array
    {
        foreach (['subtotal','discount','tax_amount','total','paid_amount'] as $k) $d[$k] = (float)$d[$k];
        $d['line_count'] = (int)$d['line_count'];
        foreach (($d['lines'] ?? []) as &$l) {
            foreach (['qty','unit_cost','unit_price','discount','tax_rate','line_total'] as $k) $l[$k] = (float)$l[$k];
            foreach (['counted_qty','system_qty'] as $k) $l[$k] = $l[$k] === null ? null : (float)$l[$k];
        }
        return $d;
    }

    /** GRN par batch/expiry ki line bhi banti hai — expiry supermarket ka roz ka masla hai. */
    private static function addBatch(PDO $p, array $line, array $doc): void
    {
        $bn = \trim((string)($line['batch_no'] ?? ''));
        $ex = $line['expiry_date'] ?? null;
        if ($bn === '' && !$ex) return;
        try {
            $p->prepare("INSERT INTO rtl_batches(id,tenant_id,site_id,product_id,batch_no,expiry_date,
                             qty,cost_price,received_on)
                         VALUES(?,?,?,?,?,?,?,?,?)")
              ->execute([\uuid(), tenant_id(), site_id(), (string)$line['product_id'],
                         $bn !== '' ? $bn : (string)$doc['doc_no'], $ex,
                         (float)$line['qty'], (float)$line['unit_cost'], (string)$doc['doc_date']]);
        } catch (\Throwable $e) { /* batch na bane to GRN phir bhi chale */ }
    }

    private static function supplierEntry(PDO $p, string $supplierId, string $type,
                                          float $credit, float $debit,
                                          ?string $refId = null, string $refNo = '', string $note = ''): void
    {
        $bal = self::supplierBalance($supplierId) + $credit - $debit;
        $p->prepare(
            "INSERT INTO rtl_supplier_ledger(id,tenant_id,site_id,supplier_id,entry_type,
                 ref_table,ref_id,ref_no,debit,credit,balance_after,note,entry_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW(6))")
            ->execute([\uuid(), tenant_id(), site_id(), $supplierId, $type,
                       $refId ? 'rtl_docs' : null, $refId, $refNo,
                       $debit, $credit, $bal, $note]);
    }

    private static function customerRefund(PDO $p, string $customerId, float $amount,
                                           string $docId, string $docNo): void
    {
        try {
            $p->prepare("UPDATE customers SET balance = balance - ? WHERE id=? AND tenant_id=?")
              ->execute([$amount, $customerId, tenant_id()]);
            $bq = $p->prepare("SELECT balance FROM customers WHERE id=? AND tenant_id=?");
            $bq->execute([$customerId, tenant_id()]);
            $p->prepare(
                "INSERT INTO rtl_customer_ledger(id,tenant_id,site_id,customer_id,entry_type,
                     ref_table,ref_id,ref_no,debit,credit,balance_after,note,entry_at)
                 VALUES(?,?,?,?, 'RETURN','rtl_docs',?,?,0,?,?,?,NOW(6))")
                ->execute([\uuid(), tenant_id(), site_id(), $customerId, $docId, $docNo,
                           $amount, (float)$bq->fetchColumn(), 'Sale return']);
        } catch (\Throwable $e) { /* khata na chale to return phir bhi ho */ }
    }

    /** GRN-2026-0001 — saal ke hisaab se, har type ki apni ginti. */
    private static function nextNo(PDO $p, string $type): string
    {
        $pre  = self::TYPES[$type][0];
        $year = \date('Y');
        for ($try = 0; $try < 12; $try++) {
            $q = $p->prepare("SELECT doc_no FROM rtl_docs WHERE tenant_id=? AND doc_type=? AND doc_no LIKE ?
                               ORDER BY doc_no DESC LIMIT 1");
            $q->execute([tenant_id(), $type, $pre.'-'.$year.'-%']);
            $last = (string)$q->fetchColumn();
            $n  = $last !== '' ? ((int)\substr($last, -4) + 1) : 1;
            $no = $pre.'-'.$year.'-'.\str_pad((string)$n, 4, '0', STR_PAD_LEFT);
            $c = $p->prepare("SELECT COUNT(*) FROM rtl_docs WHERE tenant_id=? AND doc_type=? AND doc_no=?");
            $c->execute([tenant_id(), $type, $no]);
            if (!(int)$c->fetchColumn()) return $no;
        }
        return $pre.'-'.$year.'-'.\substr(\uuid(), 0, 6);
    }

    private static function dateOrNull($v): ?string
    {
        $v = \trim((string)$v);
        return \preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}

// build: V154 build 2026-10-13
