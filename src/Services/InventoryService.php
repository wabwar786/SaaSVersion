<?php
namespace Aio\Services;
use Aio\DB;
use PDO;
final class InventoryService {
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

// build: V17.1 build 2026-08-25
