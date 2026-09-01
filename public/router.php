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

$publicPages=['login.html','signup.html','signup_pending.html','setup.html','super_admin.html','qr.html','pair.html',
  /* V86 — customer khud yahan se register karta hai; login ka sawaal hi nahi. */
  'register.html'];
$fileModule=['index.html'=>'dashboard','dashboard.html'=>'dashboard','shift_management.html'=>'shift','restaurant_pos.html'=>'pos','restaurant_order_taker_tablet.html'=>'tablet','kds.html'=>'kds','closing_history.html'=>'closing','activate.html'=>'settings','purchase_orders.html'=>'purchasing','activity_log.html'=>'activity','tables_floors.html'=>'tables','orders_management.html'=>'orders','online_orders.html'=>'online','inventory_creation.html'=>'inventory','purchasing.html'=>'purchasing','recipe_making.html'=>'recipe','menu_management.html'=>'menu','wastage_adjustment.html'=>'wastage','stock_transfer.html'=>'transfer','stock_count.html'=>'count','suppliers.html'=>'suppliers','customers.html'=>'customers','customer_mobile_app.html'=>'customer_app','customer_web_qr.html'=>'customer_web','delivery.html'=>'delivery','rider_management.html'=>'riders','reservations.html'=>'reservations','loyalty.html'=>'loyalty','whatsapp_notifications.html'=>'whatsapp','expenses.html'=>'expenses','accounting.html'=>'accounting','discounts_promotions.html'=>'promotions','staff_roles.html'=>'staff','void_refund.html'=>'void','reports.html'=>'reports','fbr.html'=>'fbr','printer_devices.html'=>'printers','multi_branch.html'=>'branches','offline_sync.html'=>'offline','users_access.html'=>'users','settings.html'=>'settings'];

if(!in_array($name,$publicPages,true)){
  if(!Auth::user()){header('Location: /login.html');exit;   /* V65: '?build=v14' address bar mein nazar aata tha */}
  $key=$fileModule[$name]??null;
  /* V78 — DASHBOARD par bhi ijazat check hoti hai.
     Pehle yahan `$key!=='dashboard'` tha, yani dashboard HAMESHA khula
     rehta tha. Cashier wahan se poore branch ki sale dekh sakta tha —
     halanke usay sirf apna kaam dikhna chahiye. Ab har page ki apni
     ijazat, aur ijazat na ho to user ko us ke apne pehle page par
     bhej diya jata hai (khali 403 se behtar). */
  if($key&&!Auth::canModule($key)){
    $home=null;
    foreach(['pos'=>'/restaurant_pos.html','shift'=>'/shift_management.html',
             'closing'=>'/closing_history.html','tablet'=>'/restaurant_order_taker_tablet.html',
             'kds'=>'/kds.html'] as $mk=>$path){
      if(Auth::canModule($mk)){$home=$path;break;}
    }
    if($home&&$home!=='/'.$name){header('Location: '.$home);exit;}
    http_response_code(403);
    exit('You do not have permission to open this screen.');
  }
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

// ---- Tenant branding (naam / logo / colors) ----
$brand=['name'=>'','logo'=>'','color'=>'','accent'=>''];
try{
  $btid=null;
  if(Auth::user())$btid=$_SESSION['user']['tenant_id']??null;
  elseif(!empty($_SESSION['login_tenant_id']))$btid=$_SESSION['login_tenant_id'];
  if(!$btid && cfg('app.role')!=='cloud')$btid=tenant_id();
  if($btid){
    $bq=\Aio\DB::pdo()->prepare("SELECT name,display_name,logo_url,brand_color,brand_accent FROM tenants WHERE id=? LIMIT 1");
    $bq->execute([$btid]);
    if($br=$bq->fetch()){
      $brand=['name'=>(string)($br['display_name']?:$br['name']),'logo'=>(string)($br['logo_url']??''),
              'color'=>(string)($br['brand_color']??''),'accent'=>(string)($br['brand_accent']??'')];
    }
  }
}catch(\Throwable $e){}

$head='<script src="/ui_state_reset.js?b=v14"></script>'
     .'<script>window.APP_CSRF='.json_encode(Csrf::token()).';window.APP_BRAND='.json_encode($brand).';window.APP_ROLE='.json_encode((string)($GLOBALS['config']['app']['role']??'local')).';</script>'
     .'<script src="/db_api.js?b=v14"></script>'
     /* V62 — delete confirm har page par available hona is required (POS samet),
        warna har screen apna alag adhoora delete likhti hai. */
     .'<script src="/delete_kit.js?b=v62"></script>'
     .'<script src="/brand.js?b=v26"></script>';
// DB-first hydration only on authenticated app pages (source of truth = DB).
// db_boot POS par NahI is required: POS khud pos-boot se hydrate hota hai, aur
// db_boot ki 2 synchronous XHRs page load ko slow karti thin.
if(!in_array($name,$publicPages,true) && Auth::user() && $name!=='restaurant_pos.html'){
  $head.='<script src="/db_boot.js?b=v16"></script>';
}
// SIRF pehla </head> replace hota hai. str_replace HAR occurrence badal deta
// tha — agar kisi page ki JS string mein wahi closing tag likha ho (print window,
// email template) to script tags us string ke andar inject ho kar page ki
// poori JS ko syntax-error se maar dete the.
$pos=stripos($html,'</head>');
if($pos!==false)$html=substr($html,0,$pos).$head.substr($html,$pos);

$tail='';
if($name==='login.html')$tail.='<script src="/login_form_bridge.js?b=v14"></script>';

if(!in_array($name,$publicPages,true)){
  $tail.='<script src="/approved_auth_exact.js?b=v14"></script>';
  $tail.='<script src="/db_mirror_bridge.js?b=v15"></script>';
  $tail.='<script src="/ui_action_modal.js?b=v14"></script>';
}

// restaurant_pos.html ab khud poori tarah DB-driven hai (POS v20) — mirror ki zaroorat nahi.
/* V72 — tablet page ab khud DB par hai; `order_taker_db.js` us purani
   screen ke liye tha jo hardcoded PRODUCTS use karti thi. Ab zaroorat
   nahi — do jagah se data bharne se dono ek doosre par likhte the. */
// V70 — KDS ab asli kitchen_tickets par (pehle hardcoded demo orders the).
if($name==='kds.html')$tail.='<script src="/kds_db.js?b=v70"></script>';
// V70 — Shift / Running Orders / Void ab asli tables par (pehle ui_records).
if(in_array($name,['shift_management.html','orders_management.html','void_refund.html',
                   'stock_transfer.html','stock_count.html','accounting.html',
                   'closing_history.html','activity_log.html','purchase_orders.html',
                   'online_orders.html','whatsapp_notifications.html'],true))
  $tail.='<script src="/ops_db.js?b=v70"></script>';

// AAKHRI closing body tag par inject karo (pehla nahi) — wahi asli document end hai.
$posB=strripos($html,'</body>');
if($posB!==false)$html=substr($html,0,$posB).$tail.substr($html,$posB);
else $html.=$tail;
header('Cache-Control: no-store, must-revalidate'); // HTML kabhi cache na ho; JS/CSS ?b= se bust hote hain
echo$html;

// build: V62 build 2026-08-26
