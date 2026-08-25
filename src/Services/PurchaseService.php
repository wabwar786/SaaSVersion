<?php
namespace Aio\Services;
use Aio\DB;
use PDO;
final class PurchaseService {
    public static function receive(array $d,array $lines): string {
        return DB::tx(function(PDO $pdo) use($d,$lines){
            $grn=uuid();$total=0;$mov=[];
            foreach($lines as $l){$total+=(float)$l['purchase_qty']*(float)$l['unit_cost'];}
            $pdo->prepare("INSERT INTO goods_receipts(id,tenant_id,site_id,grn_no,supplier_id,supplier_invoice_no,received_at,total_amount,status,received_by_user_id) VALUES(?,?,?,?,?,?,NOW(6),?,'POSTED',?)")
                ->execute([$grn,tenant_id(),site_id(),$d['grn_no'],$d['supplier_id'],$d['supplier_invoice_no']?:null,$total,current_user()['id']??null]);
            foreach($lines as $l){
                $stockQty=(float)$l['purchase_qty']*(float)$l['purchase_factor'];$stockCost=(float)$l['unit_cost']/(float)$l['purchase_factor'];
                $pdo->prepare('INSERT INTO goods_receipt_items(id,goods_receipt_id,inventory_item_id,purchase_qty,purchase_factor,stock_qty_received,unit_cost,batch_no,expiry_date,line_total) VALUES(?,?,?,?,?,?,?,?,?,?)')
                    ->execute([uuid(),$grn,$l['item_id'],$l['purchase_qty'],$l['purchase_factor'],$stockQty,$l['unit_cost'],$l['batch_no']?:null,$l['expiry_date']?:null,(float)$l['purchase_qty']*(float)$l['unit_cost']]);
                $mov[]=(object)['item_id'=>$l['item_id'],'location_id'=>$l['location_id'],'qty'=>$stockQty,'unit_cost'=>$stockCost,'source_order_item_id'=>null];
            }
            InventoryService::postMovement($pdo,'PURCHASE','GRN',$grn,$d['grn_no'],$mov,current_user()['id']??null);
            self::queueSync($pdo,'goods_receipts',$grn,'INSERT');
            return $grn;
        });
    }
    private static function queueSync(PDO $pdo,string $table,string $id,string $op): void {
        $node=$pdo->prepare("SELECT id FROM sync_nodes WHERE tenant_id=? AND site_id=? AND node_type='EDGE' AND status='ACTIVE' LIMIT 1");$node->execute([tenant_id(),site_id()]);$nodeId=$node->fetchColumn();
        if(!$nodeId)return;$key=uuid();$pdo->prepare("INSERT INTO sync_outbox(id,tenant_id,site_id,source_node_id,entity_table,entity_id,operation_type,payload_json,idempotency_key,status) VALUES(?,?,?,?,?,?,?,?,?,'PENDING')")->execute([uuid(),tenant_id(),site_id(),$nodeId,$table,$id,$op,json_encode(['id'=>$id]),$key]);
    }
}

// build: V17.1 build 2026-08-25
