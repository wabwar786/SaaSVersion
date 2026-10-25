<?php
namespace Aio\Services;
use Aio\DB;
use PDO;
final class InventoryService {
    /* ============================================================
       V146 — DEFAULT STOCK LOCATION, ek hi qanoon.

       MASLA JO TEST MEIN PAKRA GAYA:
       Char jagah se location chuni jati thi aur TEEN alag tareeqe thay —
         PosService      -> FIELD(location_type,'KITCHEN','STORE',...)
         StockDayService -> FIELD(location_type,'STORE','KITCHEN',...)
         MenuItemService -> ORDER BY name
         ModuleBridge    -> ORDER BY name
       Jahan do location hon (Kitchen aur Main Store — demo mein dono
       bante hain), wahan opening stock ek jagah jata, sale doosri jagah
       se katti, aur Stock In teesri jagah barhata. Har raasta apne
       hisaab se theek, magar mila kar stock DO khaton mein bat jata aur
       avg cost dono taraf alag chalta. Kisi screen par error nahi aata —
       bas number kabhi sahi nahi hota.

       Test mein saaf nazar aaya: Stock In ne "+24 -> on hand 24" kaha,
       jabke usi item ke 47 pehle se doosri location par pade thay.

       Ab sab yahi bulate hain. Tarteeb POS wali rakhi gayi hai (KITCHEN
       pehle), kyunke bikri ka purana data pehle se usi par hai — warna
       history do hisson mein bat jati.
       ============================================================ */
    public static function defaultLocationId(?PDO $pdo = null): string
    {
        $pdo = $pdo ?: DB::pdo();
        $q = $pdo->prepare(
            "SELECT id FROM stock_locations
              WHERE site_id=? AND is_active=1
              ORDER BY FIELD(location_type,'KITCHEN','STORE','COLD_ROOM','BAR'), name
              LIMIT 1"
        );
        $q->execute([site_id()]);
        $id = $q->fetchColumn();
        if (!$id) throw new \RuntimeException('No stock location configured');
        return (string)$id;
    }

