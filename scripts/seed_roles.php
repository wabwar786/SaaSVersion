<?php
require_once dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
$pdo=DB::pdo();
$modules=[
'dashboard'=>'Dashboard','shift'=>'Opening & Closing Shift','pos'=>'Sale Point / POS','tablet'=>'Order Taker Tablet','kds'=>'Kitchen / KDS','tables'=>'Tables & Floors','orders'=>'Running Orders','online'=>'Online Orders','inventory'=>'Inventory','purchasing'=>'Purchasing','recipe'=>'Recipe & Food Cost','menu'=>'Menu & Categories','wastage'=>'Wastage / Adjustment','transfer'=>'Stock Transfer','count'=>'Physical Stock Count','suppliers'=>'Suppliers','customers'=>'Customers','customer_app'=>'Customer Mobile App','customer_web'=>'Customer Web / QR','delivery'=>'Delivery','riders'=>'Rider Management','reservations'=>'Reservations','loyalty'=>'Loyalty / Membership','whatsapp'=>'WhatsApp / Notifications','expenses'=>'Expenses','accounting'=>'Accounting / Cash','promotions'=>'Discounts / Promotions','staff'=>'Staff / Roles','void'=>'Void / Refund','reports'=>'Reports','fbr'=>'FBR / Digital Invoice','printers'=>'Printers / Devices','branches'=>'Multi-Branch','offline'=>'Offline / Sync','users'=>'Users & Access','settings'=>'Settings'];
$sort=1;foreach($modules as $key=>$name){$q=$pdo->prepare('SELECT id FROM platform_modules WHERE module_key=?');$q->execute([$key]);if(!$q->fetchColumn())$pdo->prepare("INSERT INTO platform_modules(id,module_key,name,industry_code,sort_order,is_active) VALUES(?,?,?,'RESTAURANT',?,1)")->execute([uuid(),$key,$name,$sort]);$sort++;}
$roles=['Owner / Admin'=>null,'Branch Manager'=>['dashboard','shift','pos','tablet','kds','tables','orders','online','inventory','purchasing','recipe','menu','wastage','transfer','count','suppliers','customers','delivery','riders','reservations','loyalty','expenses','accounting','promotions','void','reports','fbr','printers','offline','users','settings'],/* V78 — Cashier ko sirf apna kaam.
   Pehle dashboard bhi milta tha, jahan se poore branch ki sale nazar
   aati thi — halanke cashier ko sirf apna hisab dekhna chahiye. Ab
   teen cheezein: shift kholna/band karna, Sale Point, aur apni purani
   closing reports. Baqi sab wahin banta hai jo Sale Point se banta hai
   (bill, customer, KOT). */
  'Cashier'=>['shift','pos','closing'],'Waiter'=>['tablet','tables'],'Chef / Kitchen'=>['kds'],'Storekeeper'=>['dashboard','inventory','purchasing','recipe','wastage','transfer','count','suppliers','reports'],'Accountant'=>['dashboard','shift','expenses','accounting','reports','suppliers','fbr'],'Rider'=>['dashboard','delivery','riders'],'Marketing'=>['dashboard','customers','loyalty','promotions','reports']];
/* V79 — koi business hi na ho to saaf batao, crash mat karo.
   Fresh database par yeh script `tenants` mein kuch na hone ki wajah se
   FK error ke saath fatal ho jati thi — boot log mein ek na-qabil-e-fehm
   stack trace, aur baqi migrations ruk jatin. */
$tq=$pdo->prepare('SELECT COUNT(*) FROM tenants WHERE id=?');
$tq->execute([tenant_id()]);
if((int)$tq->fetchColumn()===0){
  echo "ROLES_SKIPPED no business exists yet (roles are created when a business is provisioned)\n";
  return;
}
foreach($roles as $name=>$keys){$q=$pdo->prepare('SELECT id FROM roles WHERE tenant_id=? AND name=?');$q->execute([tenant_id(),$name]);$rid=$q->fetchColumn();if(!$rid){$rid=uuid();$pdo->prepare('INSERT INTO roles(id,tenant_id,name,is_system,is_active) VALUES(?,?,?,1,1)')->execute([$rid,tenant_id(),$name]);}if($keys){foreach($keys as $k){$m=$pdo->prepare('SELECT id FROM platform_modules WHERE module_key=?');$m->execute([$k]);if($mid=$m->fetchColumn()){$e=$pdo->prepare('SELECT COUNT(*) FROM role_modules WHERE role_id=? AND module_id=?');$e->execute([$rid,$mid]);if(!$e->fetchColumn())$pdo->prepare('INSERT INTO role_modules(id,role_id,module_id,is_allowed) VALUES(?,?,?,1)')->execute([uuid(),$rid,$mid]);}}}}
echo "Roles seeded.\n";

// build: V17.1 build 2026-08-25
