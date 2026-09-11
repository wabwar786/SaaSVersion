<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * RetailReportService — supermarket ki reports.
 *
 * Restaurant ka `ReportService` `orders`/`order_items` par chalta hai.
 * Retail ka apna data model hai (`rtl_sales`, `rtl_sale_items`,
 * `rtl_products`, `rtl_batches`...), is liye alag service — magar
 * bilkul wahi shakl (`catalog()` / `run()` / `csv()`), taake UI aur
 * CSV export dono ke liye ek hi code chale.
 *
 * USOOL: har query mein `tenant_id` aur `site_id` — ek branch ka data
 * doosre ko kabhi nazar na aaye.
 */
final class RetailReportService
{
    public static function catalog(): array
    {
        return [
            /* ---------------- SALE ---------------- */
            ['id'=>'sales_summary',   'group'=>'Sales',    'name'=>'Daily sales summary',   'desc'=>'Daily bills, gross, discount, tax and net'],
            ['id'=>'sales_by_item',   'group'=>'Sales',    'name'=>'Sales by item',         'desc'=>'Quantity and amount per item'],
            ['id'=>'sales_by_dept',   'group'=>'Sales',    'name'=>'Sales by department',   'desc'=>'Grocery, Bakery, Beverages waghera'],
            ['id'=>'sales_by_brand',  'group'=>'Sales',    'name'=>'Sales by brand',        'desc'=>'How much each brand sells'],
            ['id'=>'sales_by_hour',   'group'=>'Sales',    'name'=>'Sales by hour',         'desc'=>'When the rush happens — useful for staffing'],
            ['id'=>'payment_mix',     'group'=>'Sales',    'name'=>'Payment / collection',  'desc'=>'Cash, card, mixed and credit'],
            ['id'=>'price_level',     'group'=>'Sales',    'name'=>'Retail vs wholesale',   'desc'=>'Dono price levels ka muqabla'],
            ['id'=>'invoice_detail',  'group'=>'Sales',    'name'=>'Invoice detail',        'desc'=>'Har bill ka poora record'],
            ['id'=>'cashier_sales',   'group'=>'Sales',    'name'=>'Sales by cashier',      'desc'=>'Kis cashier ne kitna kaata'],
            ['id'=>'counter_sales',   'group'=>'Sales',    'name'=>'Sales by counter',      'desc'=>'Sales and bills per counter'],
            ['id'=>'basket',          'group'=>'Sales',    'name'=>'Basket analysis',       'desc'=>'Average basket, items per bill'],

            /* ---------------- OPERATIONS ---------------- */
            ['id'=>'reprints',        'group'=>'Operations','name'=>'Duplicate bill (reprints)','desc'=>'Kis bill ki kitni copies, kis ne nikalin'],
            ['id'=>'audit_activity',  'group'=>'Operations','name'=>'Audit / activity log',  'desc'=>'Sign-ins, voids, discounts, reprints — who did what'],
            ['id'=>'held_bills',      'group'=>'Operations','name'=>'Held / parked bills',   'desc'=>'Jo bills counter par rakhi reh gayin'],

            /* ---------------- TAX ---------------- */
            ['id'=>'tax_summary',     'group'=>'Tax',      'name'=>'Tax summary',           'desc'=>'Taxable value, tax and zero-rated sales'],
            ['id'=>'fbr_reconcile',   'group'=>'Tax',      'name'=>'FBR reconciliation',    'desc'=>'POS bills vs FBR ko bheje gaye'],

            /* ---------------- INVENTORY ---------------- */
            ['id'=>'stock_on_hand',   'group'=>'Inventory','name'=>'Stock on hand',         'desc'=>'Current stock and its cost value'],
            ['id'=>'low_stock',       'group'=>'Inventory','name'=>'Low stock / reorder',   'desc'=>'What needs to be ordered now'],
            ['id'=>'dead_stock',      'group'=>'Inventory','name'=>'Dead stock',            'desc'=>'Never sold — this is where cash is stuck'],
            ['id'=>'expiry',          'group'=>'Inventory','name'=>'Expiry / near expiry',  'desc'=>'Which batch expires when'],
            ['id'=>'batch_stock',     'group'=>'Inventory','name'=>'Batch-wise stock',      'desc'=>'Remaining stock and value per batch'],
            ['id'=>'fast_slow',       'group'=>'Inventory','name'=>'Fast / slow movers',    'desc'=>'What sells fast and what does not'],
            ['id'=>'margin',          'group'=>'Inventory','name'=>'Margin by item',        'desc'=>'Profit and margin % per item'],

            /* ---------------- MONEY ---------------- */
            ['id'=>'profit_margin',   'group'=>'Money',    'name'=>'Profit / margin',       'desc'=>'Sale vs cost — rozana munafa'],
            ['id'=>'expenses',        'group'=>'Money',    'name'=>'Expenses',              'desc'=>'Category-wise kharche'],
            ['id'=>'khata',           'group'=>'Money',    'name'=>'Khata / receivable',    'desc'=>'Who owes how much, and their limit'],
            ['id'=>'khata_ledger',    'group'=>'Money',    'name'=>'Khata ledger',          'desc'=>'Every credit bill and every recovery'],
            ['id'=>'credit_sales',    'group'=>'Money',    'name'=>'Credit sales',          'desc'=>'Udhaar par bike hue bills'],

            /* ---------------- CUSTOMERS ---------------- */
            ['id'=>'customers',       'group'=>'Customers','name'=>'Customer report',       'desc'=>'Bills, visits, last visit and outstanding'],
            ['id'=>'loyalty',         'group'=>'Customers','name'=>'Loyalty',               'desc'=>'Points and tiers'],
        ];
    }

