<?php
declare(strict_types=1);

$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';
$name=ltrim($path,'/');
if($name===''||$name==='index.php')$name='index.html';

$static=__DIR__.'/'.$name;
// PHP endpoints, CSS, JS, images: serve directly; don't bootstrap twice.
if(is_file($static)&&!str_ends_with(strtolower($name),'.html'))return false;

/* RETAIL ke assets: /retail/shared.css, /retail/store.js waghera.
   Yeh `public/` mein nahi, `approved_ui/retail/` mein rehte hain — wahi
   ek jagah jahan retail UI hai, do copies rakhne se drift hoti hai.
   Sirf known extensions, aur path traversal ka koi mauqa nahi. */
if(str_starts_with($name,'retail/')){
  $asset=basename(substr($name,7));
  $ext=strtolower(pathinfo($asset,PATHINFO_EXTENSION));
  $types=['css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml',
          'png'=>'image/png','jpg'=>'image/jpeg','ico'=>'image/x-icon','woff2'=>'font/woff2'];
  $f=dirname(__DIR__).'/approved_ui/retail/'.$asset;
  if(isset($types[$ext]) && is_file($f)){
    header('Content-Type: '.$types[$ext]);
    header('Cache-Control: public, max-age=3600');
    readfile($f); exit;
  }
  http_response_code(404); exit('Not found');
}

require_once dirname(__DIR__).'/src/bootstrap.php';
if(($name==='login.html') && isset($_GET['b']) && (($GLOBALS['config']['app']['role']??'')==='cloud')){ $slug=preg_replace('/[^a-z0-9-]/','',strtolower((string)$_GET['b'])); $_SESSION['login_tenant_slug']=$slug; $tid=\Aio\Services\Platform::tenantIdBySlug($slug); if($tid){$_SESSION['login_tenant_id']=$tid;} }

/* ============================================================
   V160 — OWNER KE MOBILE PORTAL KA CHHOTA PATA:  /m

   Malik ko bhejne ke liye `https://…/owner_mobile.html?b=slug` jaisa
   lamba pata theek nahi: WhatsApp par toot jata hai, aur phone par
   haath se likhna mushkil. `/m?b=slug` chhota hai aur yaad bhi reh
   jata hai.

   Yeh sirf darwaza hai, chaabi nahi: koi token nahi, koi khufiya
   raasta nahi. Link kholte hi login maanga jata hai — jaise baqi
   poore software mein. Link kisi aur ke haath lag bhi jaye to us ke
   paas user aur password nahi hoga.

   `?b=` sirf yeh batata hai ke KIS business ka login kholna hai (wohi
   kaam jo `login.html?b=` karta hai), aur login ke baad kahan jana
   hai yeh session mein likh dete hain.
   ============================================================ */
if($name==='m' || $name==='m/' || $name==='m.html'){
  if(isset($_GET['b']) && (($GLOBALS['config']['app']['role']??'')==='cloud')){
    $slug=preg_replace('/[^a-z0-9-]/','',strtolower((string)$_GET['b']));
    if($slug!==''){
      $_SESSION['login_tenant_slug']=$slug;
      $tid=\Aio\Services\Platform::tenantIdBySlug($slug);
      if($tid)$_SESSION['login_tenant_id']=$tid;
    }
  }
  $_SESSION['after_login']='/owner_mobile.html';
  header('Location: /owner_mobile.html');
  exit;
}

use Aio\Auth;
use Aio\Csrf;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* ============================================================
   RETAIL vertical.
   Supermarket ki screens `approved_ui/retail/` mein hain. Restaurant ki
   files ko haath nahi lagaya gaya — dono verticals apni apni directory
   mein rehte hain aur tenant ka industry_code tay karta hai kaunsi
   directory se page uthega.
   ============================================================ */
