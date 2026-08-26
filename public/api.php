<?php
declare(strict_types=1);
/* API ka jawab HAMESHA saaf JSON hona chahiye.
   Aap ke server par MySQL 8 ke "Undefined array key" warnings seedhe
   response mein chhap gaye aur poora JSON kharab kar diya — browser ko
   sirf "Request failed" dikha. Warnings log mein jayen, response mein nahi. */
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);
require_once dirname(__DIR__).'/src/bootstrap.php';
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
/* Bill number ab node ke prefix ke saath (offline: "L1-0007", cloud: "0007").
   Warna do nodes ke bill takra kar cloud par ek doosre ko mita dete the. */
function pos_bill_no(int $n):string{
  $pre=\Aio\Services\PageData::billPrefix();
  return $pre.str_pad((string)$n,4,'0',STR_PAD_LEFT);
}
function pos_bill_guard(array $d):string{
  $bill=ltrim((string)($d['bill_no']??''),'#');
  $pre=\Aio\Services\PageData::billPrefix();
  if($bill!==''&&$pre!==''&&strpos($bill,$pre)!==0)$bill=$pre.ltrim($bill,'-');
  $p=DB::pdo();
  if($bill!==''){
    $q=$p->prepare("SELECT order_status FROM orders WHERE site_id=? AND business_date=? AND bill_no=? LIMIT 1");
    $q->execute([site_id(),today(),$bill]);
    $st=$q->fetchColumn();
    if($st===false||$st==='OPEN')return $bill;
  }
  return pos_bill_no((int)\Aio\Services\PageData::nextBill());
}
function needLogin(){if(!Auth::user())fail('Login required',401);}function needSuper(){if(!Platform::superUser())fail('Super admin login required',401);}
/* ============================================================
   SYNC AUTH — ab PER-TENANT.
   Pehle sirf ek GLOBAL token check hota tha: leaked token se koi bhi
   kisi bhi tenant ki rows push/pull kar sakta tha (platform_users
   samet). Ab token tenants.sync_token se match hota hai aur us tenant
   ka scope request par lock ho jata hai.
   ============================================================ */
function syncTenant(){
  static $t=null; if($t!==null)return $t;
  $tok=(string)($_SERVER['HTTP_X_SYNC_TOKEN']??'');
  if($tok===''||strlen($tok)<16)fail('Invalid sync token',401);
  try{
    $q=DB::pdo()->prepare("SELECT id,slug,status FROM tenants WHERE sync_token IS NOT NULL AND sync_token=? LIMIT 1");
    $q->execute([$tok]);
    $row=$q->fetch();
  }catch(Throwable $e){ $row=null; }
  if($row){
    if(($row['status']??'')==='SUSPENDED')fail('Business suspended - sync band hai',403);
    $_SESSION['sync_tenant_id']=$row['id'];
    return $t=$row['id'];
  }
  /* Local (single-tenant) node ke liye config token — sirf tab jab
     app cloud mode mein NA ho. */
  $want=(string)(cfg('sync.token')?:'');
  if(cfg('app.role')!=='cloud' && $want!=='' && hash_equals($want,$tok)) return $t=tenant_id();
  fail('Invalid sync token',401);
}
function syncToken(){ syncTenant(); }

/* Sirf yahi tables sync ho sakti hain. users/platform_users/roles jaisi
   tables kabhi nahi (warna token leak = account takeover). */

/* Cloud par record: kis branch se kaun si table, kitni rows aayin/gayin. */
/* Har sync request par node ka heartbeat.
   PEHLE: activity sirf tab likhi jati thi jab rows>0. Jo node connect to
   hota tha magar uske paas bhejne ko kuch naya nahi hota, wo cloud par
   NAZAR HI NAHI AATA THA - dashboard "no branch computer yet" dikhata
   rehta tha. Ab connection khud record hoti hai. */
