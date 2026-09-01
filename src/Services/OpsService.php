<?php
namespace Aio\Services;

use Aio\Auth;
use Aio\DB;
use PDO;

/**
 * OpsService — wo rozana ke kaam jo ab tak "screen only" the.
 *
 * Audit (docs/MODULE_STATUS.md) mein yeh char sab se ahem the:
 *
 *   Kitchen / KDS       — 1,119 lines ka page, magar `const orders=[...]`
 *                         hardcoded. POS asli KOT `kitchen_tickets` mein
 *                         bhejta tha aur KDS unhen PARHTA HI NAHI tha.
 *   Opening & Closing   — `cashier_shifts` table maujood, page `ui_records`
 *                         mein likhta tha. Cash ka koi hisab nahi.
 *   Running Orders      — `orders` table maujood, page alag duniya mein.
 *   Void / Refund       — bill void karne ka asli kaam DeleteService mein
 *                         pehle se tha, page us tak pohanchta hi nahi tha.
 *
 * Ab chaaron asli tables par hain.
 */
final class OpsService
{
    /* ==================== KDS ==================== */

    /**
     * Kitchen ke asli tickets. POS ka `sendKot()` inhen banata hai aur
     * printer ke hisab se group karta hai — yani har station ko sirf
     * uske apne items dikhte hain.
     */
    public static function kdsTickets(int $minutes = 240): array
    {
        $q = DB::pdo()->prepare(
            "SELECT kt.id, kt.ticket_no, kt.ticket_status, kt.station_code,
                    kt.sent_at, kt.started_at, kt.ready_at,
                    o.bill_no, o.service_mode, o.id AS order_id,
                    COALESCE(dt.display_name,'') AS table_name,
                    COALESCE(pr.name,'') AS printer,
                    COALESCE(u.full_name,'') AS taken_by,
                    TIMESTAMPDIFF(MINUTE, kt.sent_at, NOW()) AS mins
               FROM kitchen_tickets kt
               JOIN orders o        ON o.id = kt.order_id
               LEFT JOIN dining_tables dt ON dt.id = o.table_id
               LEFT JOIN printers pr      ON pr.id = kt.printer_id
               LEFT JOIN users u          ON u.id = kt.created_by_user_id
              WHERE kt.site_id = ?
                AND kt.sent_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                AND o.order_status <> 'VOID'
              ORDER BY kt.sent_at ASC");
        $q->execute([site_id(), $minutes]);
        $tickets = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$tickets) return [];

        $ids = array_column($tickets, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $iq = DB::pdo()->prepare(
            "SELECT kti.kitchen_ticket_id AS tid, kti.qty_sent AS qty,
                    kti.item_status, kti.note_snapshot AS note,
                    COALESCE(oi.item_name_snapshot, mi.name, 'Item') AS name
               FROM kitchen_ticket_items kti
               LEFT JOIN order_items oi ON oi.id = kti.order_item_id
               LEFT JOIN menu_items mi  ON mi.id = oi.menu_item_id
              WHERE kti.kitchen_ticket_id IN ($in)");
        $iq->execute($ids);

        $byTicket = [];
        foreach ($iq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byTicket[$r['tid']][] = [
                'name'   => (string)$r['name'],
                'qty'    => (float)$r['qty'],
                'note'   => (string)($r['note'] ?? ''),
                'status' => (string)($r['item_status'] ?? 'PENDING'),
            ];
        }

        $modes = ['DINE_IN'=>'Dine In','TAKEAWAY'=>'Takeaway','TAKE_AWAY'=>'Takeaway',
                  'DELIVERY'=>'Delivery','QR'=>'QR Order'];
        $map   = ['SENT'=>'New','PENDING'=>'New','PREPARING'=>'Preparing',
                  'READY'=>'Ready','COMPLETED'=>'Done','SERVED'=>'Done'];

        $out = [];
        foreach ($tickets as $t) {
            $st   = strtoupper((string)$t['ticket_status']);
            $mins = (int)$t['mins'];
            $lane = $map[$st] ?? 'New';
            /* 15 minute se purana aur abhi tak ready nahi = Delayed.
               Yeh wo cheez hai jis ke liye kitchen screen hoti hai. */
            if ($lane !== 'Done' && $lane !== 'Ready' && $mins >= 15) $lane = 'Delayed';

            $out[] = [
                'id'       => $t['id'],
                'order_id' => $t['order_id'],
                'ticket'   => (string)$t['ticket_no'],
                'bill'     => (string)$t['bill_no'],
                'mode'     => $modes[strtoupper((string)$t['service_mode'])] ?? (string)$t['service_mode'],
                'table'    => (string)$t['table_name'],
                'station'  => (string)($t['printer'] ?: $t['station_code']),
                'waiter'   => (string)$t['taken_by'],
                'status'   => $lane,
                'raw'      => $st,
                'mins'     => $mins,
                'sent_at'  => (string)$t['sent_at'],
                'items'    => $byTicket[$t['id']] ?? [],
            ];
        }
        return $out;
    }

    public static function kdsSetStatus(string $ticketId, string $status): array
    {
        $map = ['PREPARING'=>'PREPARING','READY'=>'READY','DONE'=>'COMPLETED','NEW'=>'SENT'];
        $st  = $map[strtoupper($status)] ?? null;
        if (!$st) throw new \RuntimeException('Unknown kitchen status: ' . $status);

        $col = ['PREPARING'=>'started_at','READY'=>'ready_at','COMPLETED'=>'completed_at'][$st] ?? null;
        $sets = ["ticket_status = ?"];
        $args = [$st];
        if ($col) { $sets[] = "$col = NOW(6)"; }

        $p  = DB::pdo();
        $st2 = $p->prepare("UPDATE kitchen_tickets SET " . implode(', ', $sets)
                         . " WHERE id = ? AND site_id = ?");
        $st2->execute(array_merge($args, [$ticketId, site_id()]));
        if ($st2->rowCount() < 1) {
            throw new \RuntimeException('Ticket not found, or it already had that status.');
        }
        try {
            $p->prepare("UPDATE kitchen_ticket_items SET item_status=? WHERE kitchen_ticket_id=?")
              ->execute([$st === 'COMPLETED' ? 'SERVED' : $st, $ticketId]);
        } catch (\Throwable $e) {}
        return ['ok' => true, 'status' => $st];
    }

    /* ==================== SHIFTS ==================== */

    public static function shiftList(int $limit = 60): array
    {
        /* V79 — YEH LEAK THI. Cashier ko doosre cashier ki shifts bhi
           nazar aa rahi thin (opening cash, counted cash, variance samet).
           `Scope` maujood tha magar yahan lagaya hi nahi gaya tha —
           bilkul wahi ghalti jis se bachne ke liye Scope banaya tha.
           Asli do cashiers se test karne par hi pakri gayi. */
        [$scopeW, $scopeA] = Scope::shiftWhere('cs');
        $q = DB::pdo()->prepare(
            "SELECT cs.id, cs.shift_no, cs.business_date, cs.status,
                    cs.opened_at, cs.closed_at,
                    cs.opening_cash, cs.expected_cash, cs.actual_cash, cs.variance_amount,
                    COALESCE(u.full_name,'-') AS cashier
               FROM cashier_shifts cs
               LEFT JOIN users u ON u.id = cs.cashier_user_id
              WHERE cs.site_id = ? AND cs.deleted_at IS NULL AND $scopeW
              ORDER BY cs.opened_at DESC LIMIT $limit");
        $q->execute(array_merge([site_id()], $scopeA));
        return array_map(fn($x) => [
            'id'       => $x['id'],
            'shift'    => (string)$x['shift_no'],
            'date'     => (string)$x['business_date'],
            'cashier'  => (string)$x['cashier'],
            'opened'   => substr((string)$x['opened_at'], 0, 16),
            'closed'   => $x['closed_at'] ? substr((string)$x['closed_at'], 0, 16) : '',
            'opening'  => (float)$x['opening_cash'],
            'expected' => (float)$x['expected_cash'],
            'counted'  => (float)$x['actual_cash'],
            'variance' => (float)$x['variance_amount'],
            'status'   => strtoupper((string)$x['status']) === 'OPEN' ? 'Open' : 'Closed',
        ], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Shift ke doran jama hui asli cash — counting se pehle. */
    public static function shiftExpected(string $shiftId): float
    {
        try {
            $q = DB::pdo()->prepare(
                "SELECT cs.opening_cash
                        + COALESCE((SELECT SUM(p.amount) FROM payments p
                                     JOIN orders o ON o.id = p.order_id
                                     JOIN payment_methods pm ON pm.id = p.payment_method_id
                                    WHERE o.site_id = cs.site_id
                                      AND p.status <> 'CANCELLED'
                                      AND o.order_status <> 'VOID'
                                      AND pm.method_type = 'CASH'
                                      /* `payments` par `created_at` hai hi nahi — `paid_at` hai.
                                         Aur agar payment shift se juri hui ho to wahi sab se
                                         pukhta rabta hai, waqt ke muqable mein. */
                                      AND (p.shift_id = cs.id
                                           OR (p.shift_id IS NULL
                                               AND p.paid_at >= cs.opened_at
                                               AND (cs.closed_at IS NULL OR p.paid_at <= cs.closed_at)))),0) AS v
                   FROM cashier_shifts cs WHERE cs.id = ? AND cs.site_id = ?");
            $q->execute([$shiftId, site_id()]);
            return (float)($q->fetchColumn() ?: 0);
        } catch (\Throwable $e) { return 0.0; }
    }

    public static function shiftOpen(float $openingCash): array
    {
        $p = DB::pdo();
        $uid = Auth::user()['id'] ?? null;
        $q = $p->prepare("SELECT id FROM cashier_shifts
                           WHERE site_id=? AND cashier_user_id=? AND status='OPEN' AND deleted_at IS NULL LIMIT 1");
        $q->execute([site_id(), $uid]);
        if ($q->fetchColumn()) throw new \RuntimeException('You already have an open shift. Close it first.');

        $no = 'SH-' . date('ymd-His');
        $id = uuid();
        $p->prepare("INSERT INTO cashier_shifts(id,tenant_id,site_id,shift_no,business_date,
                        cashier_user_id,opened_at,opening_cash,status)
                     VALUES(?,?,?,?,CURDATE(),?,NOW(6),?,'OPEN')")
          ->execute([$id, tenant_id(), site_id(), $no, $uid, max(0, $openingCash)]);
        return ['id' => $id, 'shift_no' => $no, 'message' => 'Shift ' . $no . ' opened.'];
    }

    public static function shiftClose(string $shiftId, float $counted, string $note = ''): array
    {
        $p = DB::pdo();
        $q = $p->prepare("SELECT shift_no,status FROM cashier_shifts WHERE id=? AND site_id=? LIMIT 1");
        $q->execute([$shiftId, site_id()]);
        $s = $q->fetch(PDO::FETCH_ASSOC);
        if (!$s) throw new \RuntimeException('Shift not found.');
        if (strtoupper((string)$s['status']) !== 'OPEN') throw new \RuntimeException('This shift is already closed.');

        $expected = self::shiftExpected($shiftId);
        $var = round($counted - $expected, 2);

        $p->prepare("UPDATE cashier_shifts
                        SET closed_at=NOW(6), expected_cash=?, actual_cash=?, variance_amount=?,
                            close_note=?, status='CLOSED', updated_at=NOW(6)
                      WHERE id=? AND site_id=?")
          ->execute([$expected, $counted, $var, $note !== '' ? $note : null, $shiftId, site_id()]);

        /* V79 — SNAPSHOT yahan bhi.
           Yeh code sirf POS ke `shift-close` endpoint mein tha. Shift
           Management page se band ki gayi shift ka snapshot banta hi
           nahi tha — us ki purani report har dafa dobara ginti, aur
           waqt ke saath badal jati. Do raste the, ek adhoora.
           Ab snapshot ek hi jagah banta hai: yahan. */
        try {
            $snap = self::shiftReport($shiftId);
            $ref  = 'CL-' . date('ymd-His');
            $cash = 0.0; $card = 0.0;
            foreach (($snap['payments'] ?? []) as $x) {
                if (stripos((string)$x['method'], 'cash') !== false) $cash += (float)$x['amount'];
                else $card += (float)$x['amount'];
            }
            $p->prepare("UPDATE cashier_shifts SET snapshot_json=?, closing_ref=?,
                             gross_sales=?, net_sales=?, discount_total=?, invoice_count=?,
                             cash_sales=?, card_sales=?, expense_total=?
                           WHERE id=?")
              ->execute([json_encode($snap, JSON_UNESCAPED_UNICODE), $ref,
                         (float)($snap['total'] ?? 0),
                         (float)(($snap['total'] ?? 0) - ($snap['tax'] ?? 0)),
                         (float)($snap['discount'] ?? 0), (int)($snap['bills'] ?? 0),
                         $cash, $card, (float)($snap['expenses'] ?? 0), $shiftId]);

            /* Owner ko WhatsApp — closing MEHFOOZ hone ke baad. */
            try { WhatsApp::queueShiftClosing($shiftId, $snap); } catch (\Throwable $e) {}
        } catch (\Throwable $e) { /* snapshot na bane to bhi shift band rahe */ }

        Audit::log('SHIFT_CLOSE', 'shift', ['id' => $shiftId, 'label' => (string)$s['shift_no'],
                                            'new' => 'counted ' . $counted]);

        /* V88 — shift band hote hi poore data ka backup, tareekh ke saath.
           Yeh KABHI shift close nahi rokta: disk bhari ho ya D: drive na
           ho, shift phir bhi band rehti hai aur nakami audit mein jati
           hai. Cashier ko counter par rok dena hal nahi hai. */
        BackupService::afterShiftClose((string)$s['shift_no']);

        $word = $var == 0 ? 'exactly balanced'
              : ($var > 0 ? ('over by ' . number_format(abs($var), 2))
                          : ('short by ' . number_format(abs($var), 2)));
        return ['expected' => $expected, 'counted' => $counted, 'variance' => $var,
                'message' => 'Shift ' . $s['shift_no'] . ' closed - cash ' . $word . '.'];
    }

    /**
     * Shift closing report ka poora data — 80mm par chhapne ke liye.
     *
     * Sirf cash ka farq kaafi nahi hota. Malik yeh dekhna chahta hai ke
     * us shift mein BIKA kya: category ke hisab se, aur har category ke
     * andar item aur raqam. Neeche discount aur total.
     */
    public static function shiftReport(string $shiftId): array
    {
        $p = DB::pdo();

        /* V77 — agar shift band ho chuki hai aur us ka SNAPSHOT mehfooz
           hai, to wahi lauta do. Dobara ginne se aaj ka natija alag aa
           sakta hai (beech mein void/refund ho chuke hon), aur purani
           closing report badal jana accounts ke liye na-qabil-e-qabool
           hai. */
        try {
            $sq = $p->prepare("SELECT snapshot_json FROM cashier_shifts
                                WHERE id=? AND site_id=? AND status='CLOSED' LIMIT 1");
            $sq->execute([$shiftId, site_id()]);
            $j = $sq->fetchColumn();
            if ($j) {
                $d = json_decode((string)$j, true);
                if (is_array($d) && !empty($d['shift'])) return $d;
            }
        } catch (\Throwable $e) { /* column purane DB par na ho to normal raste par */ }

        $q = $p->prepare(
            "SELECT cs.*, COALESCE(u.full_name,'-') AS cashier
               FROM cashier_shifts cs
               LEFT JOIN users u ON u.id = cs.cashier_user_id
              WHERE cs.id=? AND cs.site_id=? LIMIT 1");
        $q->execute([$shiftId, site_id()]);
        $sh = $q->fetch(PDO::FETCH_ASSOC);
        if (!$sh) throw new \RuntimeException('Shift not found.');

        $open  = (string)$sh['opened_at'];
        $close = (string)($sh['closed_at'] ?: date('Y-m-d H:i:s'));

        /* Shift ke bills — shift_id se joro, aur jin par shift_id na ho
           un ke liye waqt se. (Purane bills par shift_id NULL hai.) */
        $where = "o.site_id=? AND o.order_status<>'VOID'
                  AND (o.shift_id=? OR (o.shift_id IS NULL
                       AND COALESCE(o.closed_at,o.created_at) BETWEEN ? AND ?))";
        $args  = [site_id(), $shiftId, $open, $close];

        $head = $p->prepare("SELECT COUNT(*) bills, COALESCE(SUM(o.subtotal),0) subtotal,
                                    COALESCE(SUM(o.discount_amount),0) discount,
                                    COALESCE(SUM(o.service_charge),0) service,
                                    COALESCE(SUM(o.tax_amount),0) tax,
                                    COALESCE(SUM(o.grand_total),0) total
                               FROM orders o WHERE $where");
        $head->execute($args);
        $tot = $head->fetch(PDO::FETCH_ASSOC) ?: [];

        $iq = $p->prepare(
            "SELECT COALESCE(mc.name,'(uncategorised)') category,
                    COALESCE(oi.item_name_snapshot, mi.name,'(deleted)') item,
                    SUM(oi.qty) qty, SUM(oi.line_total) amount
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
               LEFT JOIN menu_items mi      ON mi.id = oi.menu_item_id
               LEFT JOIN menu_categories mc ON mc.id = mi.category_id
              WHERE $where AND oi.status='ACTIVE'
              GROUP BY category, item
              ORDER BY category, amount DESC");
        $iq->execute($args);

        $cats = [];
        foreach ($iq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = (string)$r['category'];
            if (!isset($cats[$c])) $cats[$c] = ['name'=>$c,'qty'=>0,'amount'=>0,'items'=>[]];
            $cats[$c]['items'][] = ['name'=>(string)$r['item'],'qty'=>(float)$r['qty'],'amount'=>(float)$r['amount']];
            $cats[$c]['qty']    += (float)$r['qty'];
            $cats[$c]['amount'] += (float)$r['amount'];
        }

        $pq = $p->prepare(
            "SELECT COALESCE(pm.name,'(unknown)') method, COALESCE(SUM(pay.amount),0) amount
               FROM payments pay
               JOIN orders o ON o.id = pay.order_id
               LEFT JOIN payment_methods pm ON pm.id = pay.payment_method_id
              WHERE $where AND pay.status<>'CANCELLED'
              GROUP BY method ORDER BY amount DESC");
        $pq->execute($args);

        /* Expenses jo isi shift mein counter se nikle — cash count ka
           hissa hain, is liye report par nazar aane chahiyen. */
        $exp = 0.0;
        try {
            $eq = $p->prepare("SELECT COALESCE(SUM(e.amount),0) FROM expenses e
                                WHERE e.site_id=? AND e.status<>'REJECTED' AND e.deleted_at IS NULL
                                  AND e.created_at BETWEEN ? AND ?");
            $eq->execute([site_id(), $open, $close]);
            $exp = (float)$eq->fetchColumn();
        } catch (\Throwable $e) {}

        return [
            'shift'    => (string)$sh['shift_no'],
            'counter'  => (string)($sh['counter_name'] ?? ''),
            'note'     => (string)($sh['close_note'] ?? ''),
            'expenses' => $exp,
            'cashier'  => (string)$sh['cashier'],
            'date'     => (string)$sh['business_date'],
            'opened'   => substr($open, 0, 16),
            'closed'   => $sh['closed_at'] ? substr((string)$sh['closed_at'], 0, 16) : '',
            'opening'  => (float)$sh['opening_cash'],
            'expected' => (float)$sh['expected_cash'],
            'counted'  => (float)$sh['actual_cash'],
            'variance' => (float)$sh['variance_amount'],
            'bills'    => (int)($tot['bills'] ?? 0),
            'subtotal' => (float)($tot['subtotal'] ?? 0),
            'discount' => (float)($tot['discount'] ?? 0),
            'service'  => (float)($tot['service'] ?? 0),
            'tax'      => (float)($tot['tax'] ?? 0),
            'total'    => (float)($tot['total'] ?? 0),
            'categories' => array_values($cats),
            'payments' => $pq->fetchAll(PDO::FETCH_ASSOC),
            /* Sirf tracked items — poori inventory bhejna malik ke liye
               be-kaar shor hai. */
            'tracked' => (self::trackedBetween($open, $close)['rows'] ?? []),
        ];
    }

    /**
     * Closing history — purani closing reports dobara chhapne ke liye.
     *
     * Cashier sirf APNI dekh sakta hai; manager sab. Yeh filter server
     * par lagta hai (Scope), UI par nahi — warna cashier request badal
     * kar doosre ki report nikal leta.
     */
    public static function closingHistory(array $f = []): array
    {
        [$w, $a] = Scope::shiftWhere('cs', $f['user'] ?? null);

        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['from'] ?? ''))
              ? (string)$f['from'] : date('Y-m-d', strtotime('-30 day'));
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['to'] ?? ''))
              ? (string)$f['to'] : date('Y-m-d');

        $sql = "SELECT cs.id, cs.shift_no, cs.closing_ref, cs.business_date,
                       cs.opened_at, cs.closed_at, cs.counter_name,
                       cs.opening_cash, cs.expected_cash, cs.actual_cash, cs.variance_amount,
                       cs.invoice_count, cs.gross_sales, cs.discount_total,
                       cs.cash_sales, cs.card_sales,
                       COALESCE(u.full_name,'-') AS cashier,
                       CASE WHEN cs.snapshot_json IS NULL THEN 0 ELSE 1 END AS has_snapshot
                  FROM cashier_shifts cs
                  LEFT JOIN users u ON u.id = cs.cashier_user_id
                 WHERE cs.site_id = ? AND cs.status='CLOSED' AND cs.deleted_at IS NULL
                   AND cs.business_date BETWEEN ? AND ?
                   AND $w
                 ORDER BY cs.closed_at DESC LIMIT 300";
        $args = array_merge([site_id(), $from, $to], $a);

        try {
            $q = DB::pdo()->prepare($sql);
            $q->execute($args);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not load closing history: '.substr($e->getMessage(), 0, 140));
        }

        return ['from' => $from, 'to' => $to,
                'can_see_all' => Scope::isManagement(),
                'rows' => array_map(fn($x) => [
                    'id'       => $x['id'],
                    'ref'      => (string)($x['closing_ref'] ?: $x['shift_no']),
                    'shift'    => (string)$x['shift_no'],
                    'date'     => (string)$x['business_date'],
                    'counter'  => (string)($x['counter_name'] ?? ''),
                    'cashier'  => (string)$x['cashier'],
                    'opened'   => substr((string)$x['opened_at'], 0, 16),
                    'closed'   => substr((string)$x['closed_at'], 0, 16),
                    'invoices' => (int)$x['invoice_count'],
                    'sales'    => (float)$x['gross_sales'],
                    'cash'     => (float)$x['cash_sales'],
                    'card'     => (float)$x['card_sales'],
                    'expected' => (float)$x['expected_cash'],
                    'counted'  => (float)$x['actual_cash'],
                    'variance' => (float)$x['variance_amount'],
                    'saved'    => (int)$x['has_snapshot'] === 1,
                ], $rows)];
    }

    /* ==================== RUNNING ORDERS ==================== */

    public static function runningOrders(): array
    {
        /* Cashier ko sirf apne khule bill. */
        [$scopeW, $scopeA] = Scope::orderWhere('o');
        $q = DB::pdo()->prepare(
            "SELECT o.id, o.bill_no, o.service_mode, o.order_status, o.payment_status,
                    o.grand_total, o.opened_at, o.created_at,
                    COALESCE(dt.display_name,'') AS table_name,
                    COALESCE(c.full_name,'') AS customer,
                    COALESCE(u.full_name,'') AS taken_by,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id=o.id) AS lines_count,
                    TIMESTAMPDIFF(MINUTE, COALESCE(o.opened_at,o.created_at), NOW()) AS mins
               FROM orders o
               LEFT JOIN dining_tables dt ON dt.id = o.table_id
               LEFT JOIN customers c      ON c.id = o.customer_id
               LEFT JOIN users u          ON u.id = o.created_by_user_id
              WHERE o.site_id = ? AND o.order_status = 'OPEN' AND $scopeW
              ORDER BY COALESCE(o.opened_at,o.created_at) ASC");
        $q->execute(array_merge([site_id()], $scopeA));
        $modes = ['DINE_IN'=>'Dine In','TAKEAWAY'=>'Takeaway','TAKE_AWAY'=>'Takeaway',
                  'DELIVERY'=>'Delivery','QR'=>'QR Order'];
        return array_map(fn($x) => [
            'id'       => $x['id'],
            'bill'     => (string)$x['bill_no'],
            'mode'     => $modes[strtoupper((string)$x['service_mode'])] ?? (string)$x['service_mode'],
            'table'    => (string)$x['table_name'],
            'customer' => (string)$x['customer'],
            'waiter'   => (string)$x['taken_by'],
            'items'    => (int)$x['lines_count'],
            'amount'   => (float)$x['grand_total'],
            'open_min' => (int)$x['mins'],
            'payment'  => (string)$x['payment_status'],
        ], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /* ==================== STOCK TRANSFER ====================
       Pehle yeh page `ui_records` mein likhta tha — transfer karein to
       stock HILTA HI NAHI tha. Ab asli movement dono taraf. */

    public static function transferList(int $limit = 60): array
    {
        $q = DB::pdo()->prepare(
            "SELECT st.id, st.transfer_no, st.transfer_date, st.status, st.notes,
                    COALESCE(f.name,'-') AS from_site, COALESCE(t.name,'-') AS to_site,
                    COALESCE(u.full_name,'-') AS requested_by,
                    (SELECT COUNT(*) FROM stock_transfer_items i WHERE i.transfer_id = st.id) AS lines_count
               FROM stock_transfers st
               LEFT JOIN sites f ON f.id = st.from_site_id
               LEFT JOIN sites t ON t.id = st.to_site_id
               LEFT JOIN users u ON u.id = st.requested_by_user_id
              WHERE st.tenant_id = ? AND st.deleted_at IS NULL
              ORDER BY st.transfer_date DESC, st.created_at DESC LIMIT $limit");
        $q->execute([tenant_id()]);
        return array_map(fn($x) => [
            'id'    => $x['id'],
            'ref'   => (string)$x['transfer_no'],
            'date'  => substr((string)$x['transfer_date'], 0, 10),
            'from'  => (string)$x['from_site'],
            'to'    => (string)$x['to_site'],
            'lines' => (int)$x['lines_count'],
            'by'    => (string)$x['requested_by'],
            'status'=> ucfirst(strtolower((string)$x['status'])),
        ], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Transfer banayein aur stock foran hilao (single-step). */
    public static function transferCreate(string $toSiteId, array $lines, string $notes = ''): array
    {
        if ($toSiteId === '' || $toSiteId === site_id()) {
            throw new \RuntimeException('Choose a different branch to transfer to.');
        }
        $clean = [];
        foreach ($lines as $l) {
            $qty = (float)($l['qty'] ?? 0);
            $item = (string)($l['item_id'] ?? '');
            if ($item === '' || $qty <= 0) continue;
            $clean[] = ['item_id' => $item, 'qty' => $qty];
        }
        if (!$clean) throw new \RuntimeException('Add at least one item with a quantity.');

        return DB::tx(function (PDO $p) use ($toSiteId, $clean, $notes) {
            $id = uuid();
            $no = 'TR-' . date('ymd-His');
            $p->prepare("INSERT INTO stock_transfers(id,tenant_id,from_site_id,to_site_id,transfer_no,
                            transfer_date,status,requested_by_user_id,dispatched_at,received_at,notes)
                         VALUES(?,?,?,?,?,CURDATE(),'RECEIVED',?,NOW(6),NOW(6),?)")
              ->execute([$id, tenant_id(), site_id(), $toSiteId, $no,
                         Auth::user()['id'] ?? null, $notes !== '' ? $notes : null]);

            $out = []; $in = [];
            foreach ($clean as $l) {
                try {
                    $p->prepare("INSERT INTO stock_transfer_items(id,transfer_id,inventory_item_id,
                                    requested_qty,dispatched_qty,received_qty)
                                 VALUES(?,?,?,?,?,?)")
                      ->execute([uuid(), $id, $l['item_id'], $l['qty'], $l['qty'], $l['qty']]);
                } catch (\Throwable $e) { /* items table optional */ }
                $out[] = (object)['item_id'=>$l['item_id'],'location_id'=>null,'qty'=>-$l['qty'],'unit_cost'=>0,'source_order_item_id'=>null];
                $in[]  = (object)['item_id'=>$l['item_id'],'location_id'=>null,'qty'=> $l['qty'],'unit_cost'=>0,'source_order_item_id'=>null];
            }
            /* Stock DONO taraf hilta hai — yehi wo cheez thi jo pehle
               bilkul nahi hoti thi. */
            InventoryService::postMovement($p,'TRANSFER_OUT','STOCK_TRANSFER',$id,$no,$out,Auth::user()['id']??null);
            InventoryService::postMovement($p,'TRANSFER_IN','STOCK_TRANSFER',$id,$no,$in, Auth::user()['id']??null);

            return ['id'=>$id,'ref'=>$no,'message'=>'Transfer '.$no.' completed - stock moved for '.count($clean).' item(s).'];
        });
    }

    /* ==================== PHYSICAL STOCK COUNT ==================== */

    public static function countList(int $limit = 40): array
    {
        $q = DB::pdo()->prepare(
            "SELECT cs.id, cs.count_no, cs.started_at, cs.completed_at, cs.status,
                    COALESCE(sl.name,'-') AS location,
                    COALESCE(u.full_name,'-') AS started_by,
                    (SELECT COUNT(*) FROM stock_count_items i WHERE i.count_session_id = cs.id) AS lines_count
               FROM stock_count_sessions cs
               LEFT JOIN stock_locations sl ON sl.id = cs.stock_location_id
               LEFT JOIN users u ON u.id = cs.started_by_user_id
              WHERE cs.site_id = ? AND cs.deleted_at IS NULL
              ORDER BY cs.started_at DESC LIMIT $limit");
        $q->execute([site_id()]);
        return array_map(fn($x) => [
            'id'      => $x['id'],
            'ref'     => (string)$x['count_no'],
            'location'=> (string)$x['location'],
            'started' => substr((string)$x['started_at'], 0, 16),
            'done'    => $x['completed_at'] ? substr((string)$x['completed_at'], 0, 16) : '',
            'lines'   => (int)$x['lines_count'],
            'by'      => (string)$x['started_by'],
            'status'  => ucfirst(strtolower((string)$x['status'])),
        ], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** System qty vs counted qty ka farq — aur us farq ka asli adjustment. */
    public static function countPost(string $locationId, array $lines, string $note = ''): array
    {
        $clean = [];
        foreach ($lines as $l) {
            $item = (string)($l['item_id'] ?? '');
            if ($item === '') continue;
            $clean[] = ['item_id' => $item, 'counted' => (float)($l['counted'] ?? 0)];
        }
        if (!$clean) throw new \RuntimeException('Enter counted quantities first.');

        return DB::tx(function (PDO $p) use ($locationId, $clean, $note) {
            $id = uuid(); $no = 'SC-' . date('ymd-His');
            $p->prepare("INSERT INTO stock_count_sessions(id,tenant_id,site_id,count_no,stock_location_id,
                            started_at,completed_at,status,started_by_user_id)
                         VALUES(?,?,?,?,?,NOW(6),NOW(6),'COMPLETED',?)")
              ->execute([$id, tenant_id(), site_id(), $no, $locationId ?: null, Auth::user()['id'] ?? null]);

            $moves = []; $diffs = 0;
            foreach ($clean as $l) {
                $bq = $p->prepare("SELECT COALESCE(SUM(qty_on_hand),0) FROM stock_balances
                                    WHERE inventory_item_id=? AND site_id=?"
                                 . ($locationId ? " AND stock_location_id=?" : ""));
                $bq->execute($locationId ? [$l['item_id'], site_id(), $locationId] : [$l['item_id'], site_id()]);
                $system = (float)$bq->fetchColumn();
                $diff   = round($l['counted'] - $system, 3);
                try {
                    $p->prepare("INSERT INTO stock_count_items(id,count_session_id,inventory_item_id,
                                    system_qty,physical_qty,variance_qty)
                                 VALUES(?,?,?,?,?,?)")
                      ->execute([uuid(), $id, $l['item_id'], $system, $l['counted'], $diff]);
                } catch (\Throwable $e) {}
                if (abs($diff) > 0.0001) {
                    $diffs++;
                    $moves[] = (object)['item_id'=>$l['item_id'],'location_id'=>$locationId ?: null,
                                        'qty'=>$diff,'unit_cost'=>0,'source_order_item_id'=>null];
                }
            }
            if ($moves) {
                InventoryService::postMovement($p,'COUNT_ADJUST','STOCK_COUNT',$id,$no,$moves,Auth::user()['id']??null);
            }
            return ['id'=>$id,'ref'=>$no,'checked'=>count($clean),'adjusted'=>$diffs,
                    'message'=>'Count '.$no.' posted - '.count($clean).' item(s) checked, '
                              .$diffs.' adjusted to match the shelf.'];
        });
    }

    /* ==================== ACCOUNTING / CASH ==================== */

    public static function cashBook(string $from = '', string $to = ''): array
    {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-01');
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to   : date('Y-m-d');

        $q = DB::pdo()->prepare(
            "SELECT d, SUM(cash_in) cash_in, SUM(card_in) card_in, SUM(cash_out) cash_out FROM (
                SELECT DATE(p.paid_at) d,
                       SUM(CASE WHEN pm.method_type='CASH' THEN p.amount ELSE 0 END) cash_in,
                       SUM(CASE WHEN pm.method_type<>'CASH' THEN p.amount ELSE 0 END) card_in,
                       0 cash_out
                  FROM payments p
                  JOIN orders o ON o.id = p.order_id
                  LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
                 WHERE o.site_id=? AND p.status<>'CANCELLED' AND o.order_status<>'VOID'
                   AND DATE(p.paid_at) BETWEEN ? AND ?
                 GROUP BY d
                UNION ALL
                SELECT DATE(e.expense_date) d, 0, 0, SUM(e.amount)
                  FROM expenses e
                 WHERE e.site_id=? AND e.status<>'REJECTED' AND e.deleted_at IS NULL
                   AND DATE(e.expense_date) BETWEEN ? AND ?
                 GROUP BY d
             ) x GROUP BY d ORDER BY d DESC");
        $q->execute([site_id(), $from, $to, site_id(), $from, $to]);

        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'date'     => (string)$r['d'],
                'cash_in'  => (float)$r['cash_in'],
                'card_in'  => (float)$r['card_in'],
                'cash_out' => (float)$r['cash_out'],
                'net'      => round((float)$r['cash_in'] + (float)$r['card_in'] - (float)$r['cash_out'], 2),
            ];
        }
        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    /* ==================== ONLINE ORDERS ==================== */

    public static function onlineOrders(): array
    {
        $q = DB::pdo()->prepare(
            "SELECT o.id, o.bill_no, o.order_source, o.service_mode, o.order_status,
                    o.grand_total, o.created_at,
                    COALESCE(c.full_name,'Walk-in') AS customer, COALESCE(c.phone,'') AS phone,
                    COALESCE(dl.delivery_status,'') AS delivery_status,
                    COALESCE(rd.name,'') AS rider
               FROM orders o
               LEFT JOIN customers c        ON c.id = o.customer_id
               LEFT JOIN delivery_orders dl ON dl.order_id = o.id
               LEFT JOIN riders rd          ON rd.id = dl.rider_id
              WHERE o.site_id = ?
                AND (o.order_source <> 'POS' OR o.service_mode IN ('DELIVERY','QR'))
                AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
              ORDER BY o.created_at DESC LIMIT 200");
        $q->execute([site_id()]);
        return array_map(fn($x) => [
            'id'       => $x['id'],
            'bill'     => (string)$x['bill_no'],
            'source'   => (string)$x['order_source'],
            'mode'     => (string)$x['service_mode'],
            'customer' => (string)$x['customer'],
            'phone'    => (string)$x['phone'],
            'amount'   => (float)$x['grand_total'],
            'status'   => (string)$x['order_status'],
            'delivery' => (string)$x['delivery_status'],
            'rider'    => (string)$x['rider'],
            'at'       => substr((string)$x['created_at'], 0, 16),
        ], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /* ==================== NOTIFICATIONS ==================== */

    public static function notifications(int $limit = 200): array
    {
        $q = DB::pdo()->prepare(
            "SELECT id, channel, recipient, template_key, status, attempts,
                    available_at, sent_at, last_error
               FROM notification_queue
              WHERE tenant_id=? AND site_id=?
              ORDER BY COALESCE(sent_at, available_at) DESC LIMIT $limit");
        $q->execute([tenant_id(), site_id()]);
        return array_map(fn($x) => [
            'id'        => $x['id'],
            'channel'   => strtoupper((string)$x['channel']),
            'to'        => (string)$x['recipient'],
            'template'  => (string)$x['template_key'],
            'status'    => ucfirst(strtolower((string)$x['status'])),
            'attempts'  => (int)$x['attempts'],
            'when'      => substr((string)($x['sent_at'] ?: $x['available_at']), 0, 16),
            'error'     => (string)($x['last_error'] ?? ''),
        ], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /* ==================== TRACKED INVENTORY ====================
       Malik har item ka hisab nahi chahta — sirf un chand cheezon ka
       jo qeemti hain ya jinki chori ka andesha hai. Sirf wahi items
       jinpar `is_tracked` on hai. */

    /**
     * Ek arse ka tracked hisab: opening / added / sold / returned /
     * adjusted / remaining.
     */
    public static function trackedInventory(string $from = '', string $to = ''): array
    {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-d');
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to   : date('Y-m-d');
        return self::trackedBetween($from . ' 00:00:00', $to . ' 23:59:59')
             + ['from' => $from, 'to' => $to];
    }

    /**
     * @param string $start  waqt se (shift ke liye opened_at)
     * @param string $end    waqt tak (shift ke liye closed_at)
     */
    public static function trackedBetween(string $start, string $end): array
    {
        try {
            $q = DB::pdo()->prepare(
                "SELECT ii.id, ii.name, ii.sku,
                        COALESCE(u.code,'') AS unit,
                        /* ab kitna para hai */
                        COALESCE((SELECT SUM(sb.qty_on_hand) FROM stock_balances sb
                                   WHERE sb.inventory_item_id = ii.id AND sb.site_id = ?),0) AS on_hand,
                        /* arse ke andar aaya */
                        COALESCE((SELECT SUM(stl.qty_change) FROM stock_transaction_lines stl
                                   JOIN stock_transactions st ON st.id = stl.stock_transaction_id
                                  WHERE stl.inventory_item_id = ii.id AND st.site_id = ?
                                    AND st.posted_at BETWEEN ? AND ?
                                    AND stl.qty_change > 0),0) AS added,
                        /* arse ke andar gaya (bik gaya / nikla) */
                        COALESCE((SELECT -SUM(stl.qty_change) FROM stock_transaction_lines stl
                                   JOIN stock_transactions st ON st.id = stl.stock_transaction_id
                                  WHERE stl.inventory_item_id = ii.id AND st.site_id = ?
                                    AND st.posted_at BETWEEN ? AND ?
                                    AND stl.qty_change < 0
                                    AND st.reference_type = 'ORDER'),0) AS sold,
                        /* wapas aaya (void / refund) */
                        COALESCE((SELECT SUM(stl.qty_change) FROM stock_transaction_lines stl
                                   JOIN stock_transactions st ON st.id = stl.stock_transaction_id
                                  WHERE stl.inventory_item_id = ii.id AND st.site_id = ?
                                    AND st.posted_at BETWEEN ? AND ?
                                    AND st.transaction_type IN ('VOID_RETURN','REVERSAL')),0) AS returned,
                        /* wastage / count / transfer */
                        COALESCE((SELECT SUM(stl.qty_change) FROM stock_transaction_lines stl
                                   JOIN stock_transactions st ON st.id = stl.stock_transaction_id
                                  WHERE stl.inventory_item_id = ii.id AND st.site_id = ?
                                    AND st.posted_at BETWEEN ? AND ?
                                    AND st.reference_type IN ('STOCK_ADJUSTMENT','STOCK_COUNT','STOCK_TRANSFER')),0) AS adjusted
                   FROM inventory_items ii
                   LEFT JOIN units u ON u.id = ii.stock_unit_id
                  WHERE ii.site_id = ? AND ii.is_tracked = 1
                    AND ii.deleted_at IS NULL AND ii.is_active = 1
                  ORDER BY ii.name");
            $q->execute([site_id(),
                         site_id(), $start, $end,
                         site_id(), $start, $end,
                         site_id(), $start, $end,
                         site_id(), $start, $end,
                         site_id()]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return ['rows' => [], 'error' => substr($e->getMessage(), 0, 160)];
        }

        $out = [];
        foreach ($rows as $r) {
            $remaining = (float)$r['on_hand'];
            /* Opening = ab jo para hai, minus jo is arse mein aaya,
               plus jo gaya. Yani peeche ki taraf hisab — kyunke stock
               ka "opening" kahin mehfooz nahi hota. */
            $opening = $remaining - (float)$r['added'] + (float)$r['sold']
                     - (float)$r['returned'] - (float)$r['adjusted'];
            $out[] = [
                'id'        => $r['id'],
                'code'      => (string)($r['sku'] ?? ''),
                'name'      => (string)$r['name'],
                'unit'      => (string)$r['unit'],
                'opening'   => round($opening, 3),
                'added'     => round((float)$r['added'], 3),
                'sold'      => round((float)$r['sold'], 3),
                'returned'  => round((float)$r['returned'], 3),
                'adjusted'  => round((float)$r['adjusted'], 3),
                'remaining' => round($remaining, 3),
            ];
        }
        return ['rows' => $out];
    }

    /* ==================== TABLET: HOLD BILLS ====================
       Har dine-in table, us par khula bill, aur us bill ka abhi ka total.
       Order taker ko yehi chahiye: "kis table par kitna ban gaya" —
       taake customer ke poochne par foran bata sake. */

    public static function tabletTables(): array
    {
        $q = DB::pdo()->prepare(
            "SELECT dt.id, dt.display_name AS name, dt.seats,
                    COALESCE(f.name,'Main Floor') AS floor,
                    o.id AS order_id, o.bill_no, o.subtotal, o.grand_total,
                    TIMESTAMPDIFF(MINUTE, COALESCE(o.opened_at,o.created_at), NOW()) AS mins,
                    (SELECT COUNT(*)  FROM order_items oi WHERE oi.order_id=o.id AND oi.status='ACTIVE') AS lines_count,
                    (SELECT COALESCE(SUM(oi.qty*oi.unit_price),0) FROM order_items oi
                      WHERE oi.order_id=o.id AND oi.status='ACTIVE') AS running,
                    (SELECT COALESCE(SUM(GREATEST(oi.qty-oi.sent_qty,0)),0) FROM order_items oi
                      WHERE oi.order_id=o.id AND oi.status='ACTIVE') AS unsent
               FROM dining_tables dt
               LEFT JOIN floors f ON f.id = dt.floor_id
               LEFT JOIN orders o ON o.table_id = dt.id AND o.order_status='OPEN'
              WHERE dt.tenant_id=? AND dt.site_id=? AND dt.deleted_at IS NULL AND dt.is_active=1
              ORDER BY f.sort_order, dt.display_name");
        $q->execute([tenant_id(), site_id()]);

        return array_map(fn($x) => [
            'id'       => $x['id'],
            'name'     => (string)$x['name'],
            'floor'    => (string)$x['floor'],
            'seats'    => (int)$x['seats'],
            'order_id' => (string)($x['order_id'] ?? ''),
            'bill'     => (string)($x['bill_no'] ?? ''),
            'items'    => (int)$x['lines_count'],
            'running'  => (float)$x['running'],
            'unsent'   => (float)$x['unsent'],
            'mins'     => (int)($x['mins'] ?? 0),
            'busy'     => !empty($x['order_id']),
        ], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Ek khule bill ki poori tafseel — tablet par table kholne par. */
    public static function tabletOrder(string $tableId): array
    {
        $p = DB::pdo();
        $q = $p->prepare("SELECT id, bill_no FROM orders
                           WHERE site_id=? AND table_id=? AND order_status='OPEN'
                           ORDER BY created_at DESC LIMIT 1");
        $q->execute([site_id(), $tableId]);
        $o = $q->fetch(PDO::FETCH_ASSOC);
        if (!$o) return ['order_id' => '', 'bill' => '', 'items' => []];

        $iq = $p->prepare(
            "SELECT oi.id, oi.menu_item_id, oi.item_name_snapshot AS name,
                    oi.qty, oi.sent_qty, oi.unit_price, oi.kitchen_note AS note
               FROM order_items oi
              WHERE oi.order_id=? AND oi.status='ACTIVE'
              ORDER BY oi.created_at");
        $iq->execute([$o['id']]);
        return ['order_id' => $o['id'], 'bill' => (string)$o['bill_no'],
                'items' => array_map(fn($x) => [
                    'id'    => $x['id'],
                    'menu_id' => (string)$x['menu_item_id'],
                    'name'  => (string)$x['name'],
                    'qty'   => (float)$x['qty'],
                    'sent'  => (float)$x['sent_qty'],
                    'price' => (float)$x['unit_price'],
                    'note'  => (string)($x['note'] ?? ''),
                ], $iq->fetchAll(PDO::FETCH_ASSOC))];
    }

    /* ==================== VOID / REFUND ==================== */

    /** Void ho chuke bills + wo bills jo abhi void kiye ja sakte hain. */
    public static function voidLog(string $from = '', string $to = ''): array
    {
        [$scopeW, $scopeA] = Scope::orderWhere('o');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-d', strtotime('-7 day'));
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to   : date('Y-m-d');

        $q = DB::pdo()->prepare(
            "SELECT o.id, o.bill_no, o.order_status, o.grand_total,
                    DATE(COALESCE(o.closed_at,o.created_at)) AS d,
                    COALESCE(u.full_name,'-') AS cashier,
                    COALESCE((SELECT dl.reason FROM deletion_log dl
                               WHERE dl.row_id = o.id AND dl.action='VOID'
                               ORDER BY dl.created_at DESC LIMIT 1),'') AS reason,
                    COALESCE((SELECT dl.actor_name FROM deletion_log dl
                               WHERE dl.row_id = o.id AND dl.action='VOID'
                               ORDER BY dl.created_at DESC LIMIT 1),'') AS voided_by
               FROM orders o
               LEFT JOIN users u ON u.id = o.created_by_user_id
              WHERE o.site_id = ?
                AND DATE(COALESCE(o.closed_at,o.created_at)) BETWEEN ? AND ?
                AND o.order_status IN ('VOID','CLOSED','PAID')
                AND $scopeW
              ORDER BY d DESC, o.bill_no DESC LIMIT 300");
        $q->execute(array_merge([site_id(), $from, $to], $scopeA));

        return array_map(fn($x) => [
            'id'        => $x['id'],
            'date'      => (string)$x['d'],
            'bill'      => (string)$x['bill_no'],
            'cashier'   => (string)$x['cashier'],
            'amount'    => (float)$x['grand_total'],
            'status'    => strtoupper((string)$x['order_status']) === 'VOID' ? 'Voided' : 'Closed',
            'reason'    => (string)$x['reason'],
            'voided_by' => (string)$x['voided_by'],
        ], $q->fetchAll(PDO::FETCH_ASSOC));
    }
}

// build: V70 build 2026-08-27
