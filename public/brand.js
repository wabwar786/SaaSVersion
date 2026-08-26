/* ============================================================
   brand.js — Tenant branding har page par lagata hai:
   restaurant ka naam, logo aur color scheme (super admin se set).
   Har page ke apne markup ko chhue baghair kaam karta hai.
   ============================================================ */
(function(){
  'use strict';
  var B=window.APP_BRAND||{};
  if(!B.name&&!B.logo&&!B.color)return;

  function hex2rgb(h){h=(h||'').replace('#','');if(h.length===3)h=h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
    var n=parseInt(h,16);return isNaN(n)?null:[(n>>16)&255,(n>>8)&255,n&255]}
  function shade(h,p){var c=hex2rgb(h);if(!c)return h;
    return '#'+c.map(function(v){var x=Math.round(v+(p<0?v*p:(255-v)*p));return ('0'+Math.max(0,Math.min(255,x)).toString(16)).slice(-2)}).join('')}

  /* ---- colors ---- */
  function paint(){
    if(!B.color)return;
    var c=B.color, c2=B.accent||shade(c,.18);
    /* 1) inline custom properties = sab se ziada priority */
    var vars=['--brand','--brand2','--accent','--primary','--r1','--red','--danger','--pri'];
    vars.forEach(function(v,i){
      try{document.documentElement.style.setProperty(v,(v==='--brand2')?c2:c,'important')}catch(e){}
    });
    /* 2) component-level override, body ke aakhir mein (baad wala jeetta hai) */
    var st=document.getElementById('brandCss');
    if(!st){st=document.createElement('style');st.id='brandCss';}
    st.textContent='.btn.pri,.tb.pri,.df .go,.addb,.ab,.cat.on,.pill.on,.ntab.on{background:'+c+'!important;border-color:'+c+'!important}'
      +'.mark,.brandmark{background:linear-gradient(135deg,'+c+','+c2+')!important}'
      +'a.brandlink,.brand-accent{color:'+c+'!important}';
    (document.body||document.documentElement).appendChild(st);
  }

  function apply(){
    paint();
    /* ---- naam: title + jahan bhi brand text hai ---- */
    if(B.name){
      try{document.title=B.name+' · '+document.title.split('·').pop().trim()}catch(e){}
      var sel=['#brandName','.brand-name','.brandname','header .brand strong','.sidebar .brand strong','.logo-text'];
      sel.forEach(function(q){Array.prototype.forEach.call(document.querySelectorAll(q),function(el){el.textContent=B.name})});
      /* jahan bhi default brand text hai use tenant ke naam se badlo */
      Array.prototype.forEach.call(document.querySelectorAll('h1,h2,h3,span,strong,b,div,p,small'),function(el){
        if(el.children.length)return;
        var t=(el.textContent||'').trim();
        if(/^(urban spoon|restaurant operating system|urban spoon pos)$/i.test(t))el.textContent=B.name;
      });
    }
    /* ---- logo: har brand mark ko image bana do ---- */
    if(B.logo){
      var marks=document.querySelectorAll('.mark,.brandmark,#homeBtn,.brand .av,.logo,.brand-logo');
      Array.prototype.forEach.call(marks,function(el){
        if(el.querySelector('img.brandimg'))return;
        el.textContent='';
        var im=document.createElement('img');im.className='brandimg';im.src=B.logo;im.alt='';
        im.style.cssText='width:100%;height:100%;object-fit:contain;border-radius:inherit;display:block';
        el.style.overflow='hidden';el.style.padding='0';
        el.appendChild(im);
      });
    }
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',apply);
  else apply();
  setTimeout(apply,400);   /* JS-rendered headers ke liye */
})();