function syncNodeSeen(string $tid,string $action=''):void{
  static $done=false; if($done)return; $done=true;
  try{
    $p=DB::pdo();
    $ip=substr((string)($_SERVER['HTTP_X_FORWARDED_FOR']??$_SERVER['REMOTE_ADDR']??'unknown'),0,64);
    $ip=trim(explode(',',$ip)[0]);
    $site=(string)($GLOBALS['sync_site_id']??'');
    $ver=substr((string)($_SERVER['HTTP_X_NODE_BUILD']??''),0,40);
    $code=substr((string)($_SERVER['HTTP_X_NODE_CODE']??''),0,40);
    $q=$p->prepare("SELECT id FROM sync_nodes WHERE tenant_id=? AND machine_fingerprint=? LIMIT 1");
    $q->execute([$tid,$ip]);
    if($id=$q->fetchColumn()){
      $p->prepare("UPDATE sync_nodes SET last_seen_at=NOW(6),status='ACTIVE',
                     app_version=COALESCE(NULLIF(?,''),app_version),
                     node_code=COALESCE(NULLIF(?,''),node_code),
                     site_id=COALESCE(NULLIF(?,''),site_id) WHERE id=?")
        ->execute([$ver,$code,$site,$id]);
    }else{
      $p->prepare("INSERT INTO sync_nodes(id,tenant_id,site_id,node_type,node_code,machine_fingerprint,
                     last_seen_at,app_version,status,created_at)
                   VALUES(?,?,?,'OFFLINE_NODE',?,?,NOW(6),?,'ACTIVE',NOW(6))")
        ->execute([uuid(),$tid,$site?:null,$code?:null,$ip,$ver?:null]);
    }
  }catch(Throwable $e){}
}

function syncLogEnsure():void{
  static $done=false; if($done)return; $done=true;
  try{
    DB::pdo()->exec("CREATE TABLE IF NOT EXISTS sync_activity (
      id CHAR(36) NOT NULL PRIMARY KEY, tenant_id CHAR(36) NULL, site_id CHAR(36) NULL,
      run_id CHAR(36) NULL, direction ENUM('PUSH','PULL') NOT NULL, table_name VARCHAR(80) NOT NULL,
      rows_count INT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'OK',
      note VARCHAR(300) NULL, node_ip VARCHAR(64) NULL, created_at DATETIME(6) NOT NULL,
      KEY ix_sa_time (created_at), KEY ix_sa_tenant (tenant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }catch(Throwable $e){}
}
function syncActivityLog(string $tid,string $dir,string $table,int $rows,string $note=''):void{
  syncLogEnsure();
  try{
    DB::pdo()->prepare("INSERT INTO sync_activity (id,tenant_id,site_id,direction,table_name,rows_count,status,note,node_ip,created_at)
      VALUES (?,?,?,?,?,?,'OK',?,?,NOW(6))")
      ->execute([uuid(),$tid,(string)($GLOBALS['sync_site_id']??''),$dir,$table,$rows,
                 $note!==''?substr($note,0,300):null,
                 substr((string)($_SERVER['HTTP_X_FORWARDED_FOR']??$_SERVER['REMOTE_ADDR']??''),0,64)]);
    if(random_int(1,50)===1)DB::pdo()->exec("DELETE FROM sync_activity WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
  }catch(Throwable $e){}
}

function syncTableAllowed(string $table): bool {
  static $allow=[
    /* Poora software sync hota hai. platform_users/tenants yahan NahI —
       wo platform-level hain aur tenant lock ke bawajood block rehte hain. */
    'users','user_roles','roles','role_modules','user_form_permissions','employee_profiles',
    'orders','order_items','payments','order_payments','order_item_voids',
    'kitchen_tickets','kitchen_ticket_items','qr_orders','qr_sessions',
    'cashier_shifts','shift_cash_movements','shift_handovers','fiscal_invoices',
    'menu_categories','menu_items','menu_item_variants','menu_category_printer_routes',
    'recipes','recipe_ingredients',
    'inventory_categories','inventory_items','stock_transactions','stock_transaction_lines',
    'stock_balances','stock_adjustments','stock_movements','stock_locations','units',
    'goods_receipts','goods_receipt_items','purchase_orders','purchase_order_items',
    'customers','customer_addresses','suppliers','supplier_items',
    'expenses','expense_categories','reservations','riders','delivery_orders',
    'promotions','printers','floors','dining_tables','payment_methods',
    'paired_devices','notification_queue','devices','ui_records',
  ];
  return in_array($table,$allow,true);
}function moduleId($key){$q=DB::pdo()->prepare("SELECT id FROM platform_modules WHERE module_key=? LIMIT 1");$q->execute([$key]);return$q->fetchColumn();}function roleIdByName($name){$q=DB::pdo()->prepare("SELECT id FROM roles WHERE tenant_id=? AND name=? LIMIT 1");$q->execute([tenant_id(),$name]);return$q->fetchColumn();}
function accessState():array{$p=DB::pdo();$rolesQ=$p->prepare("SELECT id,name FROM roles WHERE tenant_id=? AND is_active=1 ORDER BY name");$rolesQ->execute([tenant_id()]);$roles=[];foreach($rolesQ->fetchAll() as $r){$m=$p->prepare("SELECT pm.module_key FROM role_modules rm JOIN platform_modules pm ON pm.id=rm.module_id WHERE rm.role_id=? AND rm.is_allowed=1 ORDER BY pm.sort_order");$m->execute([$r['id']]);$roles[]=['id'=>$r['id'],'name'=>$r['name'],'modules'=>array_column($m->fetchAll(),'module_key')];}$users=[];$req=[];if(Auth::user()){$uq=$p->prepare("SELECT u.*,COALESCE(r.name,IF(u.is_tenant_admin=1,'Owner / Admin','User')) role_name,COALESCE(s.name,'All Branches') branch_name FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id LEFT JOIN sites s ON s.id=ur.site_id WHERE u.tenant_id=? AND u.deleted_at IS NULL GROUP BY u.id ORDER BY u.created_at DESC");$uq->execute([tenant_id()]);foreach($uq->fetchAll() as $u){$mods=Auth::moduleKeys($u['id']);$users[]=['id'=>$u['id'],'name'=>$u['full_name'],'email'=>$u['email'],'phone'=>$u['phone']?:'','role'=>$u['role_name'],'status'=>ucfirst(strtolower($u['status'])),'branch'=>$u['branch_name'],'modules'=>$mods,'permissions'=>['view'=>true,'add'=>false,'edit'=>false,'delete'=>false,'approve'=>(bool)$u['is_tenant_admin']], 'password'=>''];}$rq=$p->query("SELECT * FROM signup_requests WHERE status='PENDING' ORDER BY requested_at DESC");foreach($rq->fetchAll() as $r)$req[]=['id'=>$r['id'],'name'=>$r['full_name'],'email'=>$r['email'],'phone'=>$r['phone']?:'','business'=>$r['requested_org_name']?:'Restaurant','requestedAt'=>$r['requested_at'],'status'=>'Pending'];}else{$email=$_SESSION['pending_signup_email']??null;if($email){$q=$p->prepare("SELECT * FROM signup_requests WHERE email=? AND status='PENDING' ORDER BY requested_at DESC LIMIT 1");$q->execute([$email]);if($r=$q->fetch())$req[]=['id'=>$r['id'],'name'=>$r['full_name'],'email'=>$r['email'],'phone'=>$r['phone']?:'','business'=>$r['requested_org_name']?:'Restaurant','requestedAt'=>$r['requested_at'],'status'=>'Pending'];}}return['users'=>$users,'requests'=>$req,'roles'=>$roles];}
function applyUser(string $id,array $d,bool $create=false,?string $requestId=null):string{$p=DB::pdo();$role=roleIdByName($d['role']??'Cashier');$mods=[];foreach($d['modules']??[] as $k)if($m=moduleId($k))$mods[]=$m;$perm=$d['permissions']??[];if($create){return UserService::create(['full_name'=>$d['name'],'email'=>$d['email'],'username'=>$d['username']??'','phone'=>$d['phone']??'','password'=>$d['password']?:'1234','role_id'=>$role,'modules'=>$mods,'is_admin'=>($d['role']??'')==='Owner / Admin','form_permissions'=>[]],$requestId);}return DB::tx(function($p)use($id,$d,$role,$mods){$p->prepare("UPDATE users SET full_name=?,email=?,phone=?,updated_at=NOW(6) WHERE id=? AND tenant_id=?")->execute([$d['name'],$d['email'],$d['phone']??'',$id,tenant_id()]);if(!empty($d['password'])){[$h,$a]=UserService::passwordHash($d['password']);$p->prepare("UPDATE users SET password_hash=?,password_algo=? WHERE id=?")->execute([$h,$a,$id]);}$p->prepare("DELETE FROM user_roles WHERE user_id=?")->execute([$id]);$p->prepare("DELETE FROM user_module_access WHERE user_id=?")->execute([$id]);if($role)$p->prepare("INSERT INTO user_roles(id,user_id,role_id,site_id,assigned_by) VALUES(?,?,?,?,?)")->execute([uuid(),$id,$role,site_id(),current_user()['id']??null]);foreach($mods as $m)$p->prepare("INSERT INTO user_module_access(id,user_id,site_id,module_id,access_mode) VALUES(?,?,?,?, 'ALLOW')")->execute([uuid(),$id,site_id(),$m]);return$id;});}
try{$a=$_GET['action']??'';if($_SERVER['REQUEST_METHOD']==='POST' && !in_array($a,['login','signup','setup','sync-push','sync-pull','sync-push-bulk','sync-pull-bulk','sync-schema','sync-ping','sa-login'],true))csrf_json();switch($a){
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
case 'pos-next-bill':needLogin();ok(['next'=>pos_bill_no((int)PageData::nextBill())]);
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
case 'offline-package':needLogin();if(cfg('app.role')!=='cloud')fail('Offline version sirf online portal se download hoti hai',403);if(!Auth::isManager())fail('Sirf Admin/Manager offline version download kar sakta hai',403);
$p=DB::pdo();$tq=$p->prepare("SELECT id,name,slug,industry_code,sync_token,COALESCE(display_name,name) dn FROM tenants WHERE id=? LIMIT 1");$tq->execute([tenant_id()]);$t=$tq->fetch();if(!$t)fail('Business not found',404);
if(empty($t['sync_token'])){$tok=bin2hex(random_bytes(24));$p->prepare("UPDATE tenants SET sync_token=? WHERE id=?")->execute([$tok,$t['id']]);$t['sync_token']=$tok;}
$sq=$p->prepare("SELECT name FROM sites WHERE id=?");$sq->execute([site_id()]);$siteName=$sq->fetchColumn()?:'Main Branch';
$root=dirname(__DIR__);
require_once $root.'/tools/build_offline_bundle.php';
/* Kitne offline packages pehle ban chuke: har naye ko agla node code milta hai */
$nodeSeq=0;
try{$nq=$p->prepare("SELECT COUNT(DISTINCT node_ip) FROM sync_activity WHERE tenant_id=?");$nq->execute([tenant_id()]);$nodeSeq=(int)$nq->fetchColumn();}catch(Throwable $e){}
$base=rtrim((string)cfg('app.base_url'),'/');
/* Config seal ke andar jata hai - sync token plaintext disk par NahI */
$cfgArr=['app'=>['role'=>'local','name'=>(string)$t['dn'],'debug'=>false,'base_url'=>'http://localhost:8080',
                 'cloud_url'=>$base,'industry'=>(string)($t['industry_code']?:'RESTAURANT'),
                 /* helpers.php local mode mein yahi keys parhta hai */
                 'tenant_id'=>(string)$t['id'],'site_id'=>site_id(),'timezone'=>'Asia/Karachi',
                 /* Har offline installation ka apna bill prefix - takrao khatam */
                 'node_code'=>'L'.(string)(1+(int)$nodeSeq)],
 'db'=>['host'=>'127.0.0.1','port'=>3307,'database'=>'aio_local','username'=>'root','password'=>'','charset'=>'utf8mb4'],
 'tenant'=>['id'=>(string)$t['id'],'slug'=>(string)$t['slug'],'site_id'=>site_id(),'site_name'=>(string)$siteName],
 'sync'=>[
   'enabled'=>true,
   'token'=>(string)$t['sync_token'],
   /* Sync engine yehi key parhta hai - pehle sirf 'endpoint' likha jata tha
      is liye offline node hamesha "Local-only mode" mein rehta tha. */
   'cloud_api_url'=>$base.'/api.php',
   'endpoint'=>$base.'/api.php',
   'batch'=>300,
   'interval'=>30,'interval_minutes'=>2,
          'push_tables'=>[
            /* SAB kuch upar jata hai. Har table par updated_at maujood hai
               (migrate_sync_columns.php), warna sync khamoshi se skip kar
               deti thi - isi wajah se local aur live figures alag the. */
            'users','user_roles','roles','role_modules','user_form_permissions','employee_profiles',
            'orders','order_items','payments','order_payments','order_item_voids',
            'kitchen_tickets','kitchen_ticket_items','qr_orders','qr_sessions',
            'cashier_shifts','shift_cash_movements','shift_handovers','fiscal_invoices',
            'menu_categories','menu_items','menu_item_variants','menu_category_printer_routes',
            'recipes','recipe_ingredients',
            'inventory_categories','inventory_items','stock_transactions','stock_transaction_lines',
            'stock_balances','stock_adjustments','stock_movements','stock_locations','units',
            'goods_receipts','goods_receipt_items','purchase_orders','purchase_order_items',
            'customers','customer_addresses','suppliers','supplier_items',
            'expenses','expense_categories','reservations','riders','delivery_orders',
            'promotions','printers','floors','dining_tables','payment_methods',
            'paired_devices','notification_queue','devices','ui_records',
          ],
          'pull_tables'=>[
            /* TWO-WAY: jo kuch cloud par bane (ya doosre device se aaye) wo
               bhi is branch par utar aata hai. Local nayi copy ko overwrite
               nahi kiya jata (last-write-wins). Server side par yeh sirf
               isi tenant + isi branch tak mehdood hai. */
            'roles','role_modules','users','user_roles','user_form_permissions','employee_profiles',
            'menu_categories','menu_items','menu_item_variants','menu_category_printer_routes',
            'recipes','recipe_ingredients',
            'inventory_categories','inventory_items','stock_locations','units',
            'stock_transactions','stock_transaction_lines','stock_balances','stock_adjustments',
            'goods_receipts','goods_receipt_items','purchase_orders','purchase_order_items',
            'customers','customer_addresses','suppliers','supplier_items',
            'expenses','expense_categories','promotions','printers','floors','dining_tables',
            'payment_methods','reservations','riders','delivery_orders',
            'orders','order_items','payments','order_payments','order_item_voids',
            'kitchen_tickets','kitchen_ticket_items','qr_orders','qr_sessions',
            'cashier_shifts','shift_cash_movements','shift_handovers','fiscal_invoices',
            'paired_devices','devices','stock_movements','notification_queue','ui_records',
          ]]];
/* ---- FIRST-RUN SNAPSHOT ----
   Offline package ke saath is business ka apna data bhi jata hai (sealed):
   users/roles (taake wahi credentials offline chalein), menu, inventory,
   tables, customers, suppliers, recipes waghera. Warna offline version
   khali kholti thi aur sirf default admin se login hota tha. */
$snapTables=[
  ['units',null],
  ['roles','tenant'],['role_modules',null],
  ['users','tenant'],['user_roles',null],
  ['payment_methods','site'],['stock_locations','site'],
  ['printers','site'],['menu_categories','site'],['menu_category_printer_routes','site'],
  ['menu_items','site'],['menu_item_variants','site'],
  ['inventory_categories','site'],['inventory_items','site'],['stock_balances','site'],
  ['floors','site'],['dining_tables','site'],
  ['customers','tenant'],['customer_addresses',null],
  ['suppliers','tenant'],['supplier_items',null],
  ['recipes','site'],['recipe_ingredients',null],
  ['expense_categories','tenant'],
];
$snap=[];$colCache=[];
$hasCol=function(string $tb,string $c)use($p,&$colCache):bool{
  if(!isset($colCache[$tb])){$q=$p->prepare("SELECT column_name AS column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$tb]);$colCache[$tb]=array_column($q->fetchAll(),'column_name');}
  return in_array($c,$colCache[$tb],true);
};
$tblExists=function(string $tb)use($p):bool{$q=$p->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$tb]);return (bool)$q->fetchColumn();};
foreach($snapTables as $st){
  [$tb,$scope]=$st;
  if(!$tblExists($tb))continue;
  try{
    if($scope==='site'&&$hasCol($tb,'site_id')){$q=$p->prepare("SELECT * FROM `$tb` WHERE site_id=?");$q->execute([site_id()]);}
    elseif($scope==='tenant'&&$hasCol($tb,'tenant_id')){$q=$p->prepare("SELECT * FROM `$tb` WHERE tenant_id=?");$q->execute([tenant_id()]);}
    else{$q=$p->prepare("SELECT * FROM `$tb` LIMIT 5000");$q->execute();}
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    /* child tables ko parent ids se filter karo */
    if($tb==='role_modules'&&!empty($snap['roles'])){$ids=array_column($snap['roles'],'id');$rows=array_values(array_filter($rows,fn($r)=>in_array($r['role_id']??'',$ids,true)));}
    if($tb==='user_roles'&&!empty($snap['users'])){$ids=array_column($snap['users'],'id');$rows=array_values(array_filter($rows,fn($r)=>in_array($r['user_id']??'',$ids,true)));}
    if($tb==='customer_addresses'&&!empty($snap['customers'])){$ids=array_column($snap['customers'],'id');$rows=array_values(array_filter($rows,fn($r)=>in_array($r['customer_id']??'',$ids,true)));}
    if($tb==='supplier_items'&&!empty($snap['suppliers'])){$ids=array_column($snap['suppliers'],'id');$rows=array_values(array_filter($rows,fn($r)=>in_array($r['supplier_id']??'',$ids,true)));}
    if($tb==='recipe_ingredients'&&!empty($snap['recipes'])){$ids=array_column($snap['recipes'],'id');$rows=array_values(array_filter($rows,fn($r)=>in_array($r['recipe_id']??'',$ids,true)));}
    if($tb==='menu_category_printer_routes'&&!empty($snap['menu_categories'])){$ids=array_column($snap['menu_categories'],'id');$rows=array_values(array_filter($rows,fn($r)=>in_array($r['category_id']??'',$ids,true)));}
    if($rows)$snap[$tb]=$rows;
  }catch(Throwable $e){}
}
$cfgArr['snapshot']=$snap;
$built=OfflineBundler::build($root,$cfgArr);
$tmp=tempnam(sys_get_temp_dir(),'aio');@unlink($tmp);$tmp.='.zip';
$zip=new ZipArchive();
if($zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)fail('ZIP banane mein masla');
/* --- SEALED core --- */
$zip->addFromString('runtime/app.sealed',$built['blob']);
$zip->addFromString('runtime/app.key',$built['k1']);
$loaderSrc=OfflineBundler::loader('','');
/* defines ko declare(strict_types) ke BAAD daalo, warna PHP fatal */
$loaderSrc=str_replace("declare(strict_types=1);",
  "declare(strict_types=1);\ndefine('SEALED_K2','".bin2hex($built['k2'])."');\ndefine('SEALED_INTEGRITY','".bin2hex($built['integrity'])."');",
  $loaderSrc);
$loader=$loaderSrc;
$zip->addFromString('runtime/boot.php',$loader);
/* CA bundle: Windows PHP ke saath koi CA bundle nahi aata, is liye HTTPS
   (Railway) par sync fail ho jati thi. Server ka bundle sath bhej dete hain. */
foreach(['/etc/ssl/certs/ca-certificates.crt','/etc/pki/tls/certs/ca-bundle.crt'] as $caf){
  if(is_file($caf)){$zip->addFile($caf,'runtime/cacert.pem');break;}
}
$zip->addFromString('runtime/app.info',json_encode([
  'name'=>(string)$t['dn'],'branch'=>$siteName,
  'industry'=>(string)($t['industry_code']?:'RESTAURANT'),
  'product'=>getenv('APP_PRODUCT')?:'SmartPOS',
  'company'=>getenv('APP_COMPANY')?:'Wabwar Software House',
  'version'=>getenv('APP_VERSION')?:'1.0.0',
  'phone'=>getenv('APP_PHONE')?:'+92 300 0000000',
  'website'=>getenv('APP_WEBSITE')?:'https://wabwar.com',
  'email'=>getenv('APP_EMAIL')?:'support@wabwar.com',
]));
/* --- entry stubs (sirf yeh readable hain) --- */
$stub=function($rel){return "<?php\nrequire_once __DIR__.'/../runtime/boot.php';\nSealedApp::boot(dirname(__DIR__));\nreturn SealedApp::run('".$rel."');\n";};
foreach(['api.php','router.php','index.php','login-submit.php','logout.php'] as $e){
  if(is_file($root.'/public/'.$e))$zip->addFromString('public/'.$e,$stub('public/'.$e));
}
/* --- sirf browser-facing static assets disk par (UI HTML ab seal mein hai) --- */
foreach([['public/assets','public/assets']] as $pair){
  $srcDir=$root.'/'.$pair[0]; if(!is_dir($srcDir))continue;
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir,FilesystemIterator::SKIP_DOTS));
  foreach($it as $f){if(!$f->isFile())continue;$zip->addFile($f->getPathname(),$pair[1].'/'.ltrim(str_replace('\\','/',substr($f->getPathname(),strlen($srcDir))),'/'));}
}
foreach(glob($root.'/public/*.js') as $j)$zip->addFile($j,'public/'.basename($j));
foreach(glob($root.'/public/*.css') as $c)$zip->addFile($c,'public/'.basename($c));
/* --- launchers --- */
foreach(['START_OFFLINE.bat','INSTALL_OFFLINE.bat','DIAGNOSE.bat'] as $b){
  if(is_file($root.'/'.$b))$zip->addFile($root.'/'.$b,$b);
}
foreach(['download_helper.ps1','resolve_php.ps1','resolve_mariadb.ps1','install_offline.ps1','start_offline.ps1','diagnose.ps1'] as $ps){
  if(is_file($root.'/tools/'.$ps))$zip->addFile($root.'/tools/'.$ps,'tools/'.$ps);
}
$zip->addEmptyDir('data');$zip->addEmptyDir('runtime/mariadb');$zip->addEmptyDir('storage/logs');
/* Agar server par vendor/php.zip ya vendor/mariadb.zip mojood hain to unhe
   package mein bhej do - phir customer ke PC par kuch download NahI hoga. */
$anyVendor=false;
foreach(['php.zip','mariadb.zip'] as $vf){
  if(is_file($root.'/vendor/'.$vf)){$zip->addFile($root.'/vendor/'.$vf,'vendor/'.$vf);$anyVendor=true;}
}
if(!$anyVendor)$zip->addEmptyDir('vendor');
$zip->addFromString('OFFLINE_README.txt',
 "SMARTPOS - OFFLINE VERSION\n".str_repeat('=',52)."\n\n"
 ."Business : ".$t['dn']."\nBranch   : ".$siteName."\n"
 ."Company  : Wabwar Software House\nCloud    : ".$base."\n\n"
 ."SETUP (one time)\n"
 ."  1) Extract this ZIP into any folder, for example:\n"
 ."     C:\\SmartPOS\n"
 ."  2) Double click INSTALL_OFFLINE.bat\n"
 ."     - PHP and the database are prepared inside this folder\n"
 ."     - Nothing is installed on Windows\n"
 ."  3) Start the software from the Desktop shortcut.\n\n"
 ."DAILY USE\n"
 ."  Open the Desktop shortcut. The software runs even without\n"
 ."  internet; data syncs to the cloud automatically once you are\n"
 ."  back online.\n\n"
 ."DATABASE\n"
 ."  Portable database on 127.0.0.1:3307 (this PC only).\n\n"
 ."UNINSTALL\n"
 ."  Simply delete this folder. No Windows services or registry\n"
 ."  entries are created.\n\n"
 ."TROUBLESHOOTING\n"
 ."  If the software does not open, run DIAGNOSE.bat and send the\n"
 ."  output to support.\n\n"
 ."SUPPORT\n"
 ."  Website : https://wabwar.com\n  Email   : support@wabwar.com\n");
$zip->close();
$data=file_get_contents($tmp);@unlink($tmp);
while(ob_get_level())ob_end_clean();
header('Content-Type: application/zip');
/* Unique naam: har download alag file. Windows "(1)" nahi lagata,
   is liye folder ke naam mein space/brackets kabhi nahi aate. */
$pkgName='SmartPOS_'.preg_replace('/[^A-Za-z0-9]/','',(string)$t['slug']).'_'.date('Ymd_Hi');
header('Content-Disposition: attachment; filename="'.$pkgName.'.zip"');
header('Content-Length: '.strlen($data));
echo $data;exit;
/* ============ QR TABLE ORDERING (session-based) ============ */
case 'qr-tables':needLogin();if(!Auth::isManager())fail('Sirf Admin/Manager',403);$p=DB::pdo();$q=$p->prepare("SELECT id,display_name,table_code FROM dining_tables WHERE site_id=? AND is_active=1 ORDER BY display_name");$q->execute([site_id()]);$base=rtrim((string)cfg('app.base_url'),'/');$rows=[];foreach($q->fetchAll() as $t){$rows[]=['id'=>$t['id'],'name'=>$t['display_name'],'url'=>$base.'/qr.html?t='.rawurlencode((string)$t['id']).'&s='.rawurlencode(site_id())];}ok(['tables'=>$rows,'base'=>$base]);
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
/* ============ OFFLINE LOGIN: user dropdown ============ */
case 'users-list':
 /* Sirf LOCAL (offline) node par bina login ke - wahan ek hi business hota
    hai aur cashier ko naam type karne ke bajaye list se chunna hota hai.
    Cloud par yeh kabhi expose nahi hoti (business isolation). */
 /* CLOUD: sirf tab jab client-link (?b=slug) se tenant resolve ho chuka ho —
    yani sirf USI restaurant ke users. Bina slug ke koi list nahi milti. */
 if(cfg('app.role')==='cloud'){
   $lt=(string)($_SESSION['login_tenant_id']??'');
   if($lt==='')fail('Business link ke baghair user list available nahi',403);
 }
 $p=DB::pdo();$q=$p->prepare("SELECT u.id,u.username,u.email,u.full_name,u.is_tenant_admin,
     COALESCE((SELECT r.name FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id LIMIT 1),'') role_name
   FROM users u WHERE u.tenant_id=? AND u.status='ACTIVE' AND u.deleted_at IS NULL ORDER BY u.is_tenant_admin DESC,u.full_name");
 $q->execute([tenant_id()]);
 $rows=array_map(fn($x)=>['login'=>($x['username']?:$x['email']),'name'=>$x['full_name'],
   'role'=>($x['role_name']?:((int)$x['is_tenant_admin']?'Admin':'User'))],$q->fetchAll());
 ok(['users'=>$rows,'mode'=>'local']);

/* ============ DEVICE PAIRING (tablet / mobile over LAN) ============ */
case 'device-pair-start':needLogin();if(!Auth::isManager())fail('Sirf Admin/Manager',403);
 $d=body();$role=strtoupper((string)($d['role']??'WAITER'));
 if(!in_array($role,['WAITER','CASHIER','KDS','MANAGER'],true))$role='WAITER';
 $p=DB::pdo();$tok=bin2hex(random_bytes(16));$did=uuid();
 $mins=(int)(getenv('PAIR_TOKEN_MINUTES')?:15);
 $p->prepare("INSERT INTO paired_devices(id,tenant_id,site_id,device_name,device_role,pair_token,user_id,status,created_at,expires_at)
   VALUES(?,?,?,?,?,?,?,'PENDING',NOW(6),DATE_ADD(NOW(6),INTERVAL ? MINUTE))")
   ->execute([$did,tenant_id(),site_id(),(string)($d['name']??'Tablet'),$role,$tok,current_user()['id']??null,$mins]);
 /* LAN addresses - tablet usi WiFi par in mein se kisi ek se connect karega */
 $port=(int)($_SERVER['SERVER_PORT']??8080);
 $ips=[];
 if(function_exists('net_get_interfaces')){
   foreach((net_get_interfaces()?:[]) as $if){
     foreach(($if['unicast']??[]) as $u){
       $a=$u['address']??'';
       if($a&&filter_var($a,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)&&$a!=='127.0.0.1'&&strpos($a,'169.254.')!==0)$ips[]=$a;
     }
   }
 }
 if(!$ips){$h=@gethostbyname(@gethostname());if($h&&filter_var($h,FILTER_VALIDATE_IP)&&$h!=='127.0.0.1')$ips[]=$h;}
 $ips=array_values(array_unique($ips));
 $urls=[];foreach($ips as $ip)$urls[]='http://'.$ip.':'.$port.'/pair.html?t='.$tok;
 if(!$urls)$urls[]=rtrim((string)cfg('app.base_url'),'/').'/pair.html?t='.$tok;
 ok(['token'=>$tok,'role'=>$role,'expires_min'=>$mins,'urls'=>$urls,'ips'=>$ips,'port'=>$port]);

case 'device-pair-claim':
 $p=DB::pdo();$tok=(string)($_GET['t']??($_POST['t']??''));
 if($tok==='')fail('Pairing code required',400);
 $q=$p->prepare("SELECT *, (expires_at > NOW(6)) alive FROM paired_devices WHERE pair_token=? LIMIT 1");
 $q->execute([$tok]);$dev=$q->fetch();
 if(!$dev)fail('Pairing code valid nahi - POS se naya QR banayein',401);
 if($dev['status']==='REVOKED')fail('Yeh device revoke ho chuka hai',403);
 if($dev['status']==='PENDING'&&!(int)$dev['alive'])fail('Pairing code ka waqt khatam - POS se naya QR banayein',401);
 /* device ko us user ki session mil jati hai jisne QR banaya (role-limited) */
 $uq=$p->prepare("SELECT * FROM users WHERE id=? AND status='ACTIVE' AND deleted_at IS NULL");
 $uq->execute([$dev['user_id']]);$u=$uq->fetch();
 if(!$u)fail('Pairing user not found',401);
 Auth::startSessionForUser($u);
 $_SESSION['device_id']=$dev['id'];$_SESSION['device_role']=$dev['device_role'];
 $p->prepare("UPDATE paired_devices SET status='ACTIVE',paired_at=COALESCE(paired_at,NOW(6)),last_seen_at=NOW(6),user_agent=? WHERE id=?")
   ->execute([substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255),$dev['id']]);
 $land=['WAITER'=>'/restaurant_order_taker_tablet.html','CASHIER'=>'/restaurant_pos.html',
        'KDS'=>'/kds.html','MANAGER'=>'/index.html'][$dev['device_role']]??'/index.html';
 ok(['device'=>$dev['device_name'],'role'=>$dev['device_role'],'redirect'=>$land]);

case 'device-list':needLogin();if(!Auth::isManager())fail('Sirf Admin/Manager',403);
 $q=DB::pdo()->prepare("SELECT id,device_name,device_role,status,paired_at,last_seen_at FROM paired_devices WHERE site_id=? AND status<>'REVOKED' ORDER BY created_at DESC LIMIT 50");
 $q->execute([site_id()]);ok(['devices'=>$q->fetchAll()]);
case 'device-revoke':needLogin();if(!Auth::isManager())fail('Sirf Admin/Manager',403);
 $d=body();DB::pdo()->prepare("UPDATE paired_devices SET status='REVOKED' WHERE id=? AND site_id=?")->execute([(string)($d['id']??''),site_id()]);ok();

case 'my-modules':needLogin();$u=Auth::user();ok(['modules'=>($u['modules']??[]),'admin'=>!empty($u['is_tenant_admin']),'manager'=>Auth::isManager(),'name'=>$u['full_name']??'']);
case 'pos-boot':needLogin();if(!Auth::canModule('pos')&&!Auth::canModule('tablet'))fail('Permission denied',403);$bu=Auth::user();$bb=PageData::posBoot();$sq=DB::pdo()->prepare("SELECT name FROM sites WHERE id=? LIMIT 1");$sq->execute([site_id()]);$bb['site']=['name'=>(string)($sq->fetchColumn()?:'Main Branch')];$sg=$p2=DB::pdo()->prepare("SELECT data_json FROM ui_records WHERE tenant_id=? AND site_id=? AND module_key='pos_settings' AND deleted=0 ORDER BY created_at DESC LIMIT 1");$sg->execute([tenant_id(),site_id()]);$sj=$sg->fetchColumn();$sd=$sj?(json_decode($sj,true)?:[]):[];$bb['settings']=['tax_cash'=>isset($sd['tax_cash'])?(float)$sd['tax_cash']:16.0,'tax_card'=>isset($sd['tax_card'])?(float)$sd['tax_card']:8.0,'service_charge'=>isset($sd['service_charge'])?(float)$sd['service_charge']:0.0];$bq=DB::pdo()->prepare("SELECT name,display_name,logo_url,brand_color,brand_accent FROM tenants WHERE id=? LIMIT 1");$bq->execute([tenant_id()]);$br=$bq->fetch()?:[];
$bb['brand']=['name'=>($br['display_name']?:($br['name']??'Restaurant')),'logo'=>$br['logo_url']??'','color'=>$br['brand_color']??'','accent'=>$br['brand_accent']??''];
$bb['can']=['manage'=>Auth::isManager(),'reports'=>Auth::canModule('reports'),'offline_download'=>(cfg('app.role')==='cloud'),'modules'=>(Auth::user()['modules']??[])];
$bb['cashier']=['name'=>$bu['full_name']??'Cashier','role'=>Auth::isManager()?(!empty($bu['is_tenant_admin'])?'Admin':'Manager'):'Cashier'];ok(['boot'=>$bb]);
case 'shift-current':needLogin();
 /* Har cashier ki apni shift: sirf isi user ki open shift return hoti hai. */
 $q=DB::pdo()->prepare("SELECT id,shift_no,business_date,opening_cash,opened_at,counter_name FROM cashier_shifts WHERE site_id=? AND cashier_user_id=? AND status='OPEN' ORDER BY opened_at DESC LIMIT 1");
 $q->execute([site_id(),current_user()['id']??'']);
 $mine=$q->fetch()?:null;
 $oq=DB::pdo()->prepare("SELECT cs.id,cs.shift_no,cs.counter_name,u.full_name cashier FROM cashier_shifts cs LEFT JOIN users u ON u.id=cs.cashier_user_id WHERE cs.site_id=? AND cs.status='OPEN'");
 $oq->execute([site_id()]);
 ok(['shift'=>$mine,'open_shifts'=>$oq->fetchAll()]);
case 'shift-open':needLogin();Auth::requireModule('pos');$d=body();$p=DB::pdo();$uid=current_user()['id']??'';
 /* 1) Isi user ki koi shift pehle se open? */
 $q=$p->prepare("SELECT shift_no FROM cashier_shifts WHERE site_id=? AND cashier_user_id=? AND status='OPEN' LIMIT 1");
 $q->execute([site_id(),$uid]);
 if($sn=$q->fetchColumn())fail('Aap ki shift '.$sn.' pehle se open hai. Pehle usay close karein.');
 /* 2) Isi counter par kisi aur ki shift open? (do cashier ek counter par nahi) */
 $counter=trim((string)($d['counter']??''))?:'Counter 1';
 $c=$p->prepare("SELECT cs.shift_no,u.full_name FROM cashier_shifts cs LEFT JOIN users u ON u.id=cs.cashier_user_id WHERE cs.site_id=? AND cs.status='OPEN' AND cs.counter_name=? LIMIT 1");
 $c->execute([site_id(),$counter]);
 if($row=$c->fetch())fail($counter.' par '.($row['full_name']?:'kisi user').' ki shift ('.$row['shift_no'].') open hai. Pehle wo close ya transfer ho.');
 /* 3) Pichli shift ka cash clear hua? */
 $lc=$p->prepare("SELECT shift_no,cash_cleared FROM cashier_shifts WHERE site_id=? AND cashier_user_id=? AND status='CLOSED' ORDER BY closed_at DESC LIMIT 1");
 $lc->execute([site_id(),$uid]);
 if($last=$lc->fetch()){ if(!(int)$last['cash_cleared'])fail('Pichli shift '.$last['shift_no'].' ka cash clear nahi hua. Pehle usay clear karein.'); }
 $sid=uuid();$no='S-'.date('ymd').'-'.strtoupper(substr(str_replace('-','',$sid),0,4));
 $p->prepare("INSERT INTO cashier_shifts(id,tenant_id,site_id,shift_no,business_date,cashier_user_id,counter_name,device_id,opened_at,opening_cash,status)
   VALUES(?,?,?,?,CURDATE(),?,?,?,NOW(6),?,'OPEN')")
   ->execute([$sid,tenant_id(),site_id(),$no,$uid,$counter,($_SESSION['device_id']??null),(float)($d['opening_cash']??0)]);
 ok(['id'=>$sid,'shift_no'=>$no,'counter'=>$counter]);

case 'shift-users':needLogin();
 /* Transfer ke liye: isi branch ke wo users jinke paas POS access hai */
 $q=DB::pdo()->prepare("SELECT DISTINCT u.id,u.full_name FROM users u WHERE u.tenant_id=? AND u.status='ACTIVE' AND u.deleted_at IS NULL AND u.id<>? ORDER BY u.full_name");
 $q->execute([tenant_id(),current_user()['id']??'']);ok(['users'=>$q->fetchAll()]);

case 'shift-clear-cash':needLogin();$d=body();$p=DB::pdo();$sid=(string)($d['shift_id']??'');
 $q=$p->prepare("SELECT * FROM cashier_shifts WHERE id=? AND site_id=? AND status='CLOSED' LIMIT 1");$q->execute([$sid,site_id()]);
 $sh=$q->fetch(); if(!$sh)fail('Closed shift nahi mili');
 if((int)$sh['cash_cleared'])ok(['already'=>true]);
 $amt=(float)($d['amount']??$sh['actual_cash']);
 $p->prepare("UPDATE cashier_shifts SET cash_cleared=1,cleared_amount=?,updated_at=NOW(6) WHERE id=?")->execute([$amt,$sid]);
 ok(['cleared'=>$amt,'shift_no'=>$sh['shift_no']]);

case 'shift-transfer':needLogin();Auth::requireModule('pos');$d=body();$p=DB::pdo();$uid=current_user()['id']??'';
 $toUser=(string)($d['to_user_id']??'');if($toUser==='')fail('Naya cashier select karein');
 $q=$p->prepare("SELECT * FROM cashier_shifts WHERE site_id=? AND cashier_user_id=? AND status='OPEN' ORDER BY opened_at DESC LIMIT 1");
 $q->execute([site_id(),$uid]);$sh=$q->fetch(); if(!$sh)fail('Aap ki koi open shift nahi');
 $uq=$p->prepare("SELECT id,full_name FROM users WHERE id=? AND tenant_id=? AND status='ACTIVE' AND deleted_at IS NULL");
 $uq->execute([$toUser,tenant_id()]);$nu=$uq->fetch(); if(!$nu)fail('Naya cashier valid nahi');
 $oc=$p->prepare("SELECT shift_no FROM cashier_shifts WHERE site_id=? AND cashier_user_id=? AND status='OPEN' LIMIT 1");
 $oc->execute([site_id(),$toUser]);
 if($x=$oc->fetchColumn())fail($nu['full_name'].' ki shift '.$x.' pehle se open hai.');
 $rep=shift_report($sh,null);
 $counted=(float)($d['counted_cash']??$rep['expected_cash']);
 $handed=(float)($d['handed_cash']??$counted);
 /* purani shift close + cash cleared (handover se) */
 $p->prepare("UPDATE cashier_shifts SET closed_at=NOW(6),expected_cash=?,actual_cash=?,variance_amount=?,status='CLOSED',
    close_note=?,cash_cleared=1,cleared_amount=?,handover_to=?,updated_at=NOW(6) WHERE id=?")
   ->execute([$rep['expected_cash'],$counted,$counted-$rep['expected_cash'],
              'Handover to '.$nu['full_name'].((string)($d['note']??'')!==''?(' - '.$d['note']):''),
              $handed,$toUser,$sh['id']]);
 /* nayi shift: handed cash hi opening banti hai */
 $nid=uuid();$nno='S-'.date('ymd').'-'.strtoupper(substr(str_replace('-','',$nid),0,4));
 $p->prepare("INSERT INTO cashier_shifts(id,tenant_id,site_id,shift_no,business_date,cashier_user_id,counter_name,opened_at,opening_cash,status)
   VALUES(?,?,?,?,CURDATE(),?,?,NOW(6),?,'OPEN')")
   ->execute([$nid,tenant_id(),site_id(),$nno,$toUser,$sh['counter_name']?:'Counter 1',$handed]);
 /* handover record */
 $p->prepare("INSERT INTO shift_handovers(id,tenant_id,site_id,from_shift_id,to_shift_id,from_user_id,to_user_id,counter_name,
     expected_cash,counted_cash,variance_amount,handed_cash,note,created_at)
   VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(6))")
   ->execute([uuid(),tenant_id(),site_id(),$sh['id'],$nid,$uid,$toUser,$sh['counter_name']?:'Counter 1',
              $rep['expected_cash'],$counted,$counted-$rep['expected_cash'],$handed,(string)($d['note']??'')]);
 $rep['actual_cash']=$counted;$rep['variance']=$counted-$rep['expected_cash'];
 $rep['closed_at']=date('Y-m-d H:i');$rep['handed_to']=$nu['full_name'];$rep['handed_cash']=$handed;$rep['new_shift']=$nno;
 ok(['report'=>$rep,'new_shift_no'=>$nno,'to'=>$nu['full_name']]);

case 'shift-handovers':needLogin();
 $q=DB::pdo()->prepare("SELECT h.*,fu.full_name from_name,tu.full_name to_name FROM shift_handovers h
   LEFT JOIN users fu ON fu.id=h.from_user_id LEFT JOIN users tu ON tu.id=h.to_user_id
   WHERE h.site_id=? ORDER BY h.created_at DESC LIMIT 50");
 $q->execute([site_id()]);ok(['handovers'=>$q->fetchAll()]);

case 'shift-preview':needLogin();Auth::requireModule('pos');$p=DB::pdo();$q=$p->prepare("SELECT id,shift_no,opening_cash,opened_at,counter_name FROM cashier_shifts WHERE site_id=? AND cashier_user_id=? AND status='OPEN' ORDER BY opened_at DESC LIMIT 1");$q->execute([site_id(),current_user()['id']??'']);$sh=$q->fetch();if(!$sh)fail('No open shift.');ok(['report'=>shift_report($sh,null)]);
case 'shift-close':needLogin();Auth::requireModule('pos');$d=body();$p=DB::pdo();$q=$p->prepare("SELECT id,shift_no,opening_cash,opened_at FROM cashier_shifts WHERE site_id=? AND cashier_user_id=? AND status='OPEN' ORDER BY opened_at DESC LIMIT 1");$q->execute([site_id(),current_user()['id']??'']);$sh=$q->fetch();if(!$sh)fail('Aap ki koi open shift nahi.');$rep=shift_report($sh,null);$actual=(float)($d['actual_cash']??$rep['expected_cash']);$clear=!empty($d['clear_cash'])?1:0;
 $p->prepare("UPDATE cashier_shifts SET closed_at=NOW(6),expected_cash=?,actual_cash=?,variance_amount=?,status='CLOSED',close_note=?,cash_cleared=?,cleared_amount=?,updated_at=NOW(6) WHERE id=?")
   ->execute([$rep['expected_cash'],$actual,$actual-$rep['expected_cash'],(string)($d['note']??''),$clear,$clear?$actual:null,$sh['id']]);$rep['actual_cash']=$actual;$rep['variance']=$actual-$rep['expected_cash'];$rep['closed_at']=date('Y-m-d H:i');$rep['note']=(string)($d['note']??'');ok(['report'=>$rep]);
case 'shift-last-report':needLogin();Auth::requireModule('pos');$p=DB::pdo();$q=$p->prepare("SELECT id,shift_no,opening_cash,opened_at,closed_at,expected_cash,actual_cash,variance_amount,close_note,cash_cleared,counter_name FROM cashier_shifts WHERE site_id=? AND cashier_user_id=? AND status='CLOSED' ORDER BY closed_at DESC LIMIT 1");$q->execute([site_id(),current_user()['id']??'']);$sh=$q->fetch();if(!$sh)fail('No closed shift yet.');$rep=shift_report($sh,$sh['closed_at']);$rep['actual_cash']=(float)$sh['actual_cash'];$rep['variance']=(float)$sh['variance_amount'];$rep['closed_at']=substr((string)$sh['closed_at'],0,16);$rep['note']=(string)($sh['close_note']??'');
 $rep['cash_cleared']=(int)($sh['cash_cleared']??0);$rep['shift_id']=$sh['id'];ok(['report'=>$rep]);
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
case 'pos-finalize':needLogin();Auth::requireModule('pos');$d=body();$d['bill_no']=pos_bill_guard($d);$id=PosService::finalize($d,$d['items']??[]);ok(['order_id'=>$id,'bill_no'=>$d['bill_no'],'next'=>pos_bill_no((int)PageData::nextBill()),'dashboard'=>PageData::dashboard()]);
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
case 'sync-ping':
 /* Token ho to node heartbeat; na ho (misal super admin console) to sirf
    build info wapas — warna 401 aata tha aur console ka build pill khali
    reh jata tha. */
 if(!empty($_SERVER['HTTP_X_SYNC_TOKEN'])){ try{ $pt=syncTenant(); syncNodeSeen($pt); }catch(Throwable $e){} }
 /* Build version bhi bhejo: offline node compare kar ke bata sakay ke
    cloud par purana build chal raha hai (warna ghanton confusion hoti hai). */
 $bv='unknown';
 try{$vf=dirname(__DIR__).'/VERSION'; if(is_file($vf))$bv=trim((string)file_get_contents($vf));}catch(Throwable $e){}
 ok(['role'=>cfg('app.role'),'time'=>date('c'),'build'=>$bv,
     'features'=>['bulk_sync'=>true,'conflict_reject'=>true,'sync_log'=>true]]);
/* ============================================================
   BULK SYNC — pehle har table ke liye alag HTTP request jati thi
   (56 pull + push = 60-110 requests har sync par). Internet par yeh
   60-90 second le leta tha aur browser timeout kar deta tha.
   Ab poora sync 2-3 requests mein.
   ============================================================ */
/* Cloud ka schema — node apne schema se compare kar ke bata sake ke
   kaun sa column ghayab/chhota hai. Row rejection ki sab se aam wajah
   yehi hoti hai aur ab wo khud pakri ja sakti hai. */
case 'sync-schema':$stid=syncTenant();syncNodeSeen($stid);
 if(session_status()===PHP_SESSION_ACTIVE)@session_write_close();
 $d=body();$want=is_array($d['tables']??null)?$d['tables']:[];
 $p=DB::pdo();$out=[];
 foreach($want as $tb){
   $tb=(string)$tb; if(!syncTableAllowed($tb))continue;
   try{
     $q=$p->prepare("SELECT column_name c,column_type t,is_nullable n,column_default d
                       FROM information_schema.columns
                      WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position");
     $q->execute([$tb]);
     $cols=[];
     foreach($q->fetchAll() as $r)$cols[$r['c']]=['type'=>$r['t'],'null'=>$r['n'],'default'=>$r['d']];
     if($cols)$out[$tb]=$cols;
   }catch(Throwable $e){}
 }
 ok(['schema'=>$out]);

case 'sync-pull-bulk':$stid=syncTenant();syncNodeSeen($stid);$d=body();
 if(session_status()===PHP_SESSION_ACTIVE)@session_write_close();
 $GLOBALS['sync_tenant_id']=$stid;$GLOBALS['sync_site_id']=(string)($d['site_id']??'');
 $want=is_array($d['tables']??null)?$d['tables']:[];
 $limit=max(50,min(2000,(int)($d['limit']??300)));
 $cap=max(500,min(20000,(int)($d['total_cap']??8000)));
 $out=[];$got=0;$more=false;
 foreach($want as $tbl=>$since){
   $tbl=(string)$tbl;
   if(!syncTableAllowed($tbl)){$out[$tbl]=['error'=>'not allowed'];continue;}
   if($got>=$cap){$more=true;break;}
   try{
     $rows=Sync::changedRows($tbl,(string)$since,$limit);
     $ts=Sync::tsCol($tbl);
     $out[$tbl]=['rows'=>$rows,'watermark'=>($rows&&$ts)?end($rows)[$ts]:(string)$since,'count'=>count($rows)];
     $got+=count($rows);
     if($rows)syncActivityLog($stid,'PULL',$tbl,count($rows));
   }catch(Throwable $e){$out[$tbl]=['error'=>substr($e->getMessage(),0,160)];}
 }
 ok(['tables'=>$out,'more'=>$more,'total'=>$got]);

case 'sync-push-bulk':$stid=syncTenant();syncNodeSeen($stid);$d=body();
 if(session_status()===PHP_SESSION_ACTIVE)@session_write_close();
 $GLOBALS['sync_site_id']=(string)($d['site_id']??'');
 $sets=is_array($d['tables']??null)?$d['tables']:[];
 $out=[];
 foreach($sets as $tbl=>$rows){
   $tbl=(string)$tbl;
   if(!syncTableAllowed($tbl)){$out[$tbl]=['error'=>'not allowed','applied'=>0,'sent'=>is_array($rows)?count($rows):0];continue;}
   $rows=is_array($rows)?$rows:[];
   try{
     Sync::$lastConflicts=[];Sync::$lastAudit=[];unset(Sync::$lastRowErrors[$tbl]);
     $n=Sync::applyRows($tbl,$rows,$stid);
     $conf=Sync::$lastConflicts;
     $bad=array_values(array_filter(Sync::$lastAudit,fn($a)=>!in_array($a['status'],['INSERTED','UPDATED'],true)));
     /* Asli wajah bhi wapas bhejo. Pehle sirf gina jata tha ke kitni rows
        na chalin - kyun nahi chalin, wo cloud ke andar hi reh jata tha aur
        node par "X row(s) not accepted" jaisa be-maani message aata tha. */
     $out[$tbl]=['applied'=>$n,'sent'=>count($rows),'conflicts'=>count($conf),
                 'conflict_detail'=>array_slice($conf,0,3),
                 'row_error'=>Sync::$lastRowErrors[$tbl]??null,
                 'rejected'=>array_slice($bad,0,5)];
     if($n>0)syncActivityLog($stid,'PUSH',$tbl,$n);
     if($conf)syncActivityLog($stid,'PUSH',$tbl,0,count($conf).' row(s) rejected (duplicate key)');
   }catch(Throwable $e){
     $out[$tbl]=['error'=>substr($e->getMessage(),0,160),'applied'=>0,'sent'=>count($rows)];
   }
 }
 ok(['tables'=>$out]);

case 'sync-push':$stid=syncTenant();syncNodeSeen($stid);$d=body();$tbl=(string)($d['table']??'');
 if(!syncTableAllowed($tbl))fail('Table sync ke liye allowed nahi: '.$tbl,403);
 Sync::$lastConflicts=[];
 $sent=count($d['rows']??[]);
 $n=Sync::applyRows($tbl,$d['rows']??[],$stid);
 $conf=Sync::$lastConflicts;
 if($n>0)syncActivityLog($stid,'PUSH',$tbl,$n);
 if($conf)syncActivityLog($stid,'PUSH',$tbl,0,count($conf).' row(s) rejected (duplicate key)');
 ok(['applied'=>$n,'sent'=>$sent,'conflicts'=>count($conf),
     'conflict_detail'=>array_slice($conf,0,5),
     'row_error'=>Sync::$lastRowErrors[$tbl]??null]);
case 'sync-pull':$stid=syncTenant();syncNodeSeen($stid);$d=body();$t=(string)($d['table']??'');
 if(!syncTableAllowed($t))fail('Table sync ke liye allowed nahi: '.$t,403);
 $GLOBALS['sync_tenant_id']=$stid;
 $GLOBALS['sync_site_id']=(string)($d['site_id']??'');$since=(string)($d['since']??'1970-01-01 00:00:00.000000');$lim=(int)($d['limit']??300);$rows=Sync::changedRows($t,$since,$lim);$ts=Sync::tsCol($t);$wm=($rows&&$ts)?end($rows)[$ts]:$since;
 if($rows)syncActivityLog($stid,'PULL',$t,count($rows));
 ok(['rows'=>$rows,'watermark'=>$wm,'count'=>count($rows)]);
case 'sync-log':needLogin();syncLogEnsure();
 $p=DB::pdo();$lim=max(1,min(50,(int)($_GET['limit']??20)));
 if(cfg('app.role')==='cloud'){
   /* Cloud: is business ke branch computers se kya aaya */
   $q=$p->prepare("SELECT direction,table_name,rows_count,status,note,node_ip,created_at
                     FROM sync_activity WHERE tenant_id=? ORDER BY created_at DESC LIMIT $lim");
   $q->execute([tenant_id()]);
   $rows=$q->fetchAll();
   $sq=$p->prepare("SELECT COUNT(*) transfers,COALESCE(SUM(rows_count),0) rows_total,MAX(created_at) last_at
                      FROM sync_activity WHERE tenant_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)");
   $sq->execute([tenant_id()]);
   $nq=$p->prepare("SELECT node_code,machine_fingerprint ip,app_version,last_seen_at,status
                      FROM sync_nodes WHERE tenant_id=? ORDER BY last_seen_at DESC LIMIT 10");
   $nq->execute([tenant_id()]);
   ok(['role'=>'cloud','activity'=>$rows,'today'=>$sq->fetch(),'nodes'=>$nq->fetchAll()]);
 }
 ok(['role'=>'local','runs'=>Sync::runLog($lim)]);
case 'sync-diagnose':needLogin();
 if(session_status()===PHP_SESSION_ACTIVE)@session_write_close();
 if(cfg('app.role')==='cloud')ok(['checks'=>[['step'=>'Mode','ok'=>true,'detail'=>'This is the cloud server - offline nodes push data here.']]]);
 ok(['checks'=>Sync::diagnose()]);
case 'sync-state':needLogin();
 /* POS ke live indicator ke liye halka endpoint */
 $role=cfg('app.role');
 $out=['role'=>$role,'enabled'=>false,'reason'=>'','pending'=>0,'last_run'=>null,
       'last_status'=>null,'last_error'=>null,'cloud_online'=>false,'cloud_url'=>''];
 if($role==='cloud'){
   /* Cloud par yeh card sirf tab dikhana hai jab is business ka koi offline
      node waqai mojood ho — warna khali card sirf shor hai. */
   $out['reason']='cloud';
   $has=false;$last=null;$nodes=0;
   try{
     $p=DB::pdo();
     /* Node ka connect hona hi kaafi hai - rows bhejna zaroori nahi */
     $nq=$p->prepare("SELECT COUNT(*) c, MAX(last_seen_at) last FROM sync_nodes WHERE tenant_id=? AND status<>'REVOKED'");
     $nq->execute([tenant_id()]);
     if($nr=$nq->fetch()){ $nodes=(int)$nr['c']; $has=$nodes>0; $last=$nr['last']; }
     $q=$p->prepare("SELECT COUNT(*) c, MAX(created_at) last FROM sync_activity WHERE tenant_id=?");
     $q->execute([tenant_id()]);
     if($r=$q->fetch()){ if((int)$r['c']>0){ $has=true; if(!$last||$r['last']>$last)$last=$r['last']; } }
     $dq=$p->prepare("SELECT COUNT(*) t, COALESCE(SUM(rows_count),0) r FROM sync_activity
                        WHERE tenant_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)");
     $dq->execute([tenant_id()]);
     if($d24=$dq->fetch()){ $out['transfers_24h']=(int)$d24['t']; $out['rows_24h']=(int)$d24['r']; }
   }catch(Throwable $e){}
   $out['local_build']=Sync::localBuild();
   $out['has_offline_node']=$has;
   $out['nodes']=$nodes;
   $out['last_run']=$last;
   ok(['sync'=>$out]);
 }
 $out['enabled']=Sync::enabled();
 $out['reason']=Sync::statusReason();
 $out['cloud_url']=(string)(($GLOBALS['config']['sync']['cloud_api_url']??'')?:'');
 try{
   $p=DB::pdo();
   $q=$p->query("SELECT MAX(last_run_at) lr, SUM(last_status='ERROR') errs FROM sync_state");
   if($r=$q->fetch()){ $out['last_run']=$r['lr']; }
   $e=$p->query("SELECT last_error FROM sync_state WHERE last_status='ERROR' AND last_error IS NOT NULL ORDER BY last_run_at DESC LIMIT 1");
   if($x=$e->fetchColumn()) $out['last_error']=substr((string)$x,0,160);
   /* kitni rows abhi bheji jani baqi hain */
   $pending=0;
   foreach((array)($GLOBALS['config']['sync']['push_tables']??[]) as $tb){
     try{
       $wq=$p->prepare("SELECT watermark FROM sync_state WHERE scope=? LIMIT 1");
       $wq->execute(['push:'.$tb]); $wm=$wq->fetchColumn()?:'1970-01-01 00:00:00.000000';
       $col=Sync::tsCol($tb); if(!$col)continue;
       $cq=$p->prepare("SELECT COUNT(*) FROM `$tb` WHERE `$col` > ?");
       $cq->execute([$wm]); $pending+=(int)$cq->fetchColumn();
     }catch(Throwable $e2){}
   }
   $out['pending']=$pending;
 }catch(Throwable $e){}
 if($out['enabled']){
   /* Session lock chhoR do: warna yeh request baqi POS calls ko block karti hai */
   if(session_status()===PHP_SESSION_ACTIVE)@session_write_close();
   $probe=Sync::cloudOnlineCached((int)($_GET['ttl']??60));
   $out['local_build']=Sync::localBuild();
   $cb=Sync::cloudBuild();$out['cloud_build']=$cb['build'];
   $out['build_mismatch']=($cb['build']!==''&&$out['local_build']!=='unknown'
       &&strtok($cb['build'],' ')!==strtok($out['local_build'],' '));
   $out['cloud_online']=$probe['online'];
   $out['probe_cached']=$probe['cached'];
   if(!$probe['online']&&$probe['error']!=='')$out['last_error']=substr($probe['error'],0,180);
 }
 ok(['sync'=>$out]);

case 'sync-run':needLogin();
 if(session_status()===PHP_SESSION_ACTIVE)@session_write_close();
 if(cfg('app.role')==='cloud')fail('Yeh cloud server hai - sync offline node se chalti hai. Yahan sirf status dekha ja sakta hai.',400);
 $why=Sync::statusReason();
 if($why!=='')fail($why,400);
 ok(Sync::run('manual'));
case 'sync-status':needLogin();
 $st=Sync::status();
 $st['role']=cfg('app.role');
 $st['reason']=(cfg('app.role')==='cloud')?'This is the cloud server. Offline nodes push data here.':Sync::statusReason();
 $st['cloud_url']=(string)(($GLOBALS['config']['sync']['cloud_api_url']??'')?:($GLOBALS['config']['sync']['endpoint']??''));
 ok(['status'=>$st]);
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
/* ============ SUPER ADMIN: BACKUP / RESET / IMPORT ============ */
case 'sa-backup':needSuper();
 $tid=(string)($_GET['tenant_id']??'');if($tid==='')fail('tenant_id required');
 $scope=strtoupper((string)($_GET['scope']??'FULL'));if(!in_array($scope,['MASTER','FULL'],true))$scope='FULL';
 $p=DB::pdo();$q=$p->prepare("SELECT slug,name FROM tenants WHERE id=?");$q->execute([$tid]);$t=$q->fetch();
 if(!$t)fail('Business not found',404);
 $b=\Aio\Services\AdminData::backup($tid,$scope);
 $file='backup_'.preg_replace('/[^A-Za-z0-9]/','',(string)$t['slug']).'_'.$scope.'_'.date('Ymd_Hi').'.json';
 $p->prepare("INSERT INTO admin_backups(id,tenant_id,scope,file_name,tables_count,rows_count,size_bytes,checksum,created_by,created_at)
              VALUES(?,?,?,?,?,?,?,?,?,NOW(6))")
   ->execute([uuid(),$tid,$scope,$file,$b['meta']['tables'],$b['meta']['rows'],$b['meta']['bytes'],$b['meta']['checksum'],
              (string)(Platform::superUser()['email']??'super'),]);
 \Aio\Services\AdminData::audit((string)(Platform::superUser()['email']??'super'),$tid,'BACKUP',
   $scope.' - '.$b['meta']['tables'].' tables, '.$b['meta']['rows'].' rows');
 while(ob_get_level())ob_end_clean();
 header('Content-Type: application/json');
 header('Content-Disposition: attachment; filename="'.$file.'"');
 header('Content-Length: '.strlen($b['json']));
 echo $b['json'];exit;

case 'sa-backup-list':needSuper();
 $tid=(string)($_GET['tenant_id']??'');
 $q=DB::pdo()->prepare("SELECT scope,file_name,tables_count,rows_count,size_bytes,created_by,created_at
                          FROM admin_backups WHERE tenant_id=? ORDER BY created_at DESC LIMIT 20");
 $q->execute([$tid]);ok(['backups'=>$q->fetchAll()]);

case 'sa-factory-reset':needSuper();$d=body();
 $tid=(string)($d['tenant_id']??'');if($tid==='')fail('tenant_id required');
 $mode=strtoupper((string)($d['mode']??'TXN'));if(!in_array($mode,['TXN','FULL'],true))$mode='TXN';
 $p=DB::pdo();$q=$p->prepare("SELECT name,slug FROM tenants WHERE id=?");$q->execute([$tid]);$t=$q->fetch();
 if(!$t)fail('Business not found',404);
 /* Hifazat 1: business ka naam type karna zaroori */
 if(trim((string)($d['confirm_name']??''))!==trim((string)$t['name']))
   fail('Type the exact business name to confirm: '.$t['name'],422);
 /* Hifazat 2: pichle 1 ghante mein backup liya gaya ho */
 $bq=$p->prepare("SELECT COUNT(*) FROM admin_backups WHERE tenant_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");
 $bq->execute([$tid]);
 if(!(int)$bq->fetchColumn())fail('Download a backup first - reset is only allowed within 1 hour of a backup.',422);
 $res=\Aio\Services\AdminData::factoryReset($tid,$mode);
 \Aio\Services\AdminData::audit((string)(Platform::superUser()['email']??'super'),$tid,'FACTORY_RESET',
   $mode.' - '.$res['total'].' rows deleted from '.count($res['deleted']).' tables');
 ok(['mode'=>$mode,'deleted'=>$res['deleted'],'total'=>$res['total'],
     'tables'=>count($res['deleted']),'kept_admin'=>$res['kept_admin']]);

case 'sa-console':needSuper();$d=body();
 $cmd=(string)($d['cmd']??'');
 /* Console ka jawab HAMESHA JSON hona chahiye. Pehle koi PHP fatal ya
    timeout aata to browser ko HTML/khali response milta aur wo sirf
    "Request failed" dikhata — asli wajah kahin nazar hi nahi aati thi. */
 @set_time_limit(180);
 register_shutdown_function(function(){
   $e=error_get_last();
   if($e&&in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)){
     if(!headers_sent())header('Content-Type: application/json');
     echo json_encode(['ok'=>true,'lines'=>[
       ['t'=>'e','v'=>'Server error: '.substr((string)$e['message'],0,300)],
       ['t'=>'d','v'=>basename((string)$e['file']).':'.$e['line']],
     ]]);
   }
 });
 try{
   ok(\Aio\Services\AdminConsole::run($cmd,(string)(Platform::superUser()['email']??'super')));
 }catch(Throwable $e){
   ok(['lines'=>[
     ['t'=>'e','v'=>'Error: '.substr($e->getMessage(),0,300)],
     ['t'=>'d','v'=>basename($e->getFile()).':'.$e->getLine()],
   ]]);
 }

case 'sa-business-delete':needSuper();$d=body();
 $tid=(string)($d['tenant_id']??'');if($tid==='')fail('tenant_id required');
 $p=DB::pdo();$q=$p->prepare("SELECT name,slug FROM tenants WHERE id=?");$q->execute([$tid]);$t=$q->fetch();
 if(!$t)fail('Business not found',404);
 if(trim((string)($d['confirm_name']??''))!==trim((string)$t['name']))
   fail('Type the exact business name to confirm: '.$t['name'],422);
 $r=\Aio\Services\AdminData::deleteBusiness($tid);
 \Aio\Services\AdminData::audit((string)(Platform::superUser()['email']??'super'),null,'DELETE_BUSINESS',
   $t['name'].' ('.$t['slug'].') - '.$r['total'].' rows from '.count($r['deleted']).' tables');
 ok(['deleted'=>$r['deleted'],'total'=>$r['total'],'tables'=>count($r['deleted'])]);

case 'sa-import-inspect':needSuper();
 $raw=file_get_contents('php://input');
 $d=json_decode($raw,true);
 $file=is_array($d)?(string)($d['file']??''):'';
 if($file==='')fail('No file content received');
 ok(\Aio\Services\AdminData::inspect($file));

case 'sa-import-run':needSuper();
 $raw=file_get_contents('php://input');$d=json_decode($raw,true);
 if(!is_array($d))fail('Bad request');
 $tid=(string)($d['tenant_id']??'');if($tid==='')fail('tenant_id required');
 $file=(string)($d['file']??'');if($file==='')fail('No file content received');
 $mode=strtoupper((string)($d['mode']??'SKIP'));if(!in_array($mode,['SKIP','UPDATE'],true))$mode='SKIP';
 $only=is_array($d['only']??null)?array_map('strval',$d['only']):[];
 $p=DB::pdo();$sq=$p->prepare("SELECT id FROM sites WHERE tenant_id=? ORDER BY created_at LIMIT 1");
 $sq->execute([$tid]);$sid=$sq->fetchColumn()?:null;
 $r=\Aio\Services\AdminData::importBackup($file,$tid,$sid?:null,$mode,$only);
 if(empty($r['ok']))fail((string)($r['message']??'Import failed'));
 $p->prepare("INSERT INTO admin_imports(id,tenant_id,site_id,source,file_name,tables_json,rows_inserted,rows_updated,rows_skipped,status,error_text,created_by,created_at)
              VALUES(?,?,?,'BACKUP',?,?,?,?,?,?,?,?,NOW(6))")
   ->execute([uuid(),$tid,$sid?:null,(string)($d['file_name']??'backup.json'),
              json_encode($r['per_table']),$r['inserted'],$r['updated'],$r['skipped'],
              $r['errors']?'PARTIAL':'OK',
              $r['errors']?substr(implode(' | ',$r['errors']),0,480):null,
              (string)(Platform::superUser()['email']??'super')]);
 \Aio\Services\AdminData::audit((string)(Platform::superUser()['email']??'super'),$tid,'IMPORT',
   'inserted '.$r['inserted'].', updated '.$r['updated'].', skipped '.$r['skipped']);
 ok($r);

case 'sa-import-list':needSuper();
 $tid=(string)($_GET['tenant_id']??'');
 $q=DB::pdo()->prepare("SELECT source,file_name,rows_inserted,rows_updated,rows_skipped,status,error_text,created_by,created_at
                          FROM admin_imports WHERE tenant_id=? ORDER BY created_at DESC LIMIT 20");
 $q->execute([$tid]);ok(['imports'=>$q->fetchAll()]);

case 'sa-audit':needSuper();
 $tid=(string)($_GET['tenant_id']??'');
 $p=DB::pdo();
 if($tid!==''){$q=$p->prepare("SELECT actor,action,detail,ip,created_at FROM admin_audit WHERE tenant_id=? ORDER BY created_at DESC LIMIT 50");$q->execute([$tid]);}
 else{$q=$p->prepare("SELECT a.actor,a.action,a.detail,a.ip,a.created_at,t.name business
                        FROM admin_audit a LEFT JOIN tenants t ON t.id=a.tenant_id
                       ORDER BY a.created_at DESC LIMIT 50");$q->execute();}
 ok(['audit'=>$q->fetchAll()]);

case 'sa-sync-monitor':needSuper();
 $p=DB::pdo();
 $n=$p->query("SELECT n.node_code,n.machine_fingerprint ip,n.app_version,n.last_seen_at,n.status,t.name business
                 FROM sync_nodes n LEFT JOIN tenants t ON t.id=n.tenant_id
                ORDER BY n.last_seen_at DESC LIMIT 30")->fetchAll();
 $a=$p->query("SELECT COUNT(*) transfers,COALESCE(SUM(rows_count),0) rows_total
                 FROM sync_activity WHERE created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)")->fetch();
 $f=$p->query("SELECT t.name business,s.table_name,s.rows_count,s.note,s.created_at
                 FROM sync_activity s LEFT JOIN tenants t ON t.id=s.tenant_id
                WHERE s.status IN ('FAILED','REJECTED') ORDER BY s.created_at DESC LIMIT 20")->fetchAll();
 ok(['nodes'=>$n,'today'=>$a,'failures'=>$f]);

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