$retailModule=[
  'index.html'=>'dashboard','pos.html'=>'rpos','counters.html'=>'counters',
  'sales.html'=>'sales','khata.html'=>'khata','products.html'=>'products',
  'departments.html'=>'departments','categories.html'=>'departments','brands.html'=>'brands',
  'uom.html'=>'uom','pricing.html'=>'pricing','scale.html'=>'scale','labels.html'=>'labels',
  'batches.html'=>'batches','grn.html'=>'grn','purchase_return.html'=>'preturn',
  'stock.html'=>'inventory','purchasing.html'=>'purchasing','purchase_orders.html'=>'po',
  'suppliers.html'=>'suppliers','customers.html'=>'customers','loyalty.html'=>'loyalty',
  'promotions.html'=>'promotions','returns.html'=>'void','shift.html'=>'shift',
  'closing.html'=>'closing','expenses.html'=>'expenses','accounting.html'=>'accounting',
  'reports.html'=>'reports','tax.html'=>'fbr','whatsapp.html'=>'whatsapp',
  'stock_count.html'=>'count','stock_day_close.html'=>'dayclose','wastage.html'=>'wastage',
  'staff.html'=>'staff','users.html'=>'users','printers.html'=>'printers',
  'branches.html'=>'branches','offline_sync.html'=>'offline','activity.html'=>'activity',
  'settings.html'=>'settings',
];

$publicPages=['login.html','signup.html','signup_pending.html','setup.html','super_admin.html','qr.html','pair.html',
  /* V86 — customer khud yahan se register karta hai; login ka sawaal hi nahi. */
  'register.html',
  /* Customer ka ordering app aur uska share page — yeh dukan ke GAHAK ke
     liye hain, staff ke liye nahi. Login maangna yahan poore maqsad ko
     khatam kar deta: customer ke paas account hai hi nahi. */
  'app.html','app-download.html',
  /* V151 — invoice ka safha. Token wale link se khulta hai aur login
     nahi maangta: parcha WhatsApp par jata hai, aur aksar us waqt jab
     customer ka software expire ho chuka ho.

     V153 — chunke yeh PUBLIC page hai, yeh HAMESHA `approved_ui/` se
     uthta hai, chahe tenant retail ho. `approved_ui/retail/invoice.html`
     ki copy kabhi serve hoti hi nahi thi — do copies sirf drift paida
     karti hain, is liye wo file hata di gayi. */
  'invoice.html'];
/* Kisi bhi vertical ke nahi — platform ke utility pages. Yeh hamesha
   `approved_ui/` se aate hain, chahe tenant retail ho. */
/* `owner_mobile.html` yahan hai kyunke yeh kisi ek vertical ki screen
   nahi — restaurant ho ya store, malik ko wohi cheezein dekhni hoti
   hain. Warna retail tenant par yeh `approved_ui/retail/` mein dhoondi
   jati aur 404 ho jati. */
$sharedPages=['activate.html','backup_restore.html','owner_mobile.html'];

/* ============================================================
   LICENCE GATE — offline node.

   Pehle expiry par sirf login reject hota tha ("Subscription expired…")
   aur user login page par hi khara rehta tha. Uske paas key bhi ho to
   usay daalne ki koi jagah nahi thi.

   Ab expiry par har page seedha `activate.html` par le jata hai —
   wahi screen jahan key daal kar software chalu hota hai. Key lagte hi
   normal kaam wahin se chalu ho jata hai.

   V149 — AB CLOUD PAR BHI.
   Pehle yeh gate sirf offline node par tha, aur cloud par expiry SIRF
   LOGIN ke waqt dekhi jati thi. Session 12 ghante ka hai (ek poori
   shift), is liye jo cashier pehle se andar tha wo expiry ke baad bhi
   poora din bills kaat sakta tha — aur maalik ko kuch pata na chalta.
   Ab dono taraf har request par.

   Cloud par renewal Super Admin karta hai, is liye wahan `activate.html`
   par key wala khana chhup jata hai (dekho APP_ROLE) aur sirf payment
   aur "humein bata dein" wala raasta rehta hai.
   ============================================================ */
