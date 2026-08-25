<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';

use Aio\DB;
use Aio\Services\UserService;

$p=DB::pdo();
function scalar(PDO $p,string $sql,array $a=[]){$q=$p->prepare($sql);$q->execute($a);return$q->fetchColumn();}
function idBy(PDO $p,string $table,string $field,string $value,string $extra='',array $extraArgs=[]){
  $sql="SELECT id FROM $table WHERE $field=? $extra LIMIT 1";$q=$p->prepare($sql);$q->execute(array_merge([$value],$extraArgs));return$q->fetchColumn();
}

// Demo cashier user.
$q=$p->prepare("SELECT id FROM users WHERE tenant_id=? AND email='ali@urbanspoon.local' LIMIT 1");$q->execute([tenant_id()]);$ali=$q->fetchColumn();
if(!$ali){
  $rq=$p->prepare("SELECT id FROM roles WHERE tenant_id=? AND name='Cashier' LIMIT 1");$rq->execute([tenant_id()]);$rid=$rq->fetchColumn();
  $mods=[];$mq=$p->prepare("SELECT pm.id FROM role_modules rm JOIN platform_modules pm ON pm.id=rm.module_id WHERE rm.role_id=? AND rm.is_allowed=1");$mq->execute([$rid]);$mods=array_column($mq->fetchAll(),'id');
  $ali=UserService::create(['full_name'=>'Ali Raza','email'=>'ali@urbanspoon.local','username'=>'ali','phone'=>'03011112233','password'=>'1234','role_id'=>$rid,'modules'=>$mods,'is_admin'=>0]);
}

// Pending signup.
$q=$p->prepare("SELECT COUNT(*) FROM signup_requests WHERE email='hamza@example.com' AND status='PENDING'");$q->execute();
if(!(int)$q->fetchColumn()){
  [$hash,$algo]=UserService::passwordHash('1234');
  $p->prepare("INSERT INTO signup_requests(id,tenant_id,requested_org_name,full_name,email,phone,password_hash,status,requested_at) VALUES(?,?,?,'Hamza Khan','hamza@example.com','03214455667',?,'PENDING',NOW(6))")->execute([uuid(),tenant_id(),'Urban Spoon',$hash]);
}

// Open shift.
$q=$p->prepare("SELECT id FROM cashier_shifts WHERE site_id=? AND status='OPEN' LIMIT 1");$q->execute([site_id()]);$shift=$q->fetchColumn();
if(!$shift){$shift=uuid();$p->prepare("INSERT INTO cashier_shifts(id,tenant_id,site_id,shift_no,business_date,cashier_user_id,opened_at,opening_cash,status,created_at) VALUES(?,?,?,'S-2048',CURDATE(),?,NOW(6),25000,'OPEN',NOW(6))")->execute([$shift,tenant_id(),site_id(),$ali]);}

// Riders.
for($i=1;$i<=5;$i++){
  $name=['Bilal Khan','Ahsan Raza','Hamid Ali','Shahzaib','Owais Khan'][$i-1];
  $q=$p->prepare("SELECT id FROM riders WHERE site_id=? AND name=? LIMIT 1");$q->execute([site_id(),$name]);
  if(!$q->fetchColumn())$p->prepare("INSERT INTO riders(id,tenant_id,site_id,name,phone,vehicle_no,status,cash_held,created_at) VALUES(?,?,?,?,?,?,?,0,NOW(6))")->execute([uuid(),tenant_id(),site_id(),$name,'0300'.str_pad((string)(1000000+$i),7,'0',STR_PAD_LEFT),'BIKE-'.$i,$i<=2?'ON_DELIVERY':'AVAILABLE']);
}

