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
        '<button class="logout" id="shellLogout" title="Sign out">⏻</button></div>';

  var side=document.getElementById('side')||document.querySelector('.sidebar');
  if(side){ side.className='sidebar'; side.innerHTML=html; }

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
