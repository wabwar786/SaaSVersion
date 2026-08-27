<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * ReportService — business reports.
 *
 * Pehle `reports.html` ek khali shell tha: chart ka CSS mojood tha,
 * data koi nahi. Customer apna karobar dekh hi nahi sakta tha.
 *
 * Design ke do usool:
 *
 * 1. HAR report ek hi shakl lautati hai — {columns, rows, totals}.
 *    Is se ek hi UI sab ko dikha deti hai, aur CSV/PDF export bhi ek hi
 *    jagah likhna parta hai. Har report ka apna page banate to har naye
 *    report ke saath teen jagah code likhna parta.
 *
 * 2. Har raqam DATABASE se aati hai, JS mein hisab nahi hota. Bill ke
 *    totals aur report ke totals ka farq hi wo cheez hai jis se customer
 *    ka software par se etimad uth jata hai.
 *
 * Sab reports site-scoped aur date-range wali hain. VOID bills har jagah
 * se khaarij hain (warna sale asli se zyada dikhti hai).
 */
final class ReportService
{
    /** Fehrist — UI isi se menu banata hai. */
    public static function catalog(): array
    {
        return [
            ['id' => 'sales_summary',   'group' => 'Sales',     'name' => 'Sales summary',            'desc' => 'Day by day sales, tax, discount and net'],
            ['id' => 'sales_by_item',   'group' => 'Sales',     'name' => 'Sales by item',            'desc' => 'Which items sell the most'],
            ['id' => 'sales_by_category','group'=> 'Sales',     'name' => 'Sales by category',        'desc' => 'Category share of sales'],
            ['id' => 'sales_by_hour',   'group' => 'Sales',     'name' => 'Sales by hour',            'desc' => 'Busiest hours of the day'],
            ['id' => 'payment_mix',     'group' => 'Sales',     'name' => 'Payment methods',          'desc' => 'Cash vs card vs wallet'],
            ['id' => 'service_mode',    'group' => 'Sales',     'name' => 'Dine-in / takeaway / delivery', 'desc' => 'Sales split by service mode'],
            ['id' => 'fbr_sales',       'group' => 'Tax',       'name' => 'FBR / fiscal sales',       'desc' => 'Bills reported to FBR, and those still pending'],
            ['id' => 'tax_summary',     'group' => 'Tax',       'name' => 'Tax summary',              'desc' => 'Taxable value and tax collected'],
            ['id' => 'expenses',        'group' => 'Money',     'name' => 'Expenses',                 'desc' => 'Expenses by category'],
            ['id' => 'profit_loss',     'group' => 'Money',     'name' => 'Profit and loss',          'desc' => 'Sales, cost of sales, expenses and profit'],
            ['id' => 'staff_sales',     'group' => 'Operations','name' => 'Sales by cashier',         'desc' => 'Who took how much'],
            ['id' => 'table_sales',     'group' => 'Operations','name' => 'Sales by table',           'desc' => 'Table turnover and value'],
            ['id' => 'void_discount',   'group' => 'Operations','name' => 'Voids and discounts',      'desc' => 'Every voided bill and discount given'],
            ['id' => 'stock_movement',  'group' => 'Inventory', 'name' => 'Stock movement',           'desc' => 'What came in and what went out'],
            ['id' => 'purchases',       'group' => 'Inventory', 'name' => 'Purchases',                'desc' => 'Goods received by supplier'],
        ];
    }

    public static function has(string $id): bool
    {
        foreach (self::catalog() as $r) if ($r['id'] === $id) return true;
        return false;
    }

    /**
     * @return array{columns:array,rows:array,totals:array,title:string,note:string}
     */
    public static function run(string $id, string $from, string $to): array
    {
        $from = self::day($from, date('Y-m-01'));
        $to   = self::day($to, date('Y-m-d'));
        if ($from > $to) [$from, $to] = [$to, $from];

        $m = 'r_' . $id;
        if (!self::has($id) || !method_exists(self::class, $m)) {
            throw new \RuntimeException('Unknown report: ' . $id);
        }
        $out = self::$m($from, $to);
        $out['from'] = $from;
        $out['to']   = $to;
        return $out;
    }