$licenceBlocked=false;
try{
  $lic=\Aio\Services\Licence::current();
  $licenceBlocked=!empty($lic['expired']);
}catch(\Throwable $e){ $licenceBlocked=false; }   /* shak ho to rasta band mat karo */
/* activate.html khud, aur uske assets, hamesha khulne chahiye. */
$licenceAllow=['activate.html','login.html','logout.html'];

/* Aur ahem: licence block ki soorat mein `activate.html` ko LOGIN ki
   shart se bhi azad karna parta hai. Warna wo bhi login par redirect
   hoti hai, login expiry ki wajah se mumkin nahi, aur customer key
   haath mein liye ek band darwaze ke saamne khara reh jata hai. */
if($licenceBlocked && $name==='activate.html'){
  $publicPages[]='activate.html';
}
if($licenceBlocked && !in_array($name,$licenceAllow,true)
   && substr($name,-5)==='.html'){
  header('Location: /activate.html?expired=1');
  exit;
}
$fileModule=['index.html'=>'dashboard','dashboard.html'=>'dashboard','shift_management.html'=>'shift','restaurant_pos.html'=>'pos','restaurant_order_taker_tablet.html'=>'tablet','kds.html'=>'kds','closing_history.html'=>'closing','activate.html'=>'activate','backup_restore.html'=>'backup','purchase_orders.html'=>'purchasing','activity_log.html'=>'activity','tables_floors.html'=>'tables','orders_management.html'=>'orders','online_orders.html'=>'online','inventory_creation.html'=>'inventory','purchasing.html'=>'purchasing','recipe_making.html'=>'recipe','menu_management.html'=>'menu','wastage_adjustment.html'=>'wastage','stock_transfer.html'=>'transfer','stock_count.html'=>'count','stock_day_close.html'=>'dayclose','suppliers.html'=>'suppliers','customers.html'=>'customers','customer_mobile_app.html'=>'customer_app','customer_web_qr.html'=>'customer_web','delivery.html'=>'delivery','rider_management.html'=>'riders','reservations.html'=>'reservations','loyalty.html'=>'loyalty','whatsapp_notifications.html'=>'whatsapp','expenses.html'=>'expenses','accounting.html'=>'accounting','discounts_promotions.html'=>'promotions','staff_roles.html'=>'staff','void_refund.html'=>'void','reports.html'=>'reports','fbr.html'=>'fbr','printer_devices.html'=>'printers','multi_branch.html'=>'branches','offline_sync.html'=>'offline','users_access.html'=>'users','settings.html'=>'settings'];

/* ============================================================
   V160 — OWNER PORTAL KA APNA PEHRA.

   Yeh `$fileModule` ki fehrist mein JAAN BOOJH KAR nahi hai. Us raste
   par `canModule()` chalta hai, jis ka qanoon yeh hai ke "features
   khali ho to sab allowed" — aur zyadatar businesses par wo khali hi
   hota hai. Nateeja yeh nikalta ke portal har jagah khud khul jata,
   jabke yeh wo cheez hai jo super admin ek ek property ko deta hai.

   Teen sawal, isi tarteeb se:
     1. Login hai? Nahi to login par, aur usi business ka (`?b=`).
     2. Super admin ne is property ko diya hai? (Auth::ownerMobile)
     3. Yeh malik/manager hai? Cashier ke liye yeh screen nahi —
        us par poore business ka munafa aur sab ka hisaab hai.
   ============================================================ */
if($name==='owner_mobile.html'){
  if(!Auth::user()){
    $slug=(string)($_SESSION['login_tenant_slug']??'');
    $_SESSION['after_login']='/owner_mobile.html';
    header('Location: /login.html'.($slug!==''?('?b='.rawurlencode($slug)):''));
    exit;
  }
  if(!Auth::ownerMobile()){
    http_response_code(403);
    exit('Owner app is not switched on for this business. Ask Wabwar Software House to enable it.');
  }
  if(!Auth::isManager()){
    http_response_code(403);
    exit('Only the owner or a manager can open this screen.');
  }
}

