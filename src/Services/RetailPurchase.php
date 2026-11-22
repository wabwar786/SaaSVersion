<?php
namespace Aio\Services;

use Aio\DB;
use Aio\Auth;
use PDO;

/**
 * RetailPurchase — Purchase Entry screen (V208). RETAIL ONLY.
 *
 * WHAT IT IS
 * The supplier's sales-tax invoice is typed line by line exactly like the
 * mart's PO sheet: Qty, Price, Total, Disc before GST, GST, Disc after GST,
 * Advance tax (W.H.T 236H), Net amount, Net unit cost, Prev cost, Mkt price,
 * Sale price. Every pair (rate/total, % / amount) can be typed from either
 * side; the side that was typed is remembered (calc_modes) and the server
 * recalculates everything with the same rules as the screen. Totals sent by
 * the screen are never trusted.
 *
 * HOW IT IS STORED
 * A Purchase Entry IS a goods receipt: rtl_docs doc_type = GRN with
 * entry_kind = 'PURCHASE', so stock, average cost, batches, the supplier
 * ledger, the GRN register and purchase returns all keep working through the
 * one posting engine (RetailDocs::postInTx). unit_cost on each line is the
 * net unit cost (after discounts, GST and advance tax).
 *
 * BILL CHECK
 * The supplier bill figures (discount, GST, sub total, advance tax, invoice
 * total) are entered on the screen. Posting is refused unless they match the
 * lines within rounding: the supplier rounds each line, so sub total and
 * invoice total may differ by 0.5 per line; discount and GST by Rs 1;
 * advance tax by Rs 0.5. The supplier ledger is credited with the bill's
 * invoice total (what the supplier will ask for); the computed total is kept
 * in calc_total.
 *
 * RESTAURANT
 * Nothing here touches restaurant tables or code. New columns are only on
 * rtl_docs / rtl_doc_lines, plus four nullable rtl_* columns on the shared
 * suppliers table that no restaurant code reads.
 */
final class RetailPurchase
{
    public const KIND = 'PURCHASE';

    /* ======================= schema (idempotent) ======================= */

    private static bool $schemaOk = false;

    /** Adds the V208 columns if they are missing. Safe to run any number of times. */
    public static function ensureSchema(?PDO $p = null): array
    {
        if (self::$schemaOk) return ['added' => 0];
        $p = $p ?: DB::pdo();
        $have = [];
        $q = $p->query("SELECT table_name t, column_name c FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name IN ('rtl_docs','rtl_doc_lines','suppliers')");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $have[\strtolower($r['t'] ?? $r['TABLE_NAME'] ?? '').'.'.\strtolower($r['c'] ?? $r['COLUMN_NAME'] ?? '')] = true;
        $want = [
            'rtl_docs' => [
                'entry_kind'    => "VARCHAR(12) NULL",
                'bill_date'     => "DATE NULL",
                'pay_mode'      => "VARCHAR(10) NULL",
                'adv_tax'       => "DECIMAL(14,4) NOT NULL DEFAULT 0",
                'calc_total'    => "DECIMAL(14,4) NOT NULL DEFAULT 0",
                'bill_discount' => "DECIMAL(14,4) NULL",
                'bill_gst'      => "DECIMAL(14,4) NULL",
                'bill_subtotal' => "DECIMAL(14,4) NULL",
                'bill_adv'      => "DECIMAL(14,4) NULL",
                'bill_total'    => "DECIMAL(14,4) NULL",
                'approved_by'   => "CHAR(36) NULL",
                'approved_at'   => "DATETIME(6) NULL",
                'verified_by'   => "CHAR(36) NULL",
                'verified_at'   => "DATETIME(6) NULL",
            ],
            'rtl_doc_lines' => [
                'barcode'        => "VARCHAR(40) NULL",
                'rate'           => "DECIMAL(14,4) NOT NULL DEFAULT 0",
                'disc1_pct'      => "DECIMAL(9,4) NOT NULL DEFAULT 0",
                'disc1_amt'      => "DECIMAL(14,4) NOT NULL DEFAULT 0",
                'gst_pct'        => "DECIMAL(9,4) NOT NULL DEFAULT 0",
                'gst_amt'        => "DECIMAL(14,4) NOT NULL DEFAULT 0",
                'disc2_pct'      => "DECIMAL(9,4) NOT NULL DEFAULT 0",
                'disc2_amt'      => "DECIMAL(14,4) NOT NULL DEFAULT 0",
                'adv_pct'        => "DECIMAL(9,4) NOT NULL DEFAULT 0",
                'adv_amt'        => "DECIMAL(14,4) NOT NULL DEFAULT 0",
                'net_amt'        => "DECIMAL(14,4) NOT NULL DEFAULT 0",
                'prev_cost'      => "DECIMAL(14,4) NULL",
                'mkt_price'      => "DECIMAL(14,4) NULL",
                'sale_price'     => "DECIMAL(14,4) NULL",
                'old_sale_price' => "DECIMAL(14,4) NULL",
                'calc_modes'     => "VARCHAR(20) NULL",
            ],
            'suppliers' => [
                'rtl_strn'     => "VARCHAR(40) NULL",
                'rtl_filer'    => "VARCHAR(12) NULL",
                'rtl_adv_pct'  => "DECIMAL(6,3) NULL",
                'rtl_disc_pct' => "DECIMAL(6,3) NULL",
            ],
        ];
        $added = 0;
        foreach ($want as $t => $cols) {
            foreach ($cols as $c => $def) {
                if (isset($have[$t.'.'.$c])) continue;
                $p->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
                $added++;
            }
        }
        self::$schemaOk = true;
        return ['added' => $added];
    }