    public static function has(string $id): bool
    {
        foreach (self::catalog() as $c) if ($c['id'] === $id) return true;
        return false;
    }

    public static function run(string $id, string $from, string $to): array
    {
        $from = self::day($from, \date('Y-m-01'));
        $to   = self::day($to, \date('Y-m-d'));
        if ($from > $to) [$from, $to] = [$to, $from];

        $m = 'r_' . $id;
        if (!self::has($id) || !\method_exists(self::class, $m)) {
            throw new \RuntimeException('Unknown report: ' . $id);
        }
        $out = self::$m($from, $to);
        $out['from'] = $from;
        $out['to']   = $to;
        return $out;
    }

    private static function day(string $v, string $fallback): string
    {
        $v = \trim($v);
        return \preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $fallback;
    }

    /** Har sale report ki bunyadi shart — branch + date + void nahi. */
    private static function billWhere(string $a = 's'): string
    {
        return "$a.tenant_id = ? AND $a.site_id = ? AND $a.deleted_at IS NULL
                AND $a.status <> 'Refunded'
                AND DATE($a.sold_at) BETWEEN ? AND ?";
    }
    private static function billArgs(string $f, string $t): array
    {
        return [tenant_id(), site_id(), $f, $t];
    }

    private static function q(string $sql, array $args): array
    {
        try {
            $st = DB::pdo()->prepare($sql);
            $st->execute($args);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Report query failed: ' . $e->getMessage());
        }
    }

    private static function shape(string $title, array $cols, array $rows, array $totals = [], string $note = ''): array
    {
        return ['title'=>$title, 'columns'=>$cols, 'rows'=>$rows, 'totals'=>$totals, 'note'=>$note];
    }

    private static function sum(array $rows, array $keys): array
    {
        $t = [];
        foreach ($keys as $k) {
            $t[$k] = 0;
            foreach ($rows as $r) $t[$k] += (float)($r[$k] ?? 0);
            $t[$k] = \round($t[$k], 2);
        }
        return $t;
    }

    /* ==================== SALES ==================== */

    private static function r_sales_summary(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(s.sold_at) d, COUNT(*) bills,
                    SUM(s.subtotal) gross, SUM(s.discount) discount,
                    SUM(s.tax_amount) tax, SUM(s.total) net,
                    SUM(s.paid_cash) cash, SUM(s.paid_card) card,
                    SUM(CASE WHEN s.status='Credit' THEN s.total ELSE 0 END) credit
               FROM rtl_sales s
              WHERE " . self::billWhere() . "
              GROUP BY d ORDER BY d DESC",
            self::billArgs($f, $t));

