<?php
namespace Aio\Services;

use Aio\DB;
use Aio\Auth;
use PDO;

/**
 * RetailPayables — supplier payables for RETAIL back office (V212).
 *
 * Flow
 *   Inventory posts a purchase (RetailPurchase) -> ledger credit 'GRN'.
 *   Inventory prints a PAYMENT SLIP (PS-000001): "Pay now" or "Pay on date",
 *   one or more open bills of one supplier.
 *   Accounts scans the slip and makes a payment: Cash = CPV, every other
 *   mode (Cheque, Bank, JazzCash, Easypaisa) = BPV. A payment can be LESS
 *   than the slip (slip becomes Part paid, the rest stays payable on the
 *   same slip). Payment -> ledger debit 'CPV' / 'BPV'.
 *   Cancel (payment or slip): manager / owner only, reason required. A
 *   cancelled payment stays visible; a reverse ledger entry is added.
 *
 * Bill balance = ledger credit of the bill − posted returns made from that
 * bill − allocations of payments that are not cancelled. Allocations of a
 * payment above the bills (advance) are used first on the next payment.
 * The supplier balance is always the ledger (credit − debit), never typed.
 *
 * Retail only: own tables (rtl_pay_slips, rtl_pay_slip_bills,
 * rtl_supplier_payments, rtl_supplier_pay_alloc, rtl_doc_prints), created
 * here on first use. The shared `suppliers` table only gets one nullable
 * column (rtl_pay_info). Nothing a restaurant uses is read or written.
 */
final class RetailPayables
{
    public const MODES = ['CASH', 'CHEQUE', 'BANK', 'JAZZCASH', 'EASYPAISA'];
    private static bool $ready = false;

    /* ======================= schema ======================= */