    /* ======================= the calculation ======================= */

    private static function r2(float $v): float { return \round($v + 0.0, 2); }

    /**
     * One line, the same rules as the screen.
     * $l: qty, rate, total, disc1_pct, disc1_amt, gst_pct, gst_amt, disc2_pct, disc2_amt,
     *     adv_pct, adv_amt, modes "rt,d1,g,d2,adv" e.g. "r,p,a,p,p"
     *     (rt: r = rate typed, t = total typed; others: p = % typed, a = amount typed)
     */
    public static function calcLine(array $l): array
    {
        $q = (float)($l['qty'] ?? 0);
        $m = \explode(',', (string)($l['modes'] ?? 'r,p,a,p,p')) + ['r', 'p', 'a', 'p', 'p'];
        [$mrt, $md1, $mg, $md2, $madv] = \array_map(fn($x) => \trim((string)$x), \array_pad($m, 5, 'p'));

        if ($mrt === 't') { $g = self::r2((float)($l['total'] ?? 0)); $rate = $q > 0 ? $g / $q : 0.0; }
        else              { $rate = (float)($l['rate'] ?? 0); $g = self::r2($q * $rate); }

        if ($md1 === 'a') { $d1 = self::r2((float)($l['disc1_amt'] ?? 0)); $d1p = $g > 0 ? $d1 / $g * 100 : 0.0; }
        else              { $d1p = (float)($l['disc1_pct'] ?? 0); $d1 = self::r2($g * $d1p / 100); }
        $n1 = $g - $d1;

        if ($mg === 'a') { $ga = self::r2((float)($l['gst_amt'] ?? 0)); $gp = $n1 > 0 ? $ga / $n1 * 100 : 0.0; }
        else             { $gp = (float)($l['gst_pct'] ?? 0); $ga = self::r2($n1 * $gp / 100); }
        $ig = $n1 + $ga;

        if ($md2 === 'a') { $d2 = self::r2((float)($l['disc2_amt'] ?? 0)); $d2p = $ig > 0 ? $d2 / $ig * 100 : 0.0; }
        else              { $d2p = (float)($l['disc2_pct'] ?? 0); $d2 = self::r2($ig * $d2p / 100); }
        $n2 = $ig - $d2;

        if ($madv === 'a') { $av = self::r2((float)($l['adv_amt'] ?? 0)); $avp = $n2 > 0 ? $av / $n2 * 100 : 0.0; }
        else               { $avp = (float)($l['adv_pct'] ?? 0); $av = self::r2($n2 * $avp / 100); }
        $lt = self::r2($n2 + $av);

        return [
            'qty' => $q, 'rate' => $rate, 'total' => $g,
            'disc1_pct' => $d1p, 'disc1_amt' => $d1, 'gst_pct' => $gp, 'gst_amt' => $ga,
            'disc2_pct' => $d2p, 'disc2_amt' => $d2, 'adv_pct' => $avp, 'adv_amt' => $av,
            'sub' => self::r2($n2), 'net_amt' => $lt, 'unit_cost' => $q > 0 ? $lt / $q : 0.0,
            'modes' => \implode(',', [$mrt === 't' ? 't' : 'r', $md1 === 'a' ? 'a' : 'p', $mg === 'a' ? 'a' : 'p',
                                      $md2 === 'a' ? 'a' : 'p', $madv === 'a' ? 'a' : 'p']),
        ];
    }