        return self::shape('Daily sales summary',
            [['k'=>'d','l'=>'Date'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'gross','l'=>'Gross','n'=>1],['k'=>'discount','l'=>'Discount','n'=>1],
             ['k'=>'tax','l'=>'Tax','n'=>1],['k'=>'net','l'=>'Net sale','n'=>1],
             ['k'=>'cash','l'=>'Cash','n'=>1],['k'=>'card','l'=>'Card','n'=>1],
             ['k'=>'credit','l'=>'Khata','n'=>1]],
            $rows, self::sum($rows, ['bills','gross','discount','tax','net','cash','card','credit']),
            'Net sale is what actually reaches your pocket.');
    }

    private static function r_sales_by_item(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT i.product_name item,
                    SUM(i.qty) qty, SUM(i.line_total) amount,
                    COUNT(DISTINCT s.id) bills,
                    ROUND(AVG(i.unit_price),2) avg_rate
               FROM rtl_sale_items i
               JOIN rtl_sales s ON s.id = i.sale_id
              WHERE " . self::billWhere() . "
              GROUP BY item ORDER BY amount DESC",
            self::billArgs($f, $t));

        return self::shape('Sales by item',
            [['k'=>'item','l'=>'Item'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'qty','l'=>'Qty','n'=>1],['k'=>'avg_rate','l'=>'Avg rate','n'=>1],
             ['k'=>'amount','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['bills','qty','amount']));
    }

    private static function r_sales_by_dept(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(d.name,'(no department)') department,
                    SUM(i.qty) qty, SUM(i.line_total) amount, COUNT(DISTINCT s.id) bills
               FROM rtl_sale_items i
               JOIN rtl_sales s ON s.id = i.sale_id
               LEFT JOIN rtl_products p ON p.id = i.product_id
               LEFT JOIN rtl_departments d ON d.id = p.department_id
              WHERE " . self::billWhere() . "
              GROUP BY department ORDER BY amount DESC",
            self::billArgs($f, $t));

        return self::shape('Sales by department',
            [['k'=>'department','l'=>'Department'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'qty','l'=>'Qty','n'=>1],['k'=>'amount','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['bills','qty','amount']));
    }

    private static function r_sales_by_brand(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(b.name,'(no brand)') brand,
                    SUM(i.qty) qty, SUM(i.line_total) amount
               FROM rtl_sale_items i
               JOIN rtl_sales s ON s.id = i.sale_id
               LEFT JOIN rtl_products p ON p.id = i.product_id
               LEFT JOIN rtl_brands b ON b.id = p.brand_id
              WHERE " . self::billWhere() . "
              GROUP BY brand ORDER BY amount DESC",
            self::billArgs($f, $t));

        return self::shape('Sales by brand',
            [['k'=>'brand','l'=>'Brand'],['k'=>'qty','l'=>'Qty','n'=>1],
             ['k'=>'amount','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['qty','amount']));
    }

    private static function r_sales_by_hour(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT LPAD(HOUR(s.sold_at),2,'0') hour_of_day,
                    COUNT(*) bills, SUM(s.total) amount,
                    ROUND(AVG(s.total),2) avg_bill
               FROM rtl_sales s
              WHERE " . self::billWhere() . "
              GROUP BY hour_of_day ORDER BY hour_of_day",
            self::billArgs($f, $t));

        return self::shape('Sales by hour',
            [['k'=>'hour_of_day','l'=>'Hour'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'amount','l'=>'Amount','n'=>1],['k'=>'avg_bill','l'=>'Avg bill','n'=>1]],
            $rows, self::sum($rows, ['bills','amount']),
            'Jis waqt all se zyada bills banen, wahan counter barhayein.');
    }

    private static function r_payment_mix(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT s.payment_method method, COUNT(*) bills,
                    SUM(s.total) amount, SUM(s.paid_cash) cash, SUM(s.paid_card) card
               FROM rtl_sales s
              WHERE " . self::billWhere() . "
              GROUP BY method ORDER BY amount DESC",
            self::billArgs($f, $t));

        return self::shape('Payment / collection',
            [['k'=>'method','l'=>'Method'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'cash','l'=>'Cash','n'=>1],['k'=>'card','l'=>'Card','n'=>1],
             ['k'=>'amount','l'=>'Total','n'=>1]],
            $rows, self::sum($rows, ['bills','cash','card','amount']));
    }

    private static function r_price_level(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT s.price_level, COUNT(*) bills, SUM(s.total) amount,
                    ROUND(AVG(s.total),2) avg_bill, SUM(s.discount) discount
               FROM rtl_sales s
              WHERE " . self::billWhere() . "
              GROUP BY s.price_level ORDER BY amount DESC",
            self::billArgs($f, $t));

        return self::shape('Retail vs wholesale',
            [['k'=>'price_level','l'=>'Price level'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'amount','l'=>'Amount','n'=>1],['k'=>'avg_bill','l'=>'Avg bill','n'=>1],
             ['k'=>'discount','l'=>'Discount','n'=>1]],
            $rows, self::sum($rows, ['bills','amount','discount']));
    }

    private static function r_invoice_detail(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(s.sold_at) d, s.bill_no, s.counter_name counter,
                    s.cashier_name cashier, s.customer_name customer,
                    s.price_level, s.line_count items,
                    s.subtotal, s.discount, s.tax_amount tax, s.total,
                    s.payment_method method, s.status,
                    s.reprint_count reprints
               FROM rtl_sales s
              WHERE " . self::billWhere() . "
              ORDER BY s.sold_at DESC",
            self::billArgs($f, $t));

        return self::shape('Invoice detail',
            [['k'=>'d','l'=>'Date'],['k'=>'bill_no','l'=>'Bill'],['k'=>'counter','l'=>'Counter'],
             ['k'=>'cashier','l'=>'Cashier'],['k'=>'customer','l'=>'Customer'],
             ['k'=>'items','l'=>'Items','n'=>1],['k'=>'discount','l'=>'Discount','n'=>1],
             ['k'=>'tax','l'=>'Tax','n'=>1],['k'=>'total','l'=>'Total','n'=>1],
             ['k'=>'method','l'=>'Payment'],['k'=>'reprints','l'=>'Copies','n'=>1]],
            $rows, self::sum($rows, ['items','discount','tax','total','reprints']));
    }

    private static function r_cashier_sales(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(NULLIF(s.cashier_name,''),'-') cashier,
                    COUNT(*) bills, SUM(s.total) amount,
                    ROUND(AVG(s.total),2) avg_bill,
                    SUM(s.discount) discount,
                    SUM(s.reprint_count) reprints
               FROM rtl_sales s
              WHERE " . self::billWhere() . "
              GROUP BY cashier ORDER BY amount DESC",
            self::billArgs($f, $t));

        return self::shape('Sales by cashier',
            [['k'=>'cashier','l'=>'Cashier'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'amount','l'=>'Sale','n'=>1],['k'=>'avg_bill','l'=>'Avg bill','n'=>1],
             ['k'=>'discount','l'=>'Discount','n'=>1],['k'=>'reprints','l'=>'Reprints','n'=>1]],
            $rows, self::sum($rows, ['bills','amount','discount','reprints']),
            'High discounts and reprints on one cashier are worth a look.');
    }

    private static function r_counter_sales(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(NULLIF(s.counter_name,''),'-') counter,
                    COUNT(*) bills, SUM(s.total) amount,
                    SUM(s.paid_cash) cash, SUM(s.paid_card) card
               FROM rtl_sales s
              WHERE " . self::billWhere() . "
              GROUP BY counter ORDER BY amount DESC",
            self::billArgs($f, $t));

        return self::shape('Sales by counter',
            [['k'=>'counter','l'=>'Counter'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'cash','l'=>'Cash','n'=>1],['k'=>'card','l'=>'Card','n'=>1],
             ['k'=>'amount','l'=>'Total','n'=>1]],
            $rows, self::sum($rows, ['bills','cash','card','amount']));
    }

    private static function r_basket(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(s.sold_at) d, COUNT(*) bills,
                    ROUND(AVG(s.total),2) avg_basket,
                    ROUND(AVG(s.line_count),2) avg_items,
                    MAX(s.total) biggest_bill,
                    SUM(s.total) amount
               FROM rtl_sales s
              WHERE " . self::billWhere() . "
              GROUP BY d ORDER BY d DESC",
            self::billArgs($f, $t));

        return self::shape('Basket analysis',
            [['k'=>'d','l'=>'Date'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'avg_basket','l'=>'Avg basket','n'=>1],['k'=>'avg_items','l'=>'Avg items','n'=>1],
             ['k'=>'biggest_bill','l'=>'Biggest bill','n'=>1],['k'=>'amount','l'=>'Total','n'=>1]],
            $rows, self::sum($rows, ['bills','amount']),
            'Growing the average basket is the cheapest way to grow sales.');
    }

    /* ==================== OPERATIONS ==================== */

    private static function r_reprints(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(r.created_at) d, r.bill_no, r.copy_no,
                    COALESCE(NULLIF(r.user_name,''),'-') by_user,
                    COALESCE(NULLIF(r.counter_name,''),'-') counter,
                    COALESCE(NULLIF(r.reason,''),'-') reason,
                    s.total
               FROM rtl_bill_reprints r
               LEFT JOIN rtl_sales s ON s.id = r.sale_id
              WHERE r.tenant_id = ? AND r.site_id = ?
                AND DATE(r.created_at) BETWEEN ? AND ?
              ORDER BY r.created_at DESC",
            [tenant_id(), site_id(), $f, $t]);

        return self::shape('Duplicate bill (reprints)',
            [['k'=>'d','l'=>'Date'],['k'=>'bill_no','l'=>'Bill'],['k'=>'copy_no','l'=>'Copy #','n'=>1],
             ['k'=>'by_user','l'=>'Kis ne'],['k'=>'counter','l'=>'Counter'],
             ['k'=>'reason','l'=>'Wajah'],['k'=>'total','l'=>'Bill total','n'=>1]],
            $rows, self::sum($rows, ['total']),
            'Duplicate bills are a common route for cash theft — several copies of one bill deserve attention.');
    }

    private static function r_audit_activity(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE_FORMAT(a.created_at,'%Y-%m-%d %H:%i') at,
                    COALESCE(a.username,'-') user, COALESCE(a.role_name,'-') role,
                    COALESCE(a.action,'-') action, COALESCE(a.module,'-') module,
                    COALESCE(a.record_label, a.description, '-') detail
               FROM audit_log a
              WHERE a.tenant_id = ? AND a.site_id = ?
                AND DATE(a.created_at) BETWEEN ? AND ?
              ORDER BY a.created_at DESC LIMIT 2000",
            [tenant_id(), site_id(), $f, $t]);

        return self::shape('Audit / activity log',
            [['k'=>'at','l'=>'Kab'],['k'=>'user','l'=>'User'],['k'=>'role','l'=>'Role'],
             ['k'=>'action','l'=>'Action'],['k'=>'module','l'=>'Module'],['k'=>'detail','l'=>'Detail']],
            $rows, [], 'No more than 2000 entries are shown.');
    }

    private static function r_held_bills(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(h.created_at) d, COALESCE(h.bill_no,'-') bill_no,
                    COALESCE(h.customer_name,'Walk-in') customer,
                    COALESCE(h.counter_name,'-') counter,
                    h.line_count items, h.total,
                    COALESCE(h.held_by,'-') held_by,
                    CASE WHEN h.deleted_at IS NULL THEN 'Abhi tak rakhi hui' ELSE 'Recall ho gayi' END state
               FROM rtl_held_bills h
              WHERE h.tenant_id = ? AND h.site_id = ?
                AND DATE(h.created_at) BETWEEN ? AND ?
              ORDER BY h.created_at DESC",
            [tenant_id(), site_id(), $f, $t]);

        return self::shape('Held / parked bills',
            [['k'=>'d','l'=>'Date'],['k'=>'bill_no','l'=>'Bill'],['k'=>'customer','l'=>'Customer'],
             ['k'=>'counter','l'=>'Counter'],['k'=>'items','l'=>'Items','n'=>1],
             ['k'=>'total','l'=>'Amount','n'=>1],['k'=>'held_by','l'=>'Kis ne'],['k'=>'state','l'=>'Halat']],
            $rows, self::sum($rows, ['items','total']),
            'No bill should be left parked at the end of the day — those goods have already left.');
    }

    /* ==================== TAX ==================== */

    private static function r_tax_summary(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(s.sold_at) d, COUNT(*) bills,
                    SUM(s.subtotal - s.discount) taxable,
                    SUM(s.tax_amount) tax,
                    SUM(CASE WHEN s.tax_amount = 0 THEN s.total ELSE 0 END) zero_rated,
                    SUM(s.total) total
               FROM rtl_sales s
              WHERE " . self::billWhere() . "
              GROUP BY d ORDER BY d DESC",
            self::billArgs($f, $t));

        return self::shape('Tax summary',
            [['k'=>'d','l'=>'Date'],['k'=>'bills','l'=>'Bills','n'=>1],
             ['k'=>'taxable','l'=>'Taxable','n'=>1],['k'=>'tax','l'=>'Tax','n'=>1],
             ['k'=>'zero_rated','l'=>'Zero-rated','n'=>1],['k'=>'total','l'=>'Total','n'=>1]],
            $rows, self::sum($rows, ['bills','taxable','tax','zero_rated','total']),
            'These are the figures used for the tax return at month end.');
    }

    private static function r_fbr_reconcile(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(s.sold_at) d, s.bill_no, s.total, s.tax_amount tax,
                    CASE WHEN s.status='Credit' THEN 'Credit sale' ELSE s.status END status
               FROM rtl_sales s
              WHERE " . self::billWhere() . "
              ORDER BY s.sold_at DESC",
            self::billArgs($f, $t));

        return self::shape('FBR reconciliation',
            [['k'=>'d','l'=>'Date'],['k'=>'bill_no','l'=>'POS bill'],
             ['k'=>'status','l'=>'Status'],['k'=>'tax','l'=>'Tax','n'=>1],
             ['k'=>'total','l'=>'Total','n'=>1]],
            $rows, self::sum($rows, ['tax','total']),
            'FBR fields for retail bills are still being added to the POS — for now this report shows the POS own record.');
    }

    /* ==================== INVENTORY ==================== */

    private static function r_stock_on_hand(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT p.name item, COALESCE(d.name,'-') department,
                    p.stock_qty qty, p.cost_price cost,
                    ROUND(p.stock_qty * p.cost_price,2) cost_value,
                    ROUND(p.stock_qty * p.retail_price,2) sale_value,
                    p.min_stock reorder_at
               FROM rtl_products p
               LEFT JOIN rtl_departments d ON d.id = p.department_id
              WHERE p.tenant_id = ? AND p.deleted_at IS NULL
              ORDER BY cost_value DESC",
            [tenant_id()]);

        return self::shape('Stock on hand',
            [['k'=>'item','l'=>'Item'],['k'=>'department','l'=>'Department'],
             ['k'=>'qty','l'=>'Qty','n'=>1],['k'=>'cost','l'=>'Cost','n'=>1],
             ['k'=>'cost_value','l'=>'Cost value','n'=>1],['k'=>'sale_value','l'=>'Sale value','n'=>1],
             ['k'=>'reorder_at','l'=>'Reorder at','n'=>1]],
            $rows, self::sum($rows, ['qty','cost_value','sale_value']),
            'This report shows the position today — the date range does not affect it.');
    }

    private static function r_low_stock(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT p.name item, COALESCE(d.name,'-') department,
                    p.stock_qty qty, p.min_stock reorder_at, p.max_stock max_stock,
                    GREATEST(p.max_stock - p.stock_qty, 0) to_order,
                    ROUND(GREATEST(p.max_stock - p.stock_qty,0) * p.cost_price,2) order_value
               FROM rtl_products p
               LEFT JOIN rtl_departments d ON d.id = p.department_id
              WHERE p.tenant_id = ? AND p.deleted_at IS NULL AND p.status='Active'
                AND p.stock_qty <= p.min_stock
              ORDER BY (p.stock_qty <= 0) DESC, p.name",
            [tenant_id()]);

        return self::shape('Low stock / reorder',
            [['k'=>'item','l'=>'Item'],['k'=>'department','l'=>'Department'],
             ['k'=>'qty','l'=>'On hand','n'=>1],['k'=>'reorder_at','l'=>'Reorder at','n'=>1],
             ['k'=>'to_order','l'=>'To order','n'=>1],['k'=>'order_value','l'=>'Order value','n'=>1]],
            $rows, self::sum($rows, ['qty','to_order','order_value']),
            'Isi list se purchase order create.');
    }

    private static function r_dead_stock(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT p.name item, COALESCE(d.name,'-') department,
                    p.stock_qty qty, ROUND(p.stock_qty*p.cost_price,2) locked_value,
                    COALESCE(x.sold_qty,0) sold_in_period
               FROM rtl_products p
               LEFT JOIN rtl_departments d ON d.id = p.department_id
               LEFT JOIN (
                    SELECT i.product_id, SUM(i.qty) sold_qty
                      FROM rtl_sale_items i
                      JOIN rtl_sales s ON s.id = i.sale_id
                     WHERE " . self::billWhere() . "
                     GROUP BY i.product_id
               ) x ON x.product_id = p.id
              WHERE p.tenant_id = ? AND p.deleted_at IS NULL
                AND p.stock_qty > 0 AND COALESCE(x.sold_qty,0) = 0
              ORDER BY locked_value DESC",
            \array_merge(self::billArgs($f, $t), [tenant_id()]));

        return self::shape('Dead stock',
            [['k'=>'item','l'=>'Item'],['k'=>'department','l'=>'Department'],
             ['k'=>'qty','l'=>'On hand','n'=>1],['k'=>'sold_in_period','l'=>'Bika','n'=>1],
             ['k'=>'locked_value','l'=>'Phansa paisa','n'=>1]],
            $rows, self::sum($rows, ['qty','locked_value']),
            'This is stock that did not sell once in the period.');
    }

    private static function r_expiry(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT p.name item, b.batch_no, b.expiry_date,
                    DATEDIFF(b.expiry_date, CURDATE()) days_left,
                    b.qty, ROUND(b.qty*b.cost_price,2) value,
                    CASE WHEN b.expiry_date < CURDATE() THEN 'Expired'
                         WHEN DATEDIFF(b.expiry_date, CURDATE()) <= 7  THEN '7 din ke andar'
                         WHEN DATEDIFF(b.expiry_date, CURDATE()) <= 30 THEN '30 din ke andar'
                         ELSE 'Theek' END state
               FROM rtl_batches b
               JOIN rtl_products p ON p.id = b.product_id
              WHERE b.tenant_id = ? AND b.deleted_at IS NULL AND b.qty > 0
              ORDER BY b.expiry_date",
            [tenant_id()]);

        return self::shape('Expiry / near expiry',
            [['k'=>'item','l'=>'Item'],['k'=>'batch_no','l'=>'Batch'],
             ['k'=>'expiry_date','l'=>'Expiry'],['k'=>'days_left','l'=>'Days left','n'=>1],
             ['k'=>'qty','l'=>'Qty','n'=>1],['k'=>'value','l'=>'Value','n'=>1],
             ['k'=>'state','l'=>'Halat']],
            $rows, self::sum($rows, ['qty','value']),
            'Expired stock left on the shelf is the most expensive kind of loss.');
    }

    private static function r_batch_stock(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT p.name item, b.batch_no, b.received_on, b.expiry_date,
                    b.qty, b.cost_price, ROUND(b.qty*b.cost_price,2) value
               FROM rtl_batches b
               JOIN rtl_products p ON p.id = b.product_id
              WHERE b.tenant_id = ? AND b.deleted_at IS NULL
              ORDER BY p.name, b.expiry_date",
            [tenant_id()]);

        return self::shape('Batch-wise stock',
            [['k'=>'item','l'=>'Item'],['k'=>'batch_no','l'=>'Batch'],
             ['k'=>'received_on','l'=>'Received'],['k'=>'expiry_date','l'=>'Expiry'],
             ['k'=>'qty','l'=>'Qty','n'=>1],['k'=>'cost_price','l'=>'Cost','n'=>1],
             ['k'=>'value','l'=>'Value','n'=>1]],
            $rows, self::sum($rows, ['qty','value']));
    }

    private static function r_fast_slow(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT p.name item, COALESCE(d.name,'-') department,
                    COALESCE(SUM(i.qty),0) sold_qty,
                    COALESCE(SUM(i.line_total),0) amount,
                    p.stock_qty on_hand
               FROM rtl_products p
               LEFT JOIN rtl_departments d ON d.id = p.department_id
               LEFT JOIN rtl_sale_items i ON i.product_id = p.id
               LEFT JOIN rtl_sales s ON s.id = i.sale_id AND " . self::billWhere() . "
              WHERE p.tenant_id = ? AND p.deleted_at IS NULL
              GROUP BY p.id ORDER BY sold_qty DESC",
            \array_merge(self::billArgs($f, $t), [tenant_id()]));

        return self::shape('Fast / slow movers',
            [['k'=>'item','l'=>'Item'],['k'=>'department','l'=>'Department'],
             ['k'=>'sold_qty','l'=>'Bika (qty)','n'=>1],['k'=>'amount','l'=>'Amount','n'=>1],
             ['k'=>'on_hand','l'=>'On hand','n'=>1]],
            $rows, self::sum($rows, ['sold_qty','amount','on_hand']),
            'Upar wale tez chalne wale, neeche wale susth.');
    }

    private static function r_margin(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT i.product_name item,
                    SUM(i.qty) qty, SUM(i.line_total) sale,
                    ROUND(SUM(i.qty * COALESCE(p.cost_price,0)),2) cost,
                    ROUND(SUM(i.line_total) - SUM(i.qty*COALESCE(p.cost_price,0)),2) profit,
                    ROUND(CASE WHEN SUM(i.line_total)>0
                          THEN (SUM(i.line_total)-SUM(i.qty*COALESCE(p.cost_price,0)))/SUM(i.line_total)*100
                          ELSE 0 END,1) margin_pct
               FROM rtl_sale_items i
               JOIN rtl_sales s ON s.id = i.sale_id
               LEFT JOIN rtl_products p ON p.id = i.product_id
              WHERE " . self::billWhere() . "
              GROUP BY item ORDER BY profit DESC",
            self::billArgs($f, $t));

        return self::shape('Margin by item',
            [['k'=>'item','l'=>'Item'],['k'=>'qty','l'=>'Qty','n'=>1],
             ['k'=>'sale','l'=>'Sale','n'=>1],['k'=>'cost','l'=>'Cost','n'=>1],
             ['k'=>'profit','l'=>'Profit','n'=>1],['k'=>'margin_pct','l'=>'Margin %','n'=>1]],
            $rows, self::sum($rows, ['qty','sale','cost','profit']),
            'Cost is what is on the product today — not the rate it was bought at.');
    }

    /* ==================== MONEY ==================== */

    private static function r_profit_margin(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(s.sold_at) d,
                    SUM(i.line_total) sale,
                    ROUND(SUM(i.qty * COALESCE(p.cost_price,0)),2) cost,
                    ROUND(SUM(i.line_total) - SUM(i.qty*COALESCE(p.cost_price,0)),2) profit,
                    ROUND(CASE WHEN SUM(i.line_total)>0
                          THEN (SUM(i.line_total)-SUM(i.qty*COALESCE(p.cost_price,0)))/SUM(i.line_total)*100
                          ELSE 0 END,1) margin_pct
               FROM rtl_sale_items i
               JOIN rtl_sales s ON s.id = i.sale_id
               LEFT JOIN rtl_products p ON p.id = i.product_id
              WHERE " . self::billWhere() . "
              GROUP BY d ORDER BY d DESC",
            self::billArgs($f, $t));

        return self::shape('Profit / margin',
            [['k'=>'d','l'=>'Date'],['k'=>'sale','l'=>'Sale','n'=>1],
             ['k'=>'cost','l'=>'Cost of goods','n'=>1],['k'=>'profit','l'=>'Profit','n'=>1],
             ['k'=>'margin_pct','l'=>'Margin %','n'=>1]],
            $rows, self::sum($rows, ['sale','cost','profit']),
            'Expenses are not included here — they are in the Expenses report.');
    }

    private static function r_expenses(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(e.expense_date) d,
                    COALESCE(ec.name,'(uncategorised)') category,
                    COALESCE(NULLIF(e.description,''), e.expense_no, '-') title,
                    COALESCE(e.payment_method,'-') method,
                    e.amount
               FROM expenses e
               LEFT JOIN expense_categories ec ON ec.id = e.category_id
              WHERE e.tenant_id = ? AND e.site_id = ?
                AND DATE(e.expense_date) BETWEEN ? AND ?
                AND e.status <> 'REJECTED' AND e.deleted_at IS NULL
              ORDER BY d DESC",
            [tenant_id(), site_id(), $f, $t]);

        return self::shape('Expenses',
            [['k'=>'d','l'=>'Date'],['k'=>'category','l'=>'Category'],
             ['k'=>'title','l'=>'Detail'],['k'=>'method','l'=>'Method'],
             ['k'=>'amount','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['amount']));
    }

    private static function r_khata(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT c.full_name customer, COALESCE(c.phone,'-') phone,
                    COALESCE(c.area,'-') area, c.credit_limit,
                    c.balance outstanding,
                    GREATEST(COALESCE(c.credit_limit,0)-COALESCE(c.balance,0),0) available
               FROM customers c
              WHERE c.tenant_id = ? AND c.deleted_at IS NULL AND COALESCE(c.balance,0) > 0
              ORDER BY c.balance DESC",
            [tenant_id()]);

        return self::shape('Khata / receivable',
            [['k'=>'customer','l'=>'Customer'],['k'=>'phone','l'=>'Phone'],['k'=>'area','l'=>'Area'],
             ['k'=>'credit_limit','l'=>'Limit','n'=>1],['k'=>'outstanding','l'=>'Baqaya','n'=>1],
             ['k'=>'available','l'=>'Available','n'=>1]],
            $rows, self::sum($rows, ['credit_limit','outstanding','available']),
            'This is the position today — the date range does not affect it.');
    }

    private static function r_khata_ledger(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(l.entry_at) d, c.full_name customer,
                    l.entry_type type, COALESCE(l.ref_no,'-') ref,
                    l.debit, l.credit, l.balance_after balance,
                    COALESCE(l.note,'-') note
               FROM rtl_customer_ledger l
               JOIN customers c ON c.id = l.customer_id
              WHERE l.tenant_id = ? AND l.site_id = ?
                AND DATE(l.entry_at) BETWEEN ? AND ?
              ORDER BY l.entry_at DESC",
            [tenant_id(), site_id(), $f, $t]);

        return self::shape('Khata ledger',
            [['k'=>'d','l'=>'Date'],['k'=>'customer','l'=>'Customer'],['k'=>'type','l'=>'Type'],
             ['k'=>'ref','l'=>'Reference'],['k'=>'debit','l'=>'Debit','n'=>1],
             ['k'=>'credit','l'=>'Credit','n'=>1],['k'=>'balance','l'=>'Balance','n'=>1],
             ['k'=>'note','l'=>'Note']],
            $rows, self::sum($rows, ['debit','credit']),
            'Debit = udhaar bika, Credit = paisa wapas aaya.');
    }

    private static function r_credit_sales(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT DATE(s.sold_at) d, s.bill_no, s.customer_name customer,
                    s.cashier_name cashier, s.total, s.line_count items
               FROM rtl_sales s
              WHERE " . self::billWhere() . " AND s.status = 'Credit'
              ORDER BY s.sold_at DESC",
            self::billArgs($f, $t));

        return self::shape('Credit sales',
            [['k'=>'d','l'=>'Date'],['k'=>'bill_no','l'=>'Bill'],['k'=>'customer','l'=>'Customer'],
             ['k'=>'cashier','l'=>'Cashier'],['k'=>'items','l'=>'Items','n'=>1],
             ['k'=>'total','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['items','total']),
            'The goods have gone but the money has not come in.');
    }

    /* ==================== CUSTOMERS ==================== */

    private static function r_customers(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT s.customer_name customer, COUNT(*) visits,
                    SUM(s.total) amount, ROUND(AVG(s.total),2) avg_bill,
                    MAX(DATE(s.sold_at)) last_visit
               FROM rtl_sales s
              WHERE " . self::billWhere() . "
              GROUP BY s.customer_name ORDER BY amount DESC",
            self::billArgs($f, $t));

        return self::shape('Customer report',
            [['k'=>'customer','l'=>'Customer'],['k'=>'visits','l'=>'Visits','n'=>1],
             ['k'=>'amount','l'=>'Sale','n'=>1],['k'=>'avg_bill','l'=>'Avg bill','n'=>1],
             ['k'=>'last_visit','l'=>'Last visit']],
            $rows, self::sum($rows, ['visits','amount']));
    }

    private static function r_loyalty(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT c.full_name customer, COALESCE(c.phone,'-') phone,
                    COALESCE(c.loyalty_points,0) points,
                    COALESCE(c.customer_type,'-') type,
                    COALESCE(c.balance,0) outstanding
               FROM customers c
              WHERE c.tenant_id = ? AND c.deleted_at IS NULL
                AND COALESCE(c.loyalty_points,0) > 0
              ORDER BY points DESC",
            [tenant_id()]);

        return self::shape('Loyalty',
            [['k'=>'customer','l'=>'Customer'],['k'=>'phone','l'=>'Phone'],
             ['k'=>'type','l'=>'Type'],['k'=>'points','l'=>'Points','n'=>1],
             ['k'=>'outstanding','l'=>'Baqaya','n'=>1]],
            $rows, self::sum($rows, ['points','outstanding']));
    }

    /* ==================== CSV ==================== */

    public static function csv(array $rep): string
    {
        $out = [];
        $cols = $rep['columns'] ?? [];
        $out[] = \implode(',', \array_map(fn($c) => '"' . \str_replace('"', '""', (string)$c['l']) . '"', $cols));
        foreach (($rep['rows'] ?? []) as $r) {
            $line = [];
            foreach ($cols as $c) {
                $v = (string)($r[$c['k']] ?? '');
                $line[] = '"' . \str_replace('"', '""', $v) . '"';
            }
            $out[] = \implode(',', $line);
        }
        return \implode("\r\n", $out) . "\r\n";
    }
}
