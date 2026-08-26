<?php
declare(strict_types=1);require_once dirname(__DIR__).'/src/bootstrap.php';
use Aio\Auth;use Aio\DB;use Aio\Csrf;use Aio\Services\PageData;use Aio\Services\UserService;use Aio\Services\InventoryService;use Aio\Services\PurchaseService;use Aio\Services\RecipeService;use Aio\Services\PosService;use Aio\Services\Sync;use Aio\Services\Platform;use Aio\Services\ModuleBridge;
header('Content-Type: application/json; charset=utf-8');
function body():array{$x=json_decode(file_get_contents('php://input'),true);return is_array($x)?$x:[];}function ok($x=[]):never{echo json_encode(['ok'=>true]+$x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}function fail($m,$s=400):never{http_response_code($s);echo json_encode(['ok'=>false,'message'=>$m],JSON_UNESCAPED_UNICODE);exit;}function csrf_json(){if($_SERVER['REQUEST_METHOD']==='POST'){try{Csrf::verifyOrFail($_SERVER['HTTP_X_CSRF_TOKEN']??'');}catch(Throwable $e){fail('Security token expired. Refresh the screen and try again.',419);}}}
function shift_report(array $sh, ?string $until): array {
  $p=DB::pdo();$from=$sh['opened_at'];$to=$until?:date('Y-m-d H:i:s.u');
  $mq=$p->prepare("SELECT pm.method_type t, COALESCE(SUM(py.amount),0) a, COUNT(*) n FROM payments py JOIN payment_methods pm ON pm.id=py.payment_method_id WHERE py.site_id=? AND py.status='COMPLETED' AND py.paid_at>=? AND py.paid_at<=? GROUP BY pm.method_type");
  $mq->execute([site_id(),$from,$to]);$methods=[];$sales=0.0;foreach($mq->fetchAll() as $m){$methods[$m['t']]=['amount'=>(float)$m['a'],'count'=>(int)$m['n']];$sales+=(float)$m['a'];}
  $oq=$p->prepare("SELECT COUNT(*) c, COALESCE(SUM(grand_total),0) g, MIN(bill_no) f, MAX(bill_no) l FROM orders WHERE site_id=? AND order_status='CLOSED' AND closed_at>=? AND closed_at<=?");
  $oq->execute([site_id(),$from,$to]);$o=$oq->fetch();
  $eq=$p->prepare("SELECT COALESCE(SUM(CASE WHEN payment_method='CASH' THEN amount ELSE 0 END),0) cashx, COALESCE(SUM(amount),0) allx FROM expenses WHERE site_id=? AND status='APPROVED' AND created_at>=? AND created_at<=?");
  $eq->execute([site_id(),$from,$to]);$e=$eq->fetch();
  $cash=(float)($methods['CASH']['amount']??0);$expected=(float)$sh['opening_cash']+$cash-(float)$e['cashx'];
  return ['shift_no'=>$sh['shift_no'],'opened_at'=>substr((string)$sh['opened_at'],0,16),'opening_cash'=>(float)$sh['opening_cash'],
          'orders'=>(int)$o['c'],'gross_sales'=>(float)$o['g'],'first_bill'=>$o['f'],'last_bill'=>$o['l'],
          'sales_total'=>$sales,'by_method'=>$methods,'cash_sales'=>$cash,
          'cash_expenses'=>(float)$e['cashx'],'all_expenses'=>(float)$e['allx'],'expected_cash'=>$expected];
}
function pos_bill_guard(array $d):string{$bill=ltrim((string)($d['bill_no']??''),'#');$p=DB::pdo();if($bill!==''){$q=$p->prepare("SELECT order_status FROM orders WHERE site_id=? AND business_date=? AND bill_no=? LIMIT 1");$q->execute([site_id(),today(),$bill]);$st=$q->fetchColumn();if($st===false||$st==='OPEN')return $bill;}return (string)\Aio\Services\PageData::nextBill();}
function needLogin(){if(!Auth::user())fail('Login required',401);}function needSuper(){if(!Platform::superUser())fail('Super admin login required',401);}
function syncToken(){$t=$_SERVER['HTTP_X_SYNC_TOKEN']??'';$want=(string)(cfg('sync.token')?:'');if($want===''||!hash_equals($want,(string)$t))fail('Invalid sync token',401);}function moduleId($key){$q=DB::pdo()->prepare("SELECT id FROM platform_modules WHERE module_key=? LIMIT 1");$q->execute([$key]);return$q->fetchColumn();}function roleIdByName($name){$q=DB::pdo()->prepare("SELECT id FROM roles WHERE tenant_id=? AND name=? LIMIT 1");$q->execute([tenant_id(),$name]);return$q->fetchColumn();}
function accessState():array{$p=DB::pdo();$rolesQ=$p->prepare("SELECT id,name FROM roles WHERE tenant_id=? AND is_active=1 ORDER BY name");$rolesQ->execute([tenant_id()]);$roles=[];foreach($rolesQ->fetchAll() as $r){$m=$p->prepare("SELECT pm.module_key FROM role_modules rm JOIN platform_modules pm ON pm.id=rm.module_id WHERE rm.role_id=? AND rm.is_allowed=1 ORDER BY pm.sort_order");$m->execute([$r['id']]);$roles[]=['id'=>$r['id'],'name'=>$r['name'],'modules'=>array_column($m->fetchAll(),'module_key')];}$users=[];$req=[];if(Auth::user()){$uq=$p->prepare("SELECT u.*,COALESCE(r.name,IF(u.is_tenant_admin=1,'Owner / Admin','User')) role_name,COALESCE(s.name,'All Branches') branch_name FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id LEFT JOIN sites s ON s.id=ur.site_id WHERE u.tenant_id=? AND u.deleted_at IS NULL GROUP BY u.id ORDER BY u.created_at DESC");$uq->execute([tenant_id()]);foreach($uq->fetchAll() as $u){$mods=Auth::moduleKeys($u['id']);$users[]=['id'=>$u['id'],'name'=>$u['full_name'],'email'=>$u['email'],'phone'=>$u['phone']?:'','role'=>$u['role_name'],'status'=>ucfirst(strtolower($u['status'])),'branch'=>$u['branch_name'],'modules'=>$mods,'permissions'=>['view'=>true,'add'=>false,'edit'=>false,'delete'=>false,'approve'=>(bool)$u['is_tenant_admin']], 'password'=>''];}$rq=$p->query("SELECT * FROM signup_requests WHERE status='PENDING' ORDER BY requested_at DESC");foreach($rq->fetchAll() as $r)$req[]=['id'=>$r['id'],'name'=>$r['full_name'],'email'=>$r['email'],'phone'=>$r['phone']?:'','business'=>$r['requested_org_name']?:'Restaurant','requestedAt'=>$r['requested_at'],'status'=>'Pending'];}else{$email=$_SESSION['pending_signup_email']??null;if($email){$q=$p->prepare("SELECT * FROM signup_requests WHERE email=? AND status='PENDING' ORDER BY requested_at DESC LIMIT 1");$q->execute([$email]);if($r=$q->fetch())$req[]=['id'=>$r['id'],'name'=>$r['full_name'],'email'=>$r['email'],'phone'=>$r['phone']?:'','business'=>$r['requested_org_name']?:'Restaurant','requestedAt'=>$r['requested_at'],'status'=>'Pending'];}}return['users'=>$users,'requests'=>$req,'roles'=>$roles];}
function applyUser(string $id,array $d,bool $create=false,?string $requestId=null):string{$p=DB::pdo();$role=roleIdByName($d['role']??'Cashier');$mods=[];foreach($d['modules']??[] as $k)if($m=moduleId($k))$mods[]=$m;$perm=$d['permissions']??[];if($create){return UserService::create(['full_name'=>$d['name'],'email'=>$d['email'],'username'=>$d['username']??'','phone'=>$d['phone']??'','password'=>$d['password']?:'1234','role_id'=>$role,'modules'=>$mods,'is_admin'=>($d['role']??'')==='Owner / Admin','form_permissions'=>[]],$requestId);}return DB::tx(function($p)use($id,$d,$role,$mods){$p->prepare("UPDATE users SET full_name=?,email=?,phone=?,updated_at=NOW(6) WHERE id=? AND tenant_id=?")->execute([$d['name'],$d['email'],$d['phone']??'',$id,tenant_id()]);if(!empty($d['password'])){[$h,$a]=UserService::passwordHash($d['password']);$p->prepare("UPDATE users SET password_hash=?,password_algo=? WHERE id=?")->execute([$h,$a,$id]);}$p->prepare("DELETE FROM user_roles WHERE user_id=?")->execute([$id]);$p->prepare("DELETE FROM user_module_access WHERE user_id=?")->execute([$id]);if($role)$p->prepare("INSERT INTO user_roles(id,user_id,role_id,site_id,assigned_by) VALUES(?,?,?,?,?)")->execute([uuid(),$id,$role,site_id(),current_user()['id']??null]);foreach($mods as $m)$p->prepare("INSERT INTO user_module_access(id,user_id,site_id,module_id,access_mode) VALUES(?,?,?,?, 'ALLOW')")->execute([uuid(),$id,site_id(),$m]);return$id;});}
try{$a=$_GET['action']??'';if($_SERVER['REQUEST_METHOD']==='POST' && !in_array($a,['login','signup','setup','sync-push','sync-pull','sync-ping','sa-login'],true))csrf_json();switch($a){
case 'current-user':
    $u=Auth::user();
    if(!$u) fail('Not logged in',401);

    $role='Owner / Admin';
    if(empty($u['is_tenant_admin'])){
        try{
            $p=DB::pdo();
            $q=$p->prepare("SELECT r.name
                              FROM user_roles ur
                              JOIN roles r ON r.id=ur.role_id
                             WHERE ur.user_id=?
                             ORDER BY ur.assigned_at DESC
                             LIMIT 1");
            $q->execute([$u['id']]);
            $role=$q->fetchColumn()?:'User';
        }catch(Throwable $e){
            $role='User';
        }
    }

    ok(['user'=>[
        'id'=>$u['id'],
        'name'=>$u['full_name'],
        'email'=>$u['email'],
        'role'=>$role,
        'modules'=>$u['modules']??[],
        'isAdmin'=>(bool)($u['is_tenant_admin']??false)
    ]]);

case 'login':
    $d=body();
    $email=trim((string)($d['email']??''));
    $password=(string)($d['password']??'');

    if($email==='' || $password===''){
        fail('Email/login and password are required.',422);
    }

    // Cloud multi-tenant: scope login to the business identified by its slug.
    if(cfg('app.role')==='cloud'){
        $slug=trim((string)($d['b']??($_SESSION['login_tenant_slug']??'')));
        if($slug!==''){ $tid=\Aio\Services\Platform::tenantIdBySlug($slug); if($tid){$_SESSION['login_tenant_id']=$tid;} }
    }

    if(!Auth::login($email,$password)){
        fail('Invalid login or account not approved.',401);
    }

    $tid=(string)($_SESSION['user']['tenant_id']??'');
    if($tid!=='' && ($blockMsg=Auth::subscriptionBlock($tid))!==null){
        Auth::logout();
        fail($blockMsg,403);
    }

    $u=Auth::user();
    if(!$u){
        fail('Login succeeded but the local session could not be created.',500);
    }

    ok(['user'=>[
        'id'=>$u['id'],
        'name'=>$u['full_name'],
        'email'=>$u['email'],
        'modules'=>$u['modules']??[],
        'isAdmin'=>(bool)($u['is_tenant_admin']??false)
    ]]);

case 'logout':Auth::logout();ok();
case 'setup':$d=body();$p=DB::pdo();$q=$p->prepare("SELECT COUNT(*) FROM users WHERE tenant_id=? AND is_tenant_admin=1 AND status='ACTIVE'");$q->execute([tenant_id()]);if((int)$q->fetchColumn())fail('Administrator already exists.');if(empty($d['name'])||empty($d['email'])||empty($d['password']))fail('Name, email and password are required.');$mods=array_column($p->query("SELECT id FROM platform_modules WHERE is_active=1")->fetchAll(),'id');UserService::create(['full_name'=>$d['name'],'email'=>$d['email'],'username'=>$d['username']??'admin','phone'=>'','password'=>$d['password'],'role_id'=>roleIdByName('Owner / Admin'),'modules'=>$mods,'is_admin'=>1]);ok();
case 'signup':$d=body();if(empty($d['name'])||empty($d['email'])||empty($d['password']))fail('Name, email and password are required.');$p=DB::pdo();$q=$p->prepare("SELECT COUNT(*) FROM users WHERE tenant_id=? AND email=?");$q->execute([tenant_id(),$d['email']]);$exists=(int)$q->fetchColumn();$q=$p->prepare("SELECT COUNT(*) FROM signup_requests WHERE email=? AND status='PENDING'");$q->execute([$d['email']]);$exists+=(int)$q->fetchColumn();if($exists)fail('Email already registered or pending.');UserService::signup(['full_name'=>$d['name'],'email'=>$d['email'],'phone'=>$d['phone']??'','business'=>$d['business']??'Restaurant','password'=>$d['password']]);$_SESSION['pending_signup_email']=$d['email'];ok();
case 'access-state':ok(['state'=>accessState()]);
case 'user-create':needLogin();Auth::requireModule('users');$d=body();$id=applyUser('', $d,true);ok(['id'=>$id,'state'=>accessState()]);
case 'user-update':needLogin();Auth::requireModule('users');$d=body();applyUser($d['id'],$d,false);ok(['state'=>accessState()]);
case 'signup-approve':needLogin();Auth::requireModule('users');$d=body();$p=DB::pdo();$q=$p->prepare("SELECT * FROM signup_requests WHERE id=? AND status='PENDING'");$q->execute([$d['id']]);$r=$q->fetch();if(!$r)fail('Request not found.');$role=roleIdByName($d['role']??'Cashier');$mods=[];foreach($d['modules']??[] as $k)if($m=moduleId($k))$mods[]=$m;UserService::create(['full_name'=>$r['full_name'],'email'=>$r['email'],'username'=>'','phone'=>$r['phone']??'','password'=>'','password_hash_override'=>$r['password_hash'],'password_algo'=>'ARGON2ID','role_id'=>$role,'modules'=>$mods,'is_admin'=>0],$r['id']);ok(['state'=>accessState()]);
case 'signup-reject':needLogin();Auth::requireModule('users');$d=body();DB::pdo()->prepare("UPDATE signup_requests SET status='REJECTED',reviewed_by_user_id=?,reviewed_at=NOW(6) WHERE id=? AND status='PENDING'")->execute([current_user()['id'],$d['id']]);ok(['state'=>accessState()]);
case 'dashboard-state':needLogin();ok(['state'=>PageData::dashboard()]);
case 'store-state':needLogin();ok(['state'=>PageData::storeState()]);
case 'inventory-category-create':needLogin();Auth::requireModule('inventory');$d=body();InventoryService::createCategory(trim($d['name']??''));ok(['state'=>PageData::storeState()]);
case 'inventory-item-create':needLogin();Auth::requireModule('inventory');$d=body();$p=DB::pdo();$cat=$p->prepare("SELECT id FROM inventory_categories WHERE site_id=? AND name=? LIMIT 1");$cat->execute([site_id(),$d['category']]);$cid=$cat->fetchColumn();$unitCode=strtoupper($d['stockUnit']==='piece'?'PCS':$d['stockUnit']);$u=$p->prepare("SELECT id FROM units WHERE code=? LIMIT 1");$u->execute([$unitCode]);$uid=$u->fetchColumn();$l=$p->prepare("SELECT id FROM stock_locations WHERE site_id=? AND name=? LIMIT 1");$l->execute([site_id(),$d['storage']]);$lid=$l->fetchColumn()?:$p->query("SELECT id FROM stock_locations WHERE site_id=".$p->quote(site_id())." LIMIT 1")->fetchColumn();$usage=['Recipe Ingredient'=>'RECIPE_INGREDIENT','Direct Sale'=>'DIRECT_SALE','Both'=>'BOTH'][$d['usage']]??'RECIPE_INGREDIENT';InventoryService::createItem(['name'=>$d['name'],'category_id'=>$cid,'sku'=>$d['sku']??'','barcode'=>$d['barcode']??'','usage_mode'=>$usage,'stock_unit_id'=>$uid,'purchase_unit_name'=>$d['purchaseUnit'],'purchase_factor'=>(float)$d['purchaseFactor'],'avg_cost'=>(float)$d['avgStockCost'],'reorder_level'=>(float)$d['reorderQty'],'opening_qty'=>(float)$d['stockQty'],'location_id'=>$lid,'track_batch'=>!empty($d['batch']),'track_expiry'=>!empty($d['expiry'])]);ok(['state'=>PageData::storeState()]);
case 'purchase-receive':needLogin();Auth::requireModule('purchasing');$d=body();$p=DB::pdo();$supplierName=$d['meta']['supplier']??'Supplier';$q=$p->prepare("SELECT id FROM suppliers WHERE tenant_id=? AND name=? LIMIT 1");$q->execute([tenant_id(),$supplierName]);$sid=$q->fetchColumn();if(!$sid){$sid=uuid();$p->prepare("INSERT INTO suppliers(id,tenant_id,site_id,name,status) VALUES(?,?,?,?,'ACTIVE')")->execute([$sid,tenant_id(),site_id(),$supplierName]);}$lines=[];foreach($d['lines'] as $x){$itemId=(string)($x['itemId']??'');$iq=$p->prepare("SELECT id,purchase_factor,default_storage_location_id FROM inventory_items WHERE id=? AND site_id=?");$iq->execute([$itemId,site_id()]);$it=$iq->fetch();if(!$it&&!empty($x['itemName'])){$iq=$p->prepare("SELECT id,purchase_factor,default_storage_location_id FROM inventory_items WHERE site_id=? AND name=? LIMIT 1");$iq->execute([site_id(),$x['itemName']]);$it=$iq->fetch();}if(!$it)continue;$lines[]=['item_id'=>$it['id'],'purchase_qty'=>(float)$x['purchaseQty'],'purchase_factor'=>(float)$it['purchase_factor'],'unit_cost'=>(float)$x['unitCost'],'location_id'=>$it['default_storage_location_id']?:$p->query("SELECT id FROM stock_locations WHERE site_id=".$p->quote(site_id())." LIMIT 1")->fetchColumn(),'batch_no'=>'','expiry_date'=>''];}$grn=$d['meta']['reference']??('GRN-'.date('Ymd-His'));PurchaseService::receive(['grn_no'=>$grn,'supplier_id'=>$sid,'supplier_invoice_no'=>''],$lines);$amount=array_sum(array_map(fn($x)=>(float)$x['purchaseQty']*(float)$x['unitCost'],$d['lines']));ok(['amount'=>$amount,'movements'=>$lines,'state'=>PageData::storeState()]);
case 'store-save-state':needLogin();$d=body();$p=DB::pdo();foreach($d['menuCategories']??[] as $c){$q=$p->prepare("SELECT id FROM menu_categories WHERE site_id=? AND name=? LIMIT 1");$q->execute([site_id(),$c['name']]);$cid=$q->fetchColumn();if(!$cid){$cid=uuid();$p->prepare("INSERT INTO menu_categories(id,tenant_id,site_id,name,is_active) VALUES(?,?,?,?,1)")->execute([$cid,tenant_id(),site_id(),$c['name']]);}$station=strtoupper($c['printer']??'MAIN');$pr=$p->prepare("SELECT id FROM printers WHERE site_id=? AND UPPER(station_code)=? AND is_active=1 LIMIT 1");$pr->execute([site_id(),$station]);$pid=$pr->fetchColumn();if($pid){$p->prepare("DELETE FROM menu_category_printer_routes WHERE category_id=?")->execute([$cid]);$p->prepare("INSERT INTO menu_category_printer_routes(id,tenant_id,site_id,category_id,printer_id,is_primary,is_active) VALUES(?,?,?,?,?,1,1)")->execute([uuid(),tenant_id(),site_id(),$cid,$pid]);}}ok(['state'=>PageData::storeState()]);
case 'recipe-save':needLogin();Auth::requireModule('recipe');$d=body();$p=DB::pdo();$cq=$p->prepare("SELECT id FROM menu_categories WHERE site_id=? AND name=? LIMIT 1");$cq->execute([site_id(),$d['category']]);$cid=$cq->fetchColumn();if(!$cid)fail('Menu category not found.');$menuId=$d['id']??'';if($menuId&&!preg_match('/^[0-9a-f-]{36}$/i',$menuId))$menuId='';$ingredients=[];foreach($d['ingredients']??[] as $x){$iid=(string)($x['itemId']??'');if(!preg_match('/^[0-9a-f-]{36}$/i',$iid)&&!empty($x['itemName'])){$iq=$p->prepare("SELECT id FROM inventory_items WHERE site_id=? AND name=? LIMIT 1");$iq->execute([site_id(),$x['itemName']]);$iid=$iq->fetchColumn()?:'';}if($iid)$ingredients[]=['item_id'=>$iid,'qty'=>(float)$x['qty'],'waste_pct'=>(float)($x['wastePct']??0)];}$id=RecipeService::save(['menu_item_id'=>$menuId,'category_id'=>$cid,'code'=>'','name'=>$d['menuName'],'description'=>'','consumption_type'=>$d['mode']==='direct'?'DIRECT_INVENTORY':'RECIPE','direct_inventory_item_id'=>(function()use($p,$d){$iid=$d['inventoryItemId']??null;if($iid&&preg_match('/^[0-9a-f-]{36}$/i',(string)$iid))return$iid;if(!empty($d['inventoryItemName'])){$q=$p->prepare("SELECT id FROM inventory_items WHERE site_id=? AND name=? LIMIT 1");$q->execute([site_id(),$d['inventoryItemName']]);return$q->fetchColumn()?:null;}return null;})(),'direct_inventory_qty'=>$d['directQty']??null,'base_price'=>0,'yield_qty'=>$d['yieldQty']??1],$ingredients);$state=PageData::storeState();$recipe=null;foreach($state['recipes'] as $r)if($r['id']===$id)$recipe=$r;ok(['recipe'=>$recipe?:['id'=>$id]+$d,'state'=>$state]);
case 'pos-next-bill':needLogin();ok(['next'=>PageData::nextBill()]);
case 'pos-diagnostics':needLogin();$p=DB::pdo();$out=['tenant_id'=>tenant_id(),'site_id'=>site_id(),'role'=>cfg('app.role')];
$sq=$p->prepare("SELECT name FROM sites WHERE id=?");$sq->execute([site_id()]);$out['site_name']=$sq->fetchColumn()?:'(site row missing!)';
$c=function($sql,$a)use($p){$q=$p->prepare($sql);$q->execute($a);return (int)$q->fetchColumn();};
$out['menu_items_this_site']=$c("SELECT COUNT(*) FROM menu_items WHERE site_id=? AND deleted_at IS NULL",[site_id()]);
$out['menu_items_pos_visible']=$c("SELECT COUNT(*) FROM menu_items WHERE site_id=? AND deleted_at IS NULL AND is_active=1 AND is_pos=1",[site_id()]);
$out['menu_items_other_sites']=$c("SELECT COUNT(*) FROM menu_items WHERE tenant_id=? AND site_id<>? AND deleted_at IS NULL",[tenant_id(),site_id()]);
$out['menu_items_no_category']=$c("SELECT COUNT(*) FROM menu_items mi LEFT JOIN menu_categories mc ON mc.id=mi.category_id WHERE mi.site_id=? AND mi.deleted_at IS NULL AND mc.id IS NULL",[site_id()]);
$out['stranded_ui_records_menu']=$c("SELECT COUNT(*) FROM ui_records WHERE tenant_id=? AND module_key='menu' AND deleted=0",[tenant_id()]);
$out['categories']=$c("SELECT COUNT(*) FROM menu_categories WHERE site_id=? AND deleted_at IS NULL",[site_id()]);
$out['payment_methods']=$c("SELECT COUNT(*) FROM payment_methods WHERE site_id=?",[site_id()]);
$out['stock_locations']=$c("SELECT COUNT(*) FROM stock_locations WHERE site_id=?",[site_id()]);
$out['tables']=$c("SELECT COUNT(*) FROM dining_tables WHERE site_id=?",[site_id()]);
$out['units_global']=$c("SELECT COUNT(*) FROM units",[]);
$out['sites_in_tenant']=$c("SELECT COUNT(*) FROM sites WHERE tenant_id=? AND deleted_at IS NULL",[tenant_id()]);
$mm=$p->prepare("SELECT mi.name,mi.base_price,mi.is_active,mi.is_pos,COALESCE(mc.name,'(no category)') cat,mi.site_id FROM menu_items mi LEFT JOIN menu_categories mc ON mc.id=mi.category_id WHERE mi.tenant_id=? AND mi.deleted_at IS NULL ORDER BY mi.created_at DESC LIMIT 20");$mm->execute([tenant_id()]);$out['recent_items']=$mm->fetchAll();
$bt=null;try{$bt=count(PageData::posBoot()['products']);}catch(Throwable $e){$out['posBoot_error']=$e->getMessage();}
$out['posBoot_products']=$bt;
ok(['diag'=>$out]);
case 'pos-settings':needLogin();$p=DB::pdo();$q=$p->prepare("SELECT data_json FROM ui_records WHERE tenant_id=? AND site_id=? AND module_key='pos_settings' AND deleted=0 ORDER BY created_at DESC LIMIT 1");$q->execute([tenant_id(),site_id()]);$j=$q->fetchColumn();$d=$j?(json_decode($j,true)?:[]):[];ok(['settings'=>['tax_cash'=>isset($d['tax_cash'])?(float)$d['tax_cash']:16.0,'tax_card'=>isset($d['tax_card'])?(float)$d['tax_card']:8.0,'service_charge'=>isset($d['service_charge'])?(float)$d['service_charge']:0.0]]);
case 'pos-settings-save':needLogin();if(!Auth::isManager())fail('Settings sirf Admin/Manager badal sakta hai',403);$d=body();$val=['tax_cash'=>max(0,(float)($d['tax_cash']??16)),'tax_card'=>max(0,(float)($d['tax_card']??8)),'service_charge'=>max(0,(float)($d['service_charge']??0))];$p=DB::pdo();$q=$p->prepare("SELECT id FROM ui_records WHERE tenant_id=? AND site_id=? AND module_key='pos_settings' AND deleted=0 LIMIT 1");$q->execute([tenant_id(),site_id()]);$id=$q->fetchColumn();$json=json_encode($val,JSON_UNESCAPED_UNICODE);if($id)$p->prepare("UPDATE ui_records SET data_json=?,row_version=row_version+1,updated_at=NOW(6) WHERE id=?")->execute([$json,$id]);else $p->prepare("INSERT INTO ui_records(id,tenant_id,site_id,module_key,data_json,deleted,created_at) VALUES(?,?,?,'pos_settings',?,0,NOW(6))")->execute([uuid(),tenant_id(),site_id(),$json]);ok(['settings'=>$val]);
case 'menu-item-image':needLogin();if(!Auth::isManager())fail('Picture change sirf Admin/Manager kar sakta hai',403);$d=body();$mid=(string)($d['menu_item_id']??'');$url=trim((string)($d['image_url']??''));if($mid===''||!preg_match('/^[0-9a-f-]{36}$/i',$mid))fail('Item required');if($url!==''&&strlen($url)>1500000)fail('Image too large (max ~1MB)');if($url!==''&&!preg_match('#^(https?://|data:image/)#i',$url))fail('Valid image URL ya uploaded image chahiye');$p=DB::pdo();$q=$p->prepare("SELECT id FROM menu_items WHERE id=? AND site_id=? AND deleted_at IS NULL");$q->execute([$mid,site_id()]);if(!$q->fetchColumn())fail('Item not found');$p->prepare("UPDATE menu_items SET image_url=?,updated_at=NOW(6) WHERE id=?")->execute([$url!==''?$url:null,$mid]);ok(['id'=>$mid,'image_url'=>$url]);
case 'pos-verify-manager':needLogin();$d=body();$pw=(string)($d['password']??'');if($pw==='')fail('Password required',422);$p=DB::pdo();$q=$p->prepare("SELECT DISTINCT u.id,u.full_name,u.password_hash FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id WHERE u.tenant_id=? AND u.status='ACTIVE' AND u.deleted_at IS NULL AND (u.is_tenant_admin=1 OR r.name LIKE '%Manager%' OR r.name LIKE '%Owner%' OR r.name LIKE '%Admin%')");$q->execute([tenant_id()]);foreach($q->fetchAll() as $u){if($u['password_hash']&&password_verify($pw,$u['password_hash']))ok(['manager'=>$u['full_name'],'manager_id'=>$u['id']]);}fail('Manager password ghalat hai',401);
case 'bill-pdf':needLogin();$bill=preg_replace('/[^0-9A-Za-z-]/','',(string)($_GET['bill']??''));if($bill==='')fail('bill required');$p=DB::pdo();$oq=$p->prepare("SELECT o.id,o.bill_no,o.service_mode,o.grand_total,o.subtotal,o.discount_amount,o.service_charge,o.tax_amount,o.closed_at,o.created_at,dt.display_name tbl,c.full_name cust,c.phone cphone FROM orders o LEFT JOIN dining_tables dt ON dt.id=o.table_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.site_id=? AND o.bill_no=? ORDER BY o.created_at DESC LIMIT 1");$oq->execute([site_id(),$bill]);$o=$oq->fetch();if(!$o)fail('Bill not found',404);$iq=$p->prepare("SELECT oi.qty,oi.unit_price,oi.line_total,COALESCE(oi.item_name_snapshot,mi.name) nm FROM order_items oi LEFT JOIN menu_items mi ON mi.id=oi.menu_item_id WHERE oi.order_id=?");$iq->execute([$o['id']]);$items=$iq->fetchAll();$sq=$p->prepare("SELECT name FROM sites WHERE id=?");$sq->execute([site_id()]);$site=$sq->fetchColumn()?:'Restaurant';
$L=[];$L[]=[$site,'c',12];$L[]=['SALES INVOICE','c',9];$L[]=['Bill #'.$o['bill_no'].'  '.$o['service_mode'].($o['tbl']?('  '.$o['tbl']):''),'c',8];$L[]=[date('d M Y  H:i',strtotime((string)($o['closed_at']?:$o['created_at']))),'c',8];if($o['cust']){$L[]=['Customer: '.$o['cust'].($o['cphone']?(' '.$o['cphone']):''),'l',8];}$L[]=['------------------------------','l',8];
foreach($items as $it){$nm=(string)$it['nm'];$L[]=[rtrim(rtrim(number_format((float)$it['qty'],3,'.',''),'0'),'.').' x '.$nm,'l',9];$L[]=['      @ '.number_format((float)$it['unit_price'],0).'            '.number_format((float)$it['line_total'],0),'r',8];}
$L[]=['------------------------------','l',8];$L[]=['Subtotal            '.number_format((float)$o['subtotal'],0),'r',9];if((float)$o['discount_amount']>0)$L[]=['Discount           -'.number_format((float)$o['discount_amount'],0),'r',9];if((float)$o['service_charge']>0)$L[]=['Service Charge      '.number_format((float)$o['service_charge'],0),'r',9];if((float)$o['tax_amount']>0)$L[]=['Sales Tax           '.number_format((float)$o['tax_amount'],0),'r',9];$L[]=['GRAND TOTAL   PKR '.number_format((float)$o['grand_total'],0),'b',11];$L[]=['','l',8];$L[]=['Thank you! Visit again.','c',8];
$pdf=\Aio\Services\Pdf::receipt($L);
while(ob_get_level())ob_end_clean();
header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="bill-'.$o['bill_no'].'.pdf"');header('Content-Length: '.strlen($pdf));echo $pdf;exit;
case 'menu-image-search':needLogin();$q=trim((string)($_GET['q']??''));if($q==='')fail('query required');$out=[];$src='suggested';$page=max(1,(int)($_GET['page']??1));
$gk=getenv('GOOGLE_CSE_KEY')?:'';$gx=getenv('GOOGLE_CSE_CX')?:'';
if($gk&&$gx){try{$ctx=stream_context_create(['http'=>['timeout'=>7]]);
 $raw=@file_get_contents('https://www.googleapis.com/customsearch/v1?searchType=image&num=10&start='.((($page-1)*10)+1).'&key='.rawurlencode($gk).'&cx='.rawurlencode($gx).'&q='.rawurlencode($q.' food'),false,$ctx);
 if($raw){$j=json_decode($raw,true);foreach(($j['items']??[]) as $r){if(!empty($r['link']))$out[]=['thumb'=>$r['image']['thumbnailLink']??$r['link'],'url'=>$r['link'],'title'=>$r['title']??''];}if($out)$src='google';}
}catch(Throwable $e){}}
if(!$out){try{$ctx2=stream_context_create(['http'=>['timeout'=>6,'header'=>"User-Agent: SaaSVersion-POS\r\n"]]);$raw2=@file_get_contents('https://api.openverse.org/v1/images/?q='.rawurlencode($q.' food').'&page='.$page.'&page_size=12',false,$ctx2);if($raw2){$j2=json_decode($raw2,true);foreach(($j2['results']??[]) as $r){if(!empty($r['thumbnail'])||!empty($r['url']))$out[]=['thumb'=>$r['thumbnail']??$r['url'],'url'=>$r['url']??$r['thumbnail'],'title'=>$r['title']??''];}}}catch(Throwable $e){}}
if(!$out){$kw=strtolower(preg_replace('/[^a-z0-9 ]/i','',$q));$kw=implode(',',array_slice(preg_split('/\s+/',trim($kw))?:['food'],0,3));for($i=0;$i<8;$i++){$u='https://loremflickr.com/400/300/'.rawurlencode($kw?:'food').',food?lock='.(1000+$i);$out[]=['thumb'=>$u,'url'=>$u,'title'=>$q];}}
ok(['images'=>array_slice($out,0,12),'source'=>$src]);
case 'offline-package':needLogin();if(!Auth::isManager())fail('Sirf Admin/Manager offline version download kar sakta hai',403);
$p=DB::pdo();$tq=$p->prepare("SELECT id,name,slug,sync_token,COALESCE(display_name,name) dn FROM tenants WHERE id=? LIMIT 1");$tq->execute([tenant_id()]);$t=$tq->fetch();if(!$t)fail('Business not found',404);
if(empty($t['sync_token'])){$tok=bin2hex(random_bytes(24));$p->prepare("UPDATE tenants SET sync_token=? WHERE id=?")->execute([$tok,$t['id']]);$t['sync_token']=$tok;}
$sq=$p->prepare("SELECT name FROM sites WHERE id=?");$sq->execute([site_id()]);$siteName=$sq->fetchColumn()?:'Main Branch';
$root=dirname(__DIR__);
$tmp=tempnam(sys_get_temp_dir(),'aio');@unlink($tmp);$tmp.='.zip';
$zip=new ZipArchive();
if($zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)fail('ZIP banane mein masla');
$skipDirs=['.git','storage/logs','storage/sessions','node_modules','docs','.github'];
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
foreach($it as $f){
  $abs=$f->getPathname();$rel=ltrim(str_replace('\\','/',substr($abs,strlen($root))),'/');
  if($rel==='')continue;
  foreach($skipDirs as $sd){if(strpos($rel,$sd)===0)continue 2;}
  if(substr($rel,-4)==='.zip')continue;
  if($f->isDir()){$zip->addEmptyDir($rel);}else{$zip->addFile($abs,$rel);}
}
/* --- is business ka apna offline config (stamped) --- */
$base=rtrim((string)cfg('app.base_url'),'/');
$cfg="<?php\n// AUTO-GENERATED offline config for: ".addslashes((string)$t['dn'])."\n"
 ."// Is file ko haath se edit na karein - dobara download kar lein.\n"
 ."return [\n"
 ."  'app' => ['role'=>'local','name'=>'".addslashes((string)$t['dn'])."','debug'=>false,\n"
 ."            'base_url'=>'http://localhost:8080',\n"
 ."            'cloud_url'=>'".addslashes($base)."'],\n"
 ."  'db'  => ['host'=>'127.0.0.1','port'=>3306,'database'=>'aio_local',\n"
 ."            'username'=>'root','password'=>'','charset'=>'utf8mb4'],\n"
 ."  'tenant' => ['id'=>'".addslashes((string)$t['id'])."','slug'=>'".addslashes((string)$t['slug'])."',\n"
 ."               'site_id'=>'".addslashes((string)site_id())."','site_name'=>'".addslashes((string)$siteName)."'],\n"
 ."  'sync' => ['enabled'=>true,'token'=>'".addslashes((string)$t['sync_token'])."',\n"
 ."             'endpoint'=>'".addslashes($base)."/api.php','interval'=>30],\n"
 ."];\n";
$zip->addFromString('config/offline.php',$cfg);
$zip->addFromString('OFFLINE_README.txt',
 "OFFLINE VERSION - ".$t['dn']."\n".str_repeat('=',50)."\n\n"
 ."1) ZIP ko C:\\".preg_replace('/[^A-Za-z0-9]/','',(string)$t['slug'])." mein extract karein.\n"
 ."2) INSTALL_OFFLINE.bat par double-click karein (ek dafa).\n"
 ."3) Desktop par bana shortcut khol kar software chalayein.\n\n"
 ."Branch: ".$siteName."\nCloud: ".$base."\n"
 ."Internet na ho tab bhi POS chalta rahega; net aate hi data khud sync ho jayega.\n");
$zip->close();
$data=file_get_contents($tmp);@unlink($tmp);
while(ob_get_level())ob_end_clean();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9_-]/','',(string)$t['slug']).'-offline.zip"');
header('Content-Length: '.strlen($data));
echo $data;exit;
/* ============ QR TABLE ORDERING (session-based) ============ */
case 'qr-tables':needLogin();if(!Auth::isManager())fail('Sirf Admin/Manager',403);$p=DB::pdo();$q=$p->prepare("SELECT id,display_name,table_code FROM dining_tables WHERE site_id=? AND is_active=1 ORDER BY display_name");$q->execute([site_id()]);$base=rtrim((string)cfg('app.base_url'),'/');$rows=[];foreach($q->fetchAll() as $t){$rows[]=['id'=>$t['id'],'name'=>$t['display_name'],'url'=>$base.'/qr.html?t='.rawurlencode((string)$t['id']).'&s='.rawurlencode(site_id())];}ok(['tables'=>$rows,'base'=>$base]);
/* Scan: har scan par NAYI session banti hai jo sirf N minute chalti hai */
case 'qr-start':$p=DB::pdo();$tid=(string)($_GET['t']??'');$sid=(string)($_GET['s']??'');if($tid===''||$sid==='')fail('Invalid QR');
 $tq=$p->prepare("SELECT dt.id,dt.display_name,dt.tenant_id,dt.site_id FROM dining_tables dt WHERE dt.id=? AND dt.site_id=? AND dt.is_active=1");$tq->execute([$tid,$sid]);$t=$tq->fetch();if(!$t)fail('QR valid nahi',404);
 $mins=(int)(getenv('QR_SESSION_MINUTES')?:90);
 $tok=bin2hex(random_bytes(20));$qid=uuid();
 $p->prepare("INSERT INTO qr_sessions(id,tenant_id,site_id,table_id,table_name,token,status,started_at,expires_at) VALUES(?,?,?,?,?,?,'ACTIVE',NOW(6),DATE_ADD(NOW(6),INTERVAL ? MINUTE))")
   ->execute([$qid,$t['tenant_id'],$t['site_id'],$t['id'],$t['display_name'],$tok,$mins]);
 $mq=$p->prepare("SELECT mi.id,mi.name,mi.base_price,mi.image_url,COALESCE(mc.name,'General') cat FROM menu_items mi LEFT JOIN menu_categories mc ON mc.id=mi.category_id WHERE mi.site_id=? AND mi.is_active=1 AND mi.is_online=1 AND mi.deleted_at IS NULL ORDER BY COALESCE(mc.sort_order,999),mi.name");$mq->execute([$sid]);
 $items=array_map(fn($x)=>['id'=>$x['id'],'name'=>$x['name'],'price'=>(float)$x['base_price'],'cat'=>$x['cat'],'img'=>$x['image_url']?:''],$mq->fetchAll());
 $bq=$p->prepare("SELECT COALESCE(display_name,name) n,logo_url,brand_color FROM tenants WHERE id=?");$bq->execute([$t['tenant_id']]);$br=$bq->fetch()?:[];
 ok(['token'=>$tok,'table'=>$t['display_name'],'expires_min'=>$mins,'menu'=>$items,
     'brand'=>['name'=>$br['n']??'Restaurant','logo'=>$br['logo_url']??'','color'=>$br['brand_color']??'']]);
case 'qr-order':$p=DB::pdo();$d=body();$tok=(string)($d['token']??'');if($tok==='')fail('Session token required',401);
 /* expiry ka faisla DB ke apne clock par (PHP/MySQL timezone alag ho sakte hain) */
 $sq=$p->prepare("SELECT *, (expires_at > NOW(6)) AS alive FROM qr_sessions WHERE token=? LIMIT 1");$sq->execute([$tok]);$ses=$sq->fetch();
 if(!$ses)fail('Session valid nahi - QR dobara scan karein',401);
 if($ses['status']!=='ACTIVE')fail('Yeh session band ho chuki hai - QR dobara scan karein',401);
 if(!(int)$ses['alive']){$p->prepare("UPDATE qr_sessions SET status='EXPIRED' WHERE id=?")->execute([$ses['id']]);fail('Session ka waqt khatam - table par QR dobara scan karein',401);}
 $items=is_array($d['items']??null)?$d['items']:[];if(!$items)fail('Cart khali hai');
 $clean=[];$tot=0.0;
 foreach($items as $it){$mid=(string)($it['id']??'');$qty=(float)($it['qty']??0);if($mid===''||$qty<=0)continue;
   $mq=$p->prepare("SELECT name,base_price FROM menu_items WHERE id=? AND site_id=? AND is_active=1 AND deleted_at IS NULL");$mq->execute([$mid,$ses['site_id']]);$m=$mq->fetch();if(!$m)continue;
   $clean[]=['id'=>$mid,'name'=>$m['name'],'qty'=>$qty,'price'=>(float)$m['base_price'],'note'=>(string)($it['note']??'')];
   $tot+=$qty*(float)$m['base_price'];}
 if(!$clean)fail('Koi valid item nahi');
 $oid=uuid();
 $p->prepare("INSERT INTO qr_orders(id,tenant_id,site_id,session_id,table_name,items_json,total,status,note,created_at) VALUES(?,?,?,?,?,?,?,'PENDING',?,NOW(6))")
   ->execute([$oid,$ses['tenant_id'],$ses['site_id'],$ses['id'],$ses['table_name'],json_encode($clean,JSON_UNESCAPED_UNICODE),$tot,(string)($d['note']??'')]);
 ok(['order_id'=>$oid,'total'=>$tot,'status'=>'PENDING','message'=>'Order cashier ko bhej diya gaya - confirm hone ka intezar karein']);
case 'qr-pending':needLogin();$p=DB::pdo();$q=$p->prepare("SELECT id,table_name,items_json,total,note,created_at FROM qr_orders WHERE site_id=? AND status='PENDING' ORDER BY created_at");$q->execute([site_id()]);$rows=[];foreach($q->fetchAll() as $r){$rows[]=['id'=>$r['id'],'table'=>$r['table_name'],'items'=>json_decode((string)$r['items_json'],true)?:[],'total'=>(float)$r['total'],'note'=>$r['note'],'at'=>substr((string)$r['created_at'],11,5)];}ok(['orders'=>$rows]);
case 'qr-handle':needLogin();$d=body();$id=(string)($d['id']??'');$act=strtoupper((string)($d['action']??''));if(!in_array($act,['ACCEPTED','REJECTED'],true))fail('action required');
 $p=DB::pdo();$p->prepare("UPDATE qr_orders SET status=?,handled_at=NOW(6),handled_by=? WHERE id=? AND site_id=? AND status='PENDING'")->execute([$act,current_user()['id']??null,$id,site_id()]);ok(['status'=>$act]);
case 'qr-session-close':needLogin();$d=body();$p=DB::pdo();$p->prepare("UPDATE qr_sessions SET status='CLOSED',closed_at=NOW(6) WHERE site_id=? AND table_name=? AND status='ACTIVE'")->execute([site_id(),(string)($d['table']??'')]);ok();
case 'pos-boot':needLogin();if(!Auth::canModule('pos')&&!Auth::canModule('tablet'))fail('Permission denied',403);$bu=Auth::user();$bb=PageData::posBoot();$sq=DB::pdo()->prepare("SELECT name FROM sites WHERE id=? LIMIT 1");$sq->execute([site_id()]);$bb['site']=['name'=>(string)($sq->fetchColumn()?:'Main Branch')];$sg=$p2=DB::pdo()->prepare("SELECT data_json FROM ui_records WHERE tenant_id=? AND site_id=? AND module_key='pos_settings' AND deleted=0 ORDER BY created_at DESC LIMIT 1");$sg->execute([tenant_id(),site_id()]);$sj=$sg->fetchColumn();$sd=$sj?(json_decode($sj,true)?:[]):[];$bb['settings']=['tax_cash'=>isset($sd['tax_cash'])?(float)$sd['tax_cash']:16.0,'tax_card'=>isset($sd['tax_card'])?(float)$sd['tax_card']:8.0,'service_charge'=>isset($sd['service_charge'])?(float)$sd['service_charge']:0.0];$bq=DB::pdo()->prepare("SELECT name,display_name,logo_url,brand_color,brand_accent FROM tenants WHERE id=? LIMIT 1");$bq->execute([tenant_id()]);$br=$bq->fetch()?:[];
$bb['brand']=['name'=>($br['display_name']?:($br['name']??'Restaurant')),'logo'=>$br['logo_url']??'','color'=>$br['brand_color']??'','accent'=>$br['brand_accent']??''];
$bb['can']=['manage'=>Auth::isManager(),'reports'=>Auth::canModule('reports')];
$bb['cashier']=['name'=>$bu['full_name']??'Cashier','role'=>Auth::isManager()?(!empty($bu['is_tenant_admin'])?'Admin':'Manager'):'Cashier'];ok(['boot'=>$bb]);
case 'shift-current':needLogin();$q=DB::pdo()->prepare("SELECT id,shift_no,business_date,opening_cash,opened_at FROM cashier_shifts WHERE site_id=? AND status='OPEN' ORDER BY opened_at DESC LIMIT 1");$q->execute([site_id()]);ok(['shift'=>$q->fetch()?:null]);
case 'shift-open':needLogin();Auth::requireModule('pos');$d=body();$p=DB::pdo();$q=$p->prepare("SELECT id FROM cashier_shifts WHERE site_id=? AND status='OPEN' LIMIT 1");$q->execute([site_id()]);if($q->fetchColumn())fail('A shift is already open. Close it first.');$sid=uuid();$no='S-'.date('ymd').'-'.strtoupper(substr(str_replace('-','',$sid),0,4));$p->prepare("INSERT INTO cashier_shifts(id,tenant_id,site_id,shift_no,business_date,cashier_user_id,opened_at,opening_cash,status) VALUES(?,?,?,?,CURDATE(),?,NOW(6),?,'OPEN')")->execute([$sid,tenant_id(),site_id(),$no,current_user()['id']??null,(float)($d['opening_cash']??0)]);ok(['id'=>$sid,'shift_no'=>$no]);
case 'shift-preview':needLogin();Auth::requireModule('pos');$p=DB::pdo();$q=$p->prepare("SELECT id,shift_no,opening_cash,opened_at FROM cashier_shifts WHERE site_id=? AND status='OPEN' ORDER BY opened_at DESC LIMIT 1");$q->execute([site_id()]);$sh=$q->fetch();if(!$sh)fail('No open shift.');ok(['report'=>shift_report($sh,null)]);
case 'shift-close':needLogin();Auth::requireModule('pos');$d=body();$p=DB::pdo();$q=$p->prepare("SELECT id,shift_no,opening_cash,opened_at FROM cashier_shifts WHERE site_id=? AND status='OPEN' ORDER BY opened_at DESC LIMIT 1");$q->execute([site_id()]);$sh=$q->fetch();if(!$sh)fail('No open shift.');$rep=shift_report($sh,null);$actual=(float)($d['actual_cash']??$rep['expected_cash']);$p->prepare("UPDATE cashier_shifts SET closed_at=NOW(6),expected_cash=?,actual_cash=?,variance_amount=?,status='CLOSED',close_note=?,updated_at=NOW(6) WHERE id=?")->execute([$rep['expected_cash'],$actual,$actual-$rep['expected_cash'],(string)($d['note']??''),$sh['id']]);$rep['actual_cash']=$actual;$rep['variance']=$actual-$rep['expected_cash'];$rep['closed_at']=date('Y-m-d H:i');$rep['note']=(string)($d['note']??'');ok(['report'=>$rep]);
case 'shift-last-report':needLogin();Auth::requireModule('pos');$p=DB::pdo();$q=$p->prepare("SELECT id,shift_no,opening_cash,opened_at,closed_at,expected_cash,actual_cash,variance_amount,close_note FROM cashier_shifts WHERE site_id=? AND status='CLOSED' ORDER BY closed_at DESC LIMIT 1");$q->execute([site_id()]);$sh=$q->fetch();if(!$sh)fail('No closed shift yet.');$rep=shift_report($sh,$sh['closed_at']);$rep['actual_cash']=(float)$sh['actual_cash'];$rep['variance']=(float)$sh['variance_amount'];$rep['closed_at']=substr((string)$sh['closed_at'],0,16);$rep['note']=(string)($sh['close_note']??'');ok(['report'=>$rep]);
case 'menu-category-create':needLogin();if(!Auth::isManager())fail('Category create sirf Admin/Manager kar sakta hai',403);$d=body();$name=trim((string)($d['name']??''));if($name==='')fail('Category name required');$p=DB::pdo();$q=$p->prepare("SELECT id FROM menu_categories WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");$q->execute([site_id(),$name]);if($q->fetchColumn())fail('Category already exists');$cid=uuid();$p->prepare("INSERT INTO menu_categories(id,tenant_id,site_id,name,icon_text,sort_order,is_active) VALUES(?,?,?,?,?,99,1)")->execute([$cid,tenant_id(),site_id(),$name,(string)($d['icon']??'•')]);$st=strtolower(trim((string)($d['printer']??'')));if($st!==''){$pr=$p->prepare("SELECT id FROM printers WHERE site_id=? AND LOWER(station_code)=? AND is_active=1 LIMIT 1");$pr->execute([site_id(),$st]);if($pid=$pr->fetchColumn())$p->prepare("INSERT INTO menu_category_printer_routes(id,tenant_id,site_id,category_id,printer_id,is_primary,route_priority,is_active) VALUES(?,?,?,?,?,1,1,1)")->execute([uuid(),tenant_id(),site_id(),$cid,$pid]);}ok(['id'=>$cid,'name'=>$name]);
case 'menu-item-rate':needLogin();if(!Auth::isManager())fail('Rate change sirf Admin/Manager kar sakta hai',403);$d=body();$rate=(float)($d['price']??0);if($rate<=0)fail('Valid rate required');$p=DB::pdo();$mid=(string)($d['menu_item_id']??'');$row=null;if($mid!==''&&preg_match('/^[0-9a-f-]{36}$/i',$mid)){$q=$p->prepare("SELECT id FROM menu_items WHERE id=? AND site_id=? AND deleted_at IS NULL");$q->execute([$mid,site_id()]);$row=$q->fetchColumn();}if(!$row&&!empty($d['name'])){$q=$p->prepare("SELECT id FROM menu_items WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");$q->execute([site_id(),(string)$d['name']]);$row=$q->fetchColumn();}if(!$row)fail('Menu item not found in database');$p->prepare("UPDATE menu_items SET base_price=?,updated_at=NOW(6) WHERE id=?")->execute([$rate,$row]);ok(['id'=>$row,'price'=>$rate]);
case 'pos-table-create':needLogin();if(!Auth::canModule('pos')&&!Auth::canModule('tables'))fail('Permission denied',403);$d=body();$nm=trim((string)($d['name']??''));if($nm==='')fail('Table name required');$p=DB::pdo();$q=$p->prepare("SELECT id FROM dining_tables WHERE site_id=? AND display_name=? LIMIT 1");$q->execute([site_id(),$nm]);if($q->fetchColumn())fail('Table already exists');$f=$p->prepare("SELECT id FROM floors WHERE site_id=? AND is_active=1 ORDER BY sort_order LIMIT 1");$f->execute([site_id()]);$fid=$f->fetchColumn();if(!$fid){$fid=uuid();$p->prepare("INSERT INTO floors(id,tenant_id,site_id,name,sort_order,is_active) VALUES(?,?,?,'Main Floor',1,1)")->execute([$fid,tenant_id(),site_id()]);}$tid=uuid();$code=strtoupper(substr(preg_replace('/[^A-Za-z0-9]/','',$nm),0,10))?:('T'.substr(str_replace('-','',$tid),0,4));$p->prepare("INSERT INTO dining_tables(id,tenant_id,site_id,floor_id,table_code,display_name,seats,shape,status,is_active) VALUES(?,?,?,?,?,?,?,'SQUARE','AVAILABLE',1)")->execute([$tid,tenant_id(),site_id(),$fid,$code,$nm,(int)($d['seats']??4)]);ok(['id'=>$tid,'name'=>$nm]);
case 'pos-quick-item':needLogin();if(!Auth::isManager())fail('Item create sirf Admin/Manager kar sakta hai',403);$d=body();$name=trim((string)($d['name']??''));$price=(float)($d['price']??0);if($name===''||$price<=0)fail('Item name and valid price required');$p=DB::pdo();$dupe=$p->prepare("SELECT id FROM menu_items WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");$dupe->execute([site_id(),$name]);if($dupe->fetchColumn())fail('A menu item with this name already exists');$catName=trim((string)($d['category']??''))?:'General';$cq=$p->prepare("SELECT id FROM menu_categories WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");$cq->execute([site_id(),$catName]);$cid=$cq->fetchColumn();if(!$cid){$cid=uuid();$p->prepare("INSERT INTO menu_categories(id,tenant_id,site_id,name,icon_text,sort_order,is_active) VALUES(?,?,?,?, '•',99,1)")->execute([$cid,tenant_id(),site_id(),$catName]);}
$itemType=($d['type']??'standard')==='weighted'?'WEIGHTED':'STANDARD';$consumption='NONE';$directId=null;$directQty=null;
$inv=is_array($d['inventory']??null)?$d['inventory']:[];$mode=(string)($inv['mode']??'none');
if($mode==='existing'&&!empty($inv['item_id'])){$iq=$p->prepare("SELECT id FROM inventory_items WHERE id=? AND site_id=? AND deleted_at IS NULL");$iq->execute([(string)$inv['item_id'],site_id()]);$directId=$iq->fetchColumn()?:null;if(!$directId)fail('Selected inventory item not found');$consumption='DIRECT_INVENTORY';$directQty=max(0.000001,(float)($inv['qty']??1));}
elseif($mode==='new'){$invName=trim((string)($inv['name']??$name));$unitCode=strtoupper(trim((string)($inv['unit']??'PCS')))?:'PCS';$uq=$p->prepare("SELECT id FROM units WHERE code=? LIMIT 1");$uq->execute([$unitCode]);$unitId=$uq->fetchColumn();if(!$unitId){$uq=$p->prepare("SELECT id FROM units ORDER BY code LIMIT 1");$uq->execute();$unitId=$uq->fetchColumn();}if(!$unitId)fail('No units configured');$lq=$p->prepare("SELECT id FROM stock_locations WHERE site_id=? AND is_active=1 ORDER BY name LIMIT 1");$lq->execute([site_id()]);$loc=$lq->fetchColumn();$directId=\Aio\Services\InventoryService::createItem(['category_id'=>null,'sku'=>null,'barcode'=>null,'name'=>$invName,'usage_mode'=>'DIRECT_SALE','stock_unit_id'=>$unitId,'purchase_unit_name'=>$unitCode,'purchase_factor'=>1,'avg_cost'=>(float)($inv['cost']??0),'reorder_level'=>(float)($inv['reorder']??0),'track_batch'=>0,'track_expiry'=>0,'location_id'=>$loc?:null,'opening_qty'=>(float)($inv['opening_qty']??0)]);$consumption='DIRECT_INVENTORY';$directQty=max(0.000001,(float)($inv['qty']??1));}
$mid=uuid();$vn=0;DB::tx(function($p)use($d,$mid,$cid,$name,$itemType,$consumption,$directId,$directQty,$price,&$vn){$p->prepare("INSERT INTO menu_items(id,tenant_id,site_id,category_id,name,description,item_type,consumption_type,direct_inventory_item_id,direct_inventory_qty,base_price,is_active,is_online,is_pos) VALUES(?,?,?,?,?,?,?,?,?,?,?,1,1,1)")->execute([$mid,tenant_id(),site_id(),$cid,$name,(string)($d['desc']??''),$itemType,$consumption,$directId,$directQty,$price]);$opts=is_array($d['variants']??null)?$d['variants']:[];foreach($opts as $o){$vname=trim((string)($o['name']??''));$vprice=(float)($o['price']??0);if($vname===''||$vprice<=0)continue;$vn++;$p->prepare("INSERT INTO menu_item_variants(id,tenant_id,site_id,menu_item_id,name,price,sort_order,is_active) VALUES(?,?,?,?,?,?,?,1)")->execute([uuid(),tenant_id(),site_id(),$mid,$vname,$vprice,$vn]);}});
ok(['id'=>$mid,'category_id'=>$cid,'inventory_item_id'=>$directId,'variants'=>$vn]);
case 'pos-finalize':needLogin();Auth::requireModule('pos');$d=body();$d['bill_no']=pos_bill_guard($d);$id=PosService::finalize($d,$d['items']??[]);ok(['order_id'=>$id,'bill_no'=>$d['bill_no'],'next'=>PageData::nextBill(),'dashboard'=>PageData::dashboard()]);
case 'pos-kot':needLogin();if(!Auth::canModule('pos')&&!Auth::canModule('tablet'))fail('Permission denied',403);$d=body();$d['bill_no']=pos_bill_guard($d);$r=PosService::sendKot($d,$d['items']??[]);$r['bill_no']=$d['bill_no'];ok($r);
case 'customer-order':
$d=body();needLogin();$p=DB::pdo();$bill='ON-'.date('His').'-'.random_int(10,99);$oid=uuid();
$p->prepare("INSERT INTO orders(id,tenant_id,site_id,bill_no,business_date,order_source,service_mode,order_status,payment_status,opened_at,created_by_user_id,subtotal,grand_total) VALUES(?,?,?,?,?,'CUSTOMER_APP','DELIVERY','OPEN','UNPAID',NOW(6),?,0,0)")->execute([$oid,tenant_id(),site_id(),$bill,today(),current_user()['id']??null]);
$total=0;foreach($d['cart']??[] as $x){$q=$p->prepare("SELECT id,base_price FROM menu_items WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");$q->execute([site_id(),$x['name']??'']);$mi=$q->fetch();if(!$mi)continue;$qty=(float)($x['qty']??1);$line=$qty*(float)$mi['base_price'];$total+=$line;$p->prepare("INSERT INTO order_items(id,tenant_id,site_id,order_id,menu_item_id,item_name_snapshot,qty,sent_qty,unit_price,line_total,status) VALUES(?,?,?,?,?,?,?,0,?,?,'ACTIVE')")->execute([uuid(),tenant_id(),site_id(),$oid,$mi['id'],$x['name'],$qty,$mi['base_price'],$line]);}
$p->prepare("UPDATE orders SET subtotal=?,grand_total=? WHERE id=?")->execute([$total,$total,$oid]);
$p->prepare("INSERT INTO online_order_details(id,tenant_id,site_id,order_id,channel_code,external_order_no,acceptance_status,requested_at) VALUES(?,?,?,?, 'APP',?,'PENDING',NOW(6))")->execute([uuid(),tenant_id(),site_id(),$oid,$bill]);
ok(['order_id'=>$oid,'movements'=>[],'shortages'=>[],'state'=>PageData::storeState()]);

case 'module-demo-create':
$d=body();needLogin();$page=(string)($d['page']??'');$f=is_array($d['fields']??null)?$d['fields']:[];$p=DB::pdo();$row=[];
$now=date('h:i A');
if($page==='customers.html'){
  $id=uuid();$name=trim($f['name']??'New Customer');$phone=trim($f['phone']??'');$type=strtoupper(str_replace(' ','_',trim($f['type']??'REGULAR')));
  $p->prepare("INSERT INTO customers(id,tenant_id,site_id,full_name,phone,customer_type,status) VALUES(?,?,?,?,?,?,'ACTIVE')")->execute([$id,tenant_id(),site_id(),$name,$phone?:null,$type]);
  $row=[$name,$phone?:'—',0,'PKR 0',0,'—',ucfirst(strtolower($type))];
}elseif($page==='suppliers.html'){
  $id=uuid();$name=trim($f['name']??'New Supplier');$phone=trim($f['phone']??'');$email=trim($f['email']??'');
  $p->prepare("INSERT INTO suppliers(id,tenant_id,site_id,name,phone,email,status) VALUES(?,?,?,?,?,?,'ACTIVE')")->execute([$id,tenant_id(),site_id(),$name,$phone?:null,$email?:null]);
  $row=[$name,$phone?:'—',0,'PKR 0','PKR 0','Today'];
}elseif($page==='reservations.html'){
  $id=uuid();$name=trim($f['name']??'Guest');$phone=trim($f['phone']??'');$dt=!empty($f['datetime'])?date('Y-m-d H:i:s',strtotime($f['datetime'])):date('Y-m-d H:i:s',strtotime('+1 hour'));$g=max(1,(int)($f['guests']??1));$no='RSV-'.date('His').random_int(10,99);
  $p->prepare("INSERT INTO reservations(id,tenant_id,site_id,reservation_no,guest_name,guest_phone,reservation_at,guest_count,status) VALUES(?,?,?,?,?,?,?,?, 'CONFIRMED')")->execute([$id,tenant_id(),site_id(),$no,$name,$phone?:null,$dt,$g]);
  $row=[date('h:i A',strtotime($dt)),$name,$g,'—','PKR 0','Confirmed'];
}elseif($page==='rider_management.html'){
  $id=uuid();$name=trim($f['name']??'New Rider');$phone=trim($f['phone']??'');$vehicle=trim($f['vehicle']??'');
  $p->prepare("INSERT INTO riders(id,tenant_id,site_id,name,phone,vehicle_no,status,cash_held) VALUES(?,?,?,?,?,?, 'AVAILABLE',0)")->execute([$id,tenant_id(),site_id(),$name,$phone?:null,$vehicle?:null]);
  $row=[$name,$phone?:'—','—','PKR 0','AVAILABLE'];
}elseif($page==='tables_floors.html'){
  $floorName=trim($f['area']??'Ground Floor');$q=$p->prepare("SELECT id FROM floors WHERE site_id=? AND name=? LIMIT 1");$q->execute([site_id(),$floorName]);$floor=$q->fetchColumn();if(!$floor){$floor=uuid();$p->prepare("INSERT INTO floors(id,tenant_id,site_id,name,sort_order,is_active) VALUES(?,?,?,?,99,1)")->execute([$floor,tenant_id(),site_id(),$floorName]);}
  $name=trim($f['name']??('Table '.random_int(31,99)));$code='T'.preg_replace('/\D/','',$name).random_int(10,99);$seats=max(1,(int)($f['seats']??2));
  $p->prepare("INSERT INTO dining_tables(id,tenant_id,site_id,floor_id,table_code,display_name,seats,status,is_active) VALUES(?,?,?,?,?,?,?,'AVAILABLE',1)")->execute([uuid(),tenant_id(),site_id(),$floor,$code,$name,$seats]);
  $row=[$name,$floorName,$seats,'AVAILABLE','—','—'];
}elseif($page==='expenses.html'){
  $cat=trim($f['category']??'General');$q=$p->prepare("SELECT id FROM expense_categories WHERE tenant_id=? AND name=? LIMIT 1");$q->execute([tenant_id(),$cat]);$cid=$q->fetchColumn();if(!$cid){$cid=uuid();$p->prepare("INSERT INTO expense_categories(id,tenant_id,name,is_active) VALUES(?,?,?,1)")->execute([$cid,tenant_id(),$cat]);}
  $ref=trim($f['reference']??('EXP-'.date('His')));$amount=(float)($f['amount']??0);
  $p->prepare("INSERT INTO expenses(id,tenant_id,site_id,expense_no,expense_date,category_id,amount,payment_method,description,status,created_by_user_id,created_at) VALUES(?,?,?,?,CURDATE(),?,?, 'CASH',?,'APPROVED',?,NOW(6))")->execute([uuid(),tenant_id(),site_id(),$ref,$cid,$amount,$f['description']??null,current_user()['id']]);
  $row=[$ref,$cat,'PKR '.number_format($amount,0),current_user()['full_name']??'Current User','APPROVED'];
}elseif($page==='discounts_promotions.html'){
  $name=trim($f['name']??'Promotion');$type=strtoupper(str_replace(' ','_',trim($f['type']??'PERCENT')));$code=trim($f['code']??'');
  $p->prepare("INSERT INTO promotions(id,tenant_id,site_id,name,promotion_type,code,rules_json,is_active,created_at) VALUES(?,?,?,?,?,?,?,1,NOW(6))")->execute([uuid(),tenant_id(),site_id(),$name,$type,$code?:null,json_encode(['source'=>'approved-ui'])]);
  $row=[$name,$f['type']??'Percent','All','Current','0','Active'];
}elseif($page==='printer_devices.html'){
  $name=trim($f['name']??'Printer');$type=strtoupper(trim($f['type']??'KITCHEN'));$address=trim($f['address']??'');
  if(in_array($type,['POS','KDS'],true)){$p->prepare("INSERT INTO devices(id,tenant_id,site_id,device_code,device_name,device_type,status,created_at) VALUES(?,?,?,?,?,?, 'ACTIVE',NOW(6))")->execute([uuid(),tenant_id(),site_id(),'DEV-'.date('His').random_int(10,99),$name,$type]);}
  else{$p->prepare("INSERT INTO printers(id,tenant_id,site_id,name,printer_type,connection_type,ip_address,is_active,created_at) VALUES(?,?,?,?,?,'NETWORK',?,1,NOW(6))")->execute([uuid(),tenant_id(),site_id(),$name,$type?:'KITCHEN',$address?:null]);}
  $row=[$name,$type.' / —',$address?:'—','Current Branch','Online'];
}elseif($page==='staff_roles.html'){
  $name=trim($f['name']??'Staff Member');$role=trim($f['role']??'Staff');$p->prepare("INSERT INTO employee_profiles(id,tenant_id,site_id,full_name,job_title,employment_status,created_at) VALUES(?,?,?,?,?,'ACTIVE',NOW(6))")->execute([uuid(),tenant_id(),site_id(),$name,$role]);$row=[$name,$role,$f['shift']??'—','Active','Standard'];
}elseif($page==='shift_management.html'){
  $ref=trim($f['reference']??('S-'.date('His')));$amount=(float)($f['amount']??0);$q=$p->prepare("SELECT COUNT(*) FROM cashier_shifts WHERE site_id=? AND status='OPEN'");$q->execute([site_id()]);if(!(int)$q->fetchColumn())$p->prepare("INSERT INTO cashier_shifts(id,tenant_id,site_id,shift_no,business_date,cashier_user_id,opened_at,opening_cash,status,created_at) VALUES(?,?,?,?,CURDATE(),?,NOW(6),?,'OPEN',NOW(6))")->execute([uuid(),tenant_id(),site_id(),$ref,current_user()['id'],$amount]);$row=['Cash','PKR 0','PKR '.number_format($amount,0),'PKR 0'];
}elseif($page==='stock_count.html'){
  $ref=trim($f['reference']??('COUNT-'.date('His')));$p->prepare("INSERT INTO stock_count_sessions(id,tenant_id,site_id,count_no,started_at,status,started_by_user_id) VALUES(?,?,?,?,NOW(6),'OPEN',?)")->execute([uuid(),tenant_id(),site_id(),$ref,current_user()['id']]);$row=[$f['location']??'Stock Location',0,0,0,'PKR 0','New Count'];
}elseif($page==='wastage_adjustment.html'){
  $ref='ADJ-'.date('His').random_int(10,99);$aid=uuid();$p->prepare("INSERT INTO stock_adjustments(id,tenant_id,site_id,adjustment_no,reason_code,status,requested_by_user_id,requested_at,note) VALUES(?,?,?,?,?,'PENDING',?,NOW(6),?)")->execute([$aid,tenant_id(),site_id(),$ref,'WASTAGE',current_user()['id'],$f['reason']??null]);$row=[$f['item']??'Inventory Item',$f['qty']??0,$f['reason']??'Wastage',current_user()['full_name']??'Current User','Pending'];
}elseif($page==='whatsapp_notifications.html'){
  $event=trim($f['event']??'Notification');$channel=strtoupper(trim($f['channel']??'WHATSAPP'));$recipient=trim($f['audience']??'Customer');$p->prepare("INSERT INTO notification_queue(id,tenant_id,site_id,channel,recipient,template_key,status,attempts,available_at) VALUES(?,?,?,?,?,?, 'PENDING',0,NOW(6))")->execute([uuid(),tenant_id(),site_id(),$channel,$recipient,$event]);$row=[$event,$channel==='WHATSAPP'?'Enabled':'—',$channel==='PUSH'?'Enabled':'—',$channel==='SMS'?'Enabled':'—',$recipient];
}elseif($page==='multi_branch.html'){
  $name=trim($f['name']??'New Branch');$code=strtoupper(preg_replace('/[^A-Z0-9]+/','-',trim($f['code']??$name)));$q=$p->prepare("SELECT organization_id,timezone,currency FROM sites WHERE id=?");$q->execute([site_id()]);$s=$q->fetch();$p->prepare("INSERT INTO sites(id,tenant_id,organization_id,code,name,site_type,timezone,currency,status,created_at) VALUES(?,?,?,?,?,'BRANCH',?,?, 'ACTIVE',NOW(6))")->execute([uuid(),tenant_id(),$s['organization_id'],$code.'-'.random_int(10,99),$name,$s['timezone'],$s['currency']]);$row=[$name,'PKR 0',0,'PKR 0','Closed','Active'];
}elseif($page==='accounting.html'){
  $ref=trim($f['reference']??('JV-'.date('His')));$p->prepare("INSERT INTO journal_entries(id,tenant_id,site_id,journal_no,journal_date,narration,status,created_by_user_id,created_at) VALUES(?,?,?,?,CURDATE(),?,'POSTED',?,NOW(6))")->execute([uuid(),tenant_id(),site_id(),$ref,$f['notes']??null,current_user()['id']]);$row=[$now,'Manual',$ref,'PKR '.number_format((float)($f['debit']??0),0),'PKR '.number_format((float)($f['credit']??0),0),'—'];
}else{
  $p->prepare("INSERT INTO audit_logs(id,tenant_id,site_id,user_id,action_code,entity_type,new_values_json,created_at) VALUES(?,?,?,?, 'UI_DEMO_CREATE',?,?,NOW(6))")->execute([uuid(),tenant_id(),site_id(),current_user()['id'],$page,json_encode($f)]);
  $row=array_values($f);
}
ok(['row'=>$row]);
case 'sync-ping':ok(['role'=>cfg('app.role'),'time'=>date('c')]);
case 'sync-push':syncToken();$d=body();$n=Sync::applyRows((string)($d['table']??''),$d['rows']??[]);ok(['applied'=>$n]);
case 'sync-pull':syncToken();$d=body();$t=(string)($d['table']??'');$since=(string)($d['since']??'1970-01-01 00:00:00.000000');$lim=(int)($d['limit']??300);$rows=Sync::changedRows($t,$since,$lim);$ts=Sync::tsCol($t);$wm=($rows&&$ts)?end($rows)[$ts]:$since;ok(['rows'=>$rows,'watermark'=>$wm,'count'=>count($rows)]);
case 'sync-run':needLogin();ok(Sync::run());
case 'sync-status':needLogin();ok(['status'=>Sync::status()]);
case 'records-list':needLogin();$mod=preg_replace('/[^a-z_]/','',strtolower($_GET['module']??''));if(ModuleBridge::handles($mod)){ok(['rows'=>ModuleBridge::list($mod),'bridged'=>true]);}$q=DB::pdo()->prepare("SELECT id,data_json FROM ui_records WHERE tenant_id=? AND (site_id=? OR site_id IS NULL) AND module_key=? AND deleted=0 ORDER BY created_at DESC");$q->execute([tenant_id(),site_id(),$mod]);$rows=[];foreach($q->fetchAll() as $r){$data=json_decode($r['data_json'],true)?:[];$data['id']=$r['id'];$rows[]=$data;}ok(['rows'=>$rows]);
case 'records-save':needLogin();$d=body();$mod=preg_replace('/[^a-z_]/','',strtolower($d['module']??''));$data=is_array($d['data']??null)?$d['data']:[];if($mod==='')fail('module required');$id=(string)($data['id']??'');unset($data['id']);if(ModuleBridge::handles($mod)){try{$id=ModuleBridge::save($mod,$id,$data);}catch(Throwable $e){fail($e->getMessage());}ok(['id'=>$id,'bridged'=>true]);}$p=DB::pdo();if($id!==''){$p->prepare("UPDATE ui_records SET data_json=?,row_version=row_version+1,updated_at=NOW(6) WHERE id=? AND tenant_id=? AND module_key=?")->execute([json_encode($data,JSON_UNESCAPED_UNICODE),$id,tenant_id(),$mod]);}else{$id=uuid();$p->prepare("INSERT INTO ui_records(id,tenant_id,site_id,module_key,data_json,origin_node) VALUES(?,?,?,?,?,?)")->execute([$id,tenant_id(),site_id(),$mod,json_encode($data,JSON_UNESCAPED_UNICODE),(string)cfg('app.role')]);}ok(['id'=>$id]);
case 'records-delete':needLogin();$d=body();$id=(string)($d['id']??'');$mod=preg_replace('/[^a-z_]/','',strtolower($d['module']??''));if(ModuleBridge::handles($mod)){try{ModuleBridge::delete($mod,$id);}catch(Throwable $e){fail($e->getMessage());}ok(['bridged'=>true]);}DB::pdo()->prepare("UPDATE ui_records SET deleted=1,row_version=row_version+1,updated_at=NOW(6) WHERE id=? AND tenant_id=?")->execute([$id,tenant_id()]);ok();
case 'sa-login':$d=body();if(!Platform::superLogin((string)($d['email']??''),(string)($d['password']??'')))fail('Invalid platform credentials',401);$u=Platform::superUser();ok(['user'=>['id'=>$u['id'],'name'=>$u['full_name'],'email'=>$u['email'],'role'=>$u['role']]]);
case 'sa-logout':Platform::superLogout();ok();
case 'sa-me':$u=Platform::superUser();ok(['user'=>$u?['id'=>$u['id'],'name'=>$u['full_name'],'email'=>$u['email'],'role'=>$u['role']]:null]);
case 'sa-plans':needSuper();ok(['plans'=>DB::pdo()->query("SELECT id,name,price,billing_cycle FROM subscription_plans WHERE is_active=1 ORDER BY price")->fetchAll()]);
case 'sa-business-list':needSuper();ok(['businesses'=>Platform::listBusinesses()]);
case 'sa-business-create':needSuper();$d=body();$r=Platform::provisionBusiness($d);ok(['business'=>$r]);
case 'sa-branding-save':needSuper();$d=body();$tid=(string)($d['tenant_id']??'');if($tid==='')fail('tenant_id required');$logo=(string)($d['logo_url']??'');if($logo!==''&&strlen($logo)>1500000)fail('Logo too large (max ~1MB)');DB::pdo()->prepare("UPDATE tenants SET display_name=?,logo_url=?,brand_color=?,brand_accent=?,updated_at=NOW(6) WHERE id=?")->execute([trim((string)($d['display_name']??''))?:null,$logo!==''?$logo:null,trim((string)($d['brand_color']??''))?:null,trim((string)($d['brand_accent']??''))?:null,$tid]);ok(['message'=>'Branding saved']);
case 'sa-features-get':needSuper();$tid=(string)($_GET['tenant_id']??'');if($tid==='')fail('tenant_id required');$p=DB::pdo();$q=$p->prepare("SELECT features_json FROM tenants WHERE id=?");$q->execute([$tid]);$j=$q->fetchColumn();$sel=$j?(json_decode((string)$j,true)?:[]):null;$all=$p->query("SELECT module_key,name title FROM platform_modules WHERE is_active=1 ORDER BY sort_order,name")->fetchAll();ok(['modules'=>$all,'selected'=>$sel]);
case 'sa-features-save':needSuper();$d=body();$tid=(string)($d['tenant_id']??'');if($tid==='')fail('tenant_id required');$f=is_array($d['features']??null)?array_values(array_unique(array_map('strval',$d['features']))):[];DB::pdo()->prepare("UPDATE tenants SET features_json=?,updated_at=NOW(6) WHERE id=?")->execute([$f?json_encode($f):null,$tid]);ok(['count'=>count($f)]);
case 'sa-wa-get':needSuper();$tid=(string)($_GET['tenant_id']??'');if($tid==='')fail('tenant_id required');$q=DB::pdo()->prepare("SELECT wa_api_url,wa_api_key,wa_events_json FROM tenants WHERE id=?");$q->execute([$tid]);$r=$q->fetch()?:[];ok(['wa'=>['url'=>$r['wa_api_url']??'','key'=>$r['wa_api_key']??'','events'=>$r['wa_events_json']?(json_decode((string)$r['wa_events_json'],true)?:[]):[]]]);
case 'sa-wa-save':needSuper();$d=body();$tid=(string)($d['tenant_id']??'');if($tid==='')fail('tenant_id required');$ev=is_array($d['events']??null)?array_values(array_map('strval',$d['events'])):[];DB::pdo()->prepare("UPDATE tenants SET wa_api_url=?,wa_api_key=?,wa_events_json=?,updated_at=NOW(6) WHERE id=?")->execute([trim((string)($d['url']??''))?:null,trim((string)($d['key']??''))?:null,json_encode($ev),$tid]);ok(['events'=>count($ev)]);
case 'sa-wa-test':needSuper();$d=body();$url=rtrim(trim((string)($d['url']??'')),'/');$key=trim((string)($d['key']??''));if($url===''||$key==='')fail('URL and API key required');$ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>8,'header'=>"x-api-key: $key\r\nContent-Type: application/json\r\n",'ignore_errors'=>true]]);$raw=@file_get_contents($url.'/api/status',false,$ctx);if($raw===false)fail('Connect nahi ho saka - URL check karein');ok(['status'=>json_decode($raw,true)?:$raw]);
case 'sa-dashboard':needSuper();$p=DB::pdo();$n=function($sql,$a=[])use($p){$q=$p->prepare($sql);$q->execute($a);return (int)$q->fetchColumn();};
$out=['businesses'=>$n("SELECT COUNT(*) FROM tenants WHERE slug IS NOT NULL AND deleted_at IS NULL"),
 'active'=>$n("SELECT COUNT(*) FROM tenants WHERE slug IS NOT NULL AND status='ACTIVE' AND deleted_at IS NULL"),
 'suspended'=>$n("SELECT COUNT(*) FROM tenants WHERE status='SUSPENDED' AND deleted_at IS NULL"),
 'branches'=>$n("SELECT COUNT(*) FROM sites WHERE deleted_at IS NULL"),
 'users'=>$n("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")];