// Reservations.
$demoRes=[
 ['RSV-DEMO-1','Ahmed Khan','03001234567','+45 minutes',4],
 ['RSV-DEMO-2','Sarah Malik','03214455667','+2 hours',2],
 ['RSV-DEMO-3','Usman Ali','03339988776','+4 hours',6]
];
foreach($demoRes as $r){
  $q=$p->prepare("SELECT id FROM reservations WHERE site_id=? AND reservation_no=? LIMIT 1");$q->execute([site_id(),$r[0]]);
  if(!$q->fetchColumn())$p->prepare("INSERT INTO reservations(id,tenant_id,site_id,reservation_no,guest_name,guest_phone,reservation_at,guest_count,status,created_at) VALUES(?,?,?,?,?,?,?,?,'CONFIRMED',NOW(6))")->execute([uuid(),tenant_id(),site_id(),$r[0],$r[1],$r[2],date('Y-m-d H:i:s',strtotime($r[3])),$r[4]]);
}

// Expenses.
foreach([['Kitchen Supplies',3200],['Fuel / Delivery',1800],['Cleaning',1250]] as $e){
  $q=$p->prepare("SELECT id FROM expense_categories WHERE tenant_id=? AND name=? LIMIT 1");$q->execute([tenant_id(),$e[0]]);$cid=$q->fetchColumn();
  if(!$cid){$cid=uuid();$p->prepare("INSERT INTO expense_categories(id,tenant_id,name,is_active) VALUES(?,?,?,1)")->execute([$cid,tenant_id(),$e[0]]);}
  $ref='DEMO-EXP-'.preg_replace('/\D/','',(string)$e[1]);
  $q=$p->prepare("SELECT id FROM expenses WHERE site_id=? AND expense_no=? LIMIT 1");$q->execute([site_id(),$ref]);
  if(!$q->fetchColumn())$p->prepare("INSERT INTO expenses(id,tenant_id,site_id,expense_no,expense_date,category_id,amount,payment_method,description,status,created_by_user_id,created_at) VALUES(?,?,?,?,CURDATE(),?,?,'CASH','Demo restaurant expense','APPROVED',?,NOW(6))")->execute([uuid(),tenant_id(),site_id(),$ref,$cid,$e[1],$ali]);
}

// Promotions.
foreach([['Lunch Deal','PERCENT','LUNCH20'],['Family Combo','FIXED','FAMILY500']] as $pr){
  $q=$p->prepare("SELECT id FROM promotions WHERE site_id=? AND name=? LIMIT 1");$q->execute([site_id(),$pr[0]]);
  if(!$q->fetchColumn())$p->prepare("INSERT INTO promotions(id,tenant_id,site_id,name,promotion_type,code,starts_at,ends_at,rules_json,is_active,created_at) VALUES(?,?,?,?,?,?,NOW(6),DATE_ADD(NOW(6),INTERVAL 30 DAY),?,1,NOW(6))")->execute([uuid(),tenant_id(),site_id(),$pr[0],$pr[1],$pr[2],json_encode(['demo'=>true])]);
}

// Staff profiles.
foreach([['Saad Ali','Waiter'],['Chef Imran','Chef / Kitchen'],['Farhan Store','Storekeeper']] as $s){
  $q=$p->prepare("SELECT id FROM employee_profiles WHERE site_id=? AND full_name=? LIMIT 1");$q->execute([site_id(),$s[0]]);
  if(!$q->fetchColumn())$p->prepare("INSERT INTO employee_profiles(id,tenant_id,site_id,full_name,job_title,employment_status,created_at) VALUES(?,?,?,?,?,'ACTIVE',NOW(6))")->execute([uuid(),tenant_id(),site_id(),$s[0],$s[1]]);
}

// Notification queue.
$q=$p->prepare("SELECT COUNT(*) FROM notification_queue WHERE site_id=? AND template_key='DEMO_ORDER_READY'");$q->execute([site_id()]);
if(!(int)$q->fetchColumn())$p->prepare("INSERT INTO notification_queue(id,tenant_id,site_id,channel,recipient,template_key,payload_json,status,attempts,available_at) VALUES(?,?,?,?,?,'DEMO_ORDER_READY',?,'PENDING',0,NOW(6))")->execute([uuid(),tenant_id(),site_id(),'WHATSAPP','03001234567',json_encode(['bill'=>'#0024'])]);