    private static function day(string $v, string $fallback): string
    {
        $v = trim($v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $fallback;
    }

    /** Har report ke liye wahi bunyadi shart: is branch ke, VOID nahi. */
    private static function billWhere(string $alias = 'o'): string
    {
        return "$alias.site_id = ? AND $alias.order_status <> 'VOID'
                AND DATE(COALESCE($alias.closed_at, $alias.created_at)) BETWEEN ? AND ?";
    }

    private static function q(string $sql, array $args): array
    {
        try {
            $st = DB::pdo()->prepare($sql);
            $st->execute($args);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            /* Report ka sawaal hi ghalat ho to user ko wajah milni chahiye,
               khali table nahi. */
            throw new \RuntimeException('Report query failed: ' . substr($e->getMessage(), 0, 160));
        }
    }

    private static function shape(string $title, array $cols, array $rows, array $totals = [], string $note = ''): array
    {
        return ['title' => $title, 'columns' => $cols, 'rows' => $rows, 'totals' => $totals, 'note' => $note];
    }

    /* ==================== SALES ==================== */

    private static function r_sales_summary(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(COALESCE(o.closed_at,o.created_at)) d,
                    COUNT(*) bills,
                    SUM(o.subtotal) subtotal,
                    SUM(o.discount_amount) discount,
                    SUM(o.service_charge) service,
                    SUM(o.tax_amount) tax,
                    SUM(o.grand_total) total
               FROM orders o
              WHERE " . self::billWhere() . "
              GROUP BY d ORDER BY d",
            [site_id(), $f, $t]);

        return self::shape('Sales summary',
            [['k'=>'d','l'=>'Date'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'subtotal','l'=>'Subtotal','n'=>1],['k'=>'discount','l'=>'Discount','n'=>1],
             ['k'=>'service','l'=>'Service','n'=>1],['k'=>'tax','l'=>'Tax','n'=>1],
             ['k'=>'total','l'=>'Total','n'=>1]],
            $rows, self::sum($rows, ['bills','subtotal','discount','service','tax','total']));
    }

    private static function r_sales_by_item(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(oi.item_name_snapshot, mi.name, '(deleted item)') item,
                    COALESCE(mc.name,'-') category,
                    SUM(oi.qty) qty, SUM(oi.line_total) total
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
               LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
               LEFT JOIN menu_categories mc ON mc.id = mi.category_id
              WHERE " . self::billWhere() . "
              GROUP BY item, category ORDER BY total DESC",
            [site_id(), $f, $t]);

