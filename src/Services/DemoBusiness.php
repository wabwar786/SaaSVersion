<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * DemoBusiness — customer ko dikhane ke liye demo business.
 *
 * Kaam karne ka tareeqa:
 *   1. Business ban jane par `seed()` demo data daalti hai (menu, tables,
 *      customers, suppliers, expenses, kuch bills).
 *   2. Har seeded row ka id `demo_seed_rows` mein LIKHA jata hai.
 *   3. Har 5 din baad `resetCustomerData()` chalti hai: jo kuch CUSTOMER
 *      ne daala wo mit jata hai, aur jo SYSTEM ne daala tha wo bacha
 *      rehta hai.
 *
 * Sab se ahem faisla: "system ka data" pehchana IDS SE jata hai, waqt se
 * nahi. Waqt par bharosa karte to demo data bhi mit jata aur agli dafa
 * customer ko khali software milta.
 */
final class DemoBusiness
{
    public const RESET_DAYS = 5;

    /** Wo tables jo demo reset par saaf hoti hain (customer ka kaam). */
    private const WIPE = [
        'kitchen_ticket_items','kitchen_tickets','printer_jobs',
        'payments','order_item_modifiers','order_items','orders',
        'stock_transaction_lines','stock_transactions','stock_adjustments',
        'goods_receipt_items','goods_receipts',
        'shift_cash_movements','shift_handovers','cashier_shifts',
        'qr_orders','qr_sessions','expenses','reservations',
        'whatsapp_queue','audit_log','deletion_log','notification_queue',
        'recipe_ingredients','recipes',
        'menu_category_printer_routes','menu_item_variants','menu_items','menu_categories',
        'stock_balances','inventory_items','inventory_categories',
        'dining_tables','floors','printers','customers','suppliers','ui_records',
        /* RETAIL: yeh customer ka apna kaam hai, reset par jata hai.
           rtl_products / departments / brands JAAN-BOOJH KAR yahan NAHI —
           wohi demo ka sample catalog hai jo bacha rehna chahiye. */
        'rtl_sale_items','rtl_bill_reprints','rtl_sales','rtl_held_bills','rtl_customer_ledger',
    ];

    public static function isDemo(?string $tenantId = null): bool
    {
        try {
            $q = DB::pdo()->prepare("SELECT is_demo FROM tenants WHERE id=? LIMIT 1");
            $q->execute([$tenantId ?: tenant_id()]);
            return (int)$q->fetchColumn() === 1;
        } catch (\Throwable $e) { return false; }
    }