// A few database orders so reports/API also have real demo records.
$menuNames=['Chicken Biryani','Fajita Pizza','Zinger Burger','Mint Margarita'];
$payCodes=['CASH','CARD','RAAST','CASH'];
$amounts=[4860,2380,3290,6150];
foreach(range(1,4) as $idx){
  $bill='DB-DEMO-'.str_pad((string)$idx,2,'0',STR_PAD_LEFT);
  $q=$p->prepare("SELECT id FROM orders WHERE site_id=? AND business_date=CURDATE() AND bill_no=? LIMIT 1");$q->execute([site_id(),$bill]);$oid=$q->fetchColumn();
  if(!$oid){
    $oid=uuid();$mode=$idx===2?'TAKEAWAY':($idx===4?'DELIVERY':'DINE_IN');
    $p->prepare("INSERT INTO orders(id,tenant_id,site_id,bill_no,business_date,order_source,service_mode,order_status,payment_status,shift_id,subtotal,grand_total,paid_amount,opened_at,closed_at,created_by_user_id,created_at) VALUES(?,?,?,?,CURDATE(),'POS',?,'CLOSED','PAID',?,?,?,?,DATE_SUB(NOW(6),INTERVAL ? MINUTE),DATE_SUB(NOW(6),INTERVAL ? MINUTE),?,NOW(6))")->execute([$oid,tenant_id(),site_id(),$bill,$mode,$shift,$amounts[$idx-1],$amounts[$idx-1],$amounts[$idx-1],120-$idx*15,115-$idx*15,$ali]);
    $mq=$p->prepare("SELECT id,base_price FROM menu_items WHERE site_id=? AND name=? LIMIT 1");$mq->execute([site_id(),$menuNames[$idx-1]]);$mi=$mq->fetch();
    if($mi)$p->prepare("INSERT INTO order_items(id,tenant_id,site_id,order_id,menu_item_id,item_name_snapshot,qty,sent_qty,unit_price,line_total,status,created_at) VALUES(?,?,?,?,?,?,1,1,?,?,'ACTIVE',NOW(6))")->execute([uuid(),tenant_id(),site_id(),$oid,$mi['id'],$menuNames[$idx-1],$mi['base_price'],$mi['base_price']]);
    $pm=$p->prepare("SELECT id FROM payment_methods WHERE site_id=? AND code=? LIMIT 1");$pm->execute([site_id(),$payCodes[$idx-1]]);$pmid=$pm->fetchColumn();
    if($pmid)$p->prepare("INSERT INTO payments(id,tenant_id,site_id,order_id,shift_id,payment_method_id,amount,received_amount,change_amount,status,paid_at,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,0,'COMPLETED',NOW(6),?)")->execute([uuid(),tenant_id(),site_id(),$oid,$shift,$pmid,$amounts[$idx-1],$amounts[$idx-1],$ali]);
  }
}

// One pending online order.
$q=$p->prepare("SELECT COUNT(*) FROM online_order_details WHERE site_id=? AND external_order_no='ON-2098'");$q->execute([site_id()]);
if(!(int)$q->fetchColumn()){
  $oid=uuid();$p->prepare("INSERT INTO orders(id,tenant_id,site_id,bill_no,business_date,order_source,service_mode,order_status,payment_status,subtotal,grand_total,opened_at,created_by_user_id,created_at) VALUES(?,?,?,?,CURDATE(),'CUSTOMER_APP','DELIVERY','OPEN','UNPAID',2980,2980,NOW(6),?,NOW(6))")->execute([$oid,tenant_id(),site_id(),'ON-2098',$ali]);
  $p->prepare("INSERT INTO online_order_details(id,tenant_id,site_id,order_id,channel_code,external_order_no,acceptance_status,requested_at) VALUES(?,?,?,?, 'APP','ON-2098','PENDING',NOW(6))")->execute([uuid(),tenant_id(),site_id(),$oid]);
}

echo "V13 full restaurant demo database ready.\n";

// build: V17.1 build 2026-08-25
