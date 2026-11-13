<?php
namespace Aio\Services;

use Aio\Auth;
use Aio\DB;
use PDO;

/**
 * RetailShift — supermarket counter ka ASLI shift (till).
 *
 * V191 SE PEHLE: retail ki Shift screen ke "Open" aur "Close" button sirf
 * ek toast dikhate the. `cashier_shifts` mein kuch nahi likha jata tha,
 * bill kisi shift se jure hue nahi the, aur Closing screen hamesha 0
 * dikhati thi. Audit mein: Open + Close dabaya, `cashier_shifts` 0 -> 0.
 *
 * AB:
 *  - Shift `cashier_shifts` mein hi banti hai (wohi table jo pehle se
 *    sync hoti hai) — koi nayi table nahi, koi migration nahi.
 *  - Bill shift se WAQT ke zariye jurta hai: is cashier ke wo bill jo
 *    shift khulne aur band hone ke beech bane. Is liye `rtl_sales` mein
 *    koi naya column nahi chahiye aur purane offline nodes par update
 *    ke baad bhi foran chalta hai.
 *  - Cashier shift khole baghair bill banaye to shift KHUD khul jati hai
 *    (opening cash 0). Counter par bill kabhi nahi rukta.
 *
 * EXPECTED CASH =  opening
 *               +  cash bills (diya hua note MINUS wapas kiya change)
 *               +  khate ki cash wasooli
 *               -  cash refund (return jo khate par nahi gaya)
 *
 * Card ka paisa aur khata (udhaar) drawer mein nahi aate, is liye
 * expected mein nahi — alag dikhte hain.
 *
 * RESTAURANT KO IS SE KOI TAALLUQ NAHI. Yeh class sirf retail ke API
 * actions (`rshift-*`) aur `RetailPos::saveSale()` se bulai jati hai.
 */
final class RetailShift
{
    public const OPEN   = 'OPEN';
    public const CLOSED = 'CLOSED';

    private static function uid(): string
    {
        return (string)((Auth::user() ?: [])['id'] ?? '');
    }

