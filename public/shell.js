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
      ['reports','Reports','reports.html','▥'],
      ['fbr','FBR / Digital Invoice','fbr.html','✓']
    ]},
    {title:'System',items:[
      ['printers','Printers / Devices','printer_devices.html','⎙'],
      ['branches','Multi-Branch','multi_branch.html','⌗'],
      ['offline','Offline / Sync','offline_sync.html','⟳'],
      ['users','Users & Access','users_access.html','⚿'],
      ['settings','Settings','settings.html','⚙']
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
  html+='<div class="side-foot"><div class="side-avatar" id="sideAvatar">'+initials(user.name)+'</div>'+
        '<div class="who"><b id="sideUserName">'+esc(user.name)+'</b><span id="sideUserRole">'+esc(user.role||'User')+'</span></div>'+
        '<button class="logout" id="shellAbout" title="About this software">&#9432;</button>'+
        '<button class="logout" id="shellLogout" title="Sign out">&#9211;</button></div>';

  var side=document.getElementById('side')||document.querySelector('.sidebar');
  if(side){ side.className='sidebar'; side.innerHTML=html; }

  /* ---------- About (V65) ----------
     Customer ko yeh maloom hona is required ke software kis ne banaya hai
     aur masle ki soorat mein kis se rabta karna hai. Pehle kahin bhi
     nahi likha tha. */
  function aboutHtml(a){
    var v=(a&&a.vendor)||{};
    return '<div class="dialog" style="width:min(420px,94vw)">'
      +'<div class="dialog-head"><div><h3>About this software</h3>'
      +'<p>'+esc(v.company||'Wabwar Software House')+'</p></div>'
      +'<button class="close" data-ab="x">&times;</button></div>'
      +'<div class="dialog-body">'
      +'<p style="font-size:12px;margin:0 0 12px">This software is developed and supported by '
      +'<b>'+esc(v.company||'Wabwar Software House')+'</b>.</p>'
      +'<div>'
      +row('Contact person', esc(v.person||''))
      +row('Phone', '<a href="tel:'+esc((v.phone||'').replace(/\s/g,''))+'">'+esc(v.phone||'')+'</a>')
      +row('Email', '<a href="mailto:'+esc(v.email||'')+'">'+esc(v.email||'')+'</a>')
      +row('Website', '<a href="https://'+esc(v.website||'')+'" target="_blank" rel="noopener">'+esc(v.website||'')+'</a>')
      +row('Build', esc((a&&a.build)||'-'))
      +'</div></div>'
      +'<div class="dialog-foot"><button class="btn" data-ab="x">Close</button>'
      +'<a class="btn primary" href="tel:'+esc((v.phone||'').replace(/\s/g,''))+'">Call support</a></div></div>';
  }
  function row(k,v){
    return '<div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px solid var(--line);font-size:12px">'
      +'<span style="color:var(--muted)">'+k+'</span><b style="text-align:right">'+v+'</b></div>';
  }
  function openAbout(){
    var a={vendor:{company:'Wabwar Software House',person:'Waseem Iqbal',
                   phone:'+92 342 5095104',email:'support@wabwar.pk',website:'www.wabwar.pk'},build:''};
    try{ if(window.DBApi){ var r=DBApi.req('about'); if(r&&r.ok)a=r; } }catch(e){}
    var ov=document.createElement('div'); ov.className='modal show'; ov.innerHTML=aboutHtml(a);
    document.body.appendChild(ov);
    ov.addEventListener('click',function(e){
      if(e.target===ov||e.target.closest('[data-ab="x"]')) ov.remove();
    });
  }
  var ab=document.getElementById('shellAbout');
  if(ab) ab.onclick=openAbout;
  window.openAbout=openAbout;

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
      +'<button class="btn sm" id="licAbout" style="margin-left:auto">Details</button>';
    var main=document.querySelector('.main')||document.body;
    main.insertBefore(bar,main.firstChild);
    var la=document.getElementById('licAbout'); if(la) la.onclick=openAbout;
  })();

  // Mobile drawer
  var scrim=document.getElementById('scrim');
  if(!scrim){ scrim=document.createElement('div'); scrim.className='scrim'; scrim.id='scrim'; document.body.appendChild(scrim); }
  var mb=document.getElementById('menuBtn');
  function close(){document.body.classList.remove('nav-open');}
  if(mb) mb.onclick=function(){document.body.classList.toggle('nav-open');};
  scrim.onclick=close;
  var lo=document.getElementById('shellLogout');
  if(lo) lo.onclick=function(){ try{RestaurantAccess.logout();}catch(e){} location.href='login.html'; };
  window.addEventListener('resize',function(){ if(innerWidth>1000) close(); });
})();

/* build: V17.1 build 2026-08-25 */