if(!in_array($name,$publicPages,true)){
  if(!Auth::user()){header('Location: /login.html');exit;   /* V65: '?build=v14' address bar mein nazar aata tha */}
  /* Retail tenant ki apni module map. Warna supermarket ka
     `products.html` restaurant ke map mein dhoonda jata aur permission
     check khamoshi se skip ho jata. */
  $isRetail=false;
  try{ $isRetail=(Auth::tenantIndustry()==='RETAIL'); }catch(\Throwable $e){}
  /* Shared pages ka module key restaurant wale map mein hai — retail map
     mein dhoondne se null milta aur permission check chup-chaap skip ho
     jata. */
  $key=($isRetail && !in_array($name,$sharedPages,true))
       ? ($retailModule[$name]??null)
       : ($fileModule[$name]??null);
  /* V78 — DASHBOARD par bhi ijazat check hoti hai.
     Pehle yahan `$key!=='dashboard'` tha, yani dashboard HAMESHA khula
     rehta tha. Cashier wahan se poore branch ki sale dekh sakta tha —
     halanke usay sirf apna kaam dikhna chahiye. Ab har page ki apni
     ijazat, aur ijazat na ho to user ko us ke apne pehle page par
     bhej diya jata hai (khali 403 se behtar). */
  if($key&&!Auth::canModule($key)){
    $home=null;
    $fallbacks = $isRetail
      ? ['rpos'=>'/pos.html','shift'=>'/shift.html','sales'=>'/sales.html','products'=>'/products.html']
      : ['pos'=>'/restaurant_pos.html','shift'=>'/shift_management.html',
         'closing'=>'/closing_history.html','tablet'=>'/restaurant_order_taker_tablet.html',
         'kds'=>'/kds.html'];
    foreach($fallbacks as $mk=>$path){
      if(Auth::canModule($mk)){$home=$path;break;}
    }
    if($home&&$home!=='/'.$name){header('Location: '.$home);exit;}
    http_response_code(403);
    exit('You do not have permission to open this screen.');
  }
}

/* ============================================================
   Kaunsi directory se page uthega.

   BUG JO PRODUCTION PAR AAYA:
   pehle sirf itna dekha jata tha ke tenant RETAIL hai ya nahi. Nateeja:
   jis browser mein ek dafa supermarket ka user login kar leta, us mein
   `super_admin.html`, `login.html`, `setup.html`, `qr.html`, `pair.html`
   — SAB 404 ho jate the, kyunke woh `approved_ui/retail/` mein maujood
   hi nahi. Super Admin console bhi isi wajah se "Page not found" de raha
   tha, halanke woh kisi vertical ka page hai hi nahi.

   Ab teen darje hain:
     1. PUBLIC pages (login/signup/setup/super admin/qr/pair) — hamesha
        `approved_ui/` se. Yeh kisi business ke nahi, platform ke hain.
     2. SHARED utility pages (activate, backup) — inka bhi koi vertical
        nahi; hamesha `approved_ui/` se.
     3. Baaki sab — tenant ke industry ke hisaab se.
   ============================================================ */
$uiDir = 'approved_ui';
if(!in_array($name,$publicPages,true) && !in_array($name,$sharedPages,true)){
  try{ if(Auth::user() && Auth::tenantIndustry()==='RETAIL') $uiDir='approved_ui/retail'; }catch(\Throwable $e){}
}
/* AHEM: yahan path ko variable se mat joro.
   Offline package `build_offline_bundle.php` se banta hai jo LITERAL
   string `dirname(__DIR__).'/approved_ui/` ko `'sealed://approved_ui/`
   se badalta hai. Variable concat us pattern se match nahi karta, is
   liye offline node par har page "Page not found" ho jata tha —
   login.html samet. Dono raaste alag alag literal likhe hain taake
   rewrite dono par lage. */
