/* ============================================================
   shell.js — one sidebar for the whole app.
   Every page loads this and passes data-active="<moduleKey>".
   Nav links, icons, grouping, active state and the mobile
   drawer all live here, so fixing a link fixes it everywhere.
   ============================================================ */
(function(){
  var GROUPS=[
    {title:'Operations',items:[
      ['shift','Opening & Closing Shift','shift_management.html','◔'],
      ['pos','Sale Point / POS','restaurant_pos.html','▤'],
      ['tablet','Order Taker Tablet','restaurant_order_taker_tablet.html','▭'],
      ['kds','Kitchen / KDS','kds.html','♨'],
      ['tables','Tables & Floors','tables_floors.html','▦'],
      ['orders','Running Orders','orders_management.html','≣'],
      ['online','Online Orders','online_orders.html','◈']
    ]},
    {title:'Inventory & Menu',items:[
      ['inventory','Inventory','inventory_creation.html','▣'],
      ['purchasing','Purchasing','purchasing.html','⇩'],
      ['po','Purchase Orders','purchase_orders.html','\u2637'],
      ['recipe','Recipe & Food Cost','recipe_making.html','✧'],
      ['menu','Menu & Categories','menu_management.html','☰'],
      ['wastage','Wastage / Adjustment','wastage_adjustment.html','⊘'],
      ['transfer','Stock Transfer','stock_transfer.html','⇄'],
      ['count','Physical Stock Count','stock_count.html','▤'],
      ['suppliers','Suppliers','suppliers.html','⌂']
    ]},
    {title:'Customers & Delivery',items:[
      ['customers','Customers','customers.html','☺'],
      ['customer_app','Customer Mobile App','customer_mobile_app.html','▢'],
      ['customer_web','Customer Web / QR','customer_web_qr.html','⊞'],
      ['delivery','Delivery','delivery.html','➤'],
      ['riders','Rider Management','rider_management.html','⛟'],
      ['reservations','Reservations','reservations.html','◷'],
      ['loyalty','Loyalty / Membership','loyalty.html','★'],
      ['whatsapp','WhatsApp / Notifications','whatsapp_notifications.html','✆']
    ]},
    {title:'Finance & Reports',items:[
      ['expenses','Expenses','expenses.html','▼'],
      ['accounting','Accounting / Cash','accounting.html','§'],
      ['promotions','Discounts / Promotions','discounts_promotions.html','%'],
      ['staff','Staff / Roles','staff_roles.html','⚇'],
      ['void','Void / Refund','void_refund.html','⊗'],
      ['closing','Shift Closing History','closing_history.html','\u25F7'],
      ['reports','Reports','reports.html','▥'],
      ['activity','User Activity Log','activity_log.html','\u2637'],
         ]},
    {title:'System',items:[
      ['printers','Printers / Devices','printer_devices.html','⎙'],
      ['branches','Multi-Branch','multi_branch.html','⌗'],
      ['offline','Offline / Sync','offline_sync.html','⟳'],
      ['users','Users & Access','users_access.html','⚿'],
      ['settings','Settings','settings.html','⚙'],
      ['settings','Activate / Renew','activate.html','\u2691']
    ]}
  ];

  var script=document.currentScript;
  var active=(script&&script.getAttribute('data-active'))||'';
  var badges={}; try{badges=JSON.parse((script&&script.getAttribute('data-badges'))||'{}')}catch(e){}

  // Current user (falls back to a demo user if the access store isn't present)
  var user=null;
  try{ if(window.RestaurantAccess) user=RestaurantAccess.current(); }catch(e){}
  if(!user) user={name:'Ali Raza',role:'Demo User',email:'ali@urbanspoon.local'};
  var allowed=user.modules||null; // null = show everything (demo)

  function initials(n){return (n||'U').split(/\s+/).map(function(w){return w[0]}).slice(0,2).join('').toUpperCase();}
  function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]});}

  function groupOpen(g){ return g.items.some(function(it){return it[0]===active;}); }

  var html='';
  html+='<div class="brand"><div class="brand-mark">U</div><div><div class="brand-name">Urban Spoon</div>'+
        '<span class="brand-sub">Restaurant Operating System</span></div></div>';
  html+='<div class="branch-chip"><span class="dot"></span><div><span>Current branch</span><b>Islamabad — F10</b></div></div>';
  html+='<nav class="nav">';
  html+='<a href="index.html" class="nav-home'+(active==='dashboard'?' active':'')+'"><span class="ic">▦</span>Dashboard</a>';
  GROUPS.forEach(function(g){
    var items=g.items.filter(function(it){return !allowed||allowed.indexOf(it[0])>-1;});
    if(!items.length) return;
    html+='<details class="nav-group"'+(groupOpen(g)?' open':'')+'><summary>'+esc(g.title)+'<span class="chev">›</span></summary><div class="nav-sub">';
    items.forEach(function(it){
      var b=badges[it[0]]?'<span class="pill">'+esc(badges[it[0]])+'</span>':'';
      html+='<a href="'+it[2]+'" class="nav-link'+(it[0]===active?' active':'')+'"><span class="ic">'+it[3]+'</span>'+esc(it[1])+b+'</a>';
    });
    html+='</div></details>';
  });
  html+='</nav>';
  /* V77 — sidebar ka footer saaf kiya.
     Neeche software house ki tafseel do jagah nazar aa rahi thi (Guide
     aur Support ke buttons dono se). Ab: user, phir chhote icons ki ek
     hi qatar, aur uske neeche EK saaf "Sign out" button — cashier ko
     logout ke liye screens badalni na paren. Vendor ki tafseel sirf
     Support ke popup mein, ek hi jagah. */
  html+='<div class="side-foot" style="flex-wrap:wrap;gap:8px">'+
        '<div class="side-avatar" id="sideAvatar">'+initials(user.name)+'</div>'+
        '<div class="who"><b id="sideUserName">'+esc(user.name)+'</b>'+
        '<span id="sideUserRole">'+esc(user.role||'User')+'</span></div>'+
        '<button class="logout" id="shellGuide" title="How this screen works">?</button>'+
        '<button class="logout" id="shellSupport" title="Contact support">&#9993;</button>'+
        '<button class="btn sm" id="shellLogout" style="flex:1 0 100%;justify-content:center">Sign out</button>'+
        '</div>';

  var side=document.getElementById('side')||document.querySelector('.sidebar');
  if(side){ side.className='sidebar'; side.innerHTML=html; }

  /* ---------- Support (V66) ----------
     Pehle yeh "About" tha aur sidebar mein rabte ka naam likha aata tha.
     Ab ek Support button, aur uska apna animated popup jismein sirf
     rabte ki tafseel hai. */
  function supportHtml(a){
    var v=(a&&a.vendor)||{}, tel=(v.phone||'').replace(/\s/g,'');
    return '<div class="dialog sup-pop" style="width:min(400px,94vw);text-align:center">'
      +'<button class="close" data-ab="x" style="position:absolute;right:10px;top:10px">&times;</button>'
      +'<div class="dialog-body" style="padding:26px 22px 20px">'
      +'<div class="sup-badge">&#9993;</div>'
      +'<h3 style="margin:14px 0 2px">Need help?</h3>'
      +'<p class="hint" style="margin:0 0 18px">Our support team is here for you</p>'
      +'<a class="sup-row" href="tel:'+esc(tel)+'"><span class="sup-ic">&#9742;</span>'
        +'<span><small>Call us</small><b>'+esc(v.phone||'')+'</b></span></a>'
      +'<a class="sup-row" href="mailto:'+esc(v.email||'')+'"><span class="sup-ic">&#9993;</span>'
        +'<span><small>Email</small><b>'+esc(v.email||'')+'</b></span></a>'
      +'<a class="sup-row" href="https://'+esc(v.website||'')+'" target="_blank" rel="noopener">'
        +'<span class="sup-ic">&#127760;</span><span><small>Website</small><b>'+esc(v.website||'')+'</b></span></a>'
      +'<p class="hint" style="margin:16px 0 0;font-size:11px">'+esc(v.company||'Wabwar Software House')
        +(a&&a.build?(' &middot; '+esc(a.build)):'')+'</p>'
      +'</div></div>';
  }
  function injectSupportCss(){
    if(document.getElementById('supCss'))return;
    var st=document.createElement('style'); st.id='supCss';
    st.textContent=
      '.sup-pop{position:relative;animation:supIn .26s cubic-bezier(.2,.9,.3,1.2)}'
     +'@keyframes supIn{from{opacity:0;transform:translateY(14px) scale(.96)}to{opacity:1;transform:none}}'
     +'.sup-badge{width:64px;height:64px;margin:0 auto;border-radius:50%;display:grid;place-items:center;'
     +'font-size:28px;color:#fff;background:var(--brand,#c02a37);animation:supPulse 2.2s ease-in-out infinite}'
     +'@keyframes supPulse{0%,100%{box-shadow:0 0 0 0 rgba(192,42,55,.35)}50%{box-shadow:0 0 0 14px rgba(192,42,55,0)}}'
     +'.sup-row{display:flex;align-items:center;gap:12px;text-align:left;padding:11px 13px;margin:8px 0;'
     +'border:1px solid var(--line);border-radius:12px;text-decoration:none;color:inherit;'
     +'transition:transform .15s ease,border-color .15s ease,background .15s ease}'
     +'.sup-row:hover{transform:translateX(3px);border-color:var(--brand-line,#eab6bb);background:var(--brand-soft,#fdeef0)}'
     +'.sup-row small{display:block;font-size:10.5px;color:var(--muted)}'
     +'.sup-row b{font-size:13px}'
     +'.sup-ic{width:34px;height:34px;flex:0 0 34px;border-radius:9px;display:grid;place-items:center;'
     +'background:var(--brand-soft,#fdeef0);color:var(--brand,#c02a37);font-size:15px}';
    document.head.appendChild(st);
  }
  function openSupport(){
    injectSupportCss();
    var a={vendor:{company:'Wabwar Software House',phone:'+92 342 5095104',
                   email:'support@wabwar.pk',website:'www.wabwar.pk'},build:''};
    try{ if(window.DBApi){ var r=DBApi.req('about'); if(r&&r.ok)a=r; } }catch(e){}
    var ov=document.createElement('div'); ov.className='modal show'; ov.innerHTML=supportHtml(a);
    document.body.appendChild(ov);
    ov.addEventListener('click',function(e){
      if(e.target===ov||e.target.closest('[data-ab="x"]')) ov.remove();
    });
    document.addEventListener('keydown',function k(e){
      if(e.key==='Escape'){ov.remove();document.removeEventListener('keydown',k);}
    });
  }
  /* ---------- Guide (V72) ----------
     Har page par ek "?" button. Guide server se aati hai (Guide.php),
     is liye har module ki madad ek hi jagah likhi hai aur screen ke
     saath purani nahi parti. */
  function openGuide(){
    var mod=(document.currentScript&&document.currentScript.dataset.active)||window.__ACTIVE_MODULE||'';
    if(!mod){var sc=document.querySelector('script[data-active]'); if(sc)mod=sc.getAttribute('data-active')||'';}
    var g=null;
    try{ if(window.DBApi){var r=DBApi.req('guide&module='+encodeURIComponent(mod)); if(r&&r.ok)g=r.guide;} }catch(e){}
    if(!g){ if(window.openSupport)openSupport(); return; }

    function block(title,arr,cls){
      if(!arr||!arr.length)return '';
      return '<div style="margin-top:14px"><div style="font-size:10.5px;letter-spacing:.06em;'
        +'text-transform:uppercase;color:var(--muted);margin-bottom:6px">'+title+'</div>'
        +'<ol style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.65'
        +(cls==='warn'?';color:var(--danger)':'')+'">'
        + arr.map(function(x){return '<li style="margin-bottom:5px">'+esc(x)+'</li>'}).join('')
        +'</ol></div>';
    }
    var ov=document.createElement('div'); ov.className='modal show';
    ov.innerHTML='<div class="dialog" style="width:min(560px,95vw);max-height:88vh;display:flex;flex-direction:column">'
      +'<div class="dialog-head"><div><h3>'+esc(g.title)+'</h3><p>How this screen works</p></div>'
      +'<button class="close" data-gx>&times;</button></div>'
      +'<div class="dialog-body" style="overflow:auto">'
      +'<p style="font-size:13px;margin:0">'+esc(g.what)+'</p>'
      + block('Getting started', g.steps, '')
      + block('Good to know', g.tips, '')
      + block('Be careful', g.warn, 'warn')
      +'</div>'
      +'<div class="dialog-foot"><button class="btn" data-gx>Close</button></div></div>';
    document.body.appendChild(ov);
    ov.addEventListener('click',function(e){
      if(e.target===ov||e.target.closest('[data-gx]')) ov.remove();
    });
  }
  window.openGuide=openGuide;
  var gb=document.getElementById('shellGuide');
  if(gb) gb.onclick=openGuide;

  var sup=document.getElementById('shellSupport');
  if(sup) sup.onclick=openSupport;
  window.openSupport=openSupport;
  window.openAbout=openSupport;   /* purana naam bhi chalta rahe */

  /* ---------- Licence banner (V65) ----------
     Expiry se 3 din pehle warning, aur expire hone par Wabwar ka rabta
     taake customer seedha call kar ke renew karwa le. */
  (function(){
    if(!window.DBApi) return;
    var r; try{ r=DBApi.req('licence-status'); }catch(e){ return; }
    if(!r||!r.ok||!r.licence) return;
    var L=r.licence;
    if(!L.expired && !L.warn) return;
    var v=L.vendor||{};
    var bar=document.createElement('div');
    bar.id='licBar';
    bar.style.cssText='position:sticky;top:0;z-index:60;padding:10px 14px;font-size:12.5px;'
      +'display:flex;gap:10px;align-items:center;flex-wrap:wrap;'
      +(L.expired?'background:var(--danger-soft);color:var(--danger);border-bottom:1px solid var(--danger-line)'
                 :'background:#fff6e5;color:#8a5a00;border-bottom:1px solid #f0d9a8');
    bar.innerHTML='<b>'+esc(L.message||'')+'</b>'
      +(L.expired?('<span>Contact '+esc(v.company||'')+' &mdash; '+esc(v.person||'')+'</span>'
        +'<a class="btn sm primary" href="tel:'+esc((v.phone||'').replace(/\s/g,''))+'">Call '+esc(v.phone||'')+'</a>'
        +'<a class="btn sm" href="mailto:'+esc(v.email||'')+'">'+esc(v.email||'')+'</a>'):'')
      /* V86 — banner se seedha activation screen par. Pehle sirf phone
         number tha; ab customer khud renew kar sakta hai. */
      +'<a class="btn sm primary" href="/activate.html" style="margin-left:auto">'
      +(L.expired?'Activate now':'Renew')+'</a>'
      +'<button class="btn sm" id="licAbout">Support</button>';
    var main=document.querySelector('.main')||document.body;
    main.insertBefore(bar,main.firstChild);
    var la=document.getElementById('licAbout'); if(la) la.onclick=openSupport;
  })();

  // Mobile drawer
  var scrim=document.getElementById('scrim');
  if(!scrim){ scrim=document.createElement('div'); scrim.className='scrim'; scrim.id='scrim'; document.body.appendChild(scrim); }
  var mb=document.getElementById('menuBtn');
  function close(){document.body.classList.remove('nav-open');}
  if(mb) mb.onclick=function(){document.body.classList.toggle('nav-open');};
  scrim.onclick=close;
  var lo=document.getElementById('shellLogout');
  if(lo) lo.onclick=function(){
    /* Logout ke baad usi business ke login par wapas — warna customer
       ko har baar apna business dhoondna parta hai aur branding bhi
       nahi aati. Slug server session mein mehfooz rehta hai. */
    var to='login.html';
    try{
      if(window.DBApi){ var r=DBApi.req('logout',{}); if(r&&r.redirect)to=r.redirect; }
    }catch(e){}
    try{RestaurantAccess.logout();}catch(e){}
    location.href=to;
  };
  window.addEventListener('resize',function(){ if(innerWidth>1000) close(); });
})();

/* build: V17.1 build 2026-08-25 */