    public static function createCategory(string $name): string {
        $id=uuid(); DB::pdo()->prepare('INSERT INTO inventory_categories(id,tenant_id,site_id,name) VALUES(?,?,?,?)')->execute([$id,tenant_id(),site_id(),$name]); return $id;
    }
    public static function createItem(array $d): string {
        return DB::tx(function(PDO $pdo) use($d){
            $id=uuid();
            $pdo->prepare("INSERT INTO inventory_items(id,tenant_id,site_id,category_id,sku,barcode,name,usage_mode,stock_unit_id,purchase_unit_name,purchase_factor,avg_cost_per_stock_unit,reorder_level,track_batch,track_expiry,default_storage_location_id,is_active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)")
                ->execute([$id,tenant_id(),site_id(),$d['category_id']?:null,$d['sku']?:null,$d['barcode']?:null,$d['name'],$d['usage_mode'],$d['stock_unit_id'],$d['purchase_unit_name'],$d['purchase_factor'],$d['avg_cost'],$d['reorder_level'],!empty($d['track_batch'])?1:0,!empty($d['track_expiry'])?1:0,$d['location_id']?:null]);
            if((float)$d['opening_qty']!=0 && $d['location_id']) self::postMovement($pdo,'OPENING',null,null,'Opening Stock',[(object)['item_id'=>$id,'location_id'=>$d['location_id'],'qty'=>(float)$d['opening_qty'],'unit_cost'=>(float)$d['avg_cost'],'source_order_item_id'=>null]], current_user()['id']??null);
            return $id;
        });
    }
    /**
     * V162 — STOCK KO EXACT NUMBER PAR LE AAO (ginti ke baad, ya sab sifar).
     *
     * YEH KYUN BANA
     * Stock sifar karne ka koi raasta hi nahi tha. Jo tareeqe thay:
     *   • Stock In popup se har item par alag alag minus — 50 item ho to
     *     50 dafa, aur ek ghalti se number aur bigar jata.
     *   • Seedha SQL `UPDATE stock_balances SET qty_on_hand=0` — number
     *     to sifar ho jata magar `stock_transactions` mein koi entry na
     *     hoti, is liye inventory report ka Opening/Used/Closing us din
     *     ke baad mel khana chhor deta.
     *   • `node_reset.php` — wo stock ke sath orders, shifts aur payments
     *     bhi uraa deta hai. Sirf handover ke liye hai.
     *
     * Ab yeh ek ASAL adjustment transaction post karta hai. Faida sirf
     * report ka nahi: `stock_transactions`, `stock_transaction_lines`
     * aur `stock_balances` teenon sync ki push list mein hain, is liye
     * offline par kiya gaya adjustment agle sync par KHUD cloud tak
     * pohanch jata hai — kuch alag se karne ki zaroorat nahi.
     *
     * HAR LOCATION THEEK HOTI HAI. Stock ek se ziada jagah para ho sakta
     * hai (Kitchen + Store). Sirf default location ko haath lagane se
     * kul number wahi ka wahi reh jata, is liye: target default location
     * par, baqi sab sifar.
     *
     * @param array<int,array{id:string,qty:float}> $targets
     * @return array{items:int,lines:int,tx:?string}
     */
    public static function adjustTo(array $targets, string $reason, ?string $userId = null): array
    {
        if (!$targets) return ['items' => 0, 'lines' => 0, 'tx' => null];

        return DB::tx(function (PDO $pdo) use ($targets, $reason, $userId) {
            $def   = self::defaultLocationId($pdo);
            $lines = [];
            $touched = 0;

            foreach ($targets as $t) {
                $itemId = (string)($t['id'] ?? '');
                if ($itemId === '') continue;
                $target = round((float)($t['qty'] ?? 0), 6);
                if ($target < 0) throw new \RuntimeException('Quantity cannot be negative');

                $q = $pdo->prepare(
                    "SELECT sb.stock_location_id loc, sb.qty_on_hand qty,
                            ii.avg_cost_per_stock_unit cost
                       FROM stock_balances sb
                       JOIN inventory_items ii ON ii.id = sb.inventory_item_id
                      WHERE sb.inventory_item_id=? AND sb.site_id=?");
                $q->execute([$itemId, site_id()]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC);

                $cost = 0.0; $hasDefault = false; $any = false;
                foreach ($rows as $b) {
                    $cost  = (float)$b['cost'];
                    $isDef = ((string)$b['loc'] === $def);
                    if ($isDef) $hasDefault = true;
                    $delta = round(($isDef ? $target : 0.0) - (float)$b['qty'], 6);
                    if ($delta == 0.0) continue;
                    $lines[] = (object)['item_id' => $itemId, 'location_id' => (string)$b['loc'],
                                        'qty' => $delta, 'unit_cost' => $cost,
                                        'source_order_item_id' => null];
                    $any = true;
                }

                /* Default location par koi row hi na ho aur stock daalna ho */
                if (!$hasDefault && $target != 0.0) {
                    if ($cost == 0.0) {
                        $cq = $pdo->prepare("SELECT avg_cost_per_stock_unit FROM inventory_items WHERE id=? LIMIT 1");
                        $cq->execute([$itemId]);
                        $cost = (float)$cq->fetchColumn();
                    }
                    $lines[] = (object)['item_id' => $itemId, 'location_id' => $def,
                                        'qty' => $target, 'unit_cost' => $cost,
                                        'source_order_item_id' => null];
                    $any = true;
                }

                if ($any) $touched++;
            }

            if (!$lines) return ['items' => 0, 'lines' => 0, 'tx' => null];

            $ref = trim($reason) !== '' ? trim($reason) : 'Stock adjustment';
            $tx  = self::postMovement($pdo, 'ADJUSTMENT', null, null, substr($ref, 0, 80), $lines, $userId);
            return ['items' => $touched, 'lines' => count($lines), 'tx' => $tx];
        });
    }

    public static function postMovement(PDO $pdo,string $type,?string $refType,?string $refId,?string $refNo,array $lines,?string $userId): string {
        $tx=uuid();
        $pdo->prepare('INSERT INTO stock_transactions(id,tenant_id,site_id,transaction_type,business_date,reference_type,reference_id,reference_no,posted_by_user_id) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$tx,tenant_id(),site_id(),$type,today(),$refType,$refId,$refNo,$userId]);
        foreach($lines as $l){
            $qty=(float)$l->qty;$cost=(float)$l->unit_cost;$value=$qty*$cost;
            $pdo->prepare('INSERT INTO stock_transaction_lines(id,stock_transaction_id,inventory_item_id,stock_location_id,qty_change,unit_cost,value_change,source_order_item_id) VALUES(?,?,?,?,?,?,?,?)')->execute([uuid(),$tx,$l->item_id,$l->location_id,$qty,$cost,$value,$l->source_order_item_id??null]);
            $q=$pdo->prepare('SELECT id,qty_on_hand,avg_cost FROM stock_balances WHERE stock_location_id=? AND inventory_item_id=? FOR UPDATE');$q->execute([$l->location_id,$l->item_id]);$b=$q->fetch();
            if($b){
                $oldQty=(float)$b['qty_on_hand'];$oldAvg=(float)$b['avg_cost'];$newQty=$oldQty+$qty;$newAvg=$oldAvg;
                if($qty>0 && $newQty>0) $newAvg=(($oldQty*$oldAvg)+($qty*$cost))/$newQty;
                $pdo->prepare('UPDATE stock_balances SET qty_on_hand=?,avg_cost=?,row_version=row_version+1 WHERE id=?')->execute([$newQty,$newAvg,$b['id']]);
                $pdo->prepare('UPDATE inventory_items SET avg_cost_per_stock_unit=? WHERE id=?')->execute([$newAvg,$l->item_id]);
            } else {
                $pdo->prepare('INSERT INTO stock_balances(id,tenant_id,site_id,stock_location_id,inventory_item_id,qty_on_hand,avg_cost) VALUES(?,?,?,?,?,?,?)')->execute([uuid(),tenant_id(),site_id(),$l->location_id,$l->item_id,$qty,$qty>0?$cost:0]);
            }
        }
        return $tx;
    }
}

// build: V162 build 2026-10-24