$file = ($uiDir === 'approved_ui/retail')
      ? dirname(__DIR__).'/approved_ui/retail/'.$name
      : dirname(__DIR__).'/approved_ui/'.$name;
/* Retail mein woh page na ho to restaurant wali common screen par mat
   giro — 404 behtar hai. Warna supermarket ka user achanak KDS dekh leta. */
if(!is_file($file)){http_response_code(404);exit('Page not found');}

$html=file_get_contents($file);

// Only path/cache-bust rewriting; approved HTML structure is not replaced.
if($uiDir==='approved_ui/retail'){
  /* Retail ke apne asset files hain (alag brand, alag engine). Inhein
     /retail/... se serve karte hain taake restaurant ki files se na takrayen. */
  /* V154 — `doc.js` aur `doc_config.js` bhi yahan. Yeh fehrist se
     chhoot jana purani bemari hai: V151 tak `module.js` aur
     `module_config.js` isi tarah chhoote hue the, aur un mein kiya hua
     har change deploy ke baad bhi purana hi chalta rehta tha (max-age
     3600). Nayi file banate waqt YAHAN likhna lazmi hai. */
  $html=preg_replace('/(src|href)="(shared\.css|region\.js|store\.js|retail_api\.js|barcode\.js|module\.js|module_config\.js|doc\.js|doc_config\.js|shell\.js)"/',
                     '$1="/retail/$2?b=r6"', $html);
}else{
/* ============================================================
   JS/CSS par cache-bust — poori list.

   Yahan sirf chaar files ka naam tha. `module.js`, `module_config.js`
   aur `shell.js` chhoot gayi thin, aur wo `max-age=3600` ke sath jati
   hain. Nateeja: in files mein kiya gaya koi bhi change deploy ke baad
   bhi purana hi chalta rehta tha — misaal ke tor par Menu page ka
   Category dropdown, jo nayi list aane ke bawajood purani hardcoded
   fehrist ('Pakistani, Pizza, BBQ...') dikhata raha.

   `?b=` ka number badalna hi in files ko taza karwane ka tareeqa hai.
   ============================================================ */
$html=str_replace(
 ['href="shared.css"','src="shared_store.js"','src="live_store.js"','src="access_store.js"',
  'src="shell.js"','src="module.js"','src="module_config.js"','src="item_dialog.js"','src="stock_in.js"'],
 ['href="/shared.css?b=v26"','src="/shared_store.js?b=v26"','src="/live_store.js?b=v26"','src="/access_store.js?b=v26"',
  'src="/shell.js?b=v26"','src="/module.js?b=v26"','src="/module_config.js?b=v26"','src="/item_dialog.js?b=v26"','src="/stock_in.js?b=v26"'],
 $html
);
}
$html=preg_replace('/(["\'])assets\//','$1/assets/',$html);