    public static function ensureSchema(): void
    {
        if (self::$ready) return;
        $p = DB::pdo();
        $have = [];
        foreach ($p->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()
                             AND table_name IN ('rtl_pay_slips','rtl_pay_slip_bills','rtl_supplier_payments','rtl_supplier_pay_alloc','rtl_doc_prints')")
                   ->fetchAll(PDO::FETCH_COLUMN) as $t) $have[\strtolower($t)] = true;
        $sync = "created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                 deleted_at DATETIME(6) NULL, row_version BIGINT NOT NULL DEFAULT 1, origin_node_id CHAR(36) NULL";
        $ddl = [
            'rtl_pay_slips' => "CREATE TABLE rtl_pay_slips(
                id CHAR(36) PRIMARY KEY, tenant_id CHAR(36) NOT NULL, site_id CHAR(36) NULL,
                slip_no VARCHAR(20) NOT NULL, supplier_id CHAR(36) NOT NULL, slip_type VARCHAR(8) NOT NULL,
                pay_on DATE NOT NULL, amount DECIMAL(14,2) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'OPEN',
                note VARCHAR(255) NULL, created_by CHAR(36) NULL, cancelled_by CHAR(36) NULL, cancelled_at DATETIME(6) NULL,
                cancel_reason VARCHAR(255) NULL, $sync,
                UNIQUE KEY uq_ps_no(tenant_id, slip_no), KEY ix_ps_sup(tenant_id, supplier_id), KEY ix_ps_due(tenant_id, status, pay_on))",
            'rtl_pay_slip_bills' => "CREATE TABLE rtl_pay_slip_bills(
                id CHAR(36) PRIMARY KEY, tenant_id CHAR(36) NOT NULL, slip_id CHAR(36) NOT NULL, doc_id CHAR(36) NOT NULL,
                amount DECIMAL(14,2) NOT NULL, $sync, KEY ix_psb_slip(slip_id), KEY ix_psb_doc(tenant_id, doc_id))",
            'rtl_supplier_payments' => "CREATE TABLE rtl_supplier_payments(
                id CHAR(36) PRIMARY KEY, tenant_id CHAR(36) NOT NULL, site_id CHAR(36) NULL,
                voucher_no VARCHAR(20) NOT NULL, voucher_type VARCHAR(4) NOT NULL, supplier_id CHAR(36) NOT NULL,
                slip_id CHAR(36) NULL, pay_date DATE NOT NULL, mode VARCHAR(10) NOT NULL, bank_name VARCHAR(80) NULL,
                cheque_no VARCHAR(40) NULL, cheque_date DATE NULL, txn_ref VARCHAR(60) NULL, our_account VARCHAR(80) NULL,
                amount DECIMAL(14,2) NOT NULL, note VARCHAR(255) NULL, status VARCHAR(10) NOT NULL DEFAULT 'DONE',
                early_ok_by CHAR(36) NULL, created_by CHAR(36) NULL, cancelled_by CHAR(36) NULL, cancelled_at DATETIME(6) NULL,
                cancel_reason VARCHAR(255) NULL, $sync,
                UNIQUE KEY uq_sp_no(tenant_id, voucher_no), KEY ix_sp_sup(tenant_id, supplier_id), KEY ix_sp_slip(slip_id))",
            'rtl_supplier_pay_alloc' => "CREATE TABLE rtl_supplier_pay_alloc(
                id CHAR(36) PRIMARY KEY, tenant_id CHAR(36) NOT NULL, payment_id CHAR(36) NOT NULL,
                applied_by_payment_id CHAR(36) NULL, supplier_id CHAR(36) NOT NULL, doc_id VARCHAR(36) NOT NULL,
                amount DECIMAL(14,2) NOT NULL, $sync,
                KEY ix_pa_pay(payment_id), KEY ix_pa_doc(tenant_id, doc_id), KEY ix_pa_sup(tenant_id, supplier_id))",
            'rtl_doc_prints' => "CREATE TABLE rtl_doc_prints(
                id CHAR(36) PRIMARY KEY, tenant_id CHAR(36) NOT NULL, doc_id CHAR(36) NOT NULL, kind VARCHAR(12) NOT NULL,
                printed_by CHAR(36) NULL, $sync, KEY ix_dp_doc(tenant_id, doc_id))",
        ];
        foreach ($ddl as $t => $sql) if (empty($have[$t])) { try { $p->exec($sql); } catch (\Throwable $e) {} }
        $c = $p->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE()
                         AND table_name='suppliers' AND column_name='rtl_pay_info'")->fetchColumn();
        if (!(int)$c) { try { $p->exec("ALTER TABLE suppliers ADD COLUMN rtl_pay_info VARCHAR(160) NULL"); } catch (\Throwable $e) {} }
        self::$ready = true;
    }

    /* ======================= helpers ======================= */

    private static function isBoss(): bool { return Auth::isManager() || Auth::isAdmin(); }
    private static function uid(): ?string { return Auth::user()['id'] ?? null; }
    private static function money($v): float { return \round((float)$v, 2); }
    private static function date($v): ?string { $v = \trim((string)$v); return \preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; }

    private static function supplier(string $id): array
    {
        $q = DB::pdo()->prepare("SELECT id,name,phone,city,contact_person,credit_days,opening_balance,tax_no,rtl_strn,rtl_filer,
                                        rtl_pay_info,status FROM suppliers WHERE id=? AND tenant_id=? AND deleted_at IS NULL");
        $q->execute([$id, tenant_id()]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new \RuntimeException('Supplier not found');
        $r['credit_days'] = (int)$r['credit_days'];
        return $r;
    }

    public static function balance(string $sid): float
    {
        $q = DB::pdo()->prepare("SELECT COALESCE(SUM(credit),0)-COALESCE(SUM(debit),0) FROM rtl_supplier_ledger
                                  WHERE tenant_id=? AND supplier_id=? AND deleted_at IS NULL");
        $q->execute([tenant_id(), $sid]);
        return self::money($q->fetchColumn());
    }

    private static function ledger(PDO $p, string $sid, string $type, float $credit, float $debit, ?string $refId, string $refNo, string $note, ?string $at = null): void
    {
        $bal = self::balance($sid) + $credit - $debit;
        $p->prepare("INSERT INTO rtl_supplier_ledger(id,tenant_id,site_id,supplier_id,entry_type,ref_table,ref_id,ref_no,debit,credit,
                         balance_after,note,entry_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,".($at ? '?' : 'NOW(6)').")")
          ->execute(\array_merge([\uuid(), tenant_id(), site_id(), $sid, $type, $refId ? 'rtl_supplier_payments' : null, $refId, $refNo,
                                  $debit, $credit, $bal, \mb_substr($note, 0, 255)], $at ? [$at] : []));
    }

    private static function nextNo(PDO $p, string $table, string $col, string $pre): string
    {
        $q = $p->prepare("SELECT MAX(CAST(SUBSTRING($col, ".(\strlen($pre) + 2).") AS UNSIGNED)) FROM $table WHERE tenant_id=? AND $col LIKE ?");
        $q->execute([tenant_id(), $pre.'-%']);
        return $pre.'-'.\str_pad((string)((int)$q->fetchColumn() + 1), 6, '0', STR_PAD_LEFT);
    }

    private static function userNames(array $ids): array
    {
        $ids = \array_values(\array_unique(\array_filter($ids)));
        if (!$ids) return [];
        $q = DB::pdo()->prepare("SELECT id, full_name FROM users WHERE id IN (".\implode(',', \array_fill(0, \count($ids), '?')).")");
        $q->execute($ids);
        return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /* ======================= open items (bills) ======================= */

    /**
     * Open items of one supplier, oldest first: the opening balance line
     * (doc_id 'OPENING') and every posted purchase with something left.
     * Each: doc_id, ref (our no), bill_no, bill_date, due_date, total,
     * returns, paid, balance, on_slip (open slip no or '').
     */
    public static function openItems(string $sid, bool $withPaid = false): array
    {
        self::ensureSchema();
        $tid = tenant_id(); $p = DB::pdo();
        $s = self::supplier($sid);
        $days = (int)$s['credit_days'];
        $out = [];
        $o = $p->prepare("SELECT COALESCE(SUM(credit),0), MIN(entry_at) FROM rtl_supplier_ledger
                           WHERE tenant_id=? AND supplier_id=? AND entry_type='OPENING' AND deleted_at IS NULL");
        $o->execute([$tid, $sid]);
        [$open, $openAt] = $o->fetch(PDO::FETCH_NUM);
        $bills = $p->prepare("SELECT d.id doc_id, d.doc_no ref, d.ref_no bill_no, COALESCE(d.bill_date, d.doc_date) bill_date,
                                     (SELECT COALESCE(SUM(l.credit),0) FROM rtl_supplier_ledger l WHERE l.tenant_id=d.tenant_id
                                        AND l.ref_id=d.id AND l.entry_type='GRN' AND l.deleted_at IS NULL) total,
                                     (SELECT COALESCE(SUM(r.total),0) FROM rtl_docs r WHERE r.tenant_id=d.tenant_id AND r.source_doc_id=d.id
                                        AND r.doc_type='PRET' AND r.status='POSTED' AND r.deleted_at IS NULL) returns
                                FROM rtl_docs d WHERE d.tenant_id=? AND d.supplier_id=? AND d.doc_type='GRN' AND d.status='POSTED'
                                 AND d.deleted_at IS NULL ORDER BY COALESCE(d.bill_date, d.doc_date), d.posted_at, d.doc_no");
        $bills->execute([$tid, $sid]);
        $rows = $bills->fetchAll(PDO::FETCH_ASSOC);
        if ((float)$open > 0) \array_unshift($rows, ['doc_id' => 'OPENING', 'ref' => 'OPENING', 'bill_no' => '',
            'bill_date' => \substr((string)$openAt, 0, 10), 'total' => $open, 'returns' => 0]);
        $paid = self::paidByDoc($sid);
        $slip = self::openSlipByDoc($sid);
        foreach ($rows as $r) {
            $total = self::money($r['total']); $ret = self::money($r['returns']);
            $pd = self::money($paid[$r['doc_id']] ?? 0);
            $bal = self::money($total - $ret - $pd);
            if ($bal <= 0.004 && !$withPaid) continue;
            $due = \date('Y-m-d', \strtotime($r['bill_date'].' +'.$days.' days'));
            $out[] = ['doc_id' => $r['doc_id'], 'ref' => $r['ref'], 'bill_no' => (string)$r['bill_no'], 'bill_date' => $r['bill_date'],
                      'due_date' => $due, 'total' => $total, 'returns' => $ret, 'paid' => $pd, 'balance' => \max(0, $bal),
                      'on_slip' => $slip[$r['doc_id']] ?? ''];
        }
        return $out;
    }

    private static function paidByDoc(string $sid): array
    {
        $q = DB::pdo()->prepare("SELECT a.doc_id, SUM(a.amount) FROM rtl_supplier_pay_alloc a
                                   JOIN rtl_supplier_payments sp ON sp.id = a.payment_id
                                  WHERE a.tenant_id=? AND a.supplier_id=? AND a.deleted_at IS NULL AND sp.status='DONE'
                                    AND (a.applied_by_payment_id IS NULL OR EXISTS (SELECT 1 FROM rtl_supplier_payments x
                                          WHERE x.id=a.applied_by_payment_id AND x.status='DONE'))
                                  GROUP BY a.doc_id");
        $q->execute([tenant_id(), $sid]);
        return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private static function openSlipByDoc(string $sid): array
    {
        $q = DB::pdo()->prepare("SELECT b.doc_id, s.slip_no FROM rtl_pay_slip_bills b JOIN rtl_pay_slips s ON s.id=b.slip_id
                                  WHERE s.tenant_id=? AND s.supplier_id=? AND s.status IN ('OPEN','PART') AND s.deleted_at IS NULL");
        $q->execute([tenant_id(), $sid]);
        return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Payment money not yet used on any bill (advance), per payment, oldest first. */
    private static function advances(string $sid): array
    {
        $q = DB::pdo()->prepare("SELECT sp.id, sp.voucher_no, sp.amount -
                                        COALESCE((SELECT SUM(a.amount) FROM rtl_supplier_pay_alloc a WHERE a.payment_id=sp.id
                                          AND a.deleted_at IS NULL AND (a.applied_by_payment_id IS NULL OR EXISTS (SELECT 1
                                          FROM rtl_supplier_payments x WHERE x.id=a.applied_by_payment_id AND x.status='DONE'))),0) left_amt
                                   FROM rtl_supplier_payments sp WHERE sp.tenant_id=? AND sp.supplier_id=? AND sp.status='DONE'
                                  ORDER BY sp.pay_date, sp.created_at");
        $q->execute([tenant_id(), $sid]);
        return \array_values(\array_filter($q->fetchAll(PDO::FETCH_ASSOC), fn($r) => (float)$r['left_amt'] > 0.004));
    }

    public static function supplierInfo(string $sid): array
    {
        self::ensureSchema();
        $s = self::supplier($sid);
        $adv = \array_sum(\array_map(fn($r) => (float)$r['left_amt'], self::advances($sid)));
        return ['supplier' => $s, 'balance' => self::balance($sid), 'advance' => self::money($adv), 'items' => self::openItems($sid)];
    }

    /* ======================= slips ======================= */

    public static function createSlip(array $d): array
    {
        self::ensureSchema();
        $sid = (string)($d['supplier_id'] ?? '');
        $type = \strtoupper((string)($d['slip_type'] ?? 'NOW')) === 'DATE' ? 'DATE' : 'NOW';
        $payOn = $type === 'NOW' ? \date('Y-m-d') : self::date($d['pay_on'] ?? '');
        if (!$payOn) throw new \RuntimeException('Select the "pay on" date');
        if ($type === 'DATE' && $payOn < \date('Y-m-d')) throw new \RuntimeException('"Pay on" date cannot be in the past');
        $want = [];
        foreach ((array)($d['bills'] ?? []) as $b) {
            $amt = self::money($b['amount'] ?? 0);
            if ($amt > 0) $want[(string)($b['doc_id'] ?? '')] = $amt;
        }
        if (!$want) throw new \RuntimeException('Select at least one bill');
        return DB::tx(function (PDO $p) use ($sid, $type, $payOn, $want, $d) {
            /* lock the supplier row: two slips for the same bill at the same moment cannot both pass */
            $p->prepare("SELECT id FROM suppliers WHERE id=? AND tenant_id=? FOR UPDATE")->execute([$sid, tenant_id()]);
            $items = []; foreach (self::openItems($sid) as $it) $items[$it['doc_id']] = $it;
            $total = 0.0;
            foreach ($want as $doc => $amt) {
                $it = $items[$doc] ?? null;
                if (!$it) throw new \RuntimeException('A selected bill is already paid or not found');
                if ($it['on_slip'] !== '') throw new \RuntimeException(($doc === 'OPENING' ? 'Opening balance' : $it['ref']).' is already on slip '.$it['on_slip']);
                if ($amt > $it['balance'] + 0.004) throw new \RuntimeException('Amount for '.$it['ref'].' is more than its balance '.\number_format($it['balance'], 2));
                $total += $amt;
            }
            $id = \uuid();
            for ($i = 0; ; $i++) {
                $no = self::nextNo($p, 'rtl_pay_slips', 'slip_no', 'PS');
                try {
                    $p->prepare("INSERT INTO rtl_pay_slips(id,tenant_id,site_id,slip_no,supplier_id,slip_type,pay_on,amount,status,note,created_by)
                                 VALUES(?,?,?,?,?,?,?,?,'OPEN',?,?)")
                      ->execute([$id, tenant_id(), site_id(), $no, $sid, $type, $payOn, self::money($total),
                                 \mb_substr(\trim((string)($d['note'] ?? '')), 0, 255), self::uid()]);
                    break;
                } catch (\PDOException $e) { if (($e->errorInfo[1] ?? 0) != 1062 || $i > 5) throw $e; }
            }
            foreach ($want as $doc => $amt)
                $p->prepare("INSERT INTO rtl_pay_slip_bills(id,tenant_id,slip_id,doc_id,amount) VALUES(?,?,?,?,?)")
                  ->execute([\uuid(), tenant_id(), $id, $doc, $amt]);
            try { Audit::log('CREATE', 'retail_pay_slip', ['id' => $id, 'label' => $no]); } catch (\Throwable $e) {}
            return self::slip($id);
        });
    }

    public static function slip(string $idOrNo): ?array
    {
        self::ensureSchema();
        $q = DB::pdo()->prepare("SELECT s.*, su.name supplier_name, su.phone supplier_phone FROM rtl_pay_slips s
                                   JOIN suppliers su ON su.id=s.supplier_id
                                  WHERE s.tenant_id=? AND (s.id=? OR s.slip_no=?) AND s.deleted_at IS NULL LIMIT 1");
        $q->execute([tenant_id(), $idOrNo, \strtoupper(\trim($idOrNo))]);
        $s = $q->fetch(PDO::FETCH_ASSOC);
        if (!$s) return null;
        $b = DB::pdo()->prepare("SELECT b.doc_id, b.amount, d.doc_no ref, d.ref_no bill_no, COALESCE(d.bill_date,d.doc_date) bill_date
                                   FROM rtl_pay_slip_bills b LEFT JOIN rtl_docs d ON d.id=b.doc_id
                                  WHERE b.slip_id=? AND b.deleted_at IS NULL ORDER BY bill_date, ref");
        $b->execute([$s['id']]);
        $s['bills'] = \array_map(function ($r) {
            $r['amount'] = (float)$r['amount'];
            if ($r['doc_id'] === 'OPENING') { $r['ref'] = 'OPENING'; $r['bill_no'] = ''; }
            return $r;
        }, $b->fetchAll(PDO::FETCH_ASSOC));
        $pq = DB::pdo()->prepare("SELECT COALESCE(SUM(amount),0) FROM rtl_supplier_payments WHERE slip_id=? AND status='DONE'");
        $pq->execute([$s['id']]);
        $s['amount'] = (float)$s['amount'];
        $s['paid'] = self::money($pq->fetchColumn());
        $s['left'] = $s['status'] === 'OPEN' || $s['status'] === 'PART' ? self::money($s['amount'] - $s['paid']) : 0.0;
        $n = self::userNames([$s['created_by'], $s['cancelled_by']]);
        $s['created_by_name'] = $n[$s['created_by']] ?? ''; $s['cancelled_by_name'] = $n[$s['cancelled_by'] ?? ''] ?? '';
        $v = DB::pdo()->prepare("SELECT voucher_no FROM rtl_supplier_payments WHERE slip_id=? AND status='DONE' ORDER BY created_at");
        $v->execute([$s['id']]);
        $s['vouchers'] = $v->fetchAll(PDO::FETCH_COLUMN);
        return $s;
    }

    private static function refreshSlip(PDO $p, string $slipId): void
    {
        $s = $p->prepare("SELECT amount, status FROM rtl_pay_slips WHERE id=?"); $s->execute([$slipId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r || \in_array($r['status'], ['CANCELLED', 'CLOSED'], true)) return;
        $q = $p->prepare("SELECT COALESCE(SUM(amount),0) FROM rtl_supplier_payments WHERE slip_id=? AND status='DONE'");
        $q->execute([$slipId]);
        $paid = (float)$q->fetchColumn();
        $st = $paid <= 0.004 ? 'OPEN' : ($paid + 0.004 >= (float)$r['amount'] ? 'PAID' : 'PART');
        $p->prepare("UPDATE rtl_pay_slips SET status=?, updated_at=NOW(6), row_version=row_version+1 WHERE id=?")->execute([$st, $slipId]);
    }

    /** Cancel an unpaid slip, or close the unpaid rest of a part-paid slip. Manager / owner. */
    public static function cancelSlip(string $id, string $reason): array
    {
        self::ensureSchema();
        if (!self::isBoss()) throw new \RuntimeException('Only a manager or the owner can cancel a slip');
        $reason = \trim($reason);
        if (\mb_strlen($reason) < 3) throw new \RuntimeException('Write the reason');
        $s = self::slip($id);
        if (!$s) throw new \RuntimeException('Slip not found');
        if (!\in_array($s['status'], ['OPEN', 'PART'], true)) throw new \RuntimeException('This slip is already '.\strtolower($s['status']));
        $st = $s['paid'] > 0 ? 'CLOSED' : 'CANCELLED';
        DB::pdo()->prepare("UPDATE rtl_pay_slips SET status=?, cancelled_by=?, cancelled_at=NOW(6), cancel_reason=?, updated_at=NOW(6),
                             row_version=row_version+1 WHERE id=? AND tenant_id=?")
          ->execute([$st, self::uid(), \mb_substr($reason, 0, 255), $s['id'], tenant_id()]);
        try { Audit::log('UPDATE', 'retail_pay_slip', ['id' => $s['id'], 'label' => $s['slip_no'].' '.$st]); } catch (\Throwable $e) {}
        return self::slip($s['id']);
    }

    public static function slips(array $f): array
    {
        self::ensureSchema();
        $w = "s.tenant_id=? AND s.deleted_at IS NULL"; $a = [tenant_id()];
        $today = \date('Y-m-d');
        if (!empty($f['supplier_id'])) { $w .= " AND s.supplier_id=?"; $a[] = (string)$f['supplier_id']; }
        if (!empty($f['q'])) { $w .= " AND s.slip_no LIKE ?"; $a[] = '%'.\addcslashes(\strtoupper(\trim((string)$f['q'])), '%_\\').'%'; }
        $st = (string)($f['status'] ?? '');
        if ($st === 'OPEN')    $w .= " AND s.status IN ('OPEN','PART')";
        if ($st === 'DUE')     { $w .= " AND s.status IN ('OPEN','PART') AND s.pay_on<=?"; $a[] = $today; }
        if ($st === 'OVERDUE') { $w .= " AND s.status IN ('OPEN','PART') AND s.pay_on<?"; $a[] = $today; }
        if ($st === 'PAID')    $w .= " AND s.status='PAID'";
        if ($st === 'CANCELLED') $w .= " AND s.status IN ('CANCELLED','CLOSED')";
        if ($d = self::date($f['from'] ?? '')) { $w .= " AND DATE(s.created_at)>=?"; $a[] = $d; }
        if ($d = self::date($f['to'] ?? ''))   { $w .= " AND DATE(s.created_at)<=?"; $a[] = $d; }
        $q = DB::pdo()->prepare("SELECT s.id, s.slip_no, s.slip_type, s.pay_on, s.amount, s.status, DATE(s.created_at) issued, su.name supplier_name,
                                        (SELECT COALESCE(SUM(p.amount),0) FROM rtl_supplier_payments p WHERE p.slip_id=s.id AND p.status='DONE') paid,
                                        (SELECT GROUP_CONCAT(p.voucher_no ORDER BY p.created_at) FROM rtl_supplier_payments p WHERE p.slip_id=s.id AND p.status='DONE') vouchers
                                   FROM rtl_pay_slips s JOIN suppliers su ON su.id=s.supplier_id WHERE $w
                                  ORDER BY s.created_at DESC LIMIT 500");
        $q->execute($a);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['amount'] = (float)$r['amount']; $r['paid'] = (float)$r['paid']; $r['left'] = \in_array($r['status'], ['OPEN','PART'], true) ? self::money($r['amount'] - $r['paid']) : 0; }
        unset($r);
        $k = DB::pdo()->prepare("SELECT
              COALESCE(SUM(CASE WHEN s.pay_on<=? THEN s.amount-(SELECT COALESCE(SUM(p.amount),0) FROM rtl_supplier_payments p WHERE p.slip_id=s.id AND p.status='DONE') END),0),
              COALESCE(SUM(CASE WHEN s.pay_on>? AND s.pay_on<=? THEN s.amount-(SELECT COALESCE(SUM(p.amount),0) FROM rtl_supplier_payments p WHERE p.slip_id=s.id AND p.status='DONE') END),0),
              COUNT(*)
            FROM rtl_pay_slips s WHERE s.tenant_id=? AND s.status IN ('OPEN','PART') AND s.deleted_at IS NULL");
        $k->execute([$today, $today, \date('Y-m-d', \strtotime('+7 days')), tenant_id()]);
        [$due, $week, $open] = $k->fetch(PDO::FETCH_NUM);
        $m = DB::pdo()->prepare("SELECT COALESCE(SUM(amount),0) FROM rtl_supplier_payments WHERE tenant_id=? AND status='DONE' AND pay_date>=?");
        $m->execute([tenant_id(), \date('Y-m-01')]);
        return ['rows' => $rows, 'kpi' => ['due' => self::money($due), 'week' => self::money($week), 'open' => (int)$open, 'paid_month' => self::money($m->fetchColumn())], 'today' => $today];
    }

    /* ======================= payments ======================= */

    /**
     * $d: supplier_id, slip_id (optional), pay_date, mode, bank_name, cheque_no,
     * cheque_date, txn_ref, our_account, amount, note, early_ok (manager),
     * alloc: [{doc_id, amount}] — optional; without it the amount goes to the
     * slip's bills (or all open items) oldest first. Older advances are used
     * on the chosen bills first.
     */
    public static function pay(array $d): array
    {
        self::ensureSchema();
        $sid = (string)($d['supplier_id'] ?? '');
        $amount = self::money($d['amount'] ?? 0);
        $mode = \strtoupper(\trim((string)($d['mode'] ?? '')));
        $date = self::date($d['pay_date'] ?? '') ?: \date('Y-m-d');
        if (!\in_array($mode, self::MODES, true)) throw new \RuntimeException('Select the payment mode');
        if ($amount <= 0) throw new \RuntimeException('Enter the amount');
        if ($date > \date('Y-m-d')) throw new \RuntimeException('Payment date cannot be in the future');
        $bank = \trim((string)($d['bank_name'] ?? '')); $chq = \trim((string)($d['cheque_no'] ?? ''));
        $chqDate = self::date($d['cheque_date'] ?? ''); $txn = \trim((string)($d['txn_ref'] ?? ''));
        if ($mode === 'CHEQUE' && ($bank === '' || $chq === '' || !$chqDate)) throw new \RuntimeException('Enter bank, cheque no. and cheque date');
        if ($mode === 'BANK' && $bank === '') throw new \RuntimeException('Enter the bank');
        if (\in_array($mode, ['JAZZCASH', 'EASYPAISA'], true) && $txn === '') throw new \RuntimeException('Enter the transaction ID');
        if ($mode === 'BANK' && $txn === '' && $chq === '') $txn = '';
        $slipId = \trim((string)($d['slip_id'] ?? ''));
        $earlyBy = null;

        return DB::tx(function (PDO $p) use ($d, $sid, $amount, $mode, $date, $bank, $chq, $chqDate, $txn, $slipId, &$earlyBy) {
            $p->prepare("SELECT id FROM suppliers WHERE id=? AND tenant_id=? FOR UPDATE")->execute([$sid, tenant_id()]);
            $sup = self::supplier($sid);
            $items = []; foreach (self::openItems($sid) as $it) $items[$it['doc_id']] = $it;
            $slip = null;
            if ($slipId !== '') {
                $slip = self::slip($slipId);
                if (!$slip || $slip['supplier_id'] !== $sid) throw new \RuntimeException('Slip not found for this supplier');
                if (!\in_array($slip['status'], ['OPEN', 'PART'], true)) throw new \RuntimeException('Slip '.$slip['slip_no'].' is '.\strtolower($slip['status']).' — it cannot be paid');
                if ($amount > $slip['left'] + 0.004) throw new \RuntimeException('Amount is more than the slip balance '.\number_format($slip['left'], 2));
                if ($slip['slip_type'] === 'DATE' && $slip['pay_on'] > \date('Y-m-d')) {
                    if (!self::isBoss()) throw new \RuntimeException('Slip '.$slip['slip_no'].' is to be paid on '.$slip['pay_on'].'. Paying early needs a manager or the owner');
                    if (empty($d['early_ok'])) throw new \RuntimeException('Slip '.$slip['slip_no'].' is to be paid on '.$slip['pay_on'].'. Tick "Pay early" to confirm');
                    $earlyBy = self::uid();
                }
            }
            $type = $mode === 'CASH' ? 'CPV' : 'BPV';
            $pid = \uuid();
            for ($i = 0; ; $i++) {
                $no = self::nextNo($p, 'rtl_supplier_payments', 'voucher_no', $type);
                try {
                    $p->prepare("INSERT INTO rtl_supplier_payments(id,tenant_id,site_id,voucher_no,voucher_type,supplier_id,slip_id,pay_date,mode,
                                     bank_name,cheque_no,cheque_date,txn_ref,our_account,amount,note,status,early_ok_by,created_by)
                                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'DONE',?,?)")
                      ->execute([$pid, tenant_id(), site_id(), $no, $type, $sid, $slip['id'] ?? null, $date, $mode,
                                 $bank ?: null, $chq ?: null, $chqDate, $txn ?: null,
                                 \mb_substr(\trim((string)($d['our_account'] ?? '')), 0, 80) ?: null, $amount,
                                 \mb_substr(\trim((string)($d['note'] ?? '')), 0, 255) ?: null, $earlyBy, self::uid()]);
                    break;
                } catch (\PDOException $e) { if (($e->errorInfo[1] ?? 0) != 1062 || $i > 5) throw $e; }
            }
            $alloc = function (string $payId, ?string $by, string $doc, float $am) use ($p, $sid, &$items) {
                $p->prepare("INSERT INTO rtl_supplier_pay_alloc(id,tenant_id,payment_id,applied_by_payment_id,supplier_id,doc_id,amount) VALUES(?,?,?,?,?,?,?)")
                  ->execute([\uuid(), tenant_id(), $payId, $by, $sid, $doc, self::money($am)]);
                $items[$doc]['balance'] = self::money($items[$doc]['balance'] - $am);
            };
            /* bills this payment may touch: the slip's bills, or every bill not on a slip */
            $pool = $slip ? \array_column($slip['bills'], 'amount', 'doc_id') : null;
            $eligible = [];
            foreach ($items as $doc => $it) {
                if ($pool !== null) { if (isset($pool[$doc])) $eligible[$doc] = \min($it['balance'], (float)$pool[$doc]); }
                elseif ($it['on_slip'] === '') $eligible[$doc] = $it['balance'];
            }
            $manual = [];
            if (!empty($d['alloc'])) {
                foreach ((array)$d['alloc'] as $a) {
                    $doc = (string)($a['doc_id'] ?? ''); $am = self::money($a['amount'] ?? 0);
                    if ($am <= 0) continue;
                    $it = $items[$doc] ?? null;
                    if (!$it) throw new \RuntimeException('A selected bill is already paid or not found');
                    if ($it['on_slip'] !== '' && (!$slip || $it['on_slip'] !== $slip['slip_no'])) throw new \RuntimeException($it['ref'].' is on slip '.$it['on_slip'].' — pay it through that slip');
                    if (!isset($eligible[$doc])) throw new \RuntimeException($it['ref'].' is not on slip '.($slip['slip_no'] ?? ''));
                    if ($am > $it['balance'] + 0.004) throw new \RuntimeException('Amount for '.$it['ref'].' is more than its balance');
                    $manual[$doc] = self::money(($manual[$doc] ?? 0) + $am);
                }
                if (\array_sum($manual) > $amount + 0.004) throw new \RuntimeException('Bill amounts are more than the payment amount');
            }
            /* 1) older advance money goes on the bills first (manual: only on the room left on the chosen bills) */
            foreach (self::advances($sid) as $adv) {
                if ($adv['id'] === $pid) continue;
                $avail = (float)$adv['left_amt'];
                foreach (($manual ?: $eligible) as $doc => $x) {
                    if ($avail <= 0.004) break;
                    $room = self::money(\min($eligible[$doc], $items[$doc]['balance']) - ($manual[$doc] ?? 0));
                    if ($room <= 0.004) continue;
                    $take = \min($room, $avail);
                    $alloc($adv['id'], $pid, $doc, $take);
                    $eligible[$doc] = self::money($eligible[$doc] - $take);
                    $avail -= $take;
                }
            }
            /* 2) this payment */
            if ($manual) {
                foreach ($manual as $doc => $am) { $am = \min($am, \max(0, $items[$doc]['balance'])); if ($am > 0.004) $alloc($pid, null, $doc, $am); }
            } else {
                $left = $amount;
                foreach ($eligible as $doc => $cap) {
                    if ($left <= 0.004) break;
                    $take = \min($cap, $items[$doc]['balance'], $left);
                    if ($take > 0.004) { $alloc($pid, null, $doc, $take); $left = self::money($left - $take); }
                }
            }
            $detail = self::modeText(['mode' => $mode, 'bank_name' => $bank, 'cheque_no' => $chq, 'txn_ref' => $txn])
                    .($slip ? ' · slip '.$slip['slip_no'] : '');
            self::ledger($p, $sid, $type, 0.0, $amount, $pid, $no, $detail);
            if ($slip) self::refreshSlip($p, $slip['id']);
            try { Audit::log('CREATE', 'retail_supplier_payment', ['id' => $pid, 'label' => $no.' '.$sup['name'].' '.$amount]); } catch (\Throwable $e) {}
            return ['payment' => self::payment($pid), 'balance' => self::balance($sid)];
        });
    }

    public static function modeText(array $r): string
    {
        $m = (string)$r['mode'];
        $lab = ['CASH' => 'Cash', 'CHEQUE' => 'Cheque', 'BANK' => 'Bank', 'JAZZCASH' => 'JazzCash', 'EASYPAISA' => 'Easypaisa'][$m] ?? $m;
        $bits = [$lab];
        if (!empty($r['cheque_no'])) $bits[] = 'No. '.$r['cheque_no'];
        if (!empty($r['bank_name'])) $bits[] = $r['bank_name'];
        if (!empty($r['txn_ref']))   $bits[] = 'TID '.$r['txn_ref'];
        return \implode(' · ', $bits);
    }

    public static function payment(string $idOrNo): ?array
    {
        self::ensureSchema();
        $q = DB::pdo()->prepare("SELECT sp.*, su.name supplier_name, su.phone supplier_phone, ps.slip_no, ps.slip_type, ps.pay_on slip_pay_on,
                                        DATE(ps.created_at) slip_issued
                                   FROM rtl_supplier_payments sp JOIN suppliers su ON su.id=sp.supplier_id
                              LEFT JOIN rtl_pay_slips ps ON ps.id=sp.slip_id
                                  WHERE sp.tenant_id=? AND (sp.id=? OR sp.voucher_no=?) LIMIT 1");
        $q->execute([tenant_id(), $idOrNo, \strtoupper(\trim($idOrNo))]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['amount'] = (float)$r['amount'];
        $a = DB::pdo()->prepare("SELECT a.doc_id, a.amount, a.applied_by_payment_id, d.doc_no ref, d.ref_no bill_no, COALESCE(d.bill_date,d.doc_date) bill_date
                                   FROM rtl_supplier_pay_alloc a LEFT JOIN rtl_docs d ON d.id=a.doc_id
                                  WHERE a.deleted_at IS NULL AND ((a.payment_id=? AND a.applied_by_payment_id IS NULL) OR a.applied_by_payment_id=?)
                                  ORDER BY bill_date");
        $a->execute([$r['id'], $r['id']]);
        $r['alloc'] = \array_map(function ($x) use ($r) {
            $x['amount'] = (float)$x['amount'];
            $x['from_advance'] = !empty($x['applied_by_payment_id']);
            if ($x['doc_id'] === 'OPENING') { $x['ref'] = 'OPENING'; $x['bill_no'] = ''; }
            return $x;
        }, $a->fetchAll(PDO::FETCH_ASSOC));
        $own = \array_sum(\array_map(fn($x) => $x['from_advance'] ? 0 : $x['amount'], $r['alloc']));
        $r['advance_left'] = self::money($r['amount'] - $own);
        $r['mode_text'] = self::modeText($r);
        $n = self::userNames([$r['created_by'], $r['cancelled_by'], $r['early_ok_by']]);
        $r['created_by_name'] = $n[$r['created_by']] ?? ''; $r['cancelled_by_name'] = $n[$r['cancelled_by'] ?? ''] ?? '';
        $r['early_ok_name'] = $n[$r['early_ok_by'] ?? ''] ?? '';
        $b = DB::pdo()->prepare("SELECT balance_after FROM rtl_supplier_ledger WHERE tenant_id=? AND ref_id=? AND entry_type IN ('CPV','BPV') LIMIT 1");
        $b->execute([tenant_id(), $r['id']]);
        $after = (float)$b->fetchColumn();
        $r['balance_after'] = $after; $r['balance_before'] = self::money($after + $r['amount']);
        return $r;
    }

    public static function cancelPayment(string $id, string $reason): array
    {
        self::ensureSchema();
        if (!self::isBoss()) throw new \RuntimeException('Only a manager or the owner can cancel a payment');
        $reason = \trim($reason);
        if (\mb_strlen($reason) < 3) throw new \RuntimeException('Write the reason');
        return DB::tx(function (PDO $p) use ($id, $reason) {
            $r = self::payment($id);
            if (!$r) throw new \RuntimeException('Payment not found');
            if ($r['status'] !== 'DONE') throw new \RuntimeException('This payment is already cancelled');
            $p->prepare("SELECT id FROM suppliers WHERE id=? AND tenant_id=? FOR UPDATE")->execute([$r['supplier_id'], tenant_id()]);
            $p->prepare("UPDATE rtl_supplier_payments SET status='CANCELLED', cancelled_by=?, cancelled_at=NOW(6), cancel_reason=?,
                             updated_at=NOW(6), row_version=row_version+1 WHERE id=?")
              ->execute([self::uid(), \mb_substr($reason, 0, 255), $r['id']]);
            /* money this payment had put on bills from older advances goes back to those advances */
            $p->prepare("UPDATE rtl_supplier_pay_alloc SET deleted_at=NOW(6), updated_at=NOW(6) WHERE applied_by_payment_id=? AND deleted_at IS NULL")
              ->execute([$r['id']]);
            self::ledger($p, $r['supplier_id'], $r['voucher_type'].'_CANCEL', $r['amount'], 0.0, $r['id'], $r['voucher_no'], 'Cancelled: '.$reason);
            if (!empty($r['slip_id'])) self::refreshSlip($p, $r['slip_id']);
            try { Audit::log('UPDATE', 'retail_supplier_payment', ['id' => $r['id'], 'label' => $r['voucher_no'].' cancelled: '.$reason]); } catch (\Throwable $e) {}
            return self::payment($r['id']);
        });
    }

    public static function payments(array $f): array
    {
        self::ensureSchema();
        $w = "sp.tenant_id=?"; $a = [tenant_id()];
        if (!empty($f['supplier_id'])) { $w .= " AND sp.supplier_id=?"; $a[] = (string)$f['supplier_id']; }
        if ($d = self::date($f['from'] ?? '')) { $w .= " AND sp.pay_date>=?"; $a[] = $d; }
        if ($d = self::date($f['to'] ?? ''))   { $w .= " AND sp.pay_date<=?"; $a[] = $d; }
        $q = DB::pdo()->prepare("SELECT sp.id, sp.voucher_no, sp.voucher_type, sp.pay_date, sp.mode, sp.bank_name, sp.cheque_no, sp.txn_ref,
                                        sp.amount, sp.status, su.name supplier_name, u.full_name by_name, ps.slip_no
                                   FROM rtl_supplier_payments sp JOIN suppliers su ON su.id=sp.supplier_id
                              LEFT JOIN users u ON u.id=sp.created_by LEFT JOIN rtl_pay_slips ps ON ps.id=sp.slip_id
                                  WHERE $w ORDER BY sp.created_at DESC LIMIT ".\max(1, \min(300, (int)($f['limit'] ?? 50))));
        $q->execute($a);
        return \array_map(function ($r) { $r['amount'] = (float)$r['amount']; $r['mode_text'] = self::modeText($r); return $r; }, $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /* ======================= ledger / balances ======================= */

    public static function ledgerView(string $sid, string $from, string $to, string $type = ''): array
    {
        self::ensureSchema();
        $s = self::supplier($sid);
        $from = self::date($from) ?: \date('Y-m-01'); $to = self::date($to) ?: \date('Y-m-d');
        $p = DB::pdo();
        /* A statement goes by the BILL date (purchase / return) and the
           PAYMENT date chosen by Accounts, not by the moment of posting. */
        $base = "FROM rtl_supplier_ledger l
                 LEFT JOIN rtl_docs d ON d.id=l.ref_id AND l.entry_type IN ('GRN','PURCH_RET')
                 LEFT JOIN rtl_docs sd ON sd.id=d.source_doc_id
                 LEFT JOIN rtl_supplier_payments sp ON sp.id=l.ref_id AND l.entry_type IN ('CPV','BPV')
                 WHERE l.tenant_id=? AND l.supplier_id=? AND l.deleted_at IS NULL";
        $eff = "COALESCE(CASE WHEN l.entry_type IN ('GRN','PURCH_RET') THEN COALESCE(d.bill_date,d.doc_date)
                              WHEN l.entry_type IN ('CPV','BPV') THEN sp.pay_date END, DATE(l.entry_at))";
        $o = $p->prepare("SELECT COALESCE(SUM(l.credit),0)-COALESCE(SUM(l.debit),0) $base AND $eff < ?");
        $o->execute([tenant_id(), $sid, $from]);
        $opening = self::money($o->fetchColumn());
        $q = $p->prepare("SELECT l.id, l.entry_type, l.ref_table, l.ref_id, l.ref_no, l.debit, l.credit, l.note, $eff eff_date,
                                 d.ref_no bill_no, d.source_doc_id, sd.ref_no src_bill_no
                           $base AND $eff BETWEEN ? AND ?
                           ORDER BY eff_date, l.entry_at, l.created_at, l.id");
        $q->execute([tenant_id(), $sid, $from, $to]);
        $bal = $opening; $rows = []; $dr = 0.0; $cr = 0.0;
        $lab = ['OPENING' => 'Opening balance', 'GRN' => 'Purchase', 'PURCH_RET' => 'Purchase return', 'PAYMENT' => 'Payment',
                'CPV' => 'Payment (CPV)', 'BPV' => 'Payment (BPV)', 'CPV_CANCEL' => 'CPV cancelled', 'BPV_CANCEL' => 'BPV cancelled'];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bal = self::money($bal + (float)$r['credit'] - (float)$r['debit']);
            $dr += (float)$r['debit']; $cr += (float)$r['credit'];
            $kind = \in_array($r['entry_type'], ['GRN', 'OPENING'], true) ? 'purchase' : (\in_array($r['entry_type'], ['PURCH_RET'], true) ? 'return' : 'payment');
            if ($type !== '' && $type !== $kind) continue;
            $rows[] = ['date' => (string)$r['eff_date'], 'entry' => $lab[$r['entry_type']] ?? $r['entry_type'], 'type' => $r['entry_type'],
                       'ref_no' => $r['entry_type'] === 'OPENING' ? '' : (string)$r['ref_no'],
                       'bill_no' => (string)($r['bill_no'] ?: $r['src_bill_no']), 'detail' => (string)($r['entry_type'] === 'OPENING' || $r['entry_type'] === 'GRN' || $r['entry_type'] === 'PURCH_RET' ? '' : $r['note']),
                       'ref_id' => $r['ref_id'], 'debit' => (float)$r['debit'], 'credit' => (float)$r['credit'], 'balance' => $bal];
        }
        return ['supplier' => $s, 'from' => $from, 'to' => $to, 'opening' => $opening, 'debit' => self::money($dr), 'credit' => self::money($cr),
                'closing' => self::money($opening + $cr - $dr), 'rows' => $rows];
    }

    /** Every supplier with balance and ageing (by bill date) — one pass, no N+1 over ledger. */
    public static function balances(array $f = []): array
    {
        self::ensureSchema();
        $tid = tenant_id(); $p = DB::pdo(); $today = \date('Y-m-d');
        $s = $p->prepare("SELECT s.id, s.name, s.phone, s.city, s.credit_days, s.status,
                                 COALESCE(l.bal,0) balance FROM suppliers s
                       LEFT JOIN (SELECT supplier_id, SUM(credit)-SUM(debit) bal FROM rtl_supplier_ledger
                                   WHERE tenant_id=? AND deleted_at IS NULL GROUP BY supplier_id) l ON l.supplier_id=s.id
                           WHERE s.tenant_id=? AND s.deleted_at IS NULL ORDER BY s.name");
        $s->execute([$tid, $tid]);
        $sup = $s->fetchAll(PDO::FETCH_ASSOC);
        $out = []; $T = ['balance' => 0, 'b0' => 0, 'b1' => 0, 'b2' => 0, 'b3' => 0, 'over' => 0, 'bills' => 0, 'week' => 0];
        $q = \trim((string)($f['q'] ?? ''));
        foreach ($sup as $r) {
            if ($q !== '' && \mb_stripos($r['name'].' '.$r['phone'], $q) === false) continue;
            $bal = self::money($r['balance']);
            $row = ['id' => $r['id'], 'name' => $r['name'], 'phone' => (string)$r['phone'], 'city' => (string)$r['city'],
                    'credit_days' => (int)$r['credit_days'], 'balance' => $bal, 'bills' => 0, 'b0' => 0, 'b1' => 0, 'b2' => 0, 'b3' => 0, 'over' => 0, 'week' => 0];
            if (\abs($bal) > 0.004) {
                foreach (self::openItems($r['id']) as $it) {
                    $age = (int)((\strtotime($today) - \strtotime($it['bill_date'])) / 86400);
                    $k = $age <= 30 ? 'b0' : ($age <= 60 ? 'b1' : ($age <= 90 ? 'b2' : 'b3'));
                    $row[$k] += $it['balance']; $row['bills']++;
                    if ($it['due_date'] < $today) $row['over'] += $it['balance'];
                    elseif ($it['due_date'] <= \date('Y-m-d', \strtotime('+7 days'))) $row['week'] += $it['balance'];
                }
            }
            $show = (string)($f['show'] ?? '');
            if ($show === 'balance' && \abs($bal) <= 0.004) continue;
            if ($show === 'overdue' && $row['over'] <= 0.004) continue;
            foreach (['b0','b1','b2','b3','over','week'] as $k) $row[$k] = self::money($row[$k]);
            foreach ($T as $k => $v) $T[$k] += $row[$k];
            $out[] = $row;
        }
        foreach ($T as $k => $v) $T[$k] = self::money($v);
        $m = $p->prepare("SELECT COALESCE(SUM(amount),0) FROM rtl_supplier_payments WHERE tenant_id=? AND status='DONE' AND pay_date>=?");
        $m->execute([$tid, \date('Y-m-01')]);
        $T['paid_month'] = self::money($m->fetchColumn());
        return ['rows' => $out, 'totals' => $T, 'today' => $today];
    }

    /* ======================= suppliers ======================= */

    public static function suppliers(): array
    {
        self::ensureSchema();
        $b = self::balances();
        $by = []; foreach ($b['rows'] as $r) $by[$r['id']] = $r['balance'];
        $q = DB::pdo()->prepare("SELECT id,name,contact_person,phone,city,credit_days,tax_no,rtl_strn,rtl_filer,rtl_pay_info,opening_balance,status
                                   FROM suppliers WHERE tenant_id=? AND deleted_at IS NULL ORDER BY name");
        $q->execute([tenant_id()]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $o = DB::pdo()->prepare("SELECT supplier_id, DATE(MIN(entry_at)) FROM rtl_supplier_ledger WHERE tenant_id=? AND entry_type='OPENING' AND deleted_at IS NULL GROUP BY supplier_id");
        $o->execute([tenant_id()]);
        $openAt = $o->fetchAll(PDO::FETCH_KEY_PAIR);
        $n = DB::pdo()->prepare("SELECT supplier_id, COUNT(*) FROM rtl_supplier_ledger WHERE tenant_id=? AND entry_type<>'OPENING' AND deleted_at IS NULL GROUP BY supplier_id");
        $n->execute([tenant_id()]);
        $moves = $n->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as &$r) {
            $r['balance'] = $by[$r['id']] ?? 0.0; $r['credit_days'] = (int)$r['credit_days'];
            $r['opening_balance'] = (float)$r['opening_balance']; $r['opening_as_of'] = $openAt[$r['id']] ?? '';
            $r['opening_locked'] = !empty($moves[$r['id']]);
        }
        return $rows;
    }

    public static function saveSupplier(array $d): array
    {
        self::ensureSchema();
        $tid = tenant_id(); $p = DB::pdo();
        $id = \trim((string)($d['id'] ?? ''));
        $name = \mb_strtoupper(\preg_replace('/\s+/', ' ', \trim((string)($d['name'] ?? ''))));
        $phone = \trim((string)($d['phone'] ?? ''));
        if ($name === '') throw new \RuntimeException('Supplier name is required');
        if (\strlen(\preg_replace('/\D/', '', $phone)) < 7) throw new \RuntimeException('Enter a valid phone number');
        $days = \max(0, \min(365, (int)($d['credit_days'] ?? 0)));
        $open = self::money(\max(0, (float)($d['opening_balance'] ?? 0)));
        $asOf = self::date($d['opening_as_of'] ?? '') ?: \date('Y-m-d');
        if ($asOf > \date('Y-m-d')) throw new \RuntimeException('"Opening as of" cannot be a future date');
        $filer = \strtoupper((string)($d['filer'] ?? 'FILER')) === 'NON_FILER' ? 'NON_FILER' : 'FILER';
        $status = \strtoupper((string)($d['status'] ?? 'ACTIVE')) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE';
        $dq = $p->prepare("SELECT id FROM suppliers WHERE tenant_id=? AND deleted_at IS NULL AND name=? AND id<>? LIMIT 1");
        $dq->execute([$tid, $name, $id]);
        if ($dq->fetchColumn() && empty($d['same_name_ok'])) throw new \RuntimeException('SAME_NAME: A supplier with this name already exists');
        return DB::tx(function (PDO $p) use ($d, $id, $name, $phone, $days, $open, $asOf, $filer, $status, $tid) {
            $vals = [$name, \trim((string)($d['contact_person'] ?? '')), $phone, \trim((string)($d['city'] ?? '')), $days,
                     \trim((string)($d['ntn'] ?? '')), \trim((string)($d['strn'] ?? '')), $filer,
                     \mb_substr(\trim((string)($d['pay_info'] ?? '')), 0, 160) ?: null, $status];
            if ($id === '') {
                $id = \uuid();
                $p->prepare("INSERT INTO suppliers(id,tenant_id,site_id,name,contact_person,phone,city,credit_days,tax_no,rtl_strn,rtl_filer,
                                 rtl_pay_info,status,opening_balance,rtl_adv_pct,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(6))")
                  ->execute(\array_merge([$id, $tid, site_id()], $vals, [$open, $filer === 'FILER' ? 0.5 : 1.0]));
                if ($open > 0) self::ledger($p, $id, 'OPENING', $open, 0.0, null, '', 'Opening balance', $asOf.' 00:00:00');
                try { Audit::log('CREATE', 'suppliers', ['id' => $id, 'label' => $name]); } catch (\Throwable $e) {}
            } else {
                $old = self::supplier($id);
                $p->prepare("UPDATE suppliers SET name=?,contact_person=?,phone=?,city=?,credit_days=?,tax_no=?,rtl_strn=?,rtl_filer=?,
                                 rtl_pay_info=?,status=?,updated_at=NOW(6) WHERE id=? AND tenant_id=?")
                  ->execute(\array_merge($vals, [$id, $tid]));
                $n = $p->prepare("SELECT COUNT(*) FROM rtl_supplier_ledger WHERE tenant_id=? AND supplier_id=? AND entry_type<>'OPENING' AND deleted_at IS NULL");
                $n->execute([$tid, $id]);
                $o = $p->prepare("SELECT id, credit, DATE(entry_at) d FROM rtl_supplier_ledger WHERE tenant_id=? AND supplier_id=? AND entry_type='OPENING' AND deleted_at IS NULL");
                $o->execute([$tid, $id]);
                $ops = $o->fetchAll(PDO::FETCH_ASSOC);
                $curOpen = self::money(\array_sum(\array_column($ops, 'credit')));
                $curAt = $ops[0]['d'] ?? '';
                if (\abs($curOpen - $open) > 0.004 || ($open > 0 && $curAt !== $asOf)) {
                    if ((int)$n->fetchColumn()) throw new \RuntimeException('Opening balance cannot be changed after the first purchase or payment');
                    $p->prepare("UPDATE rtl_supplier_ledger SET deleted_at=NOW(6), updated_at=NOW(6) WHERE tenant_id=? AND supplier_id=? AND entry_type='OPENING' AND deleted_at IS NULL")
                      ->execute([$tid, $id]);
                    if ($open > 0) self::ledger($p, $id, 'OPENING', $open, 0.0, null, '', 'Opening balance', $asOf.' 00:00:00');
                    $p->prepare("UPDATE suppliers SET opening_balance=? WHERE id=?")->execute([$open, $id]);
                }
                try { Audit::log('UPDATE', 'suppliers', ['id' => $id, 'label' => $name]); } catch (\Throwable $e) {}
            }
            foreach (self::suppliers() as $s) if ($s['id'] === $id) return $s;
            return ['id' => $id];
        });
    }

    /* ======================= purchase register ======================= */

    public static function register(array $f): array
    {
        self::ensureSchema();
        $tid = tenant_id(); $p = DB::pdo();
        $w = "d.tenant_id=? AND d.doc_type='GRN' AND d.deleted_at IS NULL"; $a = [$tid];
        $st = (string)($f['status'] ?? 'POSTED');
        if ($st === 'POSTED') $w .= " AND d.status='POSTED'";
        elseif ($st === 'DRAFT') $w .= " AND d.status NOT IN ('POSTED','CANCELLED')";
        if (!empty($f['supplier_id'])) { $w .= " AND d.supplier_id=?"; $a[] = (string)$f['supplier_id']; }
        if (($b = \trim((string)($f['bill_no'] ?? ''))) !== '') { $w .= " AND d.ref_no LIKE ?"; $a[] = '%'.\addcslashes($b, '%_\\').'%'; }
        if (($r = \trim((string)($f['ref'] ?? ''))) !== '') { $w .= " AND d.doc_no LIKE ?"; $a[] = '%'.\addcslashes(\strtoupper($r), '%_\\').'%'; }
        if ($d = self::date($f['from'] ?? '')) { $w .= " AND COALESCE(d.bill_date,d.doc_date)>=?"; $a[] = $d; }
        if ($d = self::date($f['to'] ?? ''))   { $w .= " AND COALESCE(d.bill_date,d.doc_date)<=?"; $a[] = $d; }
        if (($it = \trim((string)($f['item'] ?? ''))) !== '') {
            $w .= " AND EXISTS (SELECT 1 FROM rtl_doc_lines dl JOIN rtl_products pr ON pr.id=dl.product_id WHERE dl.doc_id=d.id
                     AND (pr.name LIKE ? OR EXISTS (SELECT 1 FROM rtl_product_barcodes pb WHERE pb.tenant_id=pr.tenant_id AND pb.product_id=pr.id AND pb.barcode=?)))";
            $a[] = '%'.\addcslashes($it, '%_\\').'%'; $a[] = $it;
        }
        $q = $p->prepare("SELECT d.id, d.doc_no, d.ref_no bill_no, COALESCE(d.bill_date,d.doc_date) bill_date, d.supplier_id, d.supplier_name,
                                 d.line_count, d.total, d.status,
                                 (SELECT COALESCE(SUM(l.credit),0) FROM rtl_supplier_ledger l WHERE l.tenant_id=d.tenant_id AND l.ref_id=d.id AND l.entry_type='GRN' AND l.deleted_at IS NULL) bill_total
                            FROM rtl_docs d WHERE $w ORDER BY COALESCE(d.bill_date,d.doc_date) DESC, d.created_at DESC LIMIT 500");
        $q->execute($a);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $info = [];
        $today = \date('Y-m-d');
        foreach (\array_unique(\array_filter(\array_column($rows, 'supplier_id'))) as $sid) {
            try { foreach (self::openItems($sid, true) as $it) $info[$it['doc_id']] = $it; } catch (\Throwable $e) {}
        }
        $pay = (string)($f['pay'] ?? '');
        $out = []; $T = ['bills' => 0, 'items' => 0, 'total' => 0, 'paid' => 0, 'balance' => 0];
        foreach ($rows as $r) {
            $it = $info[$r['id']] ?? null;
            $total = $r['status'] === 'POSTED' ? (float)$r['bill_total'] : (float)$r['total'];
            $row = ['id' => $r['id'], 'doc_no' => $r['doc_no'], 'bill_no' => (string)$r['bill_no'], 'bill_date' => $r['bill_date'],
                    'supplier_id' => $r['supplier_id'], 'supplier_name' => (string)$r['supplier_name'], 'items' => (int)$r['line_count'],
                    'total' => self::money($total), 'returns' => $it['returns'] ?? 0, 'paid' => $it['paid'] ?? 0,
                    'balance' => $it ? $it['balance'] : ($r['status'] === 'POSTED' ? 0 : null), 'due_date' => $it['due_date'] ?? '',
                    'on_slip' => $it['on_slip'] ?? '', 'status' => $r['status']];
            $ps = '';
            if ($r['status'] === 'POSTED') {
                $ps = $row['balance'] <= 0.004 ? 'PAID' : ($row['paid'] > 0.004 ? 'PART' : 'UNPAID');
                if ($ps !== 'PAID' && $row['due_date'] !== '' && $row['due_date'] < $today) $ps = 'OVERDUE';
            }
            $row['pay_status'] = $ps;
            if ($pay !== '' && $pay !== $ps && !($pay === 'UNPAID' && $ps === 'OVERDUE')) continue;
            $out[] = $row;
            $T['bills']++; $T['items'] += $row['items']; $T['total'] += $row['total']; $T['paid'] += $row['paid']; $T['balance'] += (float)$row['balance'];
        }
        foreach (['total', 'paid', 'balance'] as $k) $T[$k] = self::money($T[$k]);
        return ['rows' => $out, 'totals' => $T];
    }

    /** Purchase for printing; second and later prints are DUPLICATE (each print logged). */
    public static function printDoc(string $id): array
    {
        self::ensureSchema();
        $doc = RetailDocs::get($id);
        if (!$doc || $doc['doc_type'] !== 'GRN') throw new \RuntimeException('Purchase not found');
        $c = DB::pdo()->prepare("SELECT COUNT(*) FROM rtl_doc_prints WHERE tenant_id=? AND doc_id=?");
        $c->execute([tenant_id(), $id]);
        $before = (int)$c->fetchColumn();
        DB::pdo()->prepare("INSERT INTO rtl_doc_prints(id,tenant_id,doc_id,kind,printed_by) VALUES(?,?,?,?,?)")
          ->execute([\uuid(), tenant_id(), $id, 'DUPLICATE', self::uid()]);
        try { Audit::log('PRINT', 'retail_purchase', ['id' => $id, 'label' => $doc['doc_no'].($before ? ' duplicate '.$before : '')]); } catch (\Throwable $e) {}
        $n = self::userNames([$doc['created_by'] ?? null, $doc['posted_by'] ?? null, $doc['approved_by'] ?? null, $doc['verified_by'] ?? null]);
        foreach (['created_by', 'posted_by', 'approved_by', 'verified_by'] as $k) $doc[$k.'_name'] = $n[$doc[$k] ?? ''] ?? '';
        $doc['print_no'] = $before + 1;
        $doc['duplicate'] = true;   /* the register always prints a copy */
        $doc['printed_by_name'] = Auth::user()['full_name'] ?? '';
        $doc['printed_at'] = \date('Y-m-d H:i');
        return $doc;
    }
}

// build: V212 build 2026-11-25
