<?php
declare(strict_types=1);

$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';
$name=ltrim($path,'/');
if($name===''||$name==='index.php')$name='index.html';

$static=__DIR__.'/'.$name;
// PHP endpoints, CSS, JS, images: serve directly; don't bootstrap twice.
if(is_file($static)&&!str_ends_with(strtolower($name),'.html'))return false;

require_once dirname(__DIR__).'/src/bootstrap.php';
if(($name==='login.html') && isset($_GET['b']) && (($GLOBALS['config']['app']['role']??'')==='cloud')){ $slug=preg_replace('/[^a-z0-9-]/','',strtolower((string)$_GET['b'])); $_SESSION['login_tenant_slug']=$slug; $tid=\Aio\Services\Platform::tenantIdBySlug($slug); if($tid){$_SESSION['login_tenant_id']=$tid;} }

use Aio\Auth;
use Aio\Csrf;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$publicPages=['login.html','signup.html','signup_pending.html','setup.html','super_admin.html'];
$fileModule=['index.html'=>'dashboard','dashboard.html'=>'dashboard','shift_management.html'=>'shift','restaurant_pos.html'=>'pos','restaurant_order_taker_tablet.html'=>'tablet','kds.html'=>'kds','tables_floors.html'=>'tables','orders_management.html'=>'orders','online_orders.html'=>'online','inventory_creation.html'=>'inventory','purchasing.html'=>'purchasing','recipe_making.html'=>'recipe','menu_management.html'=>'menu','wastage_adjustment.html'=>'wastage','stock_transfer.html'=>'transfer','stock_count.html'=>'count','suppliers.html'=>'suppliers','customers.html'=>'customers','customer_mobile_app.html'=>'customer_app','customer_web_qr.html'=>'customer_web','delivery.html'=>'delivery','rider_management.html'=>'riders','reservations.html'=>'reservations','loyalty.html'=>'loyalty','whatsapp_notifications.html'=>'whatsapp','expenses.html'=>'expenses','accounting.html'=>'accounting','discounts_promotions.html'=>'promotions','staff_roles.html'=>'staff','void_refund.html'=>'void','reports.html'=>'reports','fbr.html'=>'fbr','printer_devices.html'=>'printers','multi_branch.html'=>'branches','offline_sync.html'=>'offline','users_access.html'=>'users','settings.html'=>'settings'];

if(!in_array($name,$publicPages,true)){
  if(!Auth::user()){header('Location: /login.html?build=v14');exit;}
  $key=$fileModule[$name]??null;
  if($key&&$key!=='dashboard'&&!Auth::canModule($key)){http_response_code(403);exit('Access denied.');}
}

$file=dirname(__DIR__).'/approved_ui/'.$name;
if(!is_file($file)){http_response_code(404);exit('Page not found');}

$html=file_get_contents($file);

// Only path/cache-bust rewriting; approved HTML structure is not replaced.
$html=str_replace(
 ['href="shared.css"','src="shared_store.js"','src="live_store.js"','src="access_store.js"'],
 ['href="/shared.css?b=v14"','src="/shared_store.js?b=v14"','src="/live_store.js?b=v14"','src="/access_store.js?b=v14"'],
 $html
);
$html=preg_replace('/(["\'])assets\//','$1/assets/',$html);

$head='<script src="/ui_state_reset.js?b=v14"></script>'
     .'<script>window.APP_CSRF='.json_encode(Csrf::token()).';</script>'
     .'<script src="/db_api.js?b=v14"></script>';
// DB-first hydration only on authenticated app pages (source of truth = DB).
if(!in_array($name,$publicPages,true) && Auth::user()){
  $head.='<script src="/db_boot.js?b=v15"></script>';
}
$html=str_replace('</head>',$head.'</head>',$html);

$tail='';
if($name==='login.html')$tail.='<script src="/login_form_bridge.js?b=v14"></script>';

if(!in_array($name,$publicPages,true)){
  $tail.='<script src="/approved_auth_exact.js?b=v14"></script>';
  $tail.='<script src="/db_mirror_bridge.js?b=v15"></script>';
  $tail.='<script src="/ui_action_modal.js?b=v14"></script>';
}

if($name==='restaurant_pos.html')$tail.='<script src="/pos_db_mirror.js?b=v16"></script>';
if($name==='restaurant_order_taker_tablet.html')$tail.='<script src="/order_taker_db.js?b=v16"></script>';

$html=str_replace('</body>',$tail.'</body>',$html);
echo$html;

// build: V17.1 build 2026-08-25
