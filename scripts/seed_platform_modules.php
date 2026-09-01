<?php
// Seed the GLOBAL platform_modules catalog (no tenant FK). Idempotent.
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;
$pdo=DB::pdo();
$modules=['dashboard'=>'Dashboard','shift'=>'Opening & Closing Shift','pos'=>'Sale Point / POS','tablet'=>'Order Taker Tablet','kds'=>'Kitchen / KDS','tables'=>'Tables & Floors','orders'=>'Running Orders','online'=>'Online Orders','inventory'=>'Inventory','purchasing'=>'Purchasing','recipe'=>'Recipe & Food Cost','menu'=>'Menu & Categories','wastage'=>'Wastage / Adjustment','transfer'=>'Stock Transfer','count'=>'Physical Stock Count','suppliers'=>'Suppliers','customers'=>'Customers','customer_app'=>'Customer Mobile App','customer_web'=>'Customer Web / QR','delivery'=>'Delivery','riders'=>'Rider Management','reservations'=>'Reservations','loyalty'=>'Loyalty / Membership','whatsapp'=>'WhatsApp / Notifications','expenses'=>'Expenses','accounting'=>'Accounting / Cash','promotions'=>'Discounts / Promotions','staff'=>'Staff / Roles','void'=>'Void / Refund','reports'=>'Reports','po'=>'Purchase Orders','closing'=>'Shift Closing History','activity'=>'User Activity Log','fbr'=>'FBR / Digital Invoice','printers'=>'Printers / Devices','branches'=>'Multi-Branch','offline'=>'Offline / Sync','users'=>'Users & Access','settings'=>'Settings'];
$sort=1;$added=0;
foreach($modules as $key=>$name){
  $q=$pdo->prepare('SELECT id FROM platform_modules WHERE module_key=?');$q->execute([$key]);
  /* V62.2 — id ab uuid() se NAHI, module_key se derive hoti hai.
     Warna cloud aur har node par ek hi module ka id alag hota tha aur
     user_module_access / role_modules ki rows doosri taraf ja kar kisi
     module se match hi nahi karti thin -> khamoshi se "0 Modules". */
  if(!$q->fetchColumn()){$pdo->prepare("INSERT INTO platform_modules(id,module_key,name,industry_code,sort_order,is_active) VALUES(?,?,?,'RESTAURANT',?,1)")->execute([module_uuid($key),$key,$name,$sort]);$added++;}
  $sort++;
}
echo "PLATFORM_MODULES_READY total=".count($modules)." added=$added\n";

// build: V17.1 build 2026-08-25
