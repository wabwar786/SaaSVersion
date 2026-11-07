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
            ['id' => 'account_wise',    'group' => 'Sales',     'name' => 'Account wise sale',        'desc' => 'Full sales and expenses for each account number, even if the account ran for several days'],
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
            /* V157 — "abhi kitna stock hai" batane wali koi report thi hi
               nahi. `low_stock` sirf wo items dikhati hai jin ka reorder
               level set ho AUR wo us se neeche gir chuke hon;
               `tracked_inventory` sirf tracked items. Poora godam, uski
               ginti aur uski maliyat kahin nazar nahi aati thi. */
            ['id' => 'stock_on_hand',   'group' => 'Inventory', 'name' => 'Current stock',            'desc' => 'Current stock of every item and its value'],
            ['id' => 'stock_ledger',    'group' => 'Inventory', 'name' => 'Opening / closing stock',  'desc' => 'Opening, purchases, sales, closing and current'],
            ['id' => 'stock_movement',  'group' => 'Inventory', 'name' => 'Stock movement',           'desc' => 'What came in and what went out'],
            ['id' => 'tracked_inventory','group' => 'Inventory', 'name' => 'Tracked inventory',        'desc' => 'Only the items you chose to watch closely'],
            ['id' => 'low_stock',       'group' => 'Inventory', 'name' => 'Low stock',                'desc' => 'Items at or below their minimum level'],
            ['id' => 'credit_sales',    'group' => 'Money',     'name' => 'Credit / unpaid bills',    'desc' => 'Bills that were not fully paid'],
            ['id' => 'refunds',         'group' => 'Money',     'name' => 'Returns and refunds',      'desc' => 'Money paid back to customers'],
            ['id' => 'supplier_buys',   'group' => 'Inventory', 'name' => 'Supplier purchases',       'desc' => 'How much you buy from each supplier'],
            ['id' => 'purchases',       'group' => 'Inventory', 'name' => 'Purchases',                'desc' => 'Goods received by supplier'],

            /* ---- V98: jo reports customer maang raha tha aur maujood nahi thin ---- */
            ['id' => 'invoice_detail',  'group' => 'Sales',     'name' => 'Order / invoice detail',   'desc' => 'The complete record of every bill: items, tax, discount, payment'],
            ['id' => 'waiter_sales',    'group' => 'Operations','name' => 'Sales by waiter',          'desc' => 'Sales, covers and average bill per waiter'],
            ['id' => 'discounts',       'group' => 'Operations','name' => 'Discounts',                'desc' => 'How much discount each user gave'],
            ['id' => 'voids',           'group' => 'Operations','name' => 'Void / cancelled bills',   'desc' => 'Every void bill with its reason and user'],
            ['id' => 'shift_closing',   'group' => 'Operations','name' => 'Shift / day closing',      'desc' => 'Opening, sale, payments, expenses, variance'],
            ['id' => 'wastage',         'group' => 'Inventory', 'name' => 'Wastage / write-off',      'desc' => 'Damaged and wasted stock, with its value'],
            ['id' => 'purchase_items',  'group' => 'Inventory', 'name' => 'Purchases by item',        'desc' => 'Purchases per item — quantity, rate and value'],
            ['id' => 'customers',       'group' => 'Money',     'name' => 'Customers',                'desc' => 'Bills, visits and outstanding per customer'],
            ['id' => 'fbr_reconcile',   'group' => 'Tax',       'name' => 'FBR reconciliation',       'desc' => 'POS bills vs what was sent to FBR — where the gap is'],
            ['id' => 'fbr_summary',     'group' => 'Tax',       'name' => 'FBR daily / monthly summary','desc' => 'Summary of invoices, taxable amount and sales tax'],
            ['id' => 'audit_activity',  'group' => 'Operations','name' => 'Audit / activity log',     'desc' => 'Sign-ins, bill edits, voids, discounts, refunds — who did what'],
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
        /* ============================================================
           V181 — REPORTS PAR BHI BILL NUMBER SEEDHA SADA.

           Jis bhi report mein `bill_no` ka khana ho, us par screen par
           `7` chhapta hai — database mein `L206-0007` hi rehta hai.
           Yeh EK hi jagah hai, is liye koi report peeche nahi rehti
           aur har jagah number ek jaisa dikhta hai.

           CSV aur PDF isi `rows` se bante hain, to wahan bhi wohi
           number jata hai jo screen par hai.
           ============================================================ */
        if (!empty($out['rows']) && is_array($out['rows'])) {
            foreach ($out['rows'] as &$__r) {
                if (is_array($__r) && array_key_exists('bill_no', $__r)) {
                    $__r['bill_no'] = bill_show((string)$__r['bill_no']);
                }
            }
            unset($__r);
        }
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
        /* ============================================================
           V174 — REPORT MEIN BHI SIRF POORE HUAY BILL.

           V173 mein shift ki closing parchi se cancel aur hold wale
           bill nikaal diye gaye thay. Magar YEH lakeer reh gayi thi —
           aur yehi 25 reports chalati hai, un mein Sale Summary bhi
           jo malik roz dekhta hai.

           Purani shart `order_status <> 'VOID'` thi, yani CANCEL aur
           HOLD wale bill bhi "sale" mein gin jate thay. Isi database
           par farq itna tha:

               report kehti thi   267,515
               asal sale          172,731
               -----------------------------
               jhooti sale         94,784   (55% zyada)

           Cancel aur void wale bill ab bhi nazar aate hain — un ki
           apni report hai ("Void / cancelled bills"), jo alag shart
           par chalti hai. Wo chhupaye nahi gaye, sirf SALE ki ginti
           se bahar kiye gaye hain.
           ============================================================ */
        return "$alias.site_id = ? AND $alias.order_status = 'CLOSED'
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
        /* ============================================================
           V185 — UPAR WALA NEELA KHANA HAR JAGAH SE GAYA.

           Malik ki baat: "oper jo blue main statement likhi hai wo b har
           jagah se remove kr do".

           Screen par se hatana kaafi nahi tha: `note` report ke data ke
           saath jata hai, is liye CSV aur print mein bhi chala jata. Ab
           wo yahin, jar se, khali kar diya jata hai — ek hi jagah, to
           aage koi nayi report likhte waqt bhoolne ka sawal hi nahi.

           Likha hua matn code mein jaan boojh kar chhoda gaya hai: wo
           bata deta hai ke report kis cheez ko ginti hai, jo agle
           developer ke kaam ki baat hai. Screen par kahin nahi jata.
           ============================================================ */
        return ['title' => $title, 'columns' => $cols, 'rows' => $rows,
                'totals' => $totals, 'note' => ''];
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
                    /* V171 — delivery charge apna column. Pehle yeh raqam
                       kahin nazar nahi aati thi: total mein shamil hoti
                       thi magar us ka naam koi nahi tha, to malik ko pata
                       hi nahi chalta tha ke mahine mein kitna kiraya
                       wasool hua. */
                    SUM(COALESCE(o.delivery_charge,0)) delivery,
                    SUM(o.tax_amount) tax,
                    SUM(o.grand_total) total
               FROM orders o
              WHERE " . self::billWhere() . "
              GROUP BY d ORDER BY d",
            [site_id(), $f, $t]);

        return self::shape('Sales summary',
            [['k'=>'d','l'=>'Date'],['k'=>'bills','l'=>'Bills','n'=>1,'kpi'=>1],
             ['k'=>'subtotal','l'=>'Subtotal','n'=>1],['k'=>'discount','l'=>'Discount','n'=>1,'kpi'=>1],
             ['k'=>'service','l'=>'Service','n'=>1],['k'=>'delivery','l'=>'Delivery','n'=>1],
             ['k'=>'tax','l'=>'Tax','n'=>1,'kpi'=>1],
             ['k'=>'total','l'=>'Total','n'=>1,'kpi'=>1]],
            $rows, self::sum($rows, ['bills','subtotal','discount','service','delivery','tax','total']));
    }

    /* ============================================================
       V177 — ACCOUNT (TILL) WISE SALE.

       KYUN BANI
       Till subah 11 baje khulta hai aur agli raat 2 baje band hota
       hai. Raat 12 bajte hi TAREEKH badal jati hai, is liye us ek
       till ki sale DO tareekhon mein bat jati hai. Date-wise report
       par us till ka apna total kahin nazar hi nahi aata — aadha
       pichli tareekh mein, aadha agli tareekh mein doosre tillon ke
       saath mila hua. Isi database par chala kar dekha tha:

           31 Oct ki date-wise report :  2 bill,   8,000
           01 Nov ki date-wise report :  4 bill,  14,607
           us till ka asal total      :  4 bill,  14,000  <- kahin nahi

       Yeh report us till ko EK hi line mein dikhati hai — chahe wo
       10 din chale.

       KAI TILL EK SAATH KHULE HON
       Account sirf `orders.shift_id` se tay hota hai — wahi number
       jo bill par likha gaya tha. Waqt ke hisaab se koi ANDAZA NAHI
       lagaya jata. Yeh jaan-boojh kar hai: jab do cashier ek saath
       apne apne till par bech rahe hon, to waqt ka andaza ek ki
       sale doosre ke account mein daal sakta hai. Andaza na lagane
       ka matlab yeh hai ke mixing MUMKIN HI NAHI.

       Jin bills par account number laga hi nahi, wo chhupaye nahi
       jate — aakhir mein "(bina account)" ki apni line mein aate
       hain, taake total kabhi gum na ho.

       TAREEKH KA RANGE sirf yeh chunta hai ke KAUN SE account
       dikhane hain (jo us arse mein kahin bhi chale). Account chun
       liya jaye to us ke SAARE bill ginay jate hain — chahe kisi
       bhi tareekh ke hon. Yehi is report ka poora maqsad hai.
       ============================================================ */
    private static function r_account_wise(string $f, string $t): array
    {
        /* Cashier sirf apne account dekhe; manager/malik sab. */
        [$sw, $sa] = Scope::shiftWhere('s');

        /* ============================================================
           V183 — TAREEKH AB ACCOUNT KE KHULNE PAR LAGTI HAI,
                  BILLON PAR NAHI.

           Malik ki baat: "agar main start date 7/11/2026 aur end date
           7/11/2026 rakhoon to mujhe sirf EK account dikhna chahiye jo
           7/11/2026 ko shuru hua. Pichli date wala nahi dikhna chahiye."

           Pehle (V178) ulta tha: account koi bhi ho, bas us ke wo bill
           ginay jate thay jo chuni hui tareekhon mein band huay. Is liye
           7 Nov ki report par 1 Nov aur 5 Nov ke khule account bhi aa
           jate thay.

           AB:
             • Fehrist mein sirf wo account jo CHUNI HUI TAREEKHON MEIN
               KHULE  (`DATE(s.opened_at) BETWEEN ? AND ?`).
             • Us account ka POORA hisaab — us ke SAARE bill aur SAARE
               kharche, chahe wo agle din ke hon. Yehi account ka asal
               hisaab hai: ek till subah 11 baje khul kar agli raat 2
               baje band hota hai, us ki raat 12 ke baad wali sale bhi
               usi account ki hai.

           >>> IS KA EK NATIJA SAAF SAMAJH LEIN <<<
           Ab is report ka total `Sales summary` ke total ke BARABAR
           NAHI hota, aur yeh jaan-boojh kar hai:

             • Jo account pehle khula tha magar us ke bill aaj band
               huay — wo bill ab is report mein nahi hain (us ka
               account aaj khula hi nahi).
             • Jo account aaj khula hai aur kal tak chalega — us ke
               KAL wale bill bhi abhi is report mein hain.

           Do reports ab do alag sawalon ka jawab hain:
             Sales summary   -> is DIN kitni sale hui
             Account wise    -> is din KHULE ACCOUNT ne kul kitna kamaya
           Screen par neeche wala note yehi baat likhta hai, taake koi
           dono ke total ka muqabla kar ke pareshan na ho.

           `HAVING` ki ab zarurat nahi: jo account arse mein khula, wo
           khud hi fehrist mein hai — chahe us par ek bhi bill na bana
           ho (V179 wali baat waise hi qayam hai).
           ============================================================

           PURANA FAISLA (V178) — record ke liye:
           TAREEKH KA FILTER BILLON PAR BHI.

           Pehle yeh report us account ka POORA hisaab deti thi, chahe
           us ke bill report ke arse se bahar ke hon. Asli data par
           nateeja yeh nikla:

               date wise     134 bill   198,436
               account wise  282 bill   417,986

           Farq is liye tha ke ek account 24 October ko khula tha aur
           1 November tak chala — us ke NAU din ke bill 1-3 November
           ki report mein aa gaye. Dono totalon ka muqabla hi nahi ho
           sakta tha.

           AB: wahi tareekh ki shart jo date-wise report par lagti
           hai, yahan har bill par bhi lagti hai. Is liye DONO
           REPORTS KA TOTAL BILKUL EK hota hai.

           Account ab bhi EK HI LINE hai — 10 din chalne wala account
           10 lineon mein nahi tootta. Bas us line par sirf un dinon
           ke bill ginay jate hain jo chune gaye.

           Jin accounts ka chune hue dinon mein koi kaam hi na hua, wo
           fehrist mein nahi aate (HAVING) — warna khali lineon se
           table bhar jata.
           ============================================================ */
        $rows = self::q(
            /* V181 — account ka seedha number (1,2,3). `shift_no` database
               mein jaisa hai waisa hi; yeh sirf dikhane ke liye hai. Jis
               purani row ko abhi number na mila ho, us par poora naam. */
            "SELECT COALESCE(NULLIF(CAST(s.shift_seq AS CHAR),''),s.shift_no) account,
                    COALESCE(NULLIF(s.counter_name,''),'-')      counter,
                    COALESCE(NULLIF(u.full_name,''),'-')         cashier,
                    DATE_FORMAT(s.opened_at,'%d %b %H:%i')       opened,
                    IFNULL(DATE_FORMAT(s.closed_at,'%d %b %H:%i'),'chal raha hai') closed,
                    (DATEDIFF(DATE(COALESCE(s.closed_at,NOW())),DATE(s.opened_at))+1) days,
                    /* V183 — account ka POORA kharcha, tareekh ki shart nahi. */
                    (SELECT COALESCE(SUM(e.amount),0) FROM expenses e
                      WHERE e.shift_id = s.id AND e.status <> 'REJECTED'
                        AND e.deleted_at IS NULL) expenses,
                    COUNT(o.id)                                   bills,
                    COALESCE(SUM(o.subtotal),0)                   subtotal,
                    COALESCE(SUM(o.discount_amount),0)            discount,
                    COALESCE(SUM(o.service_charge),0)             service,
                    COALESCE(SUM(COALESCE(o.delivery_charge,0)),0) delivery,
                    COALESCE(SUM(o.tax_amount),0)                 tax,
                    COALESCE(SUM(o.grand_total),0)                sale
               FROM cashier_shifts s
               LEFT JOIN users u ON u.id = s.cashier_user_id
               /* V183 — account ke SAARE band bill, tareekh ki shart nahi.
                  Tareekh ab neeche WHERE mein account ke KHULNE par lagti hai. */
               LEFT JOIN orders o
                      ON o.shift_id = s.id
                     AND o.order_status = 'CLOSED'
              WHERE s.site_id = ?
                AND s.deleted_at IS NULL
                AND DATE(s.opened_at) BETWEEN ? AND ?
                AND $sw
              GROUP BY s.id, s.shift_no, s.shift_seq, s.counter_name, u.full_name,
                       s.opened_at, s.closed_at
             /* ============================================================
                V183 — `HAVING` ab hai hi nahi.

                Jo account chuni hui tareekhon mein khula, wo khud hi
                fehrist mein hai — chahe us par ek bhi bill na bana ho.
                Yani V179 wali baat -- account khulte hi report mein aa
                jaye -- ab shart ki mohtaj nahi, khud ba khud qayam hai.

                PURANA FAISLA (V179) — record ke liye:
                ACCOUNT KHULTE HI REPORT MEIN AA JAYE.

                V178 mein yahan sirf `bills > 0 OR expenses > 0` tha. Is
                ka natija asal customer par yeh nikla: aaj subah jo till
                khola gaya aur jis par abhi pehla bill nahi bana, wo
                report mein BILKUL NAZAR HI NAHI AATA — malik ko lagta
                tha account khula hi nahi.

                Ab chaar mein se koi ek baat kaafi hai:
                  1. is arse mein us account ka koi bill ho, ya
                  2. is arse mein us account ka koi kharcha ho, ya
                  3. account ISI ARSE mein khula ho (chahe ek bill
                     bhi na bana ho), ya
                  4. account AB TAK CHAL RAHA ho aur arse ke khatam
                     hone se pehle khula ho.

                Chauthi shart ka ek faida muft mein milta hai: jo till
                koi band karna BHOOL gaya ho, wo har report par 0 bill
                ke saath nazar aata rahe ga.

                TOTAL PAR KOI ASAR NAHI. Nayi lineen sirf sifar laati
                hain, aur `bills`/`sale` mein sifar jama hota hai — is
                liye Sales summary ke sath barabri (V178 ka kaam) waisi
                hi qayam hai. Chaar arson par chala kar tasdeeq ki gayi.

                `MAX()` jaan-boojh kar lagaya hai: group `s.id` par hai
                (primary key), yani har group mein EK hi shift hai, to
                MAX wohi qeemat lauta ta hai — aur yeh har sql_mode par
                chalta hai, `ONLY_FULL_GROUP_BY` par bhi.
                ============================================================ */
              ORDER BY s.opened_at",
            array_merge([site_id(), $f, $t], $sa));

        foreach ($rows as &$r) { $r['net'] = round((float)$r['sale'] - (float)$r['expenses'], 2); }
        unset($r);

        /* Jin billon par account number hai hi nahi (tablet/QR ka order) —
           un ki apni alag line. In ka koi account hai hi nahi, is liye
           inhen BILL KI TAREEKH se liya jata hai — yeh is report mein
           wahi ek jagah hai jahan tareekh bill par lagti hai. */
        $orphan = self::q(
            "SELECT COUNT(*) bills, COALESCE(SUM(o.subtotal),0) subtotal,
                    COALESCE(SUM(o.discount_amount),0) discount,
                    COALESCE(SUM(o.service_charge),0) service,
                    COALESCE(SUM(COALESCE(o.delivery_charge,0)),0) delivery,
                    COALESCE(SUM(o.tax_amount),0) tax,
                    COALESCE(SUM(o.grand_total),0) sale
               FROM orders o
              WHERE o.shift_id IS NULL AND " . self::billWhere(),
            [site_id(), $f, $t]);

        if ($orphan && (int)$orphan[0]['bills'] > 0) {
            $o = $orphan[0];
            $rows[] = ['account'=>'(bina account)','counter'=>'-','cashier'=>'-',
                       'opened'=>'-','closed'=>'-','days'=>'-',
                       'bills'=>(int)$o['bills'],'subtotal'=>$o['subtotal'],
                       'discount'=>$o['discount'],'service'=>$o['service'],
                       'delivery'=>$o['delivery'],'tax'=>$o['tax'],
                       'sale'=>$o['sale'],'expenses'=>0,
                       'net'=>round((float)$o['sale'],2)];
        }

        return self::shape('Account wise sale',
            [['k'=>'account','l'=>'Account'],['k'=>'counter','l'=>'Counter'],
             ['k'=>'cashier','l'=>'Cashier'],['k'=>'opened','l'=>'Opened'],
             ['k'=>'closed','l'=>'Closed'],['k'=>'days','l'=>'Days','n'=>1],
             /* ============================================================
                V184 — COLUMN AB QUDRATI TARTEEB MEIN.

                Malik ki baat: "iska sequence theek nahi hai. normally
                asa hota: sale 1000, service 50, discount 30, tax 20,
                gross 1040."

                Bilkul theek — V180 mein nateeja (Sale/Net) apne hisson
                se PEHLE aa gaya tha. Ab wapas qudrati tarteeb:

                  Subtotal - Discount + Service + Delivery + Tax = Total
                  Total - Expenses = Net

                `sale` ka LABEL ab "Total" hai (key wahi `sale` hai, kuch
                nahi tootta) — Sales summary mein bhi yehi lafz hai, to
                do reports milana aasan ho jata hai.

                >>> MAGAR V180 WALA MASLA WAPAS NA AA JAYE <<<

                V180 ki asal shikayat thi "account wise report main total
                nahi aa rhi hai". Wajah: `reports.html` upar ke chaar tile
                PEHLE CHAAR numeric column se banata hai. Qudrati tarteeb
                mein wo Bills | Subtotal | Discount | Service ban jate —
                aur Total ka tile phir ghayab.

                HAL: column par ikhtiyari `kpi` nishan. `reports.html` ab
                PEHLE nishan wale column dekhta hai; jis report mein
                nishan na ho wahan bilkul pehle jaisa (pehle chaar
                numeric). Yani baqi 33 reports par koi farq nahi.

                Tile ab: Bills | Total | Expenses | Net.
                ============================================================ */
             /* ============================================================
                V180 — AHEM NUMBER PEHLE.  (ab V184 se badal gaya, upar dekhein)

                Malik ne bataya: "account wise report main total nahi aa
                rhi hai". Chala kar dekha to baat yeh nikli:

                  1366 ki screen par bina scroll sirf yahan tak nazar
                  aata tha:  Account ... Din | Bills | Subtotal | Discount
                  `Sale` TERHVAN column tha — screen se bahar.

                  Aur upar wale tile bhi: Bills | Subtotal | Discount |
                  Service — SALE ka tile tha hi nahi.

                Wajah: `reports.html` tile banane ke liye PEHLE CHAAR
                numeric column uthata hai (`nums.slice(0,4)`). Account
                wise mein Sale SAATWAN numeric column tha.

                Sales summary par yeh masla isi liye nahi aata ke us ke
                sirf 8 column hain aur Total screen par aa jata hai.

                HAL: `reports.html` ko haath nahi lagaya (us se saari 34
                reports ke tile badal jate). Sirf ISI report ki columns
                ki tarteeb badli — paise wale ahem column pehle:

                    Bills | Sale | Kharche | Net | phir tafseel

                Ab upar ke chaar tile wohi hain jo malik dekhna chahta
                hai, aur Sale bina scroll nazar aata hai. Koi number
                nahi badla, sirf tarteeb badli.
                ============================================================ */
             ['k'=>'bills','l'=>'Bills','n'=>1,'kpi'=>1],
             /* hisse pehle, nateeja baad mein */
             ['k'=>'subtotal','l'=>'Subtotal','n'=>1],
             ['k'=>'discount','l'=>'Discount','n'=>1],
             ['k'=>'service','l'=>'Service','n'=>1],
             ['k'=>'delivery','l'=>'Delivery','n'=>1],
             /* ============================================================
                V184 — TAX KA LABEL BUSINESS KE USOOL KE MUTABIQ.

                Tarteeb theek karte waqt yeh pakra gaya: jis business mein
                qeemat ke ANDAR tax hota hai (TaxMode INCLUSIVE), wahan
                `grand_total` mein tax JAMA NAHI hota — wo pehle se
                subtotal ke andar hai. Imtihaan ne khud dikha diya:

                    subtotal 3,400 · tax 468.97 · grand_total 3,400
                    (yani 2,931.03 + 16% = 3,400)

                Agar column par sirf "Tax" likha rahe to malik jama kar ke
                3,868.97 nikalega aur report jhooti lagegi. Is liye ab
                label khud bata deta hai, aur neeche ka note us business ka
                SAHIH formula likhta hai. Jahan tax bahar se lagta hai
                (EXCLUSIVE) wahan bilkul pehle jaisa.
                ============================================================ */
             ['k'=>'tax','l'=>\Aio\Services\TaxMode::isInclusive()?'Tax (incl.)':'Tax','n'=>1],
             ['k'=>'sale','l'=>'Total','n'=>1,'kpi'=>1],
             ['k'=>'expenses','l'=>'Expenses','n'=>1,'kpi'=>1],
             ['k'=>'net','l'=>'Net','n'=>1,'kpi'=>1]],
            $rows,
            /* V180 — EK PAISY KA FARQ.
               Aap ke total par Sale 283,630.11 tha magar Net 283,630.12.
               Wajah: har line ka `net` alag alag round hota hai aur phir
               wo GOL KIYE HUAY number jama kiye jate thay — 192 billon
               par yeh paisa khisak jata hai. Ab Net ka total jama kiye
               huay Sale mein se jama kiye huay Kharche nikaal kar banta
               hai, yani hamesha theek. */
            (function(array $t): array { $t['net'] = round($t['sale'] - $t['expenses'], 2); return $t; })(
                self::sum($rows, ['bills','subtotal','discount','service','delivery','tax','sale','expenses','net'])),
            'This list shows only the accounts that were OPENED within the '
          . 'selected dates. Each account gets one line carrying its FULL '
          . 'figures — every bill and every expense, even those from the next '
          . 'day (a till opened at 11am may close at 2am). That is why this '
          . 'total can differ from the Sales summary total: Sales summary '
          . 'answers "how much was sold on this day", while this report '
          . 'answers "how much did the accounts opened on this day earn in '
          . 'total". An account is decided only by the account number printed '
          . 'on the bill, never by guessing from the time. '
          . (\Aio\Services\TaxMode::isInclusive()
              ? 'Subtotal - Discount + Service + Delivery = Total. Tax is already '
              . 'inside your prices, so it is shown for information only and is '
              . 'NOT added again. Total - Expenses = Net.'
              : 'Subtotal - Discount + Service + Delivery + Tax = Total, '
              . 'and Total - Expenses = Net.'));
    }

    private static function r_sales_by_item(string $f, string $t): array
    {
        /* ============================================================
           V185 — ITEM KI APNI QEEMAT, AUR BILL KI ASAL SALE.

           Malik ki shikayat thi: Sales summary par total 68,329.81,
           magar Sales by item par 73,385.

           Yeh bug nahi tha. `line_total` ITEM ki qeemat hai, aur bill ka
           discount, service charge aur delivery item ki line mein hote hi
           nahi. Jahan discount bara ho wahan item wala number UPAR chala
           jata hai (isi liye 73,385 > 68,329.81).

           Magar baat malik ki theek hai ke har report ka total ek jaisa
           aana chahiye. Ab ek aur column: har item ko us ke bill ke
           discount/service/delivery/tax ka apna HISSA milta hai, line ke
           tanasub se --

               hissa = line_total x (grand_total / subtotal)

           -- to in sab ka jama bill ke grand_total ke barabar aa jata
           hai. `Amount` jaisa tha waisa hi hai: item ki apni qeemat.
           ============================================================ */
        $rows = self::q(
            "SELECT COALESCE(oi.item_name_snapshot, mi.name, '(deleted item)') item,
                    COALESCE(mc.name,'-') category,
                    SUM(oi.qty) qty, SUM(oi.line_total) total,
                    SUM(oi.line_total * (o.grand_total / NULLIF(o.subtotal,0))) sale
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
               LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
               LEFT JOIN menu_categories mc ON mc.id = mi.category_id
              WHERE " . self::billWhere() . "
              GROUP BY item, category ORDER BY sale DESC",
            [site_id(), $f, $t]);

        return self::shape('Sales by item',
            [['k'=>'item','l'=>'Item'],['k'=>'category','l'=>'Category'],
             ['k'=>'qty','l'=>'Qty','n'=>1,'kpi'=>1],
             ['k'=>'total','l'=>'Amount','n'=>1],
             ['k'=>'sale','l'=>'Sale','n'=>1,'kpi'=>1]],
            self::itemGapRow($rows, self::itemTotals($rows, $f, $t), 'item', true),
            self::itemTotals($rows, $f, $t));
    }

    private static function r_sales_by_category(string $f, string $t): array
    {
        /* ============================================================
           V185 — ITEM KI APNI QEEMAT, AUR BILL KI ASAL SALE.

           Malik ki shikayat thi: Sales summary par total 68,329.81,
           magar Sales by item par 73,385.

           Yeh bug nahi tha. `line_total` ITEM ki qeemat hai, aur bill ka
           discount, service charge aur delivery item ki line mein hote hi
           nahi. Jahan discount bara ho wahan item wala number UPAR chala
           jata hai (isi liye 73,385 > 68,329.81).

           Magar baat malik ki theek hai ke har report ka total ek jaisa
           aana chahiye. Ab ek aur column: har item ko us ke bill ke
           discount/service/delivery/tax ka apna HISSA milta hai, line ke
           tanasub se --

               hissa = line_total x (grand_total / subtotal)

           -- to in sab ka jama bill ke grand_total ke barabar aa jata
           hai. `Amount` jaisa tha waisa hi hai: item ki apni qeemat.
           ============================================================ */
        $rows = self::q(
            "SELECT COALESCE(mc.name,'(uncategorised)') category,
                    SUM(oi.qty) qty, SUM(oi.line_total) total,
                    SUM(oi.line_total * (o.grand_total / NULLIF(o.subtotal,0))) sale
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
               LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
               LEFT JOIN menu_categories mc ON mc.id = mi.category_id
              WHERE " . self::billWhere() . "
              GROUP BY category ORDER BY sale DESC",
            [site_id(), $f, $t]);

        return self::shape('Sales by category',
            [['k'=>'category','l'=>'Category'],['k'=>'qty','l'=>'Qty','n'=>1,'kpi'=>1],
             ['k'=>'total','l'=>'Amount','n'=>1],
             ['k'=>'sale','l'=>'Sale','n'=>1,'kpi'=>1]],
            self::itemGapRow($rows, self::itemTotals($rows, $f, $t), 'category', false),
            self::itemTotals($rows, $f, $t));
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
             ['k'=>'items','l'=>'Items','n'=>1,'kpi'=>1],['k'=>'subtotal','l'=>'Subtotal','n'=>1],
             ['k'=>'discount','l'=>'Discount','n'=>1,'kpi'=>1],['k'=>'tax','l'=>'Tax','n'=>1,'kpi'=>1],
             ['k'=>'total','l'=>'Total','n'=>1,'kpi'=>1],['k'=>'fbr_no','l'=>'FBR no.']],
            $rows, self::sum($rows, ['items','subtotal','discount','tax','total']),
            'The complete record of every bill, all in one place.');
    }

    /** Waiter-wise sale. */
    private static function r_waiter_sales(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT COALESCE(u.full_name,'(unassigned)') waiter,
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
            'The average bill is the most direct measure of.');
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
            [['k'=>'d','l'=>'Date'],['k'=>'bill_no','l'=>'Bill'],['k'=>'given_by','l'=>'Given by'],
             ['k'=>'subtotal','l'=>'Subtotal','n'=>1],['k'=>'discount','l'=>'Discount','n'=>1],
             ['k'=>'pct','l'=>'%','n'=>1],['k'=>'total','l'=>'Bill total','n'=>1]],
            $rows, self::sum($rows, ['subtotal','discount','total']),
            'A large discount % repeatedly under one user is worth a look.');
    }

    /** Sirf void / cancelled bills — wajah aur user ke sath. */
    /* V174 — is report ka naam hi Void / cancelled hai, magar yeh sirf
       VOID dekhti thi: CANCEL kiye hue bill kahin nazar hi nahi aate
       thay. Ab ke wo sale ki ginti se bahar ho chuke hain, un ka kahin
       dikhna aur bhi zaroori hai — warna wo bas gayab ho jate. */
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
                AND o.order_status IN ('VOID','CANCELLED')
              ORDER BY d DESC, o.bill_no DESC",
            [site_id(), $f, $t]);

        return self::shape('Void / cancelled bills',
            [['k'=>'d','l'=>'Date'],['k'=>'bill_no','l'=>'Bill'],['k'=>'by_user','l'=>'By user'],
             ['k'=>'reason','l'=>'Reason'],['k'=>'items','l'=>'Items','n'=>1],
             ['k'=>'total','l'=>'Amount','n'=>1]],
            $rows, self::sum($rows, ['items','total']),
            'Voids without a reason, or many voids by one user — both are worth checking.');
    }

    /** Shift / day closing — opening se variance tak. */
    private static function r_shift_closing(string $f, string $t): array
    {
        /* ============================================================
           V185 — SALE AB ASAL BILLON SE GINI JATI HAI, MEHFOOZ
                  PURANE NUMBER SE NAHI.

           Malik ki shikayat: "baqi har jagah sale same ani chaiye".
           Yahi report sab se zyada bhatak rahi thi.

           WAJAH: yeh report `cashier_shifts` ke mehfooz column parhti
           thi — `gross_sales`, `invoice_count`, `cash_sales` waghera.
           Wo column sirf us soorat mein bharte hain jab shift closing ke
           poore raaste se band ho. Rig ke data par haal yeh tha:

               shift 1  mehfooz 0 bill / 0        asal 4 bill / 14,000
               shift 6  mehfooz 0 bill / 0        asal 5 bill /  5,504.80
               shift 9  mehfooz 4 bill / 1,120    asal 3 bill /    620

           Yani kuch par KHALI, aur shift 9 par to SEEDHA GHALAT. Report
           ka jama 1,120 aa raha tha jab ke us arse ki sale 57,641.30 thi.

           AB: Sale, Bills aur Discount sidhe `orders` se; Cash/Card/
           Credit `payments` se; kharcha `expenses` se — bilkul wahi
           ginti jo closing parchi (OpsService::shiftReport) aur baqi
           saari reports karti hain.

           JO MEHFOOZ HI RAHEGA: Opening float, Counted aur Variance —
           yeh daraz ki GINTI hain, bill se nahi nikalte.

           DOOSRI KHARABI ISI JAGAH: chunao SHIFT ki tareekh par tha,
           BILL ki tareekh par nahi. 31 October ka khula till agli raat
           tak chala, to us ke 6,000 ke November wale bill 1-7 November
           ki report se bahar reh jate thay. Aur jin billon par shift
           likha hi nahi (tablet/QR) wo kisi row mein aate hi nahi thay —
           1,400 aur. Total 7,400 kam.

           AB: bill us arse ke, chahe till kisi bhi din khula ho; aur
           bina-shift wale bill apni "(no account)" line mein. Is tarah
           is report ka jama Sales summary ke BARABAR aata hai.
           ============================================================ */
        $rows = self::q(
            "SELECT s.business_date d, s.shift_no, COALESCE(s.counter_name,'-') counter,
                    COALESCE(u.full_name,'-') cashier,
                    s.opening_cash,
                    (SELECT COUNT(*) FROM orders o
                      WHERE o.shift_id = s.id AND o.order_status='CLOSED'
                        AND DATE(COALESCE(o.closed_at,o.created_at)) BETWEEN ? AND ?) bills,
                    (SELECT COALESCE(SUM(o.grand_total),0) FROM orders o
                      WHERE o.shift_id = s.id AND o.order_status='CLOSED'
                        AND DATE(COALESCE(o.closed_at,o.created_at)) BETWEEN ? AND ?) gross_sales,
                    (SELECT COALESCE(SUM(o.discount_amount),0) FROM orders o
                      WHERE o.shift_id = s.id AND o.order_status='CLOSED'
                        AND DATE(COALESCE(o.closed_at,o.created_at)) BETWEEN ? AND ?) discount,
                    (SELECT COALESCE(SUM(pay.amount),0)
                       FROM payments pay
                       JOIN orders o ON o.id = pay.order_id
                       LEFT JOIN payment_methods pm ON pm.id = pay.payment_method_id
                      WHERE o.shift_id = s.id AND o.order_status='CLOSED'
                        AND DATE(COALESCE(o.closed_at,o.created_at)) BETWEEN ? AND ?
                        AND pay.status <> 'CANCELLED'
                        AND UPPER(COALESCE(pm.code,'')) IN ('CASH','COD'))      cash_sales,
                    (SELECT COALESCE(SUM(pay.amount),0)
                       FROM payments pay
                       JOIN orders o ON o.id = pay.order_id
                       LEFT JOIN payment_methods pm ON pm.id = pay.payment_method_id
                      WHERE o.shift_id = s.id AND o.order_status='CLOSED'
                        AND DATE(COALESCE(o.closed_at,o.created_at)) BETWEEN ? AND ?
                        AND pay.status <> 'CANCELLED'
                        AND UPPER(COALESCE(pm.code,'')) = 'CARD')               card_sales,
                    (SELECT COALESCE(SUM(pay.amount),0)
                       FROM payments pay
                       JOIN orders o ON o.id = pay.order_id
                       LEFT JOIN payment_methods pm ON pm.id = pay.payment_method_id
                      WHERE o.shift_id = s.id AND o.order_status='CLOSED'
                        AND DATE(COALESCE(o.closed_at,o.created_at)) BETWEEN ? AND ?
                        AND pay.status <> 'CANCELLED'
                        AND UPPER(COALESCE(pm.code,'')) NOT IN ('CASH','COD','CARD')) credit_sales,
                    s.refund_total refunds,
                    (SELECT COALESCE(SUM(e.amount),0) FROM expenses e
                      WHERE e.shift_id = s.id AND e.status <> 'REJECTED'
                        AND e.deleted_at IS NULL
                        AND e.expense_date BETWEEN ? AND ?)                     expenses,
                    s.expected_cash, s.actual_cash, s.variance_amount variance,
                    COALESCE(s.status,'-') status
               FROM cashier_shifts s
               LEFT JOIN users u ON u.id = s.cashier_user_id
              WHERE s.site_id = ? AND s.deleted_at IS NULL
                AND (s.business_date BETWEEN ? AND ?
                     OR EXISTS (SELECT 1 FROM orders o2
                                 WHERE o2.shift_id = s.id AND o2.order_status='CLOSED'
                                   AND DATE(COALESCE(o2.closed_at,o2.created_at)) BETWEEN ? AND ?))
              ORDER BY s.business_date DESC, s.shift_no DESC",
            array_merge(
              /* teen bill wali sub-query */   [$f,$t, $f,$t, $f,$t],
              /* teen payment wali */          [$f,$t, $f,$t, $f,$t],
              /* kharche */                    [$f,$t],
              /* WHERE */                      [site_id(), $f, $t, $f, $t]));

        /* Jin billon par shift likha hi nahi (tablet/QR ka order) — apni
           line mein, taake is report ka jama Sales summary se mile. */
        $orphan = self::q(
            "SELECT COUNT(*) bills, COALESCE(SUM(o.grand_total),0) gross_sales,
                    COALESCE(SUM(o.discount_amount),0) discount
               FROM orders o
              WHERE o.shift_id IS NULL AND " . self::billWhere(),
            [site_id(), $f, $t]);
        if ($orphan && (int)$orphan[0]['bills'] > 0) {
            $o = $orphan[0];
            $rows[] = ['d'=>'-','shift_no'=>'(no account)','counter'=>'-','cashier'=>'-',
                       'bills'=>(int)$o['bills'],'opening_cash'=>0,
                       'gross_sales'=>$o['gross_sales'],'discount'=>$o['discount'],
                       'cash_sales'=>0,'card_sales'=>0,'credit_sales'=>0,'refunds'=>0,
                       'expenses'=>0,'expected_cash'=>0,'actual_cash'=>0,'variance'=>0,
                       'status'=>'-'];
        }

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
                                     'actual_cash','variance']));
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
             ['k'=>'reason','l'=>'Reason'],['k'=>'qty','l'=>'Qty','n'=>1],
             ['k'=>'value','l'=>'Value','n'=>1],['k'=>'by_user','l'=>'By user'],
             ['k'=>'status','l'=>'Status']],
            $rows, self::sum($rows, ['qty','value']),
            'This comes straight out of profit — watch the monthly trend.');
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
            'If the average rate keeps rising, it is time to talk to the supplier.');
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
             ['k'=>'outstanding','l'=>'Outstanding','n'=>1]],
            $rows, self::sum($rows, ['visits','total','outstanding']),
            'Only bills with a customer attached — walk-ins are not included.');
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
                        THEN 'SENT but no number'
                      WHEN o.fiscal_status IN ('PENDING','FAILED') THEN 'Did not reach FBR'
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
             ['k'=>'verdict','l'=>'Result'],['k'=>'tax','l'=>'Tax','n'=>1],
             ['k'=>'total','l'=>'Total','n'=>1]],
            $rows, self::sum($rows, ['tax','total']),
            'Retry any bill showing "Did not reach FBR" from the Tax screen.');
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
            [['k'=>'d','l'=>'Date'],['k'=>'bills','l'=>'Bills','n'=>1,'kpi'=>1],
             ['k'=>'sent','l'=>'FBR sent','n'=>1],['k'=>'not_sent','l'=>'Not sent','n'=>1,'kpi'=>1],
             ['k'=>'taxable','l'=>'Taxable','n'=>1],['k'=>'sales_tax','l'=>'Sales tax','n'=>1,'kpi'=>1],
             ['k'=>'zero_rated','l'=>'Zero-rated','n'=>1],['k'=>'total','l'=>'Total','n'=>1,'kpi'=>1]],
            $rows, self::sum($rows, ['bills','sent','not_sent','taxable','sales_tax','zero_rated','total']),
            'These are the figures used for the FBR return at month end.');
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
            [['k'=>'at','l'=>'Date & time'],['k'=>'user','l'=>'User'],['k'=>'role','l'=>'Role'],
             ['k'=>'action','l'=>'Action'],['k'=>'module','l'=>'Module'],
             ['k'=>'detail','l'=>'Detail'],['k'=>'ip','l'=>'IP']],
            $rows, [],
            'Voids, discounts, refunds and bill edits — are all recorded here. no more than 2000 rows are shown.');
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

    /* ============================================================
       V157 — CURRENT STOCK.

       Stock ki halat ABHI ki hoti hai, is liye yeh report date range
       par nahi chalti — bilkul `low_stock` ki tarah. Note se yeh baat
       saaf rehti hai, warna customer tareekh badal kar hairan hota
       hai ke number kyun nahi badla.

       Maliyat `avg_cost_per_stock_unit` se nikalti hai — wohi weighted
       average jo Stock In ke waqt banti hai. Is liye jin items ki cost
       kabhi daali hi nahi gayi, un ki value 0 aayegi (aur yehi sach
       hai — hamein un ki laagat maloom hi nahi).
       ============================================================ */
    private static function r_stock_on_hand(string $f, string $t): array
    {
        $rows = self::q(
            "SELECT ii.name AS item, COALESCE(ic.name,'-') AS category,
                    COALESCE(u.code,'') AS unit,
                    COALESCE((SELECT SUM(sb.qty_on_hand) FROM stock_balances sb
                               WHERE sb.inventory_item_id = ii.id AND sb.site_id = ?),0) AS on_hand,
                    COALESCE(ii.avg_cost_per_stock_unit,0) AS cost,
                    ROUND(COALESCE((SELECT SUM(sb.qty_on_hand) FROM stock_balances sb
                               WHERE sb.inventory_item_id = ii.id AND sb.site_id = ?),0)
                          * COALESCE(ii.avg_cost_per_stock_unit,0), 2) AS value
               FROM inventory_items ii
               LEFT JOIN inventory_categories ic ON ic.id = ii.category_id
               LEFT JOIN units u ON u.id = ii.stock_unit_id
              WHERE ii.site_id = ? AND ii.is_active = 1 AND ii.deleted_at IS NULL
              ORDER BY ic.name, ii.name",
            [site_id(), site_id(), site_id()]);

        return self::shape('Current stock',
            [['k'=>'item','l'=>'Item'],['k'=>'category','l'=>'Category'],['k'=>'unit','l'=>'Unit'],
             ['k'=>'on_hand','l'=>'On hand','n'=>1],['k'=>'cost','l'=>'Avg cost','n'=>1],
             ['k'=>'value','l'=>'Stock value','n'=>1]],
            $rows, self::sum($rows, ['value']),
            'This shows stock as it is right now, so the date range does not apply.');
    }

    /* ============================================================
       V158 — OPENING / CLOSING STOCK.

       HISAAB KA TAREEQA — yeh samajhna zaroori hai:

         Current  = abhi jo stock_balances par para hai
         Closing  = Current minus wo har harkat jo `to` ke BAAD hui
         Opening  = Closing minus (jo arse mein aaya) plus (jo gaya)

       Yani hum PEECHE KI TARAF ginte hain, kyunke sirf mojooda balance
       hi yaqeeni hai — `stock_balances` wohi ek jagah hai jise
       `postMovement` hamesha update karta hai. Agar opening ko kisi
       alag "snapshot" table se nikalte to wo aur mojooda balance kabhi
       na kabhi alag ho jate, aur phir pata hi na chalta ke kaun sa
       sach hai.

       `Purchased` = arse ke andar jo bhi stock BARHA (Stock In, GRN,
       opening entry, transfer in, wapsi). `Sold` = jo bill ke zariye
       gaya (`reference_type='ORDER'`).

       EK BAAT SAAF REHNI CHAHIYE: wastage, stock count aur transfer
       bhi stock hilate hain, magar un ka apna column yahan nahi hai
       (aap ne nahi manga). Is liye jis din wastage ya count hui ho,
       us din `Opening + Purchased - Sold` aur `Closing` barabar nahi
       honge — farq wahi wastage/count hai. CLOSING hamesha ASAL stock
       hai; note mein yeh likha bhi aata hai. Column chahiye ho to
       bata dijiye ga, ek line ka kaam hai.
       ============================================================ */
    private static function r_stock_ledger(string $f, string $t): array
    {
        $start = $f . ' 00:00:00';
        $end   = $t . ' 23:59:59';
        $sid   = site_id();

        $rows = self::q(
            "SELECT ii.name AS item, COALESCE(u.code,'') AS unit,
                    /* abhi ka stock */
                    COALESCE((SELECT SUM(sb.qty_on_hand) FROM stock_balances sb
                               WHERE sb.inventory_item_id=ii.id AND sb.site_id=?),0) AS current_qty,
                    /* arse ke andar aaya */
                    COALESCE((SELECT SUM(stl.qty_change) FROM stock_transaction_lines stl
                               JOIN stock_transactions st ON st.id=stl.stock_transaction_id
                              WHERE stl.inventory_item_id=ii.id AND st.site_id=?
                                AND st.posted_at BETWEEN ? AND ? AND stl.qty_change>0),0) AS purchased,
                    /* arse ke andar bill se gaya */
                    COALESCE((SELECT -SUM(stl.qty_change) FROM stock_transaction_lines stl
                               JOIN stock_transactions st ON st.id=stl.stock_transaction_id
                              WHERE stl.inventory_item_id=ii.id AND st.site_id=?
                                AND st.posted_at BETWEEN ? AND ? AND stl.qty_change<0
                                AND st.reference_type='ORDER'),0) AS sold,
                    /* arse ke andar ki KUL harkat (opening peeche ginne ke liye) */
                    COALESCE((SELECT SUM(stl.qty_change) FROM stock_transaction_lines stl
                               JOIN stock_transactions st ON st.id=stl.stock_transaction_id
                              WHERE stl.inventory_item_id=ii.id AND st.site_id=?
                                AND st.posted_at BETWEEN ? AND ?),0) AS net_in_period,
                    /* `to` ke BAAD ki harkat (closing nikalne ke liye) */
                    COALESCE((SELECT SUM(stl.qty_change) FROM stock_transaction_lines stl
                               JOIN stock_transactions st ON st.id=stl.stock_transaction_id
                              WHERE stl.inventory_item_id=ii.id AND st.site_id=?
                                AND st.posted_at > ?),0) AS net_after
               FROM inventory_items ii
               LEFT JOIN units u ON u.id=ii.stock_unit_id
              WHERE ii.site_id=? AND ii.is_active=1 AND ii.deleted_at IS NULL
              ORDER BY ii.name",
            [$sid, $sid,$start,$end, $sid,$start,$end, $sid,$start,$end, $sid,$end, $sid]);

        $out = [];
        foreach ($rows as $r) {
            $current = (float)$r['current_qty'];
            $closing = $current - (float)$r['net_after'];
            $opening = $closing - (float)$r['net_in_period'];

            $n = [
                'opening'   => $opening,
                'purchased' => (float)$r['purchased'],
                'sold'      => (float)$r['sold'],
                'closing'   => $closing,
                'current'   => $current,
            ];

            /* ============================================================
               BARI UNIT MEIN DIKHAO — WARNA QTY PAISA LAGTI HAI.

               Yeh saare aankre QUANTITY hain, raqam nahi. Magar stock
               andar GRAM aur MILLILITRE mein rakha jata hai, is liye
               42 kilo chawal screen par "42,000" chhapta tha — aur wo
               bilkul rupees jaisa lagta hai.

               Inventory screen yeh masla pehle se hal karti hai
               (`formatStock`): 1000 se ooper gram ko KG aur ml ko L
               bana deti hai. Report ab wahi qanoon chalati hai, taake
               dono jagah ek hi number nazar aaye.

               AHEM: taqseem PAANCHON columns par ek sath hoti hai, aur
               faisla sab se bare aankre se hota hai — na ke sirf
               mojooda stock se. Warna jis item ka stock aaj khatam ho
               chuka ho us ka Opening 50,000 (gram) aur Current 0 (KG)
               ek hi line mein aa jata, aur ginti bekar ho jati.
               ============================================================ */
            $u   = strtoupper((string)$r['unit']);
            $max = 0.0;
            foreach ($n as $v) $max = max($max, abs($v));

            if ($max >= 1000 && ($u === 'G' || $u === 'ML')) {
                foreach ($n as $k => $v) $n[$k] = $v / 1000;
                $u = ($u === 'G') ? 'KG' : 'L';
            } elseif ($u === 'PCS') {
                $u = 'pcs';
            }

            $out[] = [
                'item'     => $r['item'],
                'unit'     => $u,
                'opening'  => round($n['opening'], 3),
                'purchased'=> round($n['purchased'], 3),
                'sold'     => round($n['sold'], 3),
                'closing'  => round($n['closing'], 3),
                'current'  => round($n['current'], 3),
            ];
        }

        return self::shape('Opening / closing stock',
            [['k'=>'item','l'=>'Item'],['k'=>'unit','l'=>'Unit'],
             ['k'=>'opening','l'=>'Opening','n'=>1],['k'=>'purchased','l'=>'Purchased','n'=>1],
             ['k'=>'sold','l'=>'Sold','n'=>1],['k'=>'closing','l'=>'Closing','n'=>1],
             ['k'=>'current','l'=>'Current stock','n'=>1]],
            /* ============================================================
               TOTALS JAAN BOOJH KAR KHALI.

               V157 mein "Current stock" par yehi sabaq mila tha: KPI
               tile har numeric column ka ban jata hai, aur yahan PCS,
               G aur ML ek sath jamaa ho kar "1.3L Purchased" jaisa
               be-maani aankra bana dete hain. 42,005 gram chawal aur
               106 bun ko jorna kis kaam ka?

               Har item ki apni line poori maloomat deti hai; neeche
               jama karne ki zaroorat hai hi nahi. */
            $out, [],
            'These are QUANTITIES, not amounts - each item in its own unit (KG, L, pcs). '
            .'For a single day\'s opening/closing, pick "Today" above. '
            .'Closing is always the real stock. On days with wastage, a stock count or a '
            .'negative stock-in (correction), Opening + Purchased - Sold can differ a '
            .'little from it - that difference is exactly the wastage/count/correction.');
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

    /* ============================================================
       V185 — SALES BY ITEM / CATEGORY KA TOTAL.

       `Sale` ka total har line ko jor kar nahi banaya jata. Wajah do:

         1. HAR LINE PAR GOL (round) HOTA HAI. Sainkron lineon par wo
            paise khisak jate hain — bilkul wohi masla jo V180 mein `net`
            par pakra gaya tha.
         2. JIN BILLON KI KOI ITEM LINE HI NAHI (purana data, ya bahar se
            aaya hua bill) wo kisi line mein aate hi nahi — un ki sale
            khamoshi se gum ho jati.

       Is liye total SEEDHA BILLON SE ginta hai: us arse ke saare band
       bill. Yani yeh total Sales summary ke HAMESHA barabar rehta hai,
       chahe kisi bill ke item hon ya na hon.
       ============================================================ */
    private static function itemTotals(array $rows, string $f, string $t): array
    {
        $x = self::sum($rows, ['qty', 'total', 'sale']);
        $bill = self::q("SELECT COALESCE(SUM(o.grand_total),0) s FROM orders o
                          WHERE " . self::billWhere(), [site_id(), $f, $t]);
        $x['sale'] = round((float)($bill[0]['s'] ?? 0), 2);
        return $x;
    }

    /**
     * Jin billon ki koi item line nahi, un ki sale apni line mein.
     *
     * Warna screen par lineon ka jama aur neeche ka Total alag nazar aate
     * hain — aur wohi shak paida hota hai jo is poore kaam ki wajah bana.
     * Asal data par aisa bill hota hi nahi; purane ya bahar se aaye huay
     * billon par hota hai, aur tab chhupane se behtar hai ke saaf likha ho.
     *
     * @param  string $label  pehle column mein kya likhna hai
     */
    private static function itemGapRow(array $rows, array $totals, string $key, bool $withCategory): array
    {
        $lines = 0.0;
        foreach ($rows as $r) $lines += (float)($r['sale'] ?? 0);
        $gap = round((float)($totals['sale'] ?? 0) - $lines, 2);
        if (abs($gap) < 0.01) return $rows;

        $row = [$key => '(bills without item lines)', 'qty' => 0, 'total' => 0, 'sale' => $gap];
        if ($withCategory) $row['category'] = '-';
        $rows[] = $row;
        return $rows;
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

// build: V178 build 2026-11-03