        return self::shape('Sales by item',
            [['k'=>'item','l'=>'Item'],['k'=>'category','l'=>'Category'],
             ['k'=>'qty','l'=>'Qty','n'=>1],['k'=>'total','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['qty','total']));
    }

    private static function r_sales_by_category(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(mc.name,'(uncategorised)') category,
                    SUM(oi.qty) qty, SUM(oi.line_total) total
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
               LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
               LEFT JOIN menu_categories mc ON mc.id = mi.category_id
              WHERE " . self::billWhere() . "
              GROUP BY category ORDER BY total DESC",
            [site_id(), $f, $t]);

        return self::shape('Sales by category',
            [['k'=>'category','l'=>'Category'],['k'=>'qty','l'=>'Qty','n'=>1],['k'=>'total','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['qty','total']));
    }

    private static function r_sales_by_hour(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT LPAD(HOUR(COALESCE(o.closed_at,o.created_at)),2,'0') hr,
                    COUNT(*) bills, SUM(o.grand_total) total
               FROM orders o
              WHERE " . self::billWhere() . "
              GROUP BY hr ORDER BY hr",
            [site_id(), $f, $t]);
        foreach ($rows as &$r) $r['hr'] = $r['hr'] . ':00';
        unset($r);

        return self::shape('Sales by hour',
            [['k'=>'hr','l'=>'Hour'],['k'=>'bills','l'=>'Bills','n'=>1],['k'=>'total','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['bills','total']), 'Use this to plan staff and kitchen prep.');
    }

    private static function r_payment_mix(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(pm.name,'(unknown)') method,
                    COUNT(*) payments, SUM(p.amount) total
               FROM payments p
               JOIN orders o ON o.id = p.order_id
               LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
              WHERE " . self::billWhere() . " AND p.status <> 'CANCELLED'
              GROUP BY method ORDER BY total DESC",
            [site_id(), $f, $t]);

        return self::shape('Payment methods',
            [['k'=>'method','l'=>'Method'],['k'=>'payments','l'=>'Count','n'=>1],['k'=>'total','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['payments','total']));
    }

    private static function r_service_mode(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT o.service_mode mode, COUNT(*) bills,
                    SUM(o.grand_total) total, ROUND(AVG(o.grand_total),2) avg_bill
               FROM orders o
              WHERE " . self::billWhere() . "
              GROUP BY mode ORDER BY total DESC",
            [site_id(), $f, $t]);
        $names = ['DINE_IN'=>'Dine In','TAKEAWAY'=>'Takeaway','TAKE_AWAY'=>'Takeaway','DELIVERY'=>'Delivery','QR'=>'QR Order'];
        foreach ($rows as &$r) $r['mode'] = $names[strtoupper((string)$r['mode'])] ?? $r['mode'];
        unset($r);

        return self::shape('Dine-in / takeaway / delivery',
            [['k'=>'mode','l'=>'Service mode'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'total','l'=>'Amount','n'=>1],['k'=>'avg_bill','l'=>'Average bill','n'=>1]],
            $rows, self::sum($rows, ['bills','total']));
    }

    /* ==================== TAX ==================== */

    private static function r_fbr_sales(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(COALESCE(o.closed_at,o.created_at)) d,
                    SUM(CASE WHEN o.fiscal_status='SENT'  THEN 1 ELSE 0 END) fbr_bills,
                    SUM(CASE WHEN o.fiscal_status='SENT'  THEN o.grand_total ELSE 0 END) fbr_amount,
                    SUM(CASE WHEN o.fiscal_status IN ('PENDING','FAILED') THEN 1 ELSE 0 END) pending_bills,
                    SUM(CASE WHEN o.fiscal_status IN ('PENDING','FAILED') THEN o.grand_total ELSE 0 END) pending_amount,
                    SUM(CASE WHEN o.fiscal_status IS NULL OR o.fiscal_status='NONE' THEN 1 ELSE 0 END) direct_bills,
                    SUM(CASE WHEN o.fiscal_status IS NULL OR o.fiscal_status='NONE' THEN o.grand_total ELSE 0 END) direct_amount
               FROM orders o
              WHERE " . self::billWhere() . "
              GROUP BY d ORDER BY d",
            [site_id(), $f, $t]);

        return self::shape('FBR / fiscal sales',
            [['k'=>'d','l'=>'Date'],
             ['k'=>'fbr_bills','l'=>'FBR bills','n'=>1],['k'=>'fbr_amount','l'=>'FBR amount','n'=>1],
             ['k'=>'pending_bills','l'=>'Pending','n'=>1],['k'=>'pending_amount','l'=>'Pending amount','n'=>1],
             ['k'=>'direct_bills','l'=>'Direct bills','n'=>1],['k'=>'direct_amount','l'=>'Direct amount','n'=>1]],
            $rows, self::sum($rows, ['fbr_bills','fbr_amount','pending_bills','pending_amount','direct_bills','direct_amount']),
            'Pending bills were not accepted by FBR yet. Retry them from Settings.');
    }

    private static function r_tax_summary(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(COALESCE(o.closed_at,o.created_at)) d,
                    COUNT(*) bills,
                    SUM(o.grand_total - o.tax_amount) taxable_value,
                    SUM(o.tax_amount) tax,
                    SUM(o.grand_total) total
               FROM orders o
              WHERE " . self::billWhere() . "
              GROUP BY d ORDER BY d",
            [site_id(), $f, $t]);

        return self::shape('Tax summary',
            [['k'=>'d','l'=>'Date'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'taxable_value','l'=>'Taxable value','n'=>1],['k'=>'tax','l'=>'Tax','n'=>1],
             ['k'=>'total','l'=>'Total','n'=>1]],
            $rows, self::sum($rows, ['bills','taxable_value','tax','total']));
    }

    /* ==================== MONEY ==================== */

    private static function r_expenses(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(ec.name,'(uncategorised)') category,
                    COUNT(*) entries, SUM(e.amount) total
               FROM expenses e
               LEFT JOIN expense_categories ec ON ec.id = e.category_id
              WHERE e.site_id = ? AND e.status <> 'REJECTED' AND e.deleted_at IS NULL
                AND DATE(e.expense_date) BETWEEN ? AND ?
              GROUP BY category ORDER BY total DESC",
            [site_id(), $f, $t]);

        return self::shape('Expenses',
            [['k'=>'category','l'=>'Category'],['k'=>'entries','l'=>'Entries','n'=>1],['k'=>'total','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['entries','total']));
    }

    private static function r_profit_loss(string $f, string $t): array
    {
        $sale = self::q("SELECT COALESCE(SUM(o.grand_total),0) v, COALESCE(SUM(o.tax_amount),0) tax,
                                COALESCE(SUM(o.discount_amount),0) disc
                           FROM orders o WHERE " . self::billWhere(),
                        [site_id(), $f, $t])[0] ?? ['v'=>0,'tax'=>0,'disc'=>0];

        /* Cost of sales: bill ke waqt jo stock consume hua uski lagat. */
        $cost = self::q(
            "SELECT COALESCE(SUM(ABS(stl.qty_change) * stl.unit_cost),0) v
               FROM stock_transactions st
               JOIN stock_transaction_lines stl ON stl.stock_transaction_id = st.id
               JOIN orders o ON o.id = st.reference_id
              WHERE st.reference_type='ORDER' AND stl.qty_change < 0
                AND " . self::billWhere(),
            [site_id(), $f, $t])[0]['v'] ?? 0;

        $exp = self::q("SELECT COALESCE(SUM(e.amount),0) v FROM expenses e
                         WHERE e.site_id=? AND e.status<>'REJECTED' AND e.deleted_at IS NULL
                           AND DATE(e.expense_date) BETWEEN ? AND ?",
                       [site_id(), $f, $t])[0]['v'] ?? 0;

        $net   = (float)$sale['v'] - (float)$sale['tax'];
        $gross = $net - (float)$cost;
        $profit= $gross - (float)$exp;

        $rows = [
            ['line'=>'Sales (incl. tax)',      'amount'=>(float)$sale['v']],
            ['line'=>'Less: sales tax',        'amount'=>-(float)$sale['tax']],
            ['line'=>'Net sales',              'amount'=>$net],
            ['line'=>'Less: cost of sales',    'amount'=>-(float)$cost],
            ['line'=>'Gross profit',           'amount'=>$gross],
            ['line'=>'Less: expenses',         'amount'=>-(float)$exp],
            ['line'=>'Net profit',             'amount'=>$profit],
        ];

        $note = 'Cost of sales comes from recipe consumption at the time of sale. '
              . 'Items without a recipe contribute no cost, so gross profit will look high for them.';
        if ((float)$cost <= 0) {
            $note = 'No recipe cost was recorded for this period, so cost of sales is zero. '
                  . 'Add recipes to your menu items to get a true profit figure.';
        }
        return self::shape('Profit and loss',
            [['k'=>'line','l'=>'Line'],['k'=>'amount','l'=>'Amount','n'=>1]],
            $rows, [], $note);
    }

    /* ==================== OPERATIONS ==================== */

    private static function r_staff_sales(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(u.full_name,'(unknown)') cashier,
                    COUNT(*) bills, SUM(o.grand_total) total,
                    ROUND(AVG(o.grand_total),2) avg_bill,
                    SUM(o.discount_amount) discount
               FROM orders o
               LEFT JOIN users u ON u.id = o.created_by_user_id
              WHERE " . self::billWhere() . "
              GROUP BY cashier ORDER BY total DESC",
            [site_id(), $f, $t]);

        return self::shape('Sales by cashier',
            [['k'=>'cashier','l'=>'Cashier'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'total','l'=>'Amount','n'=>1],['k'=>'avg_bill','l'=>'Average bill','n'=>1],
             ['k'=>'discount','l'=>'Discount given','n'=>1]],
            $rows, self::sum($rows, ['bills','total','discount']));
    }

    private static function r_table_sales(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(dt.display_name,'(no table)') tbl,
                    COUNT(*) bills, SUM(o.grand_total) total,
                    ROUND(AVG(o.grand_total),2) avg_bill
               FROM orders o
               LEFT JOIN dining_tables dt ON dt.id = o.table_id
              WHERE " . self::billWhere() . "
              GROUP BY tbl ORDER BY total DESC",
            [site_id(), $f, $t]);

        return self::shape('Sales by table',
            [['k'=>'tbl','l'=>'Table'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'total','l'=>'Amount','n'=>1],['k'=>'avg_bill','l'=>'Average bill','n'=>1]],
            $rows, self::sum($rows, ['bills','total']));
    }

    private static function r_void_discount(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(COALESCE(o.closed_at,o.created_at)) d, o.bill_no,
                    o.order_status status, o.discount_amount discount, o.grand_total total,
                    COALESCE(u.full_name,'-') cashier
               FROM orders o
               LEFT JOIN users u ON u.id = o.created_by_user_id
              WHERE o.site_id = ?
                AND DATE(COALESCE(o.closed_at,o.created_at)) BETWEEN ? AND ?
                AND (o.order_status='VOID' OR o.discount_amount > 0)
              ORDER BY d DESC, o.bill_no DESC",
            [site_id(), $f, $t]);

        return self::shape('Voids and discounts',
            [['k'=>'d','l'=>'Date'],['k'=>'bill_no','l'=>'Bill'],['k'=>'status','l'=>'Status'],
             ['k'=>'cashier','l'=>'Cashier'],['k'=>'discount','l'=>'Discount','n'=>1],
             ['k'=>'total','l'=>'Bill total','n'=>1]],
            $rows, self::sum($rows, ['discount','total']),
            'Watch this report for unusual discount patterns.');
    }

    /* ==================== INVENTORY ==================== */

    private static function r_stock_movement(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(ii.name,'(deleted item)') item,
                    SUM(CASE WHEN stl.qty_change > 0 THEN stl.qty_change ELSE 0 END) received,
                    SUM(CASE WHEN stl.qty_change < 0 THEN -stl.qty_change ELSE 0 END) issued,
                    SUM(stl.qty_change) net
               FROM stock_transaction_lines stl
               JOIN stock_transactions st ON st.id = stl.stock_transaction_id
               LEFT JOIN inventory_items ii ON ii.id = stl.inventory_item_id
              WHERE st.site_id = ? AND st.business_date BETWEEN ? AND ?
                    /* `created_at` is table par hai hi nahi — `business_date`
                       hi sahi column hai (aur indexed bhi). */
              GROUP BY item ORDER BY issued DESC",
            [site_id(), $f, $t]);

        return self::shape('Stock movement',
            [['k'=>'item','l'=>'Item'],['k'=>'received','l'=>'Received','n'=>1],
             ['k'=>'issued','l'=>'Issued','n'=>1],['k'=>'net','l'=>'Net change','n'=>1]],
            $rows, self::sum($rows, ['received','issued','net']));
    }

    private static function r_purchases(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(gr.received_at) d, gr.grn_no,
                    COALESCE(s.name,'(unknown)') supplier,
                    COUNT(gi.id) lines,
                    COALESCE(SUM(gi.purchase_qty * gi.unit_cost),0) total
               FROM goods_receipts gr
               LEFT JOIN suppliers s ON s.id = gr.supplier_id
               LEFT JOIN goods_receipt_items gi ON gi.goods_receipt_id = gr.id
              WHERE gr.site_id = ? AND gr.deleted_at IS NULL
                AND DATE(gr.received_at) BETWEEN ? AND ?
              GROUP BY gr.id ORDER BY d DESC",
            [site_id(), $f, $t]);

        return self::shape('Purchases',
            [['k'=>'d','l'=>'Date'],['k'=>'grn_no','l'=>'Receipt'],['k'=>'supplier','l'=>'Supplier'],
             ['k'=>'lines','l'=>'Lines','n'=>1],['k'=>'total','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['lines','total']));
    }

    /* ==================== helpers ==================== */

    private static function sum(array $rows, array $keys): array
    {
        $t = [];
        foreach ($keys as $k) {
            $t[$k] = 0;
            foreach ($rows as $r) $t[$k] += (float)($r[$k] ?? 0);
            $t[$k] = round($t[$k], 2);
        }
        return $t;
    }

    /** CSV — Excel ke liye BOM ke saath, warna Urdu/naam kharab dikhte hain. */
    public static function csv(array $rep): string
    {
        $out = "\xEF\xBB\xBF";
        $out .= '"' . str_replace('"', '""', $rep['title']) . '","'
              . $rep['from'] . ' to ' . $rep['to'] . "\"\n\n";
        $out .= implode(',', array_map(fn($c) => '"' . str_replace('"', '""', $c['l']) . '"', $rep['columns'])) . "\n";
        foreach ($rep['rows'] as $r) {
            $line = [];
            foreach ($rep['columns'] as $c) {
                $v = $r[$c['k']] ?? '';
                $line[] = isset($c['n']) ? (string)(float)$v : '"' . str_replace('"', '""', (string)$v) . '"';
            }
            $out .= implode(',', $line) . "\n";
        }
        if ($rep['totals']) {
            $line = [];
            foreach ($rep['columns'] as $i => $c) {
                if ($i === 0) { $line[] = '"TOTAL"'; continue; }
                $line[] = isset($rep['totals'][$c['k']]) ? (string)$rep['totals'][$c['k']] : '';
            }
            $out .= implode(',', $line) . "\n";
        }
        return $out;
    }
}

// build: V67 build 2026-08-27