    /** Is user ki khuli shift (is branch par). */
    public static function current(?PDO $pdo = null, ?string $userId = null): ?array
    {
        $pdo = $pdo ?: DB::pdo();
        $uid = $userId ?? self::uid();
        if ($uid === '') return null;
        $q = $pdo->prepare(
            "SELECT * FROM cashier_shifts
              WHERE tenant_id=? AND site_id=? AND cashier_user_id=? AND status='OPEN'
                AND deleted_at IS NULL
              ORDER BY opened_at DESC LIMIT 1");
        $q->execute([tenant_id(), site_id(), $uid]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Shift kholo. Pehle se khuli ho to wohi wapas. */
    public static function open(string $counter = '', float $openingCash = 0.0, ?PDO $pdo = null): array
    {
        $own = $pdo === null;
        $pdo = $pdo ?: DB::pdo();
        $uid = self::uid();
        if ($uid === '') throw new \RuntimeException('Login required');
        if ($openingCash < 0) throw new \RuntimeException('Opening cash cannot be negative');

        $fn = function (PDO $p) use ($counter, $openingCash, $uid) {
            $cur = self::current($p, $uid);
            if ($cur) return $cur;

            $id = \uuid();
            $no = 'R-' . \date('ymd') . '-' . \strtoupper(\substr(\str_replace('-', '', $id), 0, 4));
            $sq = $p->prepare("SELECT COALESCE(MAX(shift_seq),0)+1 FROM cashier_shifts WHERE site_id=?");
            $sq->execute([site_id()]);
            $seq = (int)$sq->fetchColumn();
            $counter = \trim($counter) !== '' ? \substr(\trim($counter), 0, 80) : 'Counter 1';

            $p->prepare(
                "INSERT INTO cashier_shifts(id,tenant_id,site_id,shift_no,shift_seq,business_date,
                     cashier_user_id,counter_name,opened_at,opening_cash,status)
                 VALUES(?,?,?,?,?,CURDATE(),?,?,NOW(6),?, 'OPEN')")
              ->execute([$id, tenant_id(), site_id(), $no, $seq, $uid, $counter, $openingCash]);

            try { Audit::log('OPEN', 'shift', ['record_id' => $id, 'label' => $no, 'new' => $openingCash]); }
            catch (\Throwable $e) {}

            return self::current($p, $uid);
        };
        $row = $own ? DB::tx($fn) : $fn($pdo);
        if (!$row) throw new \RuntimeException('Shift could not be opened');
        return $row;
    }

    /**
     * Bill se pehle: khuli shift ho to theek, warna khud khol do.
     * `saveSale` ke transaction ke ANDAR chalta hai.
     */
    public static function ensure(PDO $pdo, string $counter = ''): array
    {
        return self::current($pdo) ?: self::open($counter, 0.0, $pdo);
    }

    /** Ek shift ka poora hisaab — asal bills, returns aur khate se. */
    public static function summary(array $s): array
    {
        $pdo  = DB::pdo();
        $tid  = tenant_id();
        $uid  = (string)$s['cashier_user_id'];
        $from = (string)$s['opened_at'];
        $to   = $s['closed_at'] ? (string)$s['closed_at'] : \date('Y-m-d H:i:s.u');

        $q = $pdo->prepare(
            "SELECT COUNT(*) bills,
                    COALESCE(SUM(subtotal),0) gross,
                    COALESCE(SUM(discount),0) discount,
                    COALESCE(SUM(total),0) total,
                    COALESCE(SUM(CASE WHEN payment_method<>'CREDIT' THEN paid_cash - change_amount ELSE 0 END),0) cash,
                    COALESCE(SUM(paid_card),0) card,
                    COALESCE(SUM(CASE WHEN payment_method='CREDIT' THEN total ELSE 0 END),0) credit
               FROM rtl_sales
              WHERE tenant_id=? AND site_id=? AND cashier_user_id=? AND deleted_at IS NULL
                AND sold_at >= ? AND sold_at <= ?");
        $q->execute([$tid, site_id(), $uid, $from, $to]);
        $a = $q->fetch(PDO::FETCH_ASSOC) ?: [];

        /* Cash refund = woh return jo khate par nahi gaya (customer_id khali). */
        $r = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN customer_id IS NULL THEN total ELSE 0 END),0) cash_ref,
                    COALESCE(SUM(total),0) all_ref
               FROM rtl_docs
              WHERE tenant_id=? AND site_id=? AND doc_type='RET' AND status='POSTED'
                AND posted_by=? AND posted_at >= ? AND posted_at <= ?");
        $r->execute([$tid, site_id(), $uid, $from, $to]);
        $ref = $r->fetch(PDO::FETCH_ASSOC) ?: [];

        /* Khate ki CASH wasooli jo isi cashier ne li (RetailPos::receivePayment
           user ko ref_id mein aur tareeqa ref_no mein likhta hai). */
        $k = $pdo->prepare(
            "SELECT COALESCE(SUM(credit),0) FROM rtl_customer_ledger
              WHERE tenant_id=? AND site_id=? AND entry_type='RECEIPT'
                AND ref_table='users' AND ref_id=? AND ref_no='CASH'
                AND entry_at >= ? AND entry_at <= ? AND deleted_at IS NULL");
        $k->execute([$tid, site_id(), $uid, $from, $to]);
        $khataCash = (float)$k->fetchColumn();

        $opening  = (float)$s['opening_cash'];
        $cash     = (float)($a['cash'] ?? 0);
        $cashRef  = (float)($ref['cash_ref'] ?? 0);
        $expected = $opening + $cash + $khataCash - $cashRef;

        return [
            'bills'       => (int)($a['bills'] ?? 0),
            'gross'       => \round((float)($a['gross'] ?? 0), 2),
            'discount'    => \round((float)($a['discount'] ?? 0), 2),
            'total'       => \round((float)($a['total'] ?? 0), 2),
            'cash_sales'  => \round($cash, 2),
            'card_sales'  => \round((float)($a['card'] ?? 0), 2),
            'credit_sales'=> \round((float)($a['credit'] ?? 0), 2),
            'khata_cash'  => \round($khataCash, 2),
            'cash_refunds'=> \round($cashRef, 2),
            'refunds'     => \round((float)($ref['all_ref'] ?? 0), 2),
            'opening'     => \round($opening, 2),
            'expected'    => \round($expected, 2),
        ];
    }

    /** Shift band karo — gini hui cash ke sath. */
    public static function close(string $id, float $counted, string $note = ''): array
    {
        if ($counted < 0) throw new \RuntimeException('Counted cash cannot be negative');
        return DB::tx(function (PDO $p) use ($id, $counted, $note) {
            $q = $p->prepare("SELECT * FROM cashier_shifts WHERE id=? AND tenant_id=? AND site_id=? FOR UPDATE");
            $q->execute([$id, tenant_id(), site_id()]);
            $s = $q->fetch(PDO::FETCH_ASSOC);
            if (!$s) throw new \RuntimeException('Shift not found');
            if ($s['status'] !== self::OPEN) throw new \RuntimeException('This shift is already closed');
            if ($s['cashier_user_id'] !== self::uid() && !Auth::isManager()) {
                throw new \RuntimeException('Only the cashier or a manager can close this shift');
            }

            $now = \date('Y-m-d H:i:s.u');
            $s['closed_at'] = $now;
            $sum = self::summary($s);
            $var = \round($counted - $sum['expected'], 2);

            $p->prepare(
                "UPDATE cashier_shifts SET status='CLOSED', closed_at=?, expected_cash=?, actual_cash=?,
                     variance_amount=?, close_note=?, approved_by_user_id=?,
                     gross_sales=?, net_sales=?, discount_total=?, cash_sales=?, card_sales=?,
                     credit_sales=?, refund_total=?, invoice_count=?, snapshot_json=?,
                     row_version=row_version+1, updated_at=NOW(6)
                 WHERE id=? AND tenant_id=?")
              ->execute([$now, $sum['expected'], $counted, $var, \substr($note, 0, 1000), self::uid(),
                         $sum['gross'], $sum['total'], $sum['discount'], $sum['cash_sales'], $sum['card_sales'],
                         $sum['credit_sales'], $sum['refunds'], $sum['bills'], \json_encode($sum),
                         $id, tenant_id()]);

            try { Audit::log('CLOSE', 'shift', ['record_id' => $id, 'label' => (string)$s['shift_no'],
                             'new' => 'expected ' . $sum['expected'] . ' / counted ' . $counted]); }
            catch (\Throwable $e) {}

            return self::shape(\array_merge($s, ['status' => 'CLOSED', 'actual_cash' => $counted,
                                                  'variance_amount' => $var]), $sum);
        });
    }

    /** Shifts ki fehrist. Cashier sirf apni, manager sab. */
    public static function listOf(int $limit = 200): array
    {
        $sql = "SELECT s.*, u.full_name cashier FROM cashier_shifts s
                  LEFT JOIN users u ON u.id = s.cashier_user_id
                 WHERE s.tenant_id=? AND s.site_id=? AND s.deleted_at IS NULL";
        $args = [tenant_id(), site_id()];
        if (!Auth::isManager()) { $sql .= " AND s.cashier_user_id=?"; $args[] = self::uid(); }
        $sql .= " ORDER BY s.opened_at DESC LIMIT " . \max(1, \min(1000, $limit));
        $q = DB::pdo()->prepare($sql);
        $q->execute($args);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $sum = null;
            if ($s['status'] === self::CLOSED && !empty($s['snapshot_json'])) {
                $sum = \json_decode((string)$s['snapshot_json'], true) ?: null;
            }
            $out[] = self::shape($s, $sum ?: self::summary($s));
        }
        return $out;
    }

    public static function shape(array $s, array $sum): array
    {
        $closed = $s['status'] === self::CLOSED;
        return [
            'id'       => (string)$s['id'],
            'shift'    => (string)($s['shift_seq'] ?? '') !== '' ? (string)$s['shift_seq'] : (string)$s['shift_no'],
            'shift_no' => (string)$s['shift_no'],
            'date'     => \substr((string)$s['opened_at'], 0, 10),
            'cashier'  => (string)($s['cashier'] ?? ((Auth::user() ?: [])['full_name'] ?? '')),
            'counter'  => (string)($s['counter_name'] ?? ''),
            'opened'   => \substr((string)$s['opened_at'], 11, 5),
            'closed'   => $closed ? \substr((string)$s['closed_at'], 11, 5) : '',
            'status'   => $closed ? 'Closed' : 'Open',
            'opening'  => (float)$s['opening_cash'],
            'expected' => $closed ? (float)$s['expected_cash'] : $sum['expected'],
            'counted'  => $closed ? (float)$s['actual_cash'] : null,
            'variance' => $closed ? (float)$s['variance_amount'] : null,
            'summary'  => $sum,
        ];
    }
}

// build: V191 build 2026-11-13