/* Logged-in business ka slug — customer app ke link ke liye. */
$tenantSlug='';
try{
  $tsid=Auth::user()?($_SESSION['user']['tenant_id']??null):null;
  if($tsid){
    $sq=\Aio\DB::pdo()->prepare("SELECT slug FROM tenants WHERE id=? LIMIT 1");
    $sq->execute([$tsid]); $tenantSlug=(string)($sq->fetchColumn()?:'');
  }
}catch(\Throwable $e){}

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
     .'<script>window.APP_CSRF='.json_encode(Csrf::token()).';window.APP_BRAND='.json_encode($brand).';window.APP_ROLE='.json_encode((string)($GLOBALS['config']['app']['role']??'local'))
     /* Business ka slug — customer app ka link isi se banta hai, taake
        har dukan ka apna QR aur apna raasta ho. */
     .';window.APP_TENANT_SLUG='.json_encode((string)($tenantSlug ?? ''))
     /* ============================================================
        V141 — LEFT MENU KA SACH SERVER SE.

        Sidebar (`shell.js`) pehle `current-user` ki `modules` par chalta
        tha, aur retail ka sidebar to kisi par bhi nahi — us mein ek
        hard-coded demo user tha, is liye wahan koi pehra hi na tha.
        Nateeja: Super Admin se hide kiye gaye modules phir bhi nazar
        aate the.

        Ab wohi list jo `Auth::canModule()` maanta hai, yahin page ke
        saath chali jati hai. Ek source, dono verticals, aur ek extra
        round trip bhi nahi. `null` ka matlab "koi pehra nahi" (login se
        pehle ke safhe). */
     .';window.APP_MODULES='.json_encode(Auth::user() ? Auth::effectiveModules() : null)
     .';window.APP_INDUSTRY='.json_encode(Auth::user() ? Auth::tenantIndustry() : '')
     /* Sidebar ke neeche asli naam — retail ki screens par wahan ab tak
        "Ali Raza / Store Manager" (demo) likha aata tha. */
     .';window.APP_USER='.json_encode(Auth::user()
            ? ['name'=>(string)(Auth::user()['full_name'] ?? 'User'),
               'role'=>(Auth::isAdmin() ? 'Owner / Admin' : (Auth::isManager() ? 'Manager' : 'User')),
               'admin'=>Auth::isAdmin()]
            : null)
     /* ============================================================
        V149 — BRANCH KA NAAM.

        Sidebar ke upar "Islamabad — F10" likha aata tha — har business
        ko, har branch par. Woh demo data tha jo chaar files mein
        hard-coded reh gaya tha: `public/shell.js`,
        `approved_ui/retail/shell.js`, `dashboard.html`, `index.html`.
        Customer ka apna branch ka naam `sites.name` mein mojood tha
        magar screen tak kabhi pohanchta hi nahi tha.

        Ab yahan se ek dafa jata hai — modules aur user ki tarah — aur
        chaaron jagah wahi parhti hain. Ek extra request bhi nahi. */
     /* APP_ROLE upar pehle se ja raha hai (line ~249) — V149 mein yahan
        doosri dafa likh diya gaya tha. Ek hi kaafi hai. */
     .';window.APP_SITE='.json_encode(Auth::user() ? (function(){
            try{
                $q=\Aio\DB::pdo()->prepare("SELECT s.name, COALESCE(NULLIF(t.display_name,''),t.name) AS biz
                                              FROM sites s JOIN tenants t ON t.id=s.tenant_id
                                             WHERE s.id=? LIMIT 1");
                $q->execute([site_id()]);
                $r=$q->fetch(\PDO::FETCH_ASSOC)?:[];
                return ['branch'=>(string)($r['name']??''),'business'=>(string)($r['biz']??'')];
            }catch(\Throwable $e){ return ['branch'=>'','business'=>'']; }
        })() : null)
     .';</script>'
     .'<script src="/db_api.js?b=v14"></script>'
     /* V62 — delete confirm har page par available hona is required (POS samet),
        warna har screen apna alag adhoora delete likhti hai. */
     .'<script src="/delete_kit.js?b=v62"></script>'
     .'<script src="/brand.js?b=v26"></script>';
// DB-first hydration only on authenticated app pages (source of truth = DB).
// db_boot POS par NahI is required: POS khud pos-boot se hydrate hota hai, aur
// db_boot ki 2 synchronous XHRs page load ko slow karti thin.
if(!in_array($name,$publicPages,true) && Auth::user() && $name!=='restaurant_pos.html' && $uiDir!=='approved_ui/retail'){
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

if(!in_array($name,$publicPages,true) && $uiDir!=='approved_ui/retail'){
  $tail.='<script src="/approved_auth_exact.js?b=v14"></script>';
  $tail.='<script src="/db_mirror_bridge.js?b=v15"></script>';
  $tail.='<script src="/ui_action_modal.js?b=v15"></script>';
}

/* ============================================================
   V165 — NAYE BUILD KI KHABAR POS KE ANDAR.

   Pehle yeh khabar SIRF launcher ki kaali window mein chhapti thi, aur
   woh bhi sirf start ke waqt. V164 ke baad woh window tray mein chhup
   jati hai — aur jo banda saara din POS par kaam karta hai, woh us
   window ko kabhi dekhta hi nahi.

   YAHAN inject karne ki wajah: ek hi jagah se SAARE safhe cover ho
   jate hain — restaurant, supermarket, aur POS bhi. Agar yeh kaam
   `shell.js` se karte to POS chhoot jata (POS shell.js use nahi
   karta), aur approved_ui ki files chhedni partin — jin ka SHA256
   taala lagta hai.

   TEEN SHARTEIN:
     - PUBLIC safhe nahi (login, qr, pair): wahan koi malik nahi hota
     - login kiya hua ho: build ka naam rahgeer ko batane ki wajah nahi
     - `role=cloud` nahi: cloud server khud update nahi hota, wahan
       UPDATE.bat jaisa kuch hai hi nahi — patti kabhi nahi dikhni
       chahiye, aur fuzool poll bhi nahi hona chahiye
   ============================================================ */
if(!in_array($name,$publicPages,true) && Auth::user()
   && (string)($GLOBALS['config']['app']['role'] ?? 'local') !== 'cloud'){
  $tail.='<script src="/update_notice.js?b=v165"></script>';
}

// restaurant_pos.html ab khud poori tarah DB-driven hai (POS v20) — mirror ki zaroorat nahi.
/* V72 — tablet page ab khud DB par hai; `order_taker_db.js` us purani
   screen ke liye tha jo hardcoded PRODUCTS use karti thi. Ab zaroorat
   nahi — do jagah se data bharne se dono ek doosre par likhte the. */
// V70 — KDS ab asli kitchen_tickets par (pehle hardcoded demo orders the).
if($name==='kds.html')$tail.='<script src="/kds_db.js?b=v70"></script>';
/* V70 — Shift / Running Orders / Void ab asli tables par (pehle ui_records).

   V154 — `$uiDir!=='approved_ui/retail'` ki shart NAYI hai aur zaroori thi.
   Kuch file ke naam dono verticals mein ek jaise hain: `stock_count.html`,
   `purchase_orders.html`, `stock_transfer.html`, `accounting.html`. Yeh
   fehrist sirf NAAM dekhti thi, vertical nahi — is liye supermarket ke
   Stock Count aur Purchase Orders par bhi RESTAURANT ka `ops_db.js` inject
   ho jata tha. Wo script apna content `.content` par likh deti hai, aur
   chunke wo baad mein chalti hai, retail ka poora safha chup chaap mit
   jata tha: screen par "Nothing to show yet" reh jata aur kahin koi error
   nahi aata.

   Restaurant ke liye kuch nahi badla — wahi sab screens, wahi script. */
if($uiDir!=='approved_ui/retail' &&
   in_array($name,['shift_management.html','orders_management.html','void_refund.html',
                   'stock_transfer.html','stock_count.html','accounting.html',
                   'closing_history.html','activity_log.html','purchase_orders.html','backup_restore.html',
                   'online_orders.html','whatsapp_notifications.html'],true))
  $tail.='<script src="/ops_db.js?b=v70"></script>';

// AAKHRI closing body tag par inject karo (pehla nahi) — wahi asli document end hai.
$posB=strripos($html,'</body>');
if($posB!==false)$html=substr($html,0,$posB).$tail.substr($html,$posB);
else $html.=$tail;
header('Cache-Control: no-store, must-revalidate'); // HTML kabhi cache na ho; JS/CSS ?b= se bust hote hain
echo$html;

// build: V165 build 2026-10-25