    /** Ek seeded row ka nishan — reset ise bachata hai. */
    private static function mark(PDO $p, string $tenantId, string $table, string $rowId): void
    {
        try {
            $p->prepare("INSERT IGNORE INTO demo_seed_rows(tenant_id,table_name,row_id,created_at)
                         VALUES(?,?,?,NOW(6))")->execute([$tenantId, $table, $rowId]);
        } catch (\Throwable $e) {}
    }

    /**
     * Demo data daalo. Idempotent — dobara chalane par kuch dohra nahi hota.
     */
    public static function seed(string $tenantId, string $siteId): array
    {
        $p = DB::pdo();
        $made = [];

        $chk = $p->prepare("SELECT COUNT(*) FROM demo_seed_rows WHERE tenant_id=?");
        $chk->execute([$tenantId]);
        if ((int)$chk->fetchColumn() > 0) return ['already' => true];

        /* ---- menu ---- */
        $cats = ['BBQ' => [['Chicken Tikka',450],['Seekh Kabab',380],['Malai Boti',520]],
                 'Karahi' => [['Chicken Karahi (Full)',1600],['Mutton Karahi (Half)',1450]],
                 'Rice' => [['Chicken Biryani',380],['Mutton Pulao',520]],
                 'Breads' => [['Roghni Naan',60],['Garlic Naan',80],['Plain Roti',25]],
                 'Beverages' => [['Fresh Lime',180],['Mineral Water',80],['Soft Drink',120]]];
        $i = 1; $items = [];
        foreach ($cats as $cat => $list) {
            $cid = uuid();
            $p->prepare("INSERT INTO menu_categories(id,tenant_id,site_id,name,icon_text,sort_order,is_active)
                         VALUES(?,?,?,?,'*',?,1)")->execute([$cid,$tenantId,$siteId,$cat,$i++]);
            self::mark($p,$tenantId,'menu_categories',$cid);
            foreach ($list as [$nm,$price]) {
                $mid = uuid();
                $p->prepare("INSERT INTO menu_items(id,tenant_id,site_id,category_id,name,item_type,
                                base_price,consumption_type,is_active)
                             VALUES(?,?,?,?,?,'STANDARD',?,'NONE',1)")
                  ->execute([$mid,$tenantId,$siteId,$cid,$nm,$price]);
                self::mark($p,$tenantId,'menu_items',$mid);
                $items[] = [$mid,$nm,$price];
            }
        }
        $made['menu_items'] = count($items);

        /* ---- floor + tables ---- */
        $fid = uuid();
        $p->prepare("INSERT INTO floors(id,tenant_id,site_id,name,sort_order,is_active) VALUES(?,?,?,'Main Hall',1,1)")
          ->execute([$fid,$tenantId,$siteId]);
        self::mark($p,$tenantId,'floors',$fid);
        for ($t = 1; $t <= 8; $t++) {
            $tid2 = uuid();
            $p->prepare("INSERT INTO dining_tables(id,tenant_id,site_id,floor_id,table_code,display_name,
                            seats,shape,status,is_active)
                         VALUES(?,?,?,?,?,?,?,'SQUARE','AVAILABLE',1)")
              ->execute([$tid2,$tenantId,$siteId,$fid,'T'.$t,'Table '.$t,$t<=4?4:6]);
            self::mark($p,$tenantId,'dining_tables',$tid2);
        }
        $made['tables'] = 8;

        /* ---- expense categories ---- */
        foreach (['Kitchen Supplies','Utilities','Staff','Fuel / Delivery','Cleaning','General'] as $ec) {
            $eid = uuid();
            try {
                $p->prepare("INSERT INTO expense_categories(id,tenant_id,name,is_active) VALUES(?,?,?,1)")
                  ->execute([$eid,$tenantId,$ec]);
                self::mark($p,$tenantId,'expense_categories',$eid);
            } catch (\Throwable $e) {}
        }

        /* ---- customers + suppliers ---- */
        foreach ([['Ahmed Raza','03001234567'],['Sara Khan','03119876543'],['Bilal Ahmad','03215556677']] as [$n,$ph]) {
            $cid2 = uuid();
            try {
                $p->prepare("INSERT INTO customers(id,tenant_id,full_name,phone,status) VALUES(?,?,?,?,'ACTIVE')")
                  ->execute([$cid2,$tenantId,$n,$ph]);
                self::mark($p,$tenantId,'customers',$cid2);
            } catch (\Throwable $e) {}
        }
        foreach ([['Al-Madina Traders','Rawalpindi'],['Fresh Farm Supply','Islamabad']] as [$n,$city]) {
            $sid2 = uuid();
            try {
                $p->prepare("INSERT INTO suppliers(id,tenant_id,name,city,status) VALUES(?,?,?,?,'ACTIVE')")
                  ->execute([$sid2,$tenantId,$n,$city]);
                self::mark($p,$tenantId,'suppliers',$sid2);
            } catch (\Throwable $e) {}
        }

        /* ---- printer ---- */
        $prid = uuid();
        try {
            $p->prepare("INSERT INTO printers(id,tenant_id,site_id,name,printer_type,station_code,
                            connection_type,ip_address,port_no,is_active,is_default)
                         VALUES(?,?,?,'Main Kitchen','KITCHEN','main','NETWORK','192.168.1.50',9100,1,1)")
              ->execute([$prid,$tenantId,$siteId]);
            self::mark($p,$tenantId,'printers',$prid);
        } catch (\Throwable $e) {}

        $made['seeded_rows'] = (int)$p->query("SELECT COUNT(*) FROM demo_seed_rows WHERE tenant_id="
                                . $p->quote($tenantId))->fetchColumn();
        return $made;
    }

    /**
     * Customer ka daala hua sab kuch mita do; system ka demo data rakho.
     *
     * @return array<string,int>  table => kitni rows gayin
     */
    /**
     * SUPERMARKET ka demo — asli catalog jaisa dikhne wala data.
     *
     * Restaurant wala `seed()` menu/tables/recipes daalta hai jo yahan
     * bemani hain. Retail ko chahiye: barcode wale products, ek scale
     * item, batch/expiry, khata wala customer, aur counters.
     */
    public static function seedRetail(string $tenantId, string $siteId): array
    {
        $p = DB::pdo();
        $made = [];

        $chk = $p->prepare("SELECT COUNT(*) FROM demo_seed_rows WHERE tenant_id=?");
        $chk->execute([$tenantId]);
        if ((int)$chk->fetchColumn() > 0) return ['already' => true];

        /* departments/units/counters provisionBusiness pehle hi daal chuki hai */
        $dep = [];
        $dq = $p->prepare("SELECT id,code FROM rtl_departments WHERE tenant_id=?");
        $dq->execute([$tenantId]);
        foreach ($dq->fetchAll(PDO::FETCH_ASSOC) as $r) $dep[$r['code']] = $r['id'];

        $unit = [];
        foreach ($p->query("SELECT id,code FROM units WHERE tenant_id IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $unit[$r['code']] = $r['id'];
        }

        /* ---- brands ---- */
        $brand = [];
        foreach (['Falak','Dalda','Tapal','Nestle','Olpers','Coca-Cola','Surf Excel','Lifebuoy','National','Shan'] as $bn) {
            $bid = uuid();
            $p->prepare("INSERT INTO rtl_brands(id,tenant_id,name) VALUES(?,?,?)")->execute([$bid,$tenantId,$bn]);
            self::mark($p,$tenantId,'rtl_brands',$bid);
            $brand[$bn] = $bid;
        }
        $made['brands'] = count($brand);

        /* ---- products ----
           [sku, name, dept, brand, unit, barcode, tax, cost, retail, wholesale,
            stock, min, scale?, plu, batch?] */
        $rows = [
            ['GRO-0001','Falak Super Basmati Rice 5 KG','GRO','Falak','PCS','8964000112233',0,2180,2450,2320,46,12,0,'',0],
            ['GRO-0002','Dalda Cooking Oil 5 Litre','GRO','Dalda','PCS','8964000445566',17,2680,2975,2840,31,10,0,'',1],
            ['GRO-0003','Tapal Danedar Tea 950 G','GRO','Tapal','PCS','8964000778899',17,1420,1590,1510,8,15,0,'',0],
            ['GRO-0004','National Chilli Powder 200 G','GRO','National','PCS','8964000131313',17,235,275,255,63,24,0,'',0],
            ['GRO-0005','Shan Biryani Masala 65 G','GRO','Shan','PCS','8964000141414',17,96,120,108,0,30,0,'',0],
            ['DAI-0001','Olpers Milk 1 Litre','DAI','Olpers','PCS','8964000221144',0,268,300,285,96,40,0,'',1],
            ['BEV-0001','Coca-Cola 1.5 Litre','BEV','Coca-Cola','PCS','5449000054227',17,168,200,182,142,48,0,'',0],
            ['BEV-0002','Nestle Water 1.5 Litre','BEV','Nestle','PCS','8964000101010',17,62,85,72,188,72,0,'',0],
            ['HSE-0001','Surf Excel 1 KG','HSE','Surf Excel','PCS','8964000334455',17,640,725,686,54,20,0,'',0],
            ['PCR-0001','Lifebuoy Soap 130 G','PCR','Lifebuoy','PCS','8964000667788',17,118,140,128,210,60,0,'',0],
            ['BAK-0001','Bread Large','BAK','','PCS','8964000889900',0,130,160,145,27,20,0,'',1],
            ['FNV-0001','Tomato (loose)','FNV','','KG','',0,95,140,120,38.5,15,1,'00201',0],
            ['FNV-0002','Banana (loose)','FNV','','KG','',0,130,190,165,22.8,10,1,'00202',0],
            ['FNV-0003','Potato (loose)','FNV','','KG','',0,58,90,75,71.2,25,1,'00203',0],
        ];
        $pids = [];
        foreach ($rows as $r) {
            [$sku,$nm,$dc,$bn,$uc,$bc,$tax,$cost,$ret,$whl,$stk,$min,$scale,$plu,$batch] = $r;
            $id = uuid();
            $p->prepare("INSERT INTO rtl_products(id,tenant_id,site_id,sku,name,department_id,brand_id,base_unit_id,
                            tax_rate,cost_price,retail_price,wholesale_price,stock_qty,min_stock,max_stock,
                            is_scale_item,plu_code,track_batch,status)
                         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'Active')")
              ->execute([$id,$tenantId,$siteId,$sku,$nm,($dep[$dc]??null),($brand[$bn]??null),($unit[$uc]??null),
                         $tax,$cost,$ret,$whl,$stk,$min,$min*4,$scale,$plu,$batch]);
            self::mark($p,$tenantId,'rtl_products',$id);
            $pids[$sku] = $id;
            if ($bc !== '') {
                $bid = uuid();
                $p->prepare("INSERT INTO rtl_product_barcodes(id,tenant_id,product_id,barcode) VALUES(?,?,?,?)")
                  ->execute([$bid,$tenantId,$id,$bc]);
                self::mark($p,$tenantId,'rtl_product_barcodes',$bid);
            }
        }
        $made['products'] = count($pids);

        /* ---- pack barcode: ek carton scan par 24 units ---- */
        if (isset($pids['BEV-0001'], $unit['CTN24'])) {
            $uid = uuid();
            $p->prepare("INSERT INTO rtl_product_uom(id,tenant_id,product_id,unit_id,barcode,factor,cost_price,retail_price,is_default_purchase)
                         VALUES(?,?,?,?,?,24,4032,4560,1)")
              ->execute([$uid,$tenantId,$pids['BEV-0001'],$unit['CTN24'],'5449000054234']);
            self::mark($p,$tenantId,'rtl_product_uom',$uid);
            $made['pack_barcodes'] = 1;
        }

        /* ---- batches: ek near-expiry taake alert nazar aaye ---- */
        $bt = [['GRO-0002','DL-2609','+190 days',31,2680],
               ['DAI-0001','OL-0904','+7 days',96,268],
               ['BAK-0001','BR-0903','+2 days',27,130]];
        foreach ($bt as [$sku,$no,$exp,$q,$c]) {
            if (!isset($pids[$sku])) continue;
            $id = uuid();
            $p->prepare("INSERT INTO rtl_batches(id,tenant_id,site_id,product_id,batch_no,expiry_date,qty,cost_price,received_on)
                         VALUES(?,?,?,?,?,?,?,?,CURDATE())")
              ->execute([$id,$tenantId,$siteId,$pids[$sku],$no,date('Y-m-d',strtotime($exp)),$q,$c]);
            self::mark($p,$tenantId,'rtl_batches',$id);
        }
        $made['batches'] = count($bt);

        /* ---- customers: khata wale bhi, taake credit flow dikh sake ---- */
        $cust = [['Walk-in Customer','','',0,0],
                 ['Hameed Kiryana Store','03008811223','G-11 Markaz',200000,84500],
                 ['Baithak Restaurant','0512233445','F-11 Markaz',350000,218900],
                 ['Ayesha Siddiqui','03214455667','F-10/3',15000,3200],
                 ['Rehan Aslam','03457788990','E-11/2',0,0]];
        foreach ($cust as [$n,$ph,$area,$lim,$bal]) {
            $id = uuid();
            try {
                $p->prepare("INSERT INTO customers(id,tenant_id,full_name,phone,area,customer_type,credit_limit,balance,status)
                             VALUES(?,?,?,?,?,?,?,?,'ACTIVE')")
                  ->execute([$id,$tenantId,$n,$ph,$area,($lim>0?'Wholesale':'Retail'),$lim,$bal]);
                self::mark($p,$tenantId,'customers',$id);
            } catch (\Throwable $e) {}
        }
        $made['customers'] = count($cust);

        foreach ([['Metro Cash & Carry','Rawalpindi'],['Unilever Distributor','Islamabad'],
                  ['Fresh Mandi Supply','Islamabad']] as [$n,$city]) {
            $id = uuid();
            try {
                $p->prepare("INSERT INTO suppliers(id,tenant_id,name,city,status) VALUES(?,?,?,?,'ACTIVE')")
                  ->execute([$id,$tenantId,$n,$city]);
                self::mark($p,$tenantId,'suppliers',$id);
            } catch (\Throwable $e) {}
        }
        $made['suppliers'] = 3;

        return $made;
    }

    public static function resetCustomerData(string $tenantId): array
    {
        $p = DB::pdo();
        $sq = $p->prepare("SELECT id FROM sites WHERE tenant_id=?");
        $sq->execute([$tenantId]);
        $sites = array_column($sq->fetchAll(), 'id');
        if (!$sites) return [];

        /* Kaun si rows system ki hain */
        $keep = [];
        try {
            $k = $p->prepare("SELECT table_name, row_id FROM demo_seed_rows WHERE tenant_id=?");
            $k->execute([$tenantId]);
            foreach ($k->fetchAll(PDO::FETCH_ASSOC) as $r) $keep[$r['table_name']][] = $r['row_id'];
        } catch (\Throwable $e) {}

        $out = [];
        $p->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::WIPE as $t) {
            try {
                $cols = self::cols($p, $t);
                if (!$cols) continue;

                $w = []; $a = [];
                if (in_array('tenant_id', $cols, true)) { $w[] = 'tenant_id = ?'; $a[] = $tenantId; }
                elseif (in_array('site_id', $cols, true)) {
                    $w[] = 'site_id IN ('.implode(',', array_fill(0, count($sites), '?')).')';
                    $a = array_merge($a, $sites);
                } else continue;   // child table — parent ke saath cascade

                /* System ki rows bacha lo */
                if (!empty($keep[$t]) && in_array('id', $cols, true)) {
                    $w[] = 'id NOT IN ('.implode(',', array_fill(0, count($keep[$t]), '?')).')';
                    $a = array_merge($a, $keep[$t]);
                }

                $st = $p->prepare("DELETE FROM `$t` WHERE ".implode(' AND ', $w));
                $st->execute($a);
                if ($st->rowCount() > 0) $out[$t] = $st->rowCount();
            } catch (\Throwable $e) { /* ek table na chale to baqi phir bhi saaf hon */ }
        }
        $p->exec('SET FOREIGN_KEY_CHECKS=1');

        try {
            $p->prepare("UPDATE tenants SET demo_last_reset=NOW(6) WHERE id=?")->execute([$tenantId]);
        } catch (\Throwable $e) {}

        return $out;
    }

    /** Jin demo businesses ka waqt aa gaya — cron/boot se chalta hai. */
    public static function runDueResets(): array
    {
        $done = [];
        try {
            $q = DB::pdo()->prepare(
                "SELECT id, name FROM tenants
                  WHERE is_demo=1 AND deleted_at IS NULL
                    AND (demo_last_reset IS NULL
                         OR demo_last_reset < DATE_SUB(NOW(), INTERVAL ? DAY))");
            $q->execute([self::RESET_DAYS]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $n = self::resetCustomerData((string)$t['id']);
                $done[(string)$t['name']] = array_sum($n);
            }
        } catch (\Throwable $e) {}
        return $done;
    }

    private static function cols(PDO $p, string $t): array
    {
        static $c = [];
        if (isset($c[$t])) return $c[$t];
        try {
            $q = $p->prepare("SELECT column_name AS c FROM information_schema.columns
                               WHERE table_schema=DATABASE() AND table_name=?");
            $q->execute([$t]);
            return $c[$t] = array_column($q->fetchAll(), 'c');
        } catch (\Throwable $e) { return $c[$t] = []; }
    }
}

// build: V79 build 2026-08-28