$rq=$p->prepare("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE paid_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')");$rq->execute();$out['revenue_mtd']=(float)$rq->fetchColumn();
$rq2=$p->prepare("SELECT COALESCE(SUM(amount),0) FROM subscription_payments");$rq2->execute();$out['revenue_total']=(float)$rq2->fetchColumn();
$ren=$p->query("SELECT t.id,t.name,t.slug,t.status,s.expiry_date,s.amount,DATEDIFF(s.expiry_date,CURDATE()) days FROM tenants t JOIN tenant_subscriptions s ON s.id=(SELECT s2.id FROM tenant_subscriptions s2 WHERE s2.tenant_id=t.id COLLATE utf8mb4_unicode_ci ORDER BY s2.created_at DESC LIMIT 1) WHERE t.deleted_at IS NULL AND s.expiry_date IS NOT NULL AND DATEDIFF(s.expiry_date,CURDATE())<=30 ORDER BY s.expiry_date")->fetchAll();
$out['renewals']=$ren;
$pay=$p->query("SELECT sp.amount,sp.method,sp.paid_at,t.name FROM subscription_payments sp JOIN tenants t ON t.id=sp.tenant_id COLLATE utf8mb4_unicode_ci ORDER BY sp.paid_at DESC LIMIT 8")->fetchAll();
$out['recent_payments']=$pay;
ok(['dash'=>$out]);
case 'sa-password-change':needSuper();$d=body();$cur=(string)($d['current']??'');$new=(string)($d['new']??'');if(strlen($new)<8)fail('New password must be at least 8 characters.');$p=DB::pdo();$q=$p->prepare("SELECT password_hash FROM platform_users WHERE id=? LIMIT 1");$q->execute([Platform::superUser()['id']]);$h=$q->fetchColumn();if(!$h||!password_verify($cur,$h))fail('Current password is incorrect.',401);$p->prepare("UPDATE platform_users SET password_hash=?,updated_at=NOW(6) WHERE id=?")->execute([password_hash($new,PASSWORD_DEFAULT),Platform::superUser()['id']]);ok(['message'=>'Password changed.']);
case 'sa-business-suspend':needSuper();$d=body();$tid=(string)($d['tenant_id']??'');if($tid==='')fail('tenant_id required');$p=DB::pdo();$p->prepare("UPDATE tenants SET status='SUSPENDED',updated_at=NOW(6) WHERE id=?")->execute([$tid]);$p->prepare("UPDATE tenant_subscriptions SET status='SUSPENDED',updated_at=NOW(6) WHERE tenant_id=? AND status='ACTIVE'")->execute([$tid]);ok(['message'=>'Business suspended.']);
case 'sa-business-activate':needSuper();$d=body();$tid=(string)($d['tenant_id']??'');if($tid==='')fail('tenant_id required');$p=DB::pdo();$p->prepare("UPDATE tenants SET status='ACTIVE',updated_at=NOW(6) WHERE id=?")->execute([$tid]);$p->prepare("UPDATE tenant_subscriptions SET status='ACTIVE',updated_at=NOW(6) WHERE tenant_id=? AND status='SUSPENDED'")->execute([$tid]);$exp=trim((string)($d['expiry_date']??''));if($exp!==''){$p->prepare("UPDATE tenant_subscriptions SET expiry_date=?,updated_at=NOW(6) WHERE tenant_id=? ORDER BY created_at DESC LIMIT 1")->execute([$exp,$tid]);}ok(['message'=>'Business activated.']);
case 'sa-business-renew':needSuper();$d=body();$tid=(string)($d['tenant_id']??'');$exp=trim((string)($d['expiry_date']??''));if($tid===''||$exp==='')fail('tenant_id and expiry_date required');$amount=(float)($d['amount']??0);$p=DB::pdo();$q=$p->prepare("SELECT id FROM tenant_subscriptions WHERE tenant_id=? ORDER BY created_at DESC LIMIT 1");$q->execute([$tid]);$sub=$q->fetchColumn();if($sub){$p->prepare("UPDATE tenant_subscriptions SET expiry_date=?,status='ACTIVE',updated_at=NOW(6) WHERE id=?")->execute([$exp,$sub]);}else{$sub=uuid();$p->prepare("INSERT INTO tenant_subscriptions(id,tenant_id,status,amount,start_date,expiry_date,created_by) VALUES(?,?,'ACTIVE',?,CURDATE(),?,?)")->execute([$sub,$tid,$amount,$exp,Platform::superUser()['id']]);}if($amount>0){$p->prepare("INSERT INTO subscription_payments(id,tenant_id,subscription_id,amount,method,reference,payer_name,note,created_by) VALUES(?,?,?,?,?,?,?,?,?)")->execute([uuid(),$tid,$sub,$amount,strtoupper((string)($d['payment_method']??'CASH')),($d['payment_reference']??null),($d['payer_name']??null),'Renewal',Platform::superUser()['id']]);}$p->prepare("UPDATE tenants SET status='ACTIVE',updated_at=NOW(6) WHERE id=?")->execute([$tid]);ok(['message'=>'Renewed till '.$exp]);
case 'sa-business-reset-admin':needSuper();$d=body();$tid=(string)($d['tenant_id']??'');if($tid==='')fail('tenant_id required');$p=DB::pdo();$q=$p->prepare("SELECT id,email FROM users WHERE tenant_id=? AND is_tenant_admin=1 AND deleted_at IS NULL ORDER BY created_at LIMIT 1");$q->execute([$tid]);$u=$q->fetch();if(!$u)fail('No admin user found for this business.');$np=substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#%'),0,10);$p->prepare("UPDATE users SET password_hash=?,password_algo='BCRYPT',status='ACTIVE',updated_at=NOW(6) WHERE id=?")->execute([password_hash($np,PASSWORD_DEFAULT),$u['id']]);ok(['admin_email'=>$u['email'],'admin_password'=>$np]);
case 'sa-business-detail':needSuper();$tid=(string)($_GET['tenant_id']??'');if($tid==='')fail('tenant_id required');$p=DB::pdo();$t=$p->prepare("SELECT id,name,slug,industry_code,status,owner_email,sync_token,created_at FROM tenants WHERE id=?");$t->execute([$tid]);$ten=$t->fetch();if(!$ten)fail('Business not found',404);$subs=$p->prepare("SELECT s.status,s.amount,s.start_date,s.expiry_date,s.created_at,COALESCE(pl.name,'—') plan FROM tenant_subscriptions s LEFT JOIN subscription_plans pl ON pl.id=s.plan_id WHERE s.tenant_id=? ORDER BY s.created_at DESC");$subs->execute([$tid]);$pays=$p->prepare("SELECT amount,method,reference,payer_name,note,paid_at FROM subscription_payments WHERE tenant_id=? ORDER BY paid_at DESC LIMIT 50");$pays->execute([$tid]);$uq=$p->prepare("SELECT COUNT(*) FROM users WHERE tenant_id=? AND deleted_at IS NULL");$uq->execute([$tid]);$sq=$p->prepare("SELECT COUNT(*) FROM sites WHERE tenant_id=? AND deleted_at IS NULL");$sq->execute([$tid]);$base=rtrim((string)cfg('app.base_url'),'/');$ten['client_link']=$base.'/login.html?b='.$ten['slug'];ok(['business'=>$ten,'subscriptions'=>$subs->fetchAll(),'payments'=>$pays->fetchAll(),'users'=>(int)$uq->fetchColumn(),'branches'=>(int)$sq->fetchColumn()]);
case 'sa-diagnostics':needSuper();$p=DB::pdo();$need=['tenants','organizations','sites','users','platform_users','platform_modules','subscription_plans','tenant_subscriptions','subscription_payments','orders','order_items','payments','payment_methods','customers','suppliers','menu_items','menu_categories','inventory_items','stock_balances','kitchen_tickets','cashier_shifts','expenses','ui_records','sync_state'];$missing=[];foreach($need as $t){$q=$p->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$t]);if(!(int)$q->fetchColumn())$missing[]=$t;}$cols=[];foreach([['tenants','slug'],['tenants','industry_code'],['tenants','sync_token'],['tenants','owner_email'],['suppliers','city'],['suppliers','category']] as $c){$q=$p->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");$q->execute($c);if(!(int)$q->fetchColumn())$cols[]=implode('.',$c);}ok(['db'=>cfg('db.database'),'php'=>PHP_VERSION,'role'=>cfg('app.role'),'missing_tables'=>$missing,'missing_columns'=>$cols,'healthy'=>!$missing&&!$cols]);
default:fail('Unknown API action',404);}
}catch(Throwable $e){
  try{@file_put_contents(dirname(__DIR__).'/storage/logs/api-error.log','['.date('c').'] action='.($_GET['action']??'').' :: '.$e->getMessage().PHP_EOL,FILE_APPEND);}catch(Throwable $x){}
  $showReal=cfg('app.debug')||\Aio\Services\Platform::superUser()||Auth::user();
  fail($showReal?$e->getMessage():'Operation failed.',500);
}

// build: V17.1 build 2026-08-25
