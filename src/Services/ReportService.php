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
            ['id' => 'tracked_inventory','group' => 'Inventory', 'name' => 'Tracked inventory',        'desc' => 'Only the items you chose to watch closely'],
            ['id' => 'low_stock',       'group' => 'Inventory', 'name' => 'Low stock',                'desc' => 'Items at or below their minimum level'],
            ['id' => 'credit_sales',    'group' => 'Money',     'name' => 'Credit / unpaid bills',    'desc' => 'Bills that were not fully paid'],
            ['id' => 'refunds',         'group' => 'Money',     'name' => 'Returns and refunds',      'desc' => 'Money paid back to customers'],
            ['id' => 'supplier_buys',   'group' => 'Inventory', 'name' => 'Supplier purchases',       'desc' => 'How much you buy from each supplier'],
            ['id' => 'purchases',       'group' => 'Inventory', 'name' => 'Purchases',                'desc' => 'Goods received by supplier'],

            /* ---- V98: jo reports customer maang raha tha aur maujood nahi thin ---- */
            ['id' => 'invoice_detail',  'group' => 'Sales',     'name' => 'Order / invoice detail',   'desc' => 'Har bill ka poora record — item, tax, discount, payment'],
            ['id' => 'waiter_sales',    'group' => 'Operations','name' => 'Sales by waiter',          'desc' => 'Waiter-wise sale, covers aur average bill'],
            ['id' => 'discounts',       'group' => 'Operations','name' => 'Discounts',                'desc' => 'Kis ne kitni chhoot di — user-wise'],
            ['id' => 'voids',           'group' => 'Operations','name' => 'Void / cancelled bills',   'desc' => 'Har void bill wajah aur user ke sath'],
            ['id' => 'shift_closing',   'group' => 'Operations','name' => 'Shift / day closing',      'desc' => 'Opening, sale, payments, expenses, variance'],
            ['id' => 'wastage',         'group' => 'Inventory', 'name' => 'Wastage / write-off',      'desc' => 'Kharab aur zaya hua maal, qeemat ke sath'],
            ['id' => 'purchase_items',  'group' => 'Inventory', 'name' => 'Purchases by item',        'desc' => 'Item-wise kharidari — qty, rate aur qeemat'],
            ['id' => 'customers',       'group' => 'Money',     'name' => 'Customers',                'desc' => 'Customer-wise bills, visits aur baqaya'],
            ['id' => 'fbr_reconcile',   'group' => 'Tax',       'name' => 'FBR reconciliation',       'desc' => 'POS ke bills vs FBR ko bheje gaye — farq kahan hai'],
            ['id' => 'fbr_summary',     'group' => 'Tax',       'name' => 'FBR daily / monthly summary','desc' => 'Invoices, taxable amount aur sales tax ka khulasa'],
            ['id' => 'audit_activity',  'group' => 'Operations','name' => 'Audit / activity log',     'desc' => 'Login, bill edit, void, discount, refund — kis ne kya kiya'],
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
    /**
     * Har report ki bunyadi shart — aur CASHIER ISOLATION bhi.
     *
     * V79 — pehle yahan sirf site aur date thi. Yani cashier Reports
     * khol kar poore branch ki sale dekh sakta tha. Ab `Scope` yahin
     * lagta hai, is liye har report par khud-ba-khud lagu hota hai —
     * har report mein alag se yaad rakhne ki zaroorat nahi.
     */
    private static function billWhere(string $alias = 'o'): string
    {
        [$w, $a] = Scope::orderWhere($alias);
        self::$scopeArgs = $a;
        return "$alias.site_id = ? AND $alias.order_status <> 'VOID'
                AND DATE(COALESCE($alias.closed_at, $alias.created_at)) BETWEEN ? AND ?
                AND $w";
    }

    /** billWhere() ke extra args — q() inhen khud jorta hai. */
    private static array $scopeArgs = [];

    private static function q(string $sql, array $args): array
    {
        /* billWhere() ne jo scope args rakhe, wo yahan judte hain. */
        if (self::$scopeArgs) { $args = array_merge($args, self::$scopeArgs); self::$scopeArgs = []; }
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

        /* V83 — PROFIT KA SACH SAAF LIKHO.
           Cost of sales recipe consumption se aata hai. Jin items ki
           recipe nahi, un ka cost SIFAR rehta hai — yani profit asal se
           ZYADA dikhta hai. Pehle report yeh baat aam alfaz mein kehti
           thi; ab GINTI ke saath batati hai, taake malik jaan sake ke
           yeh figure kitna qabil-e-etimad hai. */
        $cov = CatalogService::recipeCoverage();

        if ($cov['total'] === 0) {
            $note = 'No menu items yet, so there is nothing to cost.';
        } elseif ($cov['without_recipe'] === 0) {
            $note = 'All ' . $cov['total'] . ' menu items have a recipe, so this profit figure is reliable.';
        } else {
            $note = $cov['without_recipe'] . ' of ' . $cov['total'] . ' menu items have NO recipe ('
                  . (100 - $cov['pct']) . '%). Those items are counted as sales but cost nothing, '
                  . 'so the profit shown here is HIGHER than the real profit. '
                  . 'Add recipes to your top sellers first.';
        }

        /* Report ke andar bhi ek line — note nazar-andaz ho jata hai. */
        if ($cov['without_recipe'] > 0) {
            $rows[] = ['line' => '\u{2014} ' . $cov['without_recipe'] . ' item(s) without a recipe: cost not counted',
                       'amount' => 0];
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
                    /* `lines` MariaDB ka reserved word hai — bina backtick
                       ke poori query syntax error deti hai. */
                    COUNT(gi.id) `lines`,
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

    /* ==================== CUSTOM REPORTS ====================
       Customer apni marzi ki report bana sake — magar SQL likhe baghair.
       Khula SQL dena khatarnak hai (ek galti aur poora data jal jaye),
       is liye yahan ek MEHDOOD, mehfooz builder hai: chuni hui sources,
       chune hue fields. Har cheez server par ginti jati hai. */

    /** Kaunse data sources par report ban sakti hai. */
    /* ==================== V98 — NAYI REPORTS ====================
       Yeh wo reports hain jo restaurant version mein maujood nahi thin.
       Sab wahi `billWhere()` istemal karti hain, is liye cashier
       isolation aur date/branch filter khud-ba-khud lagte hain. */

    /** Har bill ka poora record — invoice-wise detail. */
    private static function r_invoice_detail(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(COALESCE(o.closed_at,o.created_at)) d, o.bill_no,
                    COALESCE(o.service_mode,'-') mode,
                    COALESCE(c.full_name,'Walk-in') customer,
                    COALESCE(u.full_name,'-') cashier,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id=o.id) items,
                    o.subtotal, o.discount_amount discount, o.tax_amount tax, o.grand_total total,
                    COALESCE(o.fiscal_invoice_no,'-') fbr_no,
                    COALESCE(o.order_status,'-') status
               FROM orders o
               LEFT JOIN users u ON u.id = o.created_by_user_id
               LEFT JOIN customers c ON c.id = o.customer_id
              WHERE " . self::billWhere() . "
              ORDER BY d DESC, o.bill_no DESC",
            [site_id(), $f, $t]);

        return self::shape('Order / invoice detail',
            [['k'=>'d','l'=>'Date'],['k'=>'bill_no','l'=>'Bill'],['k'=>'mode','l'=>'Mode'],
             ['k'=>'customer','l'=>'Customer'],['k'=>'cashier','l'=>'Cashier'],
             ['k'=>'items','l'=>'Items','n'=>1],['k'=>'subtotal','l'=>'Subtotal','n'=>1],
             ['k'=>'discount','l'=>'Discount','n'=>1],['k'=>'tax','l'=>'Tax','n'=>1],
             ['k'=>'total','l'=>'Total','n'=>1],['k'=>'fbr_no','l'=>'FBR no.']],
            $rows, self::sum($rows, ['items','subtotal','discount','tax','total']),
            'Ek hi jagah par har bill ka poora record.');
    }

    /** Waiter-wise sale. */
    private static function r_waiter_sales(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(u.full_name,'(assign nahi)') waiter,
                    COUNT(*) bills, SUM(o.grand_total) total,
                    ROUND(AVG(o.grand_total),2) avg_bill,
                    SUM(o.discount_amount) discount
               FROM orders o
               LEFT JOIN users u ON u.id = o.waiter_user_id
              WHERE " . self::billWhere() . "
              GROUP BY waiter ORDER BY total DESC",
            [site_id(), $f, $t]);

        return self::shape('Sales by waiter',
            [['k'=>'waiter','l'=>'Waiter'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'total','l'=>'Sale','n'=>1],['k'=>'avg_bill','l'=>'Avg bill','n'=>1],
             ['k'=>'discount','l'=>'Discount','n'=>1]],
            $rows, self::sum($rows, ['bills','total','discount']),
            'Avg bill upselling ka sab se seedha paimana hai.');
    }

    /** Sirf discounts — user-wise. */
    private static function r_discounts(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(COALESCE(o.closed_at,o.created_at)) d, o.bill_no,
                    COALESCE(u.full_name,'-') given_by,
                    o.subtotal, o.discount_amount discount,
                    ROUND(CASE WHEN o.subtotal>0 THEN o.discount_amount/o.subtotal*100 ELSE 0 END,1) pct,
                    o.grand_total total
               FROM orders o
               LEFT JOIN users u ON u.id = o.created_by_user_id
              WHERE " . self::billWhere() . " AND o.discount_amount > 0
              ORDER BY o.discount_amount DESC",
            [site_id(), $f, $t]);

        return self::shape('Discounts',
            [['k'=>'d','l'=>'Date'],['k'=>'bill_no','l'=>'Bill'],['k'=>'given_by','l'=>'Di kis ne'],
             ['k'=>'subtotal','l'=>'Subtotal','n'=>1],['k'=>'discount','l'=>'Discount','n'=>1],
             ['k'=>'pct','l'=>'%','n'=>1],['k'=>'total','l'=>'Bill total','n'=>1]],
            $rows, self::sum($rows, ['subtotal','discount','total']),
            'Bara discount % baar baar ek hi user ke naam ho to dekhna banta hai.');
    }

    /** Sirf void / cancelled bills — wajah aur user ke sath. */
    private static function r_voids(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(COALESCE(o.closed_at,o.created_at)) d, o.bill_no,
                    COALESCE(u.full_name,'-') by_user,
                    COALESCE(NULLIF(o.notes,''),
                             (SELECT a.description FROM audit_log a
                               WHERE a.record_id = o.id AND a.action LIKE '%VOID%'
                               ORDER BY a.created_at DESC LIMIT 1),
                             '-') reason,
                    o.grand_total total,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id=o.id) items
               FROM orders o
               LEFT JOIN users u ON u.id = o.created_by_user_id
              WHERE o.site_id = ?
                AND DATE(COALESCE(o.closed_at,o.created_at)) BETWEEN ? AND ?
                AND o.order_status = 'VOID'
              ORDER BY d DESC, o.bill_no DESC",
            [site_id(), $f, $t]);

        return self::shape('Void / cancelled bills',
            [['k'=>'d','l'=>'Date'],['k'=>'bill_no','l'=>'Bill'],['k'=>'by_user','l'=>'Kis ne'],
             ['k'=>'reason','l'=>'Wajah'],['k'=>'items','l'=>'Items','n'=>1],
             ['k'=>'total','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['items','total']),
            'Bina wajah ke void, ya ek hi user ke bohat se void — dono check karne layak hain.');
    }

    /** Shift / day closing — opening se variance tak. */
    private static function r_shift_closing(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT s.business_date d, s.shift_no, COALESCE(s.counter_name,'-') counter,
                    COALESCE(u.full_name,'-') cashier,
                    s.opening_cash, s.gross_sales, s.discount_total discount,
                    s.cash_sales, s.card_sales, s.credit_sales,
                    s.refund_total refunds, s.expense_total expenses,
                    s.expected_cash, s.actual_cash, s.variance_amount variance,
                    s.invoice_count bills, COALESCE(s.status,'-') status
               FROM cashier_shifts s
               LEFT JOIN users u ON u.id = s.cashier_user_id
              WHERE s.site_id = ? AND s.business_date BETWEEN ? AND ?
                AND s.deleted_at IS NULL
              ORDER BY s.business_date DESC, s.shift_no DESC",
            [site_id(), $f, $t]);

        return self::shape('Shift / day closing',
            [['k'=>'d','l'=>'Date'],['k'=>'shift_no','l'=>'Shift'],['k'=>'counter','l'=>'Counter'],
             ['k'=>'cashier','l'=>'Cashier'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'opening_cash','l'=>'Opening','n'=>1],['k'=>'gross_sales','l'=>'Sale','n'=>1],
             ['k'=>'discount','l'=>'Discount','n'=>1],['k'=>'cash_sales','l'=>'Cash','n'=>1],
             ['k'=>'card_sales','l'=>'Card','n'=>1],['k'=>'credit_sales','l'=>'Credit','n'=>1],
             ['k'=>'expenses','l'=>'Expenses','n'=>1],['k'=>'expected_cash','l'=>'Expected','n'=>1],
             ['k'=>'actual_cash','l'=>'Counted','n'=>1],['k'=>'variance','l'=>'Variance','n'=>1]],
            $rows, self::sum($rows, ['bills','opening_cash','gross_sales','discount','cash_sales',
                                     'card_sales','credit_sales','expenses','expected_cash',
                                     'actual_cash','variance']),
            'Variance hamesha sifar hona chahiye. Baar baar minus aana ek alamat hai.');
    }

    /** Wastage / write-off. */
    private static function r_wastage(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(a.requested_at) d, a.adjustment_no ref,
                    COALESCE(a.reason_code,'-') reason,
                    COALESCE(ii.name,'-') item,
                    ABS(ai.qty_change) qty,
                    ROUND(ABS(ai.qty_change) * COALESCE(ii.avg_cost_per_stock_unit,0),2) value,
                    COALESCE(u.full_name,'-') by_user,
                    COALESCE(a.status,'-') status
               FROM stock_adjustments a
               JOIN stock_adjustment_items ai ON ai.adjustment_id = a.id
               LEFT JOIN inventory_items ii ON ii.id = ai.inventory_item_id
               LEFT JOIN users u ON u.id = a.requested_by_user_id
              WHERE a.site_id = ? AND DATE(a.requested_at) BETWEEN ? AND ?
                AND ai.qty_change < 0
              ORDER BY d DESC",
            [site_id(), $f, $t]);

        return self::shape('Wastage / write-off',
            [['k'=>'d','l'=>'Date'],['k'=>'ref','l'=>'Reference'],['k'=>'item','l'=>'Item'],
             ['k'=>'reason','l'=>'Wajah'],['k'=>'qty','l'=>'Qty','n'=>1],
             ['k'=>'value','l'=>'Value','n'=>1],['k'=>'by_user','l'=>'Kis ne'],
             ['k'=>'status','l'=>'Status']],
            $rows, self::sum($rows, ['qty','value']),
            'Yeh seedha munafe se katta hai — har mahine ka rujhan dekhein.');
    }

    /** Item-wise kharidari. */
    private static function r_purchase_items(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(ii.name,'-') item,
                    SUM(gi.stock_qty_received) qty,
                    ROUND(AVG(gi.unit_cost),2) avg_rate,
                    SUM(gi.line_total) total,
                    COUNT(DISTINCT g.id) receipts
               FROM goods_receipts g
               JOIN goods_receipt_items gi ON gi.goods_receipt_id = g.id
               LEFT JOIN inventory_items ii ON ii.id = gi.inventory_item_id
              WHERE g.site_id = ? AND DATE(g.received_at) BETWEEN ? AND ?
                AND gi.deleted_at IS NULL
              GROUP BY item ORDER BY total DESC",
            [site_id(), $f, $t]);

        return self::shape('Purchases by item',
            [['k'=>'item','l'=>'Item'],['k'=>'receipts','l'=>'Receipts','n'=>1],
             ['k'=>'qty','l'=>'Qty','n'=>1],['k'=>'avg_rate','l'=>'Avg rate','n'=>1],
             ['k'=>'total','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['receipts','qty','total']),
            'Avg rate barhta hua nazar aaye to supplier se baat karne ka waqt hai.');
    }

    /** Customer-wise bills, visits aur baqaya. */
    private static function r_customers(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT c.full_name customer, COALESCE(c.phone,'-') phone,
                    COUNT(o.id) visits, SUM(o.grand_total) total,
                    ROUND(AVG(o.grand_total),2) avg_bill,
                    MAX(DATE(COALESCE(o.closed_at,o.created_at))) last_visit,
                    COALESCE(c.balance,0) outstanding
               FROM orders o
               JOIN customers c ON c.id = o.customer_id
              WHERE " . self::billWhere() . "
              GROUP BY c.id ORDER BY total DESC",
            [site_id(), $f, $t]);

        return self::shape('Customers',
            [['k'=>'customer','l'=>'Customer'],['k'=>'phone','l'=>'Phone'],
             ['k'=>'visits','l'=>'Visits','n'=>1],['k'=>'total','l'=>'Sale','n'=>1],
             ['k'=>'avg_bill','l'=>'Avg bill','n'=>1],['k'=>'last_visit','l'=>'Last visit'],
             ['k'=>'outstanding','l'=>'Baqaya','n'=>1]],
            $rows, self::sum($rows, ['visits','total','outstanding']),
            'Sirf wo bills jin par customer laga hua hai — walk-in yahan nahi aate.');
    }

    /** POS ke bills vs FBR ko bheje gaye — farq kahan hai. */
    private static function r_fbr_reconcile(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(COALESCE(o.closed_at,o.created_at)) d, o.bill_no,
                    o.grand_total total, o.tax_amount tax,
                    COALESCE(o.fiscal_status,'NONE') fbr_status,
                    COALESCE(o.fiscal_invoice_no,'-') fbr_no,
                    CASE
                      WHEN o.fiscal_status='SENT' AND (o.fiscal_invoice_no IS NULL OR o.fiscal_invoice_no='')
                        THEN 'SENT magar number nahi'
                      WHEN o.fiscal_status IN ('PENDING','FAILED') THEN 'FBR tak nahi pohancha'
                      WHEN o.fiscal_status='SENT' THEN 'Match'
                      ELSE 'FBR se bahar'
                    END verdict
               FROM orders o
              WHERE " . self::billWhere() . "
              ORDER BY (o.fiscal_status='SENT'), d DESC, o.bill_no DESC",
            [site_id(), $f, $t]);

        return self::shape('FBR reconciliation',
            [['k'=>'d','l'=>'Date'],['k'=>'bill_no','l'=>'POS bill'],
             ['k'=>'fbr_no','l'=>'FBR invoice'],['k'=>'fbr_status','l'=>'Status'],
             ['k'=>'verdict','l'=>'Nateeja'],['k'=>'tax','l'=>'Tax','n'=>1],
             ['k'=>'total','l'=>'Total','n'=>1]],
            $rows, self::sum($rows, ['tax','total']),
            'Jo bills "FBR tak nahi pohancha" dikhayen, unhein Tax screen se retry karein.');
    }

    /** FBR ka rozana / mahana khulasa. */
    private static function r_fbr_summary(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(COALESCE(o.closed_at,o.created_at)) d,
                    COUNT(*) bills,
                    SUM(CASE WHEN o.fiscal_status='SENT' THEN 1 ELSE 0 END) sent,
                    SUM(CASE WHEN o.fiscal_status IN ('PENDING','FAILED') THEN 1 ELSE 0 END) not_sent,
                    SUM(o.subtotal - o.discount_amount) taxable,
                    SUM(o.tax_amount) sales_tax,
                    SUM(CASE WHEN o.tax_amount = 0 THEN o.grand_total ELSE 0 END) zero_rated,
                    SUM(o.grand_total) total
               FROM orders o
              WHERE " . self::billWhere() . "
              GROUP BY d ORDER BY d DESC",
            [site_id(), $f, $t]);

        return self::shape('FBR daily / monthly summary',
            [['k'=>'d','l'=>'Date'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'sent','l'=>'FBR bheje','n'=>1],['k'=>'not_sent','l'=>'Reh gaye','n'=>1],
             ['k'=>'taxable','l'=>'Taxable','n'=>1],['k'=>'sales_tax','l'=>'Sales tax','n'=>1],
             ['k'=>'zero_rated','l'=>'Zero-rated','n'=>1],['k'=>'total','l'=>'Total','n'=>1]],
            $rows, self::sum($rows, ['bills','sent','not_sent','taxable','sales_tax','zero_rated','total']),
            'Mahane ke aakhir mein yehi aankray FBR return ke kaam aate hain.');
    }

    /** Audit / activity — kis ne kya kiya. */
    private static function r_audit_activity(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE_FORMAT(a.created_at,'%Y-%m-%d %H:%i') at,
                    COALESCE(a.username,'-') user,
                    COALESCE(a.role_name,'-') role,
                    COALESCE(a.action,'-') action,
                    COALESCE(a.module,'-') module,
                    COALESCE(a.record_label, a.description, '-') detail,
                    COALESCE(a.ip_address,'-') ip
               FROM audit_log a
              WHERE a.site_id = ? AND DATE(a.created_at) BETWEEN ? AND ?
              ORDER BY a.created_at DESC
              LIMIT 2000",
            [site_id(), $f, $t]);

        return self::shape('Audit / activity log',
            [['k'=>'at','l'=>'Kab'],['k'=>'user','l'=>'User'],['k'=>'role','l'=>'Role'],
             ['k'=>'action','l'=>'Action'],['k'=>'module','l'=>'Module'],
             ['k'=>'detail','l'=>'Detail'],['k'=>'ip','l'=>'IP']],
            $rows, [],
            'Void, discount, refund aur bill edit — sab yahan darj hote hain. 2000 se zyada nahi dikhaya jata.');
    }

    public static function sources(): array
    {
        return [
            'sales' => [
                'label' => 'Sales (bills)',
                'group_by' => [
                    'day'      => ['label'=>'Day',            'sql'=>"DATE(COALESCE(o.closed_at,o.created_at))"],
                    'month'    => ['label'=>'Month',          'sql'=>"DATE_FORMAT(COALESCE(o.closed_at,o.created_at),'%Y-%m')"],
                    'hour'     => ['label'=>'Hour of day',    'sql'=>"LPAD(HOUR(COALESCE(o.closed_at,o.created_at)),2,'0')"],
                    'weekday'  => ['label'=>'Day of week',    'sql'=>"DAYNAME(COALESCE(o.closed_at,o.created_at))"],
                    'mode'     => ['label'=>'Service mode',   'sql'=>"o.service_mode"],
                    'cashier'  => ['label'=>'Cashier',        'sql'=>"COALESCE(u.full_name,'(unknown)')"],
                    'table'    => ['label'=>'Table',          'sql'=>"COALESCE(dt.display_name,'(no table)')"],
                    'customer' => ['label'=>'Customer',       'sql'=>"COALESCE(c.full_name,'Walk-in')"],
                ],
                'measures' => [
                    'bills'    => ['label'=>'Bills',          'sql'=>"COUNT(DISTINCT o.id)"],
                    'sales'    => ['label'=>'Sales',          'sql'=>"SUM(o.grand_total)"],
                    'subtotal' => ['label'=>'Subtotal',       'sql'=>"SUM(o.subtotal)"],
                    'tax'      => ['label'=>'Tax',            'sql'=>"SUM(o.tax_amount)"],
                    'discount' => ['label'=>'Discount',       'sql'=>"SUM(o.discount_amount)"],
                    'avg_bill' => ['label'=>'Average bill',   'sql'=>"ROUND(AVG(o.grand_total),2)"],
                ],
            ],
            'items' => [
                'label' => 'Items sold',
                'group_by' => [
                    'item'     => ['label'=>'Item',      'sql'=>"COALESCE(oi.item_name_snapshot,mi.name,'(deleted)')"],
                    'category' => ['label'=>'Category',  'sql'=>"COALESCE(mc.name,'(uncategorised)')"],
                    'day'      => ['label'=>'Day',       'sql'=>"DATE(COALESCE(o.closed_at,o.created_at))"],
                    'month'    => ['label'=>'Month',     'sql'=>"DATE_FORMAT(COALESCE(o.closed_at,o.created_at),'%Y-%m')"],
                ],
                'measures' => [
                    'qty'      => ['label'=>'Quantity',  'sql'=>"SUM(oi.qty)"],
                    'sales'    => ['label'=>'Sales',     'sql'=>"SUM(oi.line_total)"],
                    'lines'    => ['label'=>'Times sold','sql'=>"COUNT(*)"],
                    'avg_rate' => ['label'=>'Average rate','sql'=>"ROUND(AVG(oi.unit_price),2)"],
                ],
            ],
            'expenses' => [
                'label' => 'Expenses',
                'group_by' => [
                    'category' => ['label'=>'Category','sql'=>"COALESCE(ec.name,'(uncategorised)')"],
                    'day'      => ['label'=>'Day',     'sql'=>"DATE(e.expense_date)"],
                    'month'    => ['label'=>'Month',   'sql'=>"DATE_FORMAT(e.expense_date,'%Y-%m')"],
                ],
                'measures' => [
                    'entries' => ['label'=>'Entries','sql'=>"COUNT(*)"],
                    'amount'  => ['label'=>'Amount', 'sql'=>"SUM(e.amount)"],
                ],
            ],
        ];
    }

    private static function fromSql(string $src): string
    {
        return match ($src) {
            'items' => "FROM order_items oi
                        JOIN orders o ON o.id = oi.order_id
                        LEFT JOIN menu_items mi      ON mi.id = oi.menu_item_id
                        LEFT JOIN menu_categories mc ON mc.id = mi.category_id
                       WHERE " . self::billWhere(),
            'expenses' => "FROM expenses e
                           LEFT JOIN expense_categories ec ON ec.id = e.category_id
                          WHERE e.site_id = ? AND e.status <> 'REJECTED' AND e.deleted_at IS NULL
                            AND DATE(e.expense_date) BETWEEN ? AND ?",
            default => "FROM orders o
                        LEFT JOIN users u          ON u.id  = o.created_by_user_id
                        LEFT JOIN dining_tables dt ON dt.id = o.table_id
                        LEFT JOIN customers c      ON c.id  = o.customer_id
                       WHERE " . self::billWhere(),
        };
    }

    /**
     * Customer ka apna report chalao.
     * @param array $spec ['source','group','measures'=>[],'sort','limit']
     */
    public static function custom(array $spec, string $from, string $to): array
    {
        $from = self::day($from, date('Y-m-01'));
        $to   = self::day($to, date('Y-m-d'));
        if ($from > $to) [$from, $to] = [$to, $from];

        $srcKey = (string)($spec['source'] ?? 'sales');
        $all = self::sources();
        if (!isset($all[$srcKey])) throw new \RuntimeException('Unknown data source.');
        $src = $all[$srcKey];

        $gKey = (string)($spec['group'] ?? array_key_first($src['group_by']));
        if (!isset($src['group_by'][$gKey])) throw new \RuntimeException('Unknown grouping.');

        $wanted = array_values(array_filter((array)($spec['measures'] ?? []),
            fn($m) => isset($src['measures'][$m])));
        if (!$wanted) $wanted = [array_key_first($src['measures'])];
        if (count($wanted) > 6) $wanted = array_slice($wanted, 0, 6);

        /* Sab kuch registry se aata hai — user ka koi harf SQL mein nahi
           jata. Isi liye yeh mehfooz hai. */
        $cols = [['k' => 'g', 'l' => $src['group_by'][$gKey]['label']]];
        $sel  = [$src['group_by'][$gKey]['sql'] . ' AS g'];
        foreach ($wanted as $m) {
            $sel[]  = $src['measures'][$m]['sql'] . ' AS ' . $m;
            $cols[] = ['k' => $m, 'l' => $src['measures'][$m]['label'], 'n' => 1];
        }

        $sortKey = (string)($spec['sort'] ?? $wanted[0]);
        if (!in_array($sortKey, $wanted, true) && $sortKey !== 'g') $sortKey = $wanted[0];
        $order = $sortKey === 'g' ? 'g ASC' : ($sortKey . ' DESC');
        $limit = max(5, min(500, (int)($spec['limit'] ?? 100)));

        $sql = 'SELECT ' . implode(', ', $sel) . ' ' . self::fromSql($srcKey)
             . ' GROUP BY g ORDER BY ' . $order . ' LIMIT ' . $limit;

        $rows = self::q($sql, [site_id(), $from, $to]);

        $title = 'Custom: ' . $src['label'] . ' by ' . $src['group_by'][$gKey]['label'];
        return self::shape($title, $cols, $rows, self::sum($rows, $wanted),
            'Your own report. Change the source, grouping or figures and run it again.')
            + ['from' => $from, 'to' => $to];
    }

    /* -------- V78: spec ke baqi reports -------- */

    private static function r_tracked_inventory(string $f, string $t): array
    {
        $d = OpsService::trackedInventory($f, $t);
        $rows = $d['rows'] ?? [];
        return self::shape('Tracked inventory',
            [['k'=>'name','l'=>'Item'],['k'=>'unit','l'=>'Unit'],
             ['k'=>'opening','l'=>'Opening','n'=>1],['k'=>'added','l'=>'Added','n'=>1],
             ['k'=>'sold','l'=>'Sold','n'=>1],['k'=>'returned','l'=>'Returned','n'=>1],
             ['k'=>'adjusted','l'=>'Adjusted','n'=>1],['k'=>'remaining','l'=>'Remaining','n'=>1]],
            $rows, self::sum($rows, ['opening','added','sold','returned','adjusted','remaining']),
            $rows ? 'Only items marked as tracked appear here. Turn tracking on from Inventory.'
                  : 'No items are marked as tracked yet. Open Inventory and switch tracking on for the items you want to watch.');
    }

    private static function r_low_stock(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT ii.name AS item, COALESCE(ic.name,'-') AS category,
                    COALESCE(u.code,'') AS unit,
                    ii.reorder_level AS minimum,
                    COALESCE((SELECT SUM(sb.qty_on_hand) FROM stock_balances sb
                               WHERE sb.inventory_item_id = ii.id AND sb.site_id = ?),0) AS on_hand
               FROM inventory_items ii
               LEFT JOIN inventory_categories ic ON ic.id = ii.category_id
               LEFT JOIN units u ON u.id = ii.stock_unit_id
              WHERE ii.site_id = ? AND ii.is_active = 1 AND ii.deleted_at IS NULL
                AND ii.reorder_level > 0
             HAVING on_hand <= minimum
              ORDER BY (minimum - on_hand) DESC",
            [site_id(), site_id()]);
        /* Yeh report date range par nahi chalti — stock ki halat ABHI ki
           hoti hai. Note se yeh baat saaf rehni chahiye. */
        return self::shape('Low stock',
            [['k'=>'item','l'=>'Item'],['k'=>'category','l'=>'Category'],['k'=>'unit','l'=>'Unit'],
             ['k'=>'on_hand','l'=>'In stock','n'=>1],['k'=>'minimum','l'=>'Minimum','n'=>1]],
            $rows, [], 'This shows stock as it is right now, so the date range does not apply.');
    }

    private static function r_credit_sales(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(COALESCE(o.closed_at,o.created_at)) d, o.bill_no,
                    COALESCE(c.full_name,'Walk-in') customer, COALESCE(c.phone,'') phone,
                    o.grand_total total, COALESCE(o.paid_amount,0) paid,
                    (o.grand_total - COALESCE(o.paid_amount,0)) due
               FROM orders o
               LEFT JOIN customers c ON c.id = o.customer_id
              WHERE " . self::billWhere() . "
                AND (o.grand_total - COALESCE(o.paid_amount,0)) > 0.009
              ORDER BY d DESC",
            [site_id(), $f, $t]);
        return self::shape('Credit / unpaid bills',
            [['k'=>'d','l'=>'Date'],['k'=>'bill_no','l'=>'Bill'],['k'=>'customer','l'=>'Customer'],
             ['k'=>'phone','l'=>'Phone'],['k'=>'total','l'=>'Bill total','n'=>1],
             ['k'=>'paid','l'=>'Paid','n'=>1],['k'=>'due','l'=>'Still due','n'=>1]],
            $rows, self::sum($rows, ['total','paid','due']),
            'Chase the biggest amounts first. Phone numbers are shown so you can call.');
    }

    private static function r_refunds(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(COALESCE(o.closed_at,o.created_at)) d, o.bill_no,
                    o.grand_total amount, COALESCE(u.full_name,'-') cashier,
                    COALESCE((SELECT dl.reason FROM deletion_log dl
                               WHERE dl.row_id=o.id AND dl.action='VOID'
                               ORDER BY dl.created_at DESC LIMIT 1),'') reason
               FROM orders o
               LEFT JOIN users u ON u.id = o.created_by_user_id
              WHERE o.site_id=? AND o.order_status='VOID'
                AND DATE(COALESCE(o.closed_at,o.created_at)) BETWEEN ? AND ?
              ORDER BY d DESC",
            [site_id(), $f, $t]);
        return self::shape('Returns and refunds',
            [['k'=>'d','l'=>'Date'],['k'=>'bill_no','l'=>'Bill'],['k'=>'cashier','l'=>'Cashier'],
             ['k'=>'reason','l'=>'Reason'],['k'=>'amount','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['amount']),
            'A rising number of voids is worth looking into.');
    }

    private static function r_supplier_buys(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(s.name,'(unknown)') supplier, COALESCE(s.phone,'') phone,
                    COUNT(DISTINCT gr.id) receipts,
                    COALESCE(SUM(gi.purchase_qty * gi.unit_cost),0) amount
               FROM goods_receipts gr
               LEFT JOIN suppliers s ON s.id = gr.supplier_id
               LEFT JOIN goods_receipt_items gi ON gi.goods_receipt_id = gr.id
              WHERE gr.site_id=? AND gr.deleted_at IS NULL
                AND DATE(gr.received_at) BETWEEN ? AND ?
              GROUP BY supplier, phone
              ORDER BY amount DESC",
            [site_id(), $f, $t]);
        return self::shape('Supplier purchases',
            [['k'=>'supplier','l'=>'Supplier'],['k'=>'phone','l'=>'Phone'],
             ['k'=>'receipts','l'=>'Receipts','n'=>1],['k'=>'amount','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['receipts','amount']));
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
