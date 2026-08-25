/* ============================================================
   pos_db_mirror.js v2 — POS ab DB ke saath do-tarfa chalta hai.

   1) BOOT: products / customers / categories / printers / next
      bill number DB se aate hain (demo arrays overwrite ho
      jaate hain, in-place, approved UI ke render functions
      wahi ke wahi).
   2) KOT: DB save BLOCKING hai — ticket DB mein na jaye to
      kitchen-sent mark NAHI hota (pehle silently kho jata tha).
   3) FINALIZE: server bill number authoritative hai (do
      terminals par duplicate bills khatam); response ke baad
      dashboard cache DB se refresh hota hai.
   4) CREATE: POS ke "+ New Item / Category" ab server par
      persist hote hain, aur item ko inventory se link/create
      karne ke fields modal mein inject hote hain.
   ============================================================ */
(function(){
  'use strict';

  function req(action,payload){
    try{return DBApi.req(action,payload)}
    catch(e){return {ok:false,message:e.message||'Database request failed'}}
  }

  /* ------------------------- BOOT ------------------------- */

  function hydrateFromDb(){
    var r=req('pos-boot');
    if(!r.ok||!r.boot){
      if(typeof toast==='function')toast('POS running on cached data: '+(r.message||'server unreachable'));
      return;
    }
    var b=r.boot;

    // products (const P -> mutate in place)
    if(typeof P!=='undefined'&&Array.isArray(P)&&Array.isArray(b.products)){
      P.length=0;
      b.products.forEach(function(x,i){
        P.push({id:x.id,name:x.name,cat:x.cat,price:x.price,
                weighted:!!x.weighted,unit:x.unit||'',pizza:!!x.pizza,
                th:'t'+((i%15)+1),img:x.img||'assets/menu_01.jpg',
                desc:x.desc||'',today:x.today||0,yesterday:x.yesterday||0,days7:x.days7||0});
      });
    }

    // customers (const customers -> in place; keep walk-in first)
    if(typeof customers!=='undefined'&&Array.isArray(customers)&&Array.isArray(b.customers)){
      customers.length=0;
      b.customers.forEach(function(c){
        customers.push({id:c.id,name:c.name,phone:c.phone||'',address:c.address||'',
                        area:c.area||'',type:c.type||'Regular'});
      });
    }

    // categories (let menuCategories)
    if(typeof menuCategories!=='undefined'&&Array.isArray(b.categories)&&b.categories.length){
      menuCategories.length=0;
      b.categories.forEach(function(c){menuCategories.push({name:c.name,icon:c.icon||'•',printer:c.printer||'main'})});
    }

    // kitchen printers (const kitchenPrinters -> replace keys in place)
    if(typeof kitchenPrinters!=='undefined'&&b.printers&&Object.keys(b.printers).length){
      Object.keys(kitchenPrinters).forEach(function(k){delete kitchenPrinters[k]});
      Object.keys(b.printers).forEach(function(k){
        kitchenPrinters[k]={name:b.printers[k].name,station:b.printers[k].station};
      });
    }

    // server-authoritative bill number
    if(typeof b.nextBill!=='undefined'){
      try{activeBillNo=Number(b.nextBill)||activeBillNo;}catch(e){}
    }

    // re-render with DB data
    try{if(typeof ensureProductImagesV4==='function')ensureProductImagesV4();}catch(e){}
    try{if(typeof renderCategoryNavigation==='function')renderCategoryNavigation();}catch(e){}
    try{if(typeof rp==='function')rp();}catch(e){}
    try{if(typeof populateCategorySelects==='function')populateCategorySelects();}catch(e){}
    try{if(typeof populateRateItems==='function')populateRateItems();}catch(e){}
    try{if(typeof renderCustomerList==='function')renderCustomerList();}catch(e){}
    try{if(typeof applyMainBillContext==='function')applyMainBillContext();}catch(e){}
  }

  /* ---------------------- PAYLOAD ---------------------- */

  function productBaseName(line){
    try{
      var p=(typeof P!=='undefined'&&Array.isArray(P))?P.find(function(x){return x.id===line.id}):null;
      return (p&&p.name)||line.name||'';
    }catch(e){return line.name||''}
  }

  function buildPayload(){
    var t=typeof tots==='function'?tots():{d:0,sc:0,tax:0,total:0};
    var customer=(typeof selectedCustomer!=='undefined'&&selectedCustomer)?selectedCustomer:null;
    return {
      bill_no:(function(){try{return String(activeBillNo).padStart(4,'0')}catch(e){return '0001'}})(),
      service_mode:typeof orderMode!=='undefined'?orderMode:'Dine In',
      table_name:typeof selectedTable!=='undefined'?(selectedTable||''):'',
      customer_id:(customer&&customer.id)||'walkin',
      customer_name:(customer&&customer.name)||'Walk-in Customer',
      customer_phone:(customer&&customer.phone)||'',
      shift_id:window.__AIO_SHIFT_ID||'',
      guest_count:null,
      discount_amount:Number(t.d||0),
      service_charge:Number(t.sc||0),
      tax_amount:Number(t.tax||0),
      received_amount:Number((document.querySelector('#receivedAmount')||{}).value||t.total||0),
      payment_code:typeof paymentMethod!=='undefined'?paymentMethod:'Cash',
      items:(typeof C!=='undefined'?C:[]).map(function(i){return {
        menu_item_id:i.id,
        base_name:productBaseName(i),
        name:i.name,
        qty:Number(i.qty||0),
        unit_price:Number(i.unitPrice||0),
        sent_qty:Number(i.sentQty||0),
        note:i.note||''
      }})
    };
  }

  function syncBillNoFromServer(r){
    if(r&&r.ok&&r.bill_no){
      var n=parseInt(String(r.bill_no).replace(/\D/g,''),10);
      var cur=0;try{cur=Number(activeBillNo);}catch(e){return}
      if(n&&n!==cur){
        try{activeBillNo=n;}catch(e){return}
        try{if(typeof applyMainBillContext==='function')applyMainBillContext();}catch(e){}
        if(typeof toast==='function')toast('Bill number assigned by server: '+(typeof formatBillNo==='function'?formatBillNo(n):n));
      }
    }
  }

  /* -------------------- SHIFT (server) -------------------- */

  function ensureShift(){
    var r=req('shift-current');
    if(r.ok&&r.shift&&r.shift.id){window.__AIO_SHIFT_ID=r.shift.id;window.__AIO_SHIFT_NO=r.shift.shift_no;return;}
    // auto-open a shift with 0 opening cash so billing kabhi block na ho;
    // opening cash Shift screen se set/adjust ho sakta hai.
    var o=req('shift-open',{opening_cash:0});
    if(o.ok){window.__AIO_SHIFT_ID=o.id;window.__AIO_SHIFT_NO=o.shift_no;}
  }

  /* ---------------------- INSTALL ---------------------- */

  function install(){
    if(window.__V13_POS_DB_MIRROR)return;
    window.__V13_POS_DB_MIRROR=true;

    hydrateFromDb();
    ensureShift();

    // rp() pgrid ko poora overwrite karta hai — empty-state uske BAAD lagta hai.
    if(typeof window.rp==='function'){
      var originalRp=window.rp;
      window.rp=function(){var out=originalRp.apply(this,arguments);try{renderEmptyState();}catch(e){}return out;};
    }

    // KOT: DB save is REQUIRED (blocking) — kitchen ticket kabhi silently lost na ho.
    if(typeof window.markPendingAsSent==='function'){
      var originalKot=window.markPendingAsSent;
      window.markPendingAsSent=function(){
        var payload=buildPayload();
        var r=req('pos-kot',payload);
        if(!r.ok){
          if(typeof toast==='function')toast(r.message||'KOT could not be saved — not sent to kitchen');
          return false;
        }
        syncBillNoFromServer(r);
        return originalKot.apply(this,arguments);
      };
    }

    // FINALIZE: server saves first; bill number server ka final hai.
    if(typeof window.completeCharge==='function'){
      var originalCharge=window.completeCharge;
      window.completeCharge=function(action){
        if(typeof validateTender==='function'&&!validateTender())return false;
        var payload=buildPayload();
        var r=req('pos-finalize',payload);
        if(!r.ok){
          if(typeof toast==='function')toast(r.message||'Bill could not be saved to database');
          return false;
        }
        syncBillNoFromServer(r);
        // dashboard cache = DB truth (fake client counters nahi)
        try{
          if(r.dashboard){
            r.dashboard.deliveryReady=r.dashboard.deliveryReady||0;
            localStorage.setItem('urban_spoon_live_v5',JSON.stringify(r.dashboard));
          }
        }catch(e){}
        var out=originalCharge.apply(this,arguments);
        // agla bill number bhi server ka
        if(r.next){try{window.__AIO_NEXT_BILL=Number(r.next)||null;}catch(e){}}
        return out;
      };
    }

    // new bill -> server se number
    if(typeof window.startNewBill==='function'){
      var originalNew=window.startNewBill;
      window.startNewBill=function(){
        var out=originalNew.apply(this,arguments);
        var n=req('pos-next-bill');
        if(n.ok&&n.next){
          try{activeBillNo=Number(n.next)||activeBillNo;
            if(typeof applyMainBillContext==='function')applyMainBillContext();}catch(e){}
        }
        return out;
      };
    }

    // CREATE CATEGORY -> persist
    if(typeof window.createMenuCategory==='function'){
      var originalCat=window.createMenuCategory;
      window.createMenuCategory=function(){
        var name=(document.querySelector('#newCategoryName')||{}).value;
        var icon=(document.querySelector('#newCategoryIcon')||{}).value;
        var printer=(document.querySelector('#newCategoryPrinter')||{}).value;
        var ok=originalCat.apply(this,arguments);
        if(ok===true){
          var r=req('menu-category-create',{name:(name||'').trim(),icon:(icon||'').trim(),printer:printer||''});
          if(!r.ok&&typeof toast==='function')toast('Category saved on screen; DB: '+(r.message||'failed'));
        }
        return ok;
      };
    }

    // CREATE ITEM -> persist (with inventory link/create)
    if(typeof window.createMenuItem==='function'){
      var originalItem=window.createMenuItem;
      window.createMenuItem=function(){
        var d={
          name:((document.querySelector('#niName')||{}).value||'').trim(),
          price:Number((document.querySelector('#niPrice')||{}).value||0),
          category:(document.querySelector('#niCat')||{}).value||'',
          type:(document.querySelector('#niType')||{}).value||'standard',
          desc:((document.querySelector('#niDesc')||{}).value||'').trim()
        };
        // configurable -> variants
        if(d.type==='configurable'&&typeof parsePairs==='function'){
          d.variants=parsePairs(((document.querySelector('#niOptions')||{}).value||''));
        }
        // inventory link fields (injected below)
        var mode=(document.querySelector('#niInvMode')||{}).value||'none';
        if(mode==='existing'){
          d.inventory={mode:'existing',
            item_id:(document.querySelector('#niInvExisting')||{}).value||'',
            qty:Number((document.querySelector('#niInvQty')||{}).value||1)};
        }else if(mode==='new'){
          d.inventory={mode:'new',
            name:((document.querySelector('#niInvNewName')||{}).value||'').trim()||d.name,
            unit:(document.querySelector('#niInvNewUnit')||{}).value||'PCS',
            opening_qty:Number((document.querySelector('#niInvNewOpening')||{}).value||0),
            cost:Number((document.querySelector('#niInvNewCost')||{}).value||0),
            qty:Number((document.querySelector('#niInvQty')||{}).value||1)};
        }else{
          d.inventory={mode:'none'};
        }

        var ok=originalItem.apply(this,arguments);
        if(ok===true){
          var r=req('pos-quick-item',d);
          if(!r.ok){
            if(typeof toast==='function')toast('Item shown on screen only; DB: '+(r.message||'failed'));
          }else{
            // local placeholder id -> real DB id, taake isi session mein bill ban sake
            try{
              var created=(typeof P!=='undefined')?P.find(function(x){return x.name===d.name}):null;
              if(created&&r.id)created.id=r.id;
            }catch(e){}
            if(typeof toast==='function')toast(d.name+' saved to database'+(r.inventory_item_id?' + inventory linked':''));
            var eb=document.getElementById('aioEmptyMenu');if(eb)eb.remove();
          }
        }
        return ok;
      };
    }

    // RATE CHANGE -> persist to DB
    if(typeof window.updateExistingRate==='function'){
      var originalRate=window.updateExistingRate;
      window.updateExistingRate=function(){
        var sel=document.querySelector('#rateItemSelect');
        var id=sel?sel.value:'';
        var name=(sel&&sel.selectedIndex>=0)?sel.options[sel.selectedIndex].textContent:'';
        var rate=Number((document.querySelector('#newItemRate')||{}).value||0);
        var ok=originalRate.apply(this,arguments);
        if(ok===true){
          var r=req('menu-item-rate',{menu_item_id:id,name:name,price:rate});
          if(!r.ok&&typeof toast==='function')toast('Rate changed on screen; DB: '+(r.message||'failed'));
        }
        return ok;
      };
    }

    injectInventoryFields();
    renderEmptyState();
  }

  /* ---- empty menu: pehle item tak clear guidance ---- */
  function renderEmptyState(){
    if(typeof P==='undefined'||!Array.isArray(P)||P.length)return;
    if(document.getElementById('aioEmptyMenu'))return;
    var grid=document.getElementById('pgrid');
    if(!grid)return;
    var box=document.createElement('div');
    box.id='aioEmptyMenu';
    box.style.cssText='grid-column:1/-1;padding:34px 18px;text-align:center;border:1px dashed var(--l,#d7e0da);border-radius:12px;margin:8px';
    box.innerHTML='<div style="font-size:30px;margin-bottom:8px">🍽️</div>'+
      '<div style="font-weight:700;font-size:15px;margin-bottom:4px">Menu abhi khali hai</div>'+
      '<div style="font-size:12px;color:#7b8a80;margin-bottom:14px">Apna pehla item banayein — POS foran ready ho jayega.</div>'+
      '<button id="aioFirstItem" style="padding:10px 18px;border:0;border-radius:10px;background:#e23744;color:#fff;font-weight:700;cursor:pointer;font-size:13px">＋ Create First Item</button>';
    grid.appendChild(box);
    var btn=document.getElementById('aioFirstItem');
    if(btn)btn.onclick=function(){
      try{
        if(typeof populateCategorySelects==='function')populateCategorySelects();
        if(typeof populateRateItems==='function')populateRateItems();
        if(typeof setItemManagementMode==='function')setItemManagementMode('item');
        if(typeof om==='function')om('newitem');
      }catch(e){}
    };
  }

  /* ---- "+ New Item" modal: inventory link/create fields ---- */

  function injectInventoryFields(){
    var typeSel=document.querySelector('#niType');
    if(!typeSel||document.querySelector('#niInvMode'))return;
    var host=typeSel.closest('label')||typeSel.parentElement;
    if(!host||!host.parentElement)return;

    var wrap=document.createElement('div');
    wrap.className='full';
    wrap.style.cssText='grid-column:1/-1;border:1px solid var(--line,#dbe5df);padding:10px;margin-top:4px;background:var(--panel-2,#f6faf8)';
    wrap.innerHTML=
      '<div style="font-size:11px;font-weight:700;margin-bottom:6px">Inventory (stock deduction)</div>'+
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">'+
      '<label style="display:flex;flex-direction:column;gap:4px;font-size:11px"><span>Mode</span>'+
        '<select id="niInvMode">'+
          '<option value="none">No inventory link</option>'+
          '<option value="existing">Deduct existing inventory item</option>'+
          '<option value="new">Create new inventory item</option>'+
        '</select></label>'+
      '<label id="niInvQtyWrap" style="display:none;flex-direction:column;gap:4px;font-size:11px"><span>Deduct qty per sale</span>'+
        '<input id="niInvQty" type="number" step="0.001" value="1"></label>'+
      '<label id="niInvExistingWrap" style="display:none;flex-direction:column;gap:4px;font-size:11px;grid-column:1/-1"><span>Inventory item</span>'+
        '<select id="niInvExisting"></select></label>'+
      '<label id="niInvNewNameWrap" style="display:none;flex-direction:column;gap:4px;font-size:11px"><span>New inventory item name</span>'+
        '<input id="niInvNewName" placeholder="Same as menu item"></label>'+
      '<label id="niInvNewUnitWrap" style="display:none;flex-direction:column;gap:4px;font-size:11px"><span>Stock unit</span>'+
        '<select id="niInvNewUnit"><option>PCS</option><option>G</option><option>KG</option><option>ML</option><option>L</option></select></label>'+
      '<label id="niInvNewOpeningWrap" style="display:none;flex-direction:column;gap:4px;font-size:11px"><span>Opening stock</span>'+
        '<input id="niInvNewOpening" type="number" step="0.001" value="0"></label>'+
      '<label id="niInvNewCostWrap" style="display:none;flex-direction:column;gap:4px;font-size:11px"><span>Cost per unit</span>'+
        '<input id="niInvNewCost" type="number" step="0.01" value="0"></label>'+
      '</div>';
    host.parentElement.insertBefore(wrap,host.nextSibling);

    function fillExisting(){
      var sel=document.querySelector('#niInvExisting');
      if(!sel)return;
      var items=(window.__AIO_STORE_STATE&&window.__AIO_STORE_STATE.inventoryItems)||[];
      if(!items.length&&window.RestaurantStore){try{items=RestaurantStore.getState().inventoryItems||[]}catch(e){}}
      sel.innerHTML=items.map(function(i){
        return '<option value="'+i.id+'">'+i.name+' — '+(Math.round((i.stockQty||0)*100)/100)+' '+(i.stockUnit||'')+'</option>';
      }).join('')||'<option value="">(no inventory items)</option>';
    }

    document.querySelector('#niInvMode').addEventListener('change',function(){
      var m=this.value;
      var show=function(id,on){var el=document.querySelector(id);if(el)el.style.display=on?'flex':'none'};
      show('#niInvQtyWrap',m!=='none');
      show('#niInvExistingWrap',m==='existing');
      show('#niInvNewNameWrap',m==='new');
      show('#niInvNewUnitWrap',m==='new');
      show('#niInvNewOpeningWrap',m==='new');
      show('#niInvNewCostWrap',m==='new');
      if(m==='existing')fillExisting();
    });
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install);
  else install();
})();

/* build: V17.1 build 2026-08-25 */
