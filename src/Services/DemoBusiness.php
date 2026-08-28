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
