<?php
namespace Aio\Services;
use Aio\DB;use PDO;
final class PosService {
 public static function sendKot(array $d,array $items): array { return DB::tx(function(PDO $p)use($d,$items){$order=self::ensureOpenOrder($p,$d);$pending=[];foreach($items as $i){$row=self::upsertOrderItem($p,$order,$i);$pd=max(0,(float)$row['qty']-(float)$row['sent_qty']);if($pd>0)$pending[]=['row'=>$row,'pending'=>$pd,'input'=>$i];}if(!$pending)return ['order_id'=>$order,'sent'=>0];$groups=[];foreach($pending as $x){$pr=self::printerForMenu($p,$x['row']['menu_item_id']);if(!$pr)continue;$groups[$pr['id']]['printer']=$pr;$groups[$pr['id']]['items'][]=$x;} $sent=0;foreach($groups as $g){$kt=uuid();$ticket='KOT-'.strtoupper(substr(str_replace('-','',$kt),0,8));$p->prepare("INSERT INTO kitchen_tickets(id,tenant_id,site_id,order_id,ticket_no,printer_id,station_code,ticket_status,sent_at,created_by_user_id) VALUES(?,?,?,?,?,?,?,'NEW',NOW(6),?)")->execute([$kt,tenant_id(),site_id(),$order,$ticket,$g['printer']['id'],$g['printer']['station_code'],current_user()['id']??null]);$lines=[];foreach($g['items'] as $x){$p->prepare("INSERT INTO kitchen_ticket_items(id,kitchen_ticket_id,order_item_id,qty_sent,item_status,note_snapshot) VALUES(?,?,?,?, 'NEW',?)")->execute([uuid(),$kt,$x['row']['id'],$x['pending'],$x['row']['kitchen_note']]);$p->prepare("UPDATE order_items SET sent_qty=qty,updated_at=NOW(6) WHERE id=?")->execute([$x['row']['id']]);$lines[]=$x['pending'].' x '.$x['row']['item_name_snapshot'];$sent++;}$payload=$ticket."\n".implode("\n",$lines);$p->prepare("INSERT INTO printer_jobs(id,tenant_id,site_id,printer_id,job_type,reference_type,reference_id,payload_text,status) VALUES(?,?,?,?, 'KOT','KITCHEN_TICKET',?,?, 'PENDING')")->execute([uuid(),tenant_id(),site_id(),$g['printer']['id'],$kt,$payload]);}self::queueSync($p,'orders',$order,'UPDATE');return ['order_id'=>$order,'sent'=>$sent];}); }
 public static function finalize(array $d,array $items): string { return DB::tx(function(PDO $p)use($d,$items){$order=self::ensureOpenOrder($p,$d);$subtotal=0;foreach($items as $i){$row=self::upsertOrderItem($p,$order,$i);$subtotal+=(float)$row['qty']*(float)$row['unit_price'];}$discount=(float)($d['discount_amount']??0);$service=(float)($d['service_charge']??0);$tax=(float)($d['tax_amount']??0);$grand=$subtotal-$discount+$service+$tax;$received=(float)($d['received_amount']??$grand);$p->prepare("UPDATE orders SET order_status='CLOSED',payment_status='PAID',subtotal=?,discount_amount=?,service_charge=?,tax_amount=?,grand_total=?,paid_amount=?,change_amount=?,closed_at=NOW(6),updated_at=NOW(6) WHERE id=?")->execute([$subtotal,$discount,$service,$tax,$grand,$grand,max(0,$received-$grand),$order]);$exists=$p->prepare("SELECT COUNT(*) FROM payments WHERE order_id=? AND status='COMPLETED'");$exists->execute([$order]);if(!(int)$exists->fetchColumn()){$pm=$p->prepare("SELECT id FROM payment_methods WHERE site_id=? AND code=? AND is_active=1 LIMIT 1");$pm->execute([site_id(),self::paymentCode($d['payment_code']??'Cash')]);$pmid=$pm->fetchColumn();if(!$pmid)throw new \RuntimeException('Payment method not configured');$p->prepare("INSERT INTO payments(id,tenant_id,site_id,order_id,shift_id,payment_method_id,amount,received_amount,change_amount,status,paid_at,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,'COMPLETED',NOW(6),?)")->execute([uuid(),tenant_id(),site_id(),$order,$d['shift_id']?:null,$pmid,$grand,$received,max(0,$received-$grand),current_user()['id']??null]);}
  $st=$p->prepare("SELECT COUNT(*) FROM stock_transactions WHERE reference_type='ORDER' AND reference_id=? AND transaction_type='SALE'");$st->execute([$order]);if(!(int)$st->fetchColumn()){self::postInventory($p,$order,$d['bill_no'],$items);}self::sendPendingInsideTx($p,$order);self::queueSync($p,'orders',$order,'UPDATE');return $order;}); }
 private static function ensureOpenOrder(PDO $p,array $d):string{
    $bill=ltrim((string)$d['bill_no'],'#');
    $q=$p->prepare("SELECT id FROM orders WHERE site_id=? AND business_date=? AND bill_no=? LIMIT 1 FOR UPDATE");
    $q->execute([site_id(),today(),$bill]);$id=$q->fetchColumn();
    $table=self::tableId($p,$d['table_name']??'');
    $customer=null;
    $rawCustomer=(string)($d['customer_id']??'');
    if($rawCustomer && $rawCustomer!=='walkin'){
        if(preg_match('/^[0-9a-f-]{36}$/i',$rawCustomer)){
            $cq=$p->prepare("SELECT id FROM customers WHERE id=? AND tenant_id=? LIMIT 1");
            $cq->execute([$rawCustomer,tenant_id()]);$customer=$cq->fetchColumn()?:null;
        }
        if(!$customer && !empty($d['customer_name'])){
            $cq=$p->prepare("SELECT id FROM customers WHERE tenant_id=? AND full_name=? LIMIT 1");
            $cq->execute([tenant_id(),$d['customer_name']]);$customer=$cq->fetchColumn()?:null;
        }
    }
    $mode=self::modeCode($d['service_mode']??'Dine In');
    if($id){
        $p->prepare("UPDATE orders SET service_mode=?,table_id=?,customer_id=?,shift_id=?,guest_count=?,updated_at=NOW(6) WHERE id=?")->execute([$mode,$table,$customer,$d['shift_id']?:null,$d['guest_count']?:null,$id]);
        return$id;
    }
    $id=uuid();
    $p->prepare("INSERT INTO orders(id,tenant_id,site_id,bill_no,business_date,order_source,service_mode,order_status,payment_status,table_id,customer_id,shift_id,guest_count,opened_at,created_by_user_id) VALUES(?,?,?,?,?,'POS',?,'OPEN','UNPAID',?,?,?,?,NOW(6),?)")->execute([$id,tenant_id(),site_id(),$bill,today(),$mode,$table,$customer,$d['shift_id']?:null,$d['guest_count']?:null,current_user()['id']??null]);
    return$id;
 }
 private static function resolveMenuId(PDO $p,array $i):string{
    $raw=(string)($i['menu_item_id']??$i['id']??'');
    if($raw && preg_match('/^[0-9a-f-]{36}$/i',$raw)){
        $q=$p->prepare("SELECT id FROM menu_items WHERE id=? AND site_id=? AND deleted_at IS NULL LIMIT 1");
        $q->execute([$raw,site_id()]);
        if($id=$q->fetchColumn())return$id;
    }
    $base=trim((string)($i['base_name']??$i['name']??''));
    $q=$p->prepare("SELECT id FROM menu_items WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");
    $q->execute([site_id(),$base]);
    if($id=$q->fetchColumn())return$id;
    foreach([' Large',' Medium',' Small'] as $suffix){
        if(str_ends_with($base,$suffix)){
            $n=substr($base,0,-strlen($suffix));
            $q->execute([site_id(),$n]);
            if($id=$q->fetchColumn())return$id;
        }
    }
    throw new \RuntimeException('Menu item not found in database: '.($i['name']??$base));
 }
 private static function upsertOrderItem(PDO $p,string $order,array $i):array{
    $mid=self::resolveMenuId($p,$i);
    $name=$i['name']??'';
    $q=$p->prepare("SELECT id,sent_qty FROM order_items WHERE order_id=? AND menu_item_id=? AND item_name_snapshot=? AND status='ACTIVE' LIMIT 1 FOR UPDATE");
    $q->execute([$order,$mid,$name]);
    $old=$q->fetch();
    $qty=(float)$i['qty'];
    $rate=(float)($i['unit_price']??$i['unitPrice']??0);
    $note=$i['note']??'';
    $m=$p->prepare("SELECT mi.name,mi.consumption_type,mi.direct_inventory_item_id,mi.direct_inventory_qty,r.id recipe_id,r.version_no,r.yield_qty FROM menu_items mi LEFT JOIN recipes r ON r.menu_item_id=mi.id AND r.is_current=1 WHERE mi.id=?");
    $m->execute([$mid]);$menu=$m->fetch();
    if(!$menu)throw new \RuntimeException('Menu item not found in database: '.$name);
    if($old){
        $p->prepare("UPDATE order_items SET qty=?,unit_price=?,line_total=?,kitchen_note=?,updated_at=NOW(6) WHERE id=?")->execute([$qty,$rate,$qty*$rate,$note,$old['id']]);
        $id=$old['id'];
    }else{
        $id=uuid();$snap=null;
        if($menu['recipe_id']){
            $iq=$p->prepare("SELECT inventory_item_id,qty_per_yield,waste_pct FROM recipe_ingredients WHERE recipe_id=?");
            $iq->execute([$menu['recipe_id']]);$snap=$iq->fetchAll();
        }
        $p->prepare("INSERT INTO order_items(id,tenant_id,site_id,order_id,menu_item_id,item_name_snapshot,qty,sent_qty,unit_price,line_total,kitchen_note,recipe_id_snapshot,recipe_version_snapshot,recipe_snapshot_json,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ACTIVE')")->execute([$id,tenant_id(),site_id(),$order,$mid,$name,$qty,0,$rate,$qty*$rate,$note,$menu['recipe_id']?:null,$menu['version_no']?:null,$snap?json_encode($snap):null]);
    }
    $row=$p->prepare("SELECT * FROM order_items WHERE id=?");$row->execute([$id]);return$row->fetch();
 }
 private static function sendPendingInsideTx(PDO $p,string $order):void{$q=$p->prepare("SELECT oi.*,mi.category_id FROM order_items oi JOIN menu_items mi ON mi.id=oi.menu_item_id WHERE oi.order_id=? AND oi.qty>oi.sent_qty AND oi.status='ACTIVE'");$q->execute([$order]);$rows=$q->fetchAll();$groups=[];foreach($rows as $r){$pr=self::printerForMenu($p,$r['menu_item_id']);if(!$pr)continue;$groups[$pr['id']]['printer']=$pr;$groups[$pr['id']]['rows'][]=$r;}foreach($groups as $g){$kt=uuid();$ticket='KOT-'.strtoupper(substr(str_replace('-','',$kt),0,8));$p->prepare("INSERT INTO kitchen_tickets(id,tenant_id,site_id,order_id,ticket_no,printer_id,station_code,ticket_status,sent_at,created_by_user_id) VALUES(?,?,?,?,?,?,?,'NEW',NOW(6),?)")->execute([$kt,tenant_id(),site_id(),$order,$ticket,$g['printer']['id'],$g['printer']['station_code'],current_user()['id']??null]);$lines=[];foreach($g['rows'] as $r){$pending=(float)$r['qty']-(float)$r['sent_qty'];$p->prepare("INSERT INTO kitchen_ticket_items(id,kitchen_ticket_id,order_item_id,qty_sent,item_status,note_snapshot) VALUES(?,?,?,?, 'NEW',?)")->execute([uuid(),$kt,$r['id'],$pending,$r['kitchen_note']]);$p->prepare("UPDATE order_items SET sent_qty=qty WHERE id=?")->execute([$r['id']]);$lines[]=$pending.' x '.$r['item_name_snapshot'];}$p->prepare("INSERT INTO printer_jobs(id,tenant_id,site_id,printer_id,job_type,reference_type,reference_id,payload_text,status) VALUES(?,?,?,?, 'KOT','KITCHEN_TICKET',?,?, 'PENDING')")->execute([uuid(),tenant_id(),site_id(),$g['printer']['id'],$kt,$ticket."\n".implode("\n",$lines)]);}}
 private static function postInventory(PDO $p,string $order,string $bill,array $items):void{$loc=self::defaultLocation($p);$moves=[];foreach($items as $i){$mid=self::resolveMenuId($p,$i);$m=$p->prepare("SELECT mi.consumption_type,mi.direct_inventory_item_id,mi.direct_inventory_qty,r.id recipe_id,r.yield_qty FROM menu_items mi LEFT JOIN recipes r ON r.menu_item_id=mi.id AND r.is_current=1 WHERE mi.id=?");$m->execute([$mid]);$x=$m->fetch();if(!$x)continue;$qty=(float)$i['qty'];$oi=$p->prepare("SELECT id FROM order_items WHERE order_id=? AND menu_item_id=? AND item_name_snapshot=? LIMIT 1");$oi->execute([$order,$mid,$i['name']]);$oid=$oi->fetchColumn();if($x['consumption_type']==='DIRECT_INVENTORY'&&$x['direct_inventory_item_id'])$moves[]=(object)['item_id'=>$x['direct_inventory_item_id'],'location_id'=>$loc,'qty'=>-$qty*(float)$x['direct_inventory_qty'],'unit_cost'=>self::cost($p,$x['direct_inventory_item_id']),'source_order_item_id'=>$oid];elseif($x['consumption_type']==='RECIPE'&&$x['recipe_id']){$ri=$p->prepare("SELECT inventory_item_id,qty_per_yield,waste_pct FROM recipe_ingredients WHERE recipe_id=?");$ri->execute([$x['recipe_id']]);foreach($ri->fetchAll() as $r){$need=((float)$r['qty_per_yield']*$qty/max(.000001,(float)$x['yield_qty']))*(1+(float)$r['waste_pct']/100);$moves[]=(object)['item_id'=>$r['inventory_item_id'],'location_id'=>$loc,'qty'=>-$need,'unit_cost'=>self::cost($p,$r['inventory_item_id']),'source_order_item_id'=>$oid];}}}if($moves)InventoryService::postMovement($p,'SALE','ORDER',$order,$bill,$moves,current_user()['id']??null);}
 private static function printerForMenu(PDO $p,string $mid):?array{$q=$p->prepare("SELECT p.id,p.name,p.station_code FROM menu_items mi JOIN menu_category_printer_routes r ON r.category_id=mi.category_id AND r.is_active=1 JOIN printers p ON p.id=r.printer_id AND p.is_active=1 WHERE mi.id=? ORDER BY r.is_primary DESC,r.route_priority LIMIT 1");$q->execute([$mid]);return$q->fetch()?:null;}
 private static function defaultLocation(PDO $p):string{$q=$p->prepare("SELECT id FROM stock_locations WHERE site_id=? AND is_active=1 ORDER BY FIELD(location_type,'KITCHEN','STORE','COLD_ROOM','BAR'),name LIMIT 1");$q->execute([site_id()]);$id=$q->fetchColumn();if(!$id)throw new \RuntimeException('No stock location configured');return$id;}
 private static function cost(PDO $p,string $id):float{$q=$p->prepare("SELECT avg_cost_per_stock_unit FROM inventory_items WHERE id=?");$q->execute([$id]);return(float)$q->fetchColumn();}
 private static function tableId(PDO $p,string $name):?string{if(!$name)return null;$q=$p->prepare("SELECT id FROM dining_tables WHERE site_id=? AND display_name=? LIMIT 1");$q->execute([site_id(),$name]);return$q->fetchColumn()?:null;}
 private static function modeCode(string $m):string{$m=strtoupper(str_replace(' ','_',trim($m)));return $m==='HOME_DELIVERY'?'DELIVERY':($m==='DINE_IN'?'DINE_IN':($m==='TAKEAWAY'?'TAKEAWAY':'DELIVERY'));}
 private static function paymentCode(string $m):string{$x=strtoupper(str_replace([' ','/'],'_',trim($m)));return ['BANK_TRANSFER'=>'BANK','SPLIT_PAYMENT'=>'CASH'][$x]??$x;}
 private static function queueSync(PDO $p,string $table,string $id,string $op):void{$q=$p->prepare("SELECT id FROM sync_nodes WHERE tenant_id=? AND site_id=? AND node_type='EDGE' AND status='ACTIVE' LIMIT 1");$q->execute([tenant_id(),site_id()]);$node=$q->fetchColumn();if(!$node)return;$p->prepare("INSERT INTO sync_outbox(id,tenant_id,site_id,source_node_id,entity_table,entity_id,operation_type,payload_json,idempotency_key,status) VALUES(?,?,?,?,?,?,?,?,?,'PENDING')")->execute([uuid(),tenant_id(),site_id(),$node,$table,$id,$op,json_encode(['id'=>$id]),uuid()]);}
}
