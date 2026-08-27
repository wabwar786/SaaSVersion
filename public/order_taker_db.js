/* ============================================================
   order_taker_db.js — Order Taker Tablet ab REAL data par:
   • Menu / categories / tables DB se (pos-boot) — dummy PRODUCTS khatam
   • "Send Pending Items to Kitchen" ab ASLI KOT banata hai (pos-kot),
     DB fail ho to kitchen-sent mark nahi hota
   • Table select DB ki dining_tables se; har table ka apna running order
   • Waiter naam session user se
   ============================================================ */
(function(){
  'use strict';

  function req(action,payload){
    try{return DBApi.req(action,payload)}
    catch(e){return {ok:false,message:e.message||'Database request failed'}}
  }

  function install(){
    if(window.__AIO_ORDER_TAKER)return;
    window.__AIO_ORDER_TAKER=true;
    if(typeof PRODUCTS==='undefined'||typeof renderProducts!=='function')return;

    var r=req('pos-boot');
    if(!r.ok||!r.boot){
      if(typeof toast==='function')toast('Live menu could not be loaded: '+(r.message||'server unreachable'));
      return;
    }
    var b=r.boot;

    /* demo cart saaf — asli order khali cart se shuru hota hai */
    if(typeof cart!=='undefined'&&Array.isArray(cart))cart.length=0;

    /* ---- menu ---- */
    PRODUCTS.length=0;
    (b.products||[]).forEach(function(x,i){
      PRODUCTS.push({id:x.id,name:x.name,cat:x.cat,price:x.price,
        img:x.img||('assets/menu_'+String((i%12)+1).padStart(2,'0')+'.jpg'),
        desc:x.desc||'',weighted:!!x.weighted,pizza:!!x.pizza});
    });

    /* ---- categories ---- */
    if(typeof CATS!=='undefined'&&Array.isArray(CATS)){
      CATS.length=0;CATS.push('All');
      (b.categories||[]).forEach(function(c){CATS.push(c.name)});
    }

    /* ---- tables (top select + payload) ---- */
    var tsel=document.querySelector('.tablet-topbar select, header select, select');
    if(tsel&&Array.isArray(b.tables)&&b.tables.length){
      tsel.innerHTML=b.tables.map(function(t){
        return '<option'+(t.status==='OCCUPIED'?' data-busy="1"':'')+'>'+(t.name||t.display_name)+'</option>';
      }).join('');
    }
    function currentTable(){
      return tsel&&tsel.value?tsel.value:'Counter';
    }

    /* ---- waiter naam ---- */
    if(b.cashier){
      var w=document.querySelectorAll('.waiter strong, [class*=waiter] strong');
      for(var i=0;i<w.length;i++)w[i].textContent=b.cashier.name;
    }

    /* ---- empty menu guidance ---- */
    if(!PRODUCTS.length){
      var grid=document.querySelector('.tablet-products,.products-grid,#tabletProducts');
      if(grid){
        var d=document.createElement('div');
        d.style.cssText='grid-column:1/-1;padding:30px;text-align:center;color:#5f6f66;font-size:13px';
        d.innerHTML='🍽️ Menu abhi is empty — POS ya Menu & Categories se items banayein, yahan foran aa jayenge.';
        grid.appendChild(d);
      }
    }

    /* ---- REAL KOT ---- */
    var btn=document.getElementById('tabletSendKitchen');
    if(btn){
      btn.onclick=function(){
        var pending=cart.filter(function(i){return i.qty-(i.sentQty||0)>0});
        if(!pending.length){toast('Nothing pending for kitchen');return}
        var nb=req('pos-next-bill');
        var payload={
          bill_no:window.__AIO_TABLET_BILL||String((nb.ok&&nb.next)||1).padStart(4,'0'),
          service_mode:'Dine In',
          table_name:currentTable(),
          customer_id:'walkin',
          customer_name:'Walk-in Customer',
          payment_code:'Cash',
          items:cart.map(function(i){
            var p=PRODUCTS.find(function(x){return x.id===i.id});
            return {menu_item_id:i.id,base_name:(p&&p.name)||i.name,name:i.name,
                    qty:Number(i.qty||0),unit_price:Number(i.price||0),
                    sent_qty:Number(i.sentQty||0),note:i.note||''};
          })
        };
        var kr=req('pos-kot',payload);
        if(!kr.ok){toast(kr.message||'KOT save failed — kitchen ko NahI gaya');return}
        window.__AIO_TABLET_BILL=kr.bill_no||payload.bill_no;
        pending.forEach(function(i){i.sentQty=i.qty});
        renderCart();
        toast('KOT sent — Bill #'+window.__AIO_TABLET_BILL+' ('+kr.sent+' ticket)');
      };
    }

    /* ---- New Order: naya bill number, cart reset ---- */
    var nbtn=document.getElementById('tabletNewOrder');
    if(nbtn){
      nbtn.onclick=function(){
        cart.length=0;window.__AIO_TABLET_BILL='';
        renderCart();toast('New order started for '+currentTable());
      };
    }
    if(tsel)tsel.onchange=function(){window.__AIO_TABLET_BILL='';};

    /* ---- table CARDS strip DB se ---- */
    var strip=document.querySelector('.table-strip');
    if(strip&&Array.isArray(b.tables)&&b.tables.length){
      strip.innerHTML=b.tables.slice(0,12).map(function(t,i){
        var busy=t.status==='OCCUPIED';
        return '<button class="table-card'+(busy?' running':'')+(i===0?' selected':'')+'" data-aiotable="'+(t.name||t.display_name)+'"><b>'+(t.name||t.display_name)+'</b><small>'+(busy?'Occupied':'Available')+'</small></button>';
      }).join('');
      var cards=strip.querySelectorAll('[data-aiotable]');
      for(var ci=0;ci<cards.length;ci++){
        cards[ci].onclick=function(){
          for(var j=0;j<cards.length;j++)cards[j].classList.remove('selected');
          this.classList.add('selected');
          if(tsel){tsel.value=this.dataset.aiotable;window.__AIO_TABLET_BILL='';}
          var h2=document.querySelector('.tablet-order h2, .order-panel h2, aside h2');
          if(h2)h2.textContent=this.dataset.aiotable;
        };
      }
    }

    /* ---- design polish + version badge ---- */
    if(!document.getElementById('aioTabCss')){
      var st=document.createElement('style');st.id='aioTabCss';
      st.textContent='.product-card,.tablet-products>div{transition:transform .14s,box-shadow .14s;border-radius:14px}'
        +'.product-card:hover{transform:translateY(-3px);box-shadow:0 14px 30px rgba(21,34,27,.12)}'
        +'#aioTabVer{position:fixed;bottom:8px;right:12px;font-size:10px;color:#9aa8a0;font-weight:700;z-index:50}';
      document.head.appendChild(st);
      var v=document.createElement('div');v.id='aioTabVer';v.textContent='Tablet v19.2';document.body.appendChild(v);
    }

    try{renderCats();renderProducts();renderCart();}catch(e){}
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install);
  else install();
})();