    /** Bill check: [ok, rows[label, ours, bill, diff, ok]] */
    public static function billCheck(array $T, array $bill, int $lines): array
    {
        $tolL = 0.5 * \max(1, $lines);
        $rows = [
            ['Discount',              $T['discount'], $bill['discount'], 1.0],
            ['GST',                   $T['gst'],      $bill['gst'],      1.0],
            ['Sub total (inc GST)',   $T['sub'],      $bill['subtotal'], $tolL],
            ['W.H.T 236H / Adv tax',  $T['adv'],      $bill['adv'],      0.5],
            ['Invoice total',         $T['total'],    $bill['total'],    $tolL],
        ];
        $ok = true; $out = [];
        foreach ($rows as [$lab, $a, $b, $tol]) {
            if ($b === null) { $ok = false; $out[] = [$lab, $a, null, null, false]; continue; }
            $d = \round($b - $a, 2);
            $good = \abs($d) <= $tol + 0.0001;
            if (!$good) $ok = false;
            $out[] = [$lab, \round($a, 2), (float)$b, $d, $good];
        }
        return [$ok, $out];
    }

    /* ======================= reading ======================= */

    /** Suppliers for the dropdown, with balance (one grouped query, not one per supplier). */
    public static function suppliers(): array
    {
        self::ensureSchema();
        $tid = tenant_id();
        $q = DB::pdo()->prepare(
            "SELECT s.id, s.name, s.phone, s.contact_person, s.tax_no, s.credit_days, s.address_text, s.city,
                    s.rtl_strn, s.rtl_filer, s.rtl_adv_pct, s.rtl_disc_pct,
                    COALESCE(b.bal,0) balance
               FROM suppliers s
          LEFT JOIN (SELECT supplier_id, SUM(credit)-SUM(debit) bal FROM rtl_supplier_ledger
                      WHERE tenant_id=? AND deleted_at IS NULL GROUP BY supplier_id) b ON b.supplier_id=s.id
              WHERE s.tenant_id=? AND s.deleted_at IS NULL AND COALESCE(s.status,'ACTIVE')<>'INACTIVE'
              ORDER BY s.name");
        $q->execute([$tid, $tid]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['balance'] = (float)$r['balance'];
            $r['credit_days'] = (int)$r['credit_days'];
            $r['rtl_adv_pct'] = $r['rtl_adv_pct'] === null ? null : (float)$r['rtl_adv_pct'];
            $r['rtl_disc_pct'] = $r['rtl_disc_pct'] === null ? null : (float)$r['rtl_disc_pct'];
        }
        return $rows;
    }

    /** New supplier from the popup. Retail only; does not use the shared ModuleBridge path. */
    public static function createSupplier(array $d): array
    {
        self::ensureSchema();
        $name = \trim((string)($d['name'] ?? ''));
        $phone = \trim((string)($d['phone'] ?? ''));
        if ($name === '') throw new \RuntimeException('Supplier name is required');
        if (\strlen(\preg_replace('/\D/', '', $phone)) < 7) throw new \RuntimeException('Enter a valid phone number');
        $tid = tenant_id();
        return DB::tx(function (PDO $p) use ($d, $name, $phone, $tid) {
            $dq = $p->prepare("SELECT id FROM suppliers WHERE tenant_id=? AND deleted_at IS NULL AND name=? LIMIT 1");
            $dq->execute([$tid, $name]);
            if ($dq->fetchColumn()) throw new \RuntimeException('A supplier with this name already exists');
            $filer = \strtoupper((string)($d['filer'] ?? 'FILER')) === 'NON_FILER' ? 'NON_FILER' : 'FILER';
            $adv = \array_key_exists('adv_pct', $d) && $d['adv_pct'] !== '' ? (float)$d['adv_pct'] : ($filer === 'FILER' ? 0.5 : 1.0);
            $open = \max(0.0, (float)($d['opening_balance'] ?? 0));
            $id = \uuid();
            $p->prepare("INSERT INTO suppliers(id,tenant_id,site_id,name,contact_person,phone,address_text,city,tax_no,
                             credit_days,opening_balance,status,rtl_strn,rtl_filer,rtl_adv_pct,rtl_disc_pct,created_at)
                         VALUES(?,?,?,?,?,?,?,?,?,?,?,'ACTIVE',?,?,?,?,NOW(6))")
              ->execute([$id, $tid, site_id(), $name, \trim((string)($d['contact_person'] ?? '')), $phone,
                         \trim((string)($d['address'] ?? '')), \trim((string)($d['city'] ?? '')),
                         \trim((string)($d['ntn'] ?? '')), \max(0, (int)($d['credit_days'] ?? 0)), $open,
                         \trim((string)($d['strn'] ?? '')), $filer, $adv, (float)($d['disc_pct'] ?? 0)]);
            if ($open > 0) {
                $p->prepare("INSERT INTO rtl_supplier_ledger(id,tenant_id,site_id,supplier_id,entry_type,ref_table,ref_id,
                                 ref_no,debit,credit,balance_after,note,entry_at)
                             VALUES(?,?,?,?,'OPENING',NULL,NULL,'',0,?,?,'Opening balance',NOW(6))")
                  ->execute([\uuid(), $tid, site_id(), $id, $open, $open]);
            }
            try { Audit::log('CREATE', 'suppliers', ['id' => $id, 'label' => $name]); } catch (\Throwable $e) {}
            foreach (self::suppliers() as $s) if ($s['id'] === $id) return $s;
            return ['id' => $id, 'name' => $name];
        });
    }

    /** Products for a scan or a name search, with last purchase cost. */
    public static function findProducts(string $code, string $q = ''): array
    {
        $rows = [];
        if ($code !== '') {
            $hit = RetailCatalog::findByCode($code);
            if ($hit) { $pr = $hit['product']; $pr['_scanned'] = $code; $rows[] = $pr; }
        } elseif (\mb_strlen(\trim($q)) >= 2) {
            $rows = RetailCatalog::search($q, 15);
        }
        return \array_map([self::class, 'productRow'], $rows);
    }

    private static function productRow(array $pr): array
    {
        $bc = (string)($pr['_scanned'] ?? '');
        if ($bc === '') $bc = (string)(($pr['barcodes'][0] ?? '') ?: ($pr['sku'] ?? ''));
        return [
            'id' => $pr['id'], 'name' => $pr['name'], 'barcode' => $bc,
            'cost_price' => (float)($pr['cost_price'] ?? 0), 'retail_price' => (float)($pr['retail_price'] ?? 0),
            'mrp' => (float)($pr['mrp'] ?? 0), 'tax_rate' => (float)($pr['tax_rate'] ?? 0),
            'prev_cost' => self::lastCost((string)$pr['id'], (float)($pr['cost_price'] ?? 0)),
        ];
    }

    /** Net unit cost of the last posted receipt of this product (index ix_rdl_prod). */
    public static function lastCost(string $pid, float $fallback = 0.0): float
    {
        $q = DB::pdo()->prepare("SELECT l.unit_cost FROM rtl_doc_lines l JOIN rtl_docs d ON d.id=l.doc_id
                                  WHERE l.tenant_id=? AND l.product_id=? AND d.doc_type='GRN' AND d.status='POSTED'
                                    AND l.deleted_at IS NULL
                                  ORDER BY d.posted_at DESC LIMIT 1");
        $q->execute([tenant_id(), $pid]);
        $v = $q->fetchColumn();
        return $v === false ? $fallback : (float)$v;
    }

    /** Open purchase orders of a supplier (not yet received by a posted purchase). */
    public static function openPos(string $supplierId): array
    {
        $tid = tenant_id();
        $q = DB::pdo()->prepare(
            "SELECT po.id, po.doc_no, po.doc_date, po.line_count, po.total FROM rtl_docs po
              WHERE po.tenant_id=? AND po.doc_type='PO' AND po.status IN ('DRAFT','POSTED') AND po.deleted_at IS NULL
                AND (?='' OR po.supplier_id=?)
                AND NOT EXISTS (SELECT 1 FROM rtl_docs g WHERE g.tenant_id=po.tenant_id AND g.doc_type='GRN'
                                 AND g.status='POSTED' AND g.source_doc_id=po.id)
              ORDER BY po.doc_date DESC LIMIT 100");
        $q->execute([$tid, $supplierId, $supplierId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Lines of a PO, ready for the grid. */
    public static function poLines(string $poId): array
    {
        $doc = RetailDocs::get($poId);
        if (!$doc || $doc['doc_type'] !== 'PO') throw new \RuntimeException('Purchase order not found');
        $out = [];
        foreach ($doc['lines'] as $l) {
            $pr = RetailCatalog::product((string)$l['product_id']);
            if (!$pr) continue;
            $row = self::productRow($pr);
            $row['qty'] = (float)$l['qty'];
            $row['rate'] = (float)$l['unit_cost'];
            $out[] = $row;
        }
        return ['po' => ['id' => $doc['id'], 'doc_no' => $doc['doc_no'], 'supplier_id' => $doc['supplier_id']], 'lines' => $out];
    }

    public static function get(string $id): ?array
    {
        self::ensureSchema();
        $doc = RetailDocs::get($id);
        if (!$doc || ($doc['entry_kind'] ?? '') !== self::KIND) return null;
        $names = [];
        $ids = \array_filter([$doc['created_by'] ?? null, $doc['approved_by'] ?? null, $doc['verified_by'] ?? null, $doc['posted_by'] ?? null]);
        if ($ids) {
            $ph = \implode(',', \array_fill(0, \count($ids), '?'));
            $q = DB::pdo()->prepare("SELECT id, full_name FROM users WHERE id IN ($ph)");
            $q->execute(\array_values($ids));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) $names[$u['id']] = $u['full_name'];
        }
        foreach (['created_by', 'approved_by', 'verified_by', 'posted_by'] as $k) {
            $doc[$k.'_name'] = isset($doc[$k]) && $doc[$k] ? ($names[$doc[$k]] ?? '') : '';
        }
        return $doc;
    }

    public static function recent(int $limit = 60): array
    {
        self::ensureSchema();
        $q = DB::pdo()->prepare("SELECT id, doc_no, doc_date, status, supplier_name, ref_no, total, line_count, updated_at
                                   FROM rtl_docs WHERE tenant_id=? AND doc_type='GRN' AND entry_kind=? AND deleted_at IS NULL
                                  ORDER BY created_at DESC LIMIT ".\max(1, \min(200, $limit)));
        $q->execute([tenant_id(), self::KIND]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ======================= saving ======================= */

    /** Cleans and recalculates the request. Returns [header, lines, totals]. */
    private static function prepare(PDO $p, array $d): array
    {
        $tid = tenant_id();
        $sup = \trim((string)($d['supplier_id'] ?? ''));
        if ($sup === '') throw new \RuntimeException('Select the supplier');
        $sq = $p->prepare("SELECT name FROM suppliers WHERE id=? AND tenant_id=? AND deleted_at IS NULL");
        $sq->execute([$sup, $tid]);
        $supName = (string)($sq->fetchColumn() ?: '');
        if ($supName === '') throw new \RuntimeException('Supplier not found');

        $lines = [];
        $T = ['gross' => 0.0, 'discount' => 0.0, 'gst' => 0.0, 'sub' => 0.0, 'adv' => 0.0, 'total' => 0.0];
        foreach ((array)($d['lines'] ?? []) as $l) {
            $pid = \trim((string)($l['product_id'] ?? ''));
            if ($pid === '') continue;
            $pq = $p->prepare("SELECT id, name, retail_price, cost_price, mrp FROM rtl_products
                                WHERE id=? AND tenant_id=? AND deleted_at IS NULL");
            $pq->execute([$pid, $tid]);
            $prod = $pq->fetch(PDO::FETCH_ASSOC);
            if (!$prod) throw new \RuntimeException('Product not found on one of the lines');
            $c = self::calcLine($l);
            if ($c['qty'] <= 0)  throw new \RuntimeException($prod['name'].': quantity must be greater than zero');
            if ($c['rate'] < 0 || $c['total'] < 0) throw new \RuntimeException($prod['name'].': price cannot be negative');
            if ($c['disc1_amt'] < 0 || $c['disc2_amt'] < 0 || $c['gst_amt'] < 0 || $c['adv_amt'] < 0)
                throw new \RuntimeException($prod['name'].': discount, GST and advance tax cannot be negative');
            if ($c['disc1_amt'] > $c['total'] + 0.001) throw new \RuntimeException($prod['name'].': discount is more than the line total');
            $sale = \array_key_exists('sale_price', $l) && $l['sale_price'] !== '' && $l['sale_price'] !== null
                  ? (float)$l['sale_price'] : (float)$prod['retail_price'];
            if ($sale < 0) throw new \RuntimeException($prod['name'].': sale price cannot be negative');
            $mkt = \array_key_exists('mkt_price', $l) && $l['mkt_price'] !== '' && $l['mkt_price'] !== null
                 ? (float)$l['mkt_price'] : (float)$prod['mrp'];
            $lines[] = $c + [
                'product_id' => $pid, 'name' => (string)$prod['name'],
                'barcode' => \substr(\trim((string)($l['barcode'] ?? '')), 0, 40),
                'prev_cost' => self::lastCost($pid, (float)$prod['cost_price']),
                'mkt_price' => \max(0.0, $mkt), 'sale_price' => $sale,
                'old_sale_price' => (float)$prod['retail_price'],
                'batch_no' => \trim((string)($l['batch_no'] ?? '')),
                'expiry' => \preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($l['expiry_date'] ?? '')) ? (string)$l['expiry_date'] : null,
            ];
            $T['gross'] += $c['total']; $T['discount'] += $c['disc1_amt'] + $c['disc2_amt'];
            $T['gst'] += $c['gst_amt']; $T['sub'] += $c['sub']; $T['adv'] += $c['adv_amt']; $T['total'] += $c['net_amt'];
        }
        if (!$lines) throw new \RuntimeException('Add at least one item');
        foreach ($T as $k => $v) $T[$k] = \round($v, 2);

        $num = function ($v) { return ($v === null || $v === '') ? null : \round((float)$v, 2); };
        $bill = ['discount' => $num($d['bill_discount'] ?? null), 'gst' => $num($d['bill_gst'] ?? null),
                 'subtotal' => $num($d['bill_subtotal'] ?? null), 'adv' => $num($d['bill_adv'] ?? null),
                 'total' => $num($d['bill_total'] ?? null)];
        $date = (string)($d['bill_date'] ?? '');
        $head = [
            'supplier_id' => $sup, 'supplier_name' => $supName,
            'ref_no' => \substr(\trim((string)($d['ref_no'] ?? '')), 0, 60),
            'bill_date' => \preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
            'pay_mode' => \strtoupper((string)($d['pay_mode'] ?? 'CREDIT')) === 'CASH' ? 'CASH' : 'CREDIT',
            'source_doc_id' => \trim((string)($d['po_id'] ?? '')) ?: null,
            'note' => \substr(\trim((string)($d['note'] ?? '')), 0, 255),
        ];
        return [$head, $lines, $T, $bill];
    }

    private static function nextNo(PDO $p): string
    {
        /* Same numbering as every GRN (GRN-2026-0001) — one register. */
        $year = \date('Y');
        $q = $p->prepare("SELECT doc_no FROM rtl_docs WHERE tenant_id=? AND doc_type='GRN' AND doc_no LIKE ?
                           ORDER BY doc_no DESC LIMIT 1 FOR UPDATE");
        $q->execute([tenant_id(), 'GRN-'.$year.'-%']);
        $last = (string)$q->fetchColumn();
        $n = $last !== '' ? ((int)\substr($last, -4) + 1) : 1;
        for ($i = 0; $i < 20; $i++, $n++) {
            $no = 'GRN-'.$year.'-'.\str_pad((string)$n, 4, '0', STR_PAD_LEFT);
            $c = $p->prepare("SELECT COUNT(*) FROM rtl_docs WHERE tenant_id=? AND doc_type='GRN' AND doc_no=?");
            $c->execute([tenant_id(), $no]);
            if (!(int)$c->fetchColumn()) return $no;
        }
        return 'GRN-'.$year.'-'.\substr(\uuid(), 0, 6);
    }

    /** Writes header + lines (DRAFT). Must run inside a transaction. */
    private static function write(PDO $p, array $d, string $id): string
    {
        $tid = tenant_id();
        if ($id !== '') {
            $q = $p->prepare("SELECT status, entry_kind FROM rtl_docs WHERE id=? AND tenant_id=? AND doc_type='GRN' FOR UPDATE");
            $q->execute([$id, $tid]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || ($row['entry_kind'] ?? '') !== self::KIND) throw new \RuntimeException('Purchase entry not found');
            if ($row['status'] !== 'DRAFT') throw new \RuntimeException('This purchase is already '.\strtolower($row['status']).' and cannot be changed');
        }
        [$h, $lines, $T, $bill] = self::prepare($p, $d);
        if ($h['ref_no'] !== '') {
            /* Same supplier invoice entered twice = stock and payable twice. */
            $dq = $p->prepare("SELECT doc_no FROM rtl_docs WHERE tenant_id=? AND doc_type='GRN' AND supplier_id=? AND ref_no=?
                                 AND status<>'CANCELLED' AND id<>? LIMIT 1");
            $dq->execute([$tid, $h['supplier_id'], $h['ref_no'], $id]);
            if ($dn = $dq->fetchColumn()) throw new \RuntimeException('Supplier invoice '.$h['ref_no'].' is already entered in '.$dn);
        }
        $user = current_user()['id'] ?? null;
        $cols = [$h['supplier_id'], $h['supplier_name'], $h['ref_no'], $h['bill_date'], $h['pay_mode'], $h['source_doc_id'],
                 \count($lines), $T['gross'], $T['discount'], $T['gst'], $T['total'], $T['adv'], $T['total'],
                 $bill['discount'], $bill['gst'], $bill['subtotal'], $bill['adv'], $bill['total'], $h['note']];
        if ($id === '') {
            $id = \uuid();
            $p->prepare("INSERT INTO rtl_docs(id,tenant_id,site_id,doc_type,doc_no,doc_date,status,entry_kind,
                             supplier_id,supplier_name,ref_no,bill_date,pay_mode,source_doc_id,
                             line_count,subtotal,discount,tax_amount,total,adv_tax,calc_total,
                             bill_discount,bill_gst,bill_subtotal,bill_adv,bill_total,note,created_by)
                         VALUES(?,?,?,'GRN',?,?,'DRAFT','PURCHASE',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
              ->execute(\array_merge([$id, $tid, site_id(), self::nextNo($p), \date('Y-m-d')], $cols, [$user]));
        } else {
            $p->prepare("UPDATE rtl_docs SET supplier_id=?,supplier_name=?,ref_no=?,bill_date=?,pay_mode=?,source_doc_id=?,
                             line_count=?,subtotal=?,discount=?,tax_amount=?,total=?,adv_tax=?,calc_total=?,
                             bill_discount=?,bill_gst=?,bill_subtotal=?,bill_adv=?,bill_total=?,note=?,
                             approved_by=NULL,approved_at=NULL,verified_by=NULL,verified_at=NULL,updated_at=NOW(6)
                          WHERE id=? AND tenant_id=?")
              ->execute(\array_merge($cols, [$id, $tid]));
            $p->prepare("DELETE FROM rtl_doc_lines WHERE doc_id=? AND tenant_id=?")->execute([$id, $tid]);
        }
        $ins = $p->prepare("INSERT INTO rtl_doc_lines(id,tenant_id,doc_id,product_id,product_name,unit_code,qty,
                                unit_cost,unit_price,discount,tax_rate,line_total,batch_no,expiry_date,
                                barcode,rate,disc1_pct,disc1_amt,gst_pct,gst_amt,disc2_pct,disc2_amt,adv_pct,adv_amt,
                                net_amt,prev_cost,mkt_price,sale_price,old_sale_price,calc_modes,created_at)
                            VALUES(?,?,?,?,?,'',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $k = 0;
        foreach ($lines as $l) {
            /* created_at in order, so the lines come back in the order typed */
            $at = \date('Y-m-d H:i:s', \time()).'.'.\str_pad((string)$k++, 6, '0', STR_PAD_LEFT);
            $ins->execute([\uuid(), $tid, $id, $l['product_id'], $l['name'], $l['qty'],
                           \round($l['unit_cost'], 4), $l['sale_price'], $l['disc1_amt'] + $l['disc2_amt'],
                           \round($l['gst_pct'], 3), $l['net_amt'], $l['batch_no'] ?: null, $l['expiry'],
                           $l['barcode'] ?: null, \round($l['rate'], 4), \round($l['disc1_pct'], 4), $l['disc1_amt'],
                           \round($l['gst_pct'], 4), $l['gst_amt'], \round($l['disc2_pct'], 4), $l['disc2_amt'],
                           \round($l['adv_pct'], 4), $l['adv_amt'], $l['net_amt'], $l['prev_cost'], $l['mkt_price'],
                           $l['sale_price'], $l['old_sale_price'], $l['modes'], $at]);
        }
        return $id;
    }

    public static function saveDraft(array $d, string $id = ''): array
    {
        self::ensureSchema();
        $id = DB::tx(fn(PDO $p) => self::write($p, $d, $id));
        try { Audit::log('SAVE', 'retail_purchase', ['id' => $id, 'label' => 'Purchase draft']); } catch (\Throwable $e) {}
        return self::get($id);
    }

    /**
     * Save + post in ONE transaction: stock, average cost, batches, supplier
     * ledger (bill invoice total), new sale prices. Manager only.
     */
    public static function post(array $d, string $id = ''): array
    {
        self::ensureSchema();
        if (!Auth::isManager() && !Auth::isAdmin()) throw new \RuntimeException('Only a manager or the owner can post a purchase');
        if (empty($d['confirm'])) throw new \RuntimeException('Tick "Confirm" before posting');
        $tid = tenant_id();
        $docId = DB::tx(function (PDO $p) use ($d, $id, $tid) {
            $id = self::write($p, $d, $id);
            [$h, $lines, $T, $bill] = self::prepare($p, $d);
            if ($bill['total'] === null || $bill['total'] <= 0) throw new \RuntimeException('Enter the supplier bill figures (invoice total) before posting');
            [$ok, $rows] = self::billCheck($T, $bill, \count($lines));
            if (!$ok) {
                $bad = [];
                foreach ($rows as $r) if (!$r[4]) $bad[] = $r[0].($r[2] === null ? ' (not entered)' : ' (diff '.\number_format((float)$r[3], 2).')');
                throw new \RuntimeException('The purchase does not match the supplier bill: '.\implode(', ', $bad));
            }
            $user = current_user()['id'] ?? null;
            /* Supplier is credited with what the supplier bills. */
            $p->prepare("UPDATE rtl_docs SET total=?, approved_by=COALESCE(approved_by,?), approved_at=COALESCE(approved_at,NOW(6))
                          WHERE id=? AND tenant_id=?")->execute([$bill['total'], $user, $id, $tid]);

            RetailDocs::postInTx($p, $id);

            /* New sale / market prices go to the product (and the POS) only now. */
            foreach ($lines as $l) {
                $chg = \abs($l['sale_price'] - $l['old_sale_price']) > 0.0001;
                $p->prepare("UPDATE rtl_products SET retail_price=?, mrp=?, updated_at=NOW(6) WHERE id=? AND tenant_id=?")
                  ->execute([$l['sale_price'], $l['mkt_price'], $l['product_id'], $tid]);
                if ($chg) {
                    try { Audit::log('PRICE_CHANGED', 'retail_purchase', ['id' => $l['product_id'], 'label' => $l['name'],
                        'old' => $l['old_sale_price'], 'new' => $l['sale_price']]); } catch (\Throwable $e) {}
                }
            }
            return $id;
        });
        return self::get($docId);
    }

    /** Verified by: a manager confirms the entry was checked against the bill. Draft or posted. */
    public static function verify(string $id): array
    {
        self::ensureSchema();
        if (!Auth::isManager() && !Auth::isAdmin()) throw new \RuntimeException('Only a manager or the owner can verify a purchase');
        $doc = self::get($id);
        if (!$doc) throw new \RuntimeException('Purchase entry not found');
        if ($doc['status'] === 'CANCELLED') throw new \RuntimeException('A cancelled purchase cannot be verified');
        DB::pdo()->prepare("UPDATE rtl_docs SET verified_by=?, verified_at=NOW(6) WHERE id=? AND tenant_id=?")
                 ->execute([current_user()['id'] ?? null, $id, tenant_id()]);
        try { Audit::log('VERIFY', 'retail_purchase', ['id' => $id, 'label' => $doc['doc_no']]); } catch (\Throwable $e) {}
        return self::get($id);
    }

    /** Approved by on a draft (posting by a manager approves it automatically). */
    public static function approve(string $id): array
    {
        self::ensureSchema();
        if (!Auth::isManager() && !Auth::isAdmin()) throw new \RuntimeException('Only a manager or the owner can approve a purchase');
        $doc = self::get($id);
        if (!$doc) throw new \RuntimeException('Purchase entry not found');
        if ($doc['status'] !== 'DRAFT') throw new \RuntimeException('Only a draft can be approved');
        DB::pdo()->prepare("UPDATE rtl_docs SET approved_by=?, approved_at=NOW(6) WHERE id=? AND tenant_id=?")
                 ->execute([current_user()['id'] ?? null, $id, tenant_id()]);
        try { Audit::log('APPROVE', 'retail_purchase', ['id' => $id, 'label' => $doc['doc_no']]); } catch (\Throwable $e) {}
        return self::get($id);
    }
}

// build: V208 build 2026-11-22
