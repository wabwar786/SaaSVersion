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
    var b=r.boot;window.__AIO_LAST_BOOT=b;

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

    // categories — HAMESHA replace (0 par bhi), warna demo categories user ko
    // dhoka deti hain ke menu maujood hai.
    if(typeof menuCategories!=='undefined'&&Array.isArray(b.categories)){
      menuCategories.length=0;
      b.categories.forEach(function(c){menuCategories.push({name:c.name,icon:c.icon||'•',printer:c.printer||'main'})});
    }

    // header: asli cashier (static demo naam ki jagah)
    if(b.cashier){
      var us=document.querySelector('header .user');
      if(us){
        var av=us.querySelector('.av'),nm=us.querySelector('strong'),rl=us.querySelector('small');
        var initials=(b.cashier.name||'U').split(/\s+/).map(function(w){return w[0]||''}).join('').slice(0,2).toUpperCase();
        if(av)av.textContent=initials;
        if(nm)nm.textContent=b.cashier.name;
        if(rl)rl.textContent=b.cashier.role;
      }
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

    injectDesign(b);
  }

  /* ============ VISIBLE design layer (approved UI ke upar) ============ */
  var VERSION='POS v19.2';

  function injectDesign(b){
    if(!document.getElementById('aioPosCss')){
      var st=document.createElement('style');st.id='aioPosCss';
      st.textContent=[
        /* product cards: depth + hover lift + price pill */
        '#pgrid .prod{border-radius:14px;box-shadow:0 1px 2px rgba(21,34,27,.06),0 8px 22px rgba(21,34,27,.05);transition:transform .14s ease, box-shadow .14s ease;overflow:hidden}',
        '#pgrid .prod:hover{transform:translateY(-3px);box-shadow:0 4px 10px rgba(21,34,27,.08),0 16px 34px rgba(21,34,27,.10)}',
        '#pgrid .prod .price{background:#eef7f1;color:#0f7a3d;border-radius:999px;padding:3px 9px;font-weight:800}',
        '#pgrid .prod .add{border-radius:999px;font-weight:800;box-shadow:0 4px 10px rgba(226,55,68,.25)}',
        /* category pills: active glow */
        '.catbar button.active, .cats .active{box-shadow:0 6px 16px rgba(21,34,27,.18)}',
        /* status strip */
        '#aioStatusStrip{display:flex;gap:10px;align-items:center;padding:8px 14px;background:linear-gradient(90deg,#f4faf6,#ffffff);border-bottom:1px solid #e4eae6;font-size:12px;color:#41504a}',
        '#aioStatusStrip .sb{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #e4eae6;border-radius:999px;padding:4px 12px;font-weight:700}',
        '#aioStatusStrip .dotg{width:7px;height:7px;border-radius:99px;background:#28a745;display:inline-block}',
        '#aioStatusStrip .dotr{width:7px;height:7px;border-radius:99px;background:#e23744;display:inline-block}',
        '#aioVer{margin-left:auto;font-size:10px;color:#9aa8a0;font-weight:700;letter-spacing:.4px}'
      ].join('');
      document.head.appendChild(st);
    }
    var strip=document.getElementById('aioStatusStrip');
    if(!strip){
      strip=document.createElement('div');strip.id='aioStatusStrip';
      var hdr=document.querySelector('header');
      if(hdr&&hdr.parentElement)hdr.parentElement.insertBefore(strip,hdr.nextSibling);
      else document.body.insertBefore(strip,document.body.firstChild);
    }
    var items=(typeof P!=='undefined'&&Array.isArray(P))?P.length:0;
    var shiftHtml=window.__AIO_SHIFT_NO
      ?'<span class="sb"><span class="dotg"></span>Shift '+window.__AIO_SHIFT_NO+' <a href="#" id="aioStripClose" style="margin-left:6px;color:#e23744;font-weight:800;text-decoration:none">Close</a></span>'
      :'<span class="sb"><span class="dotr"></span>Shift not opened <a href="#" id="aioStripOpen" style="margin-left:6px;color:#0f7a3d;font-weight:800;text-decoration:none">Open</a></span>';
    strip.innerHTML=
      '<span class="sb">🏬 '+((b&&b.site&&b.site.name)||'Branch')+'</span>'
      +shiftHtml
      +'<span class="sb">🍽 '+items+' menu item'+(items===1?'':'s')+'</span>'
      +(items===0?'<span style="color:#b3541e;font-weight:700">Menu khali hai — “+ New Item” se pehla item banayein</span>':'')
      +'<span id="aioVer">'+VERSION+'</span>';
    var oc=document.getElementById('aioStripOpen');if(oc)oc.onclick=function(e){e.preventDefault();showOpenGate();};
    var cc=document.getElementById('aioStripClose');if(cc)cc.onclick=function(e){e.preventDefault();showCloseModal();};
  }

  function refreshStrip(){try{injectDesign(window.__AIO_LAST_BOOT||null);}catch(e){}}

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

  /* ================= SHIFT GATE + CLOSE + REPORT ================= */

  function money(n){return 'PKR '+Number(n||0).toLocaleString(undefined,{maximumFractionDigits:0})}

  function ensureShift(){
    var r=req('shift-current');
    if(r.ok&&r.shift&&r.shift.id){
      window.__AIO_SHIFT_ID=r.shift.id;window.__AIO_SHIFT_NO=r.shift.shift_no;
      updateShiftChip(r.shift.shift_no);refreshStrip();
      return;
    }
    showOpenGate();
  }

  function overlay(id){
    var el=document.createElement('div');el.id=id;
    el.style.cssText='position:fixed;inset:0;background:rgba(8,20,14,.62);backdrop-filter:blur(3px);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px';
    return el;
  }
  function card(){
    var c=document.createElement('div');
    c.style.cssText='background:#fff;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.35);width:100%;max-width:430px;padding:26px;font-family:inherit;color:#15221b';
    return c;
  }

  function showOpenGate(){
    if(document.getElementById('aioShiftGate'))return;
    var ov=overlay('aioShiftGate'),c=card();
    c.innerHTML='<div style="font-size:26px;margin-bottom:6px">🔓</div>'
      +'<div style="font-size:17px;font-weight:800;margin-bottom:4px">Shift Opening</div>'
      +'<div style="font-size:12.5px;color:#5f6f66;margin-bottom:18px">POS billing shift open hone ke baad hi chalega. Drawer ka opening cash count karke enter karein.</div>'
      +'<label style="display:block;font-size:11px;font-weight:700;color:#5f6f66;margin-bottom:5px">OPENING CASH (PKR)</label>'
      +'<input id="aioOpenCash" type="number" min="0" step="1" value="0" style="width:100%;padding:12px;border:1px solid #d7e0da;border-radius:10px;font-size:16px;font-weight:700;margin-bottom:16px">'
      +'<button id="aioOpenBtn" style="width:100%;padding:13px;border:0;border-radius:10px;background:#e23744;color:#fff;font-weight:800;font-size:14px;cursor:pointer">Open Shift & Start Billing</button>';
    ov.appendChild(c);document.body.appendChild(ov);
    setTimeout(function(){var i=document.getElementById('aioOpenCash');if(i){i.focus();i.select();}},50);
    document.getElementById('aioOpenBtn').onclick=function(){
      this.disabled=true;this.textContent='Opening…';
      var r=req('shift-open',{opening_cash:Number(document.getElementById('aioOpenCash').value||0)});
      if(!r.ok){this.disabled=false;this.textContent='Open Shift & Start Billing';if(typeof toast==='function')toast(r.message||'Failed');return;}
      window.__AIO_SHIFT_ID=r.id;window.__AIO_SHIFT_NO=r.shift_no;
      updateShiftChip(r.shift_no);refreshStrip();
      ov.remove();
      if(typeof toast==='function')toast('Shift '+r.shift_no+' opened');
    };
  }

  function updateShiftChip(no){
    var chips=document.querySelectorAll('header .chip');
    for(var i=0;i<chips.length;i++){
      if(/Shift/i.test(chips[i].textContent)){
        chips[i].innerHTML='<span class="dot"></span>Shift '+no+' <button id="aioCloseShift" style="margin-left:8px;padding:4px 10px;border:1px solid #e23744;background:transparent;color:#e23744;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer">Close</button>';
        var b=document.getElementById('aioCloseShift');
        if(b)b.onclick=showCloseModal;
        return;
      }
    }
  }

  function reportRows(rep){
    var rows=''
      +kv('Shift',rep.shift_no)+kv('Opened',rep.opened_at)
      +(rep.closed_at?kv('Closed',rep.closed_at):'')
      +kv('Opening Cash',money(rep.opening_cash))
      +kv('Orders',rep.orders+(rep.first_bill?(' (Bill '+rep.first_bill+' – '+rep.last_bill+')'):''))
      +kv('Gross Sales',money(rep.gross_sales));
    var bm=rep.by_method||{};
    Object.keys(bm).forEach(function(k){rows+=kv('&nbsp;&nbsp;'+k,money(bm[k].amount)+' ('+bm[k].count+')');});
    rows+=kv('Cash Expenses','− '+money(rep.cash_expenses))
      +kv('<b>Expected Cash</b>','<b>'+money(rep.expected_cash)+'</b>');
    if(typeof rep.actual_cash!=='undefined')
      rows+=kv('Actual Counted',money(rep.actual_cash))
        +kv('<b>Variance</b>','<b style="color:'+(Math.abs(rep.variance)<1?'#28a745':'#e23744')+'">'+money(rep.variance)+'</b>');
    return rows;
    function kv(a,b){return '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed #e4eae6;font-size:13px"><span style="color:#5f6f66">'+a+'</span><span>'+b+'</span></div>'}
  }

  function showCloseModal(){
    var pr=req('shift-preview');
    if(!pr.ok){if(typeof toast==='function')toast(pr.message||'Failed');return;}
    var rep=pr.report;
    var ov=overlay('aioShiftClose'),c=card();
    c.innerHTML='<div style="font-size:17px;font-weight:800;margin-bottom:12px">Shift Closing — '+rep.shift_no+'</div>'
      +'<div style="max-height:44vh;overflow:auto;margin-bottom:14px">'+reportRows(rep)+'</div>'
      +'<label style="display:block;font-size:11px;font-weight:700;color:#5f6f66;margin-bottom:5px">ACTUAL COUNTED CASH (PKR)</label>'
      +'<input id="aioActualCash" type="number" min="0" step="1" value="'+Math.round(rep.expected_cash)+'" style="width:100%;padding:12px;border:1px solid #d7e0da;border-radius:10px;font-size:16px;font-weight:700;margin-bottom:14px">'
      +'<div style="display:flex;gap:10px"><button id="aioCloseCancel" style="flex:1;padding:12px;border:1px solid #d7e0da;background:#fff;border-radius:10px;font-weight:700;cursor:pointer">Cancel</button>'
      +'<button id="aioCloseConfirm" style="flex:2;padding:12px;border:0;border-radius:10px;background:#e23744;color:#fff;font-weight:800;cursor:pointer">Close Shift & Report</button></div>';
    ov.appendChild(c);document.body.appendChild(ov);
    document.getElementById('aioCloseCancel').onclick=function(){ov.remove()};
    document.getElementById('aioCloseConfirm').onclick=function(){
      this.disabled=true;this.textContent='Closing…';
      var r=req('shift-close',{actual_cash:Number(document.getElementById('aioActualCash').value||0)});
      if(!r.ok){this.disabled=false;this.textContent='Close Shift & Report';if(typeof toast==='function')toast(r.message||'Failed');return;}
      ov.remove();window.__AIO_SHIFT_ID='';window.__AIO_SHIFT_NO='';refreshStrip();
      showReport(r.report,true);
    };
  }

  function showReport(rep,thenGate){
    var ov=overlay('aioShiftReport'),c=card();
    c.innerHTML='<div style="font-size:17px;font-weight:800;margin-bottom:2px">Shift Report</div>'
      +'<div style="font-size:12px;color:#5f6f66;margin-bottom:12px">Cashier reconciliation summary</div>'
      +'<div id="aioRepBody" style="max-height:52vh;overflow:auto;margin-bottom:16px">'+reportRows(rep)+'</div>'
      +'<div style="display:flex;gap:10px"><button id="aioRepPrint" style="flex:1;padding:12px;border:1px solid #d7e0da;background:#fff;border-radius:10px;font-weight:700;cursor:pointer">🖨 Print</button>'
      +'<button id="aioRepDone" style="flex:1;padding:12px;border:0;border-radius:10px;background:#15221b;color:#fff;font-weight:800;cursor:pointer">Done</button></div>';
    ov.appendChild(c);document.body.appendChild(ov);
    document.getElementById('aioRepPrint').onclick=function(){
      var w=window.open('','_blank','width=420,height=640');
      w.document.write('<html><head><title>Shift Report '+rep.shift_no+'</title></head><body style="font-family:monospace;font-size:12px;padding:14px"><h3 style="margin:0 0 10px">SHIFT REPORT — '+rep.shift_no+'</h3>'+document.getElementById('aioRepBody').innerHTML+'</body></html>');
      w.document.close();w.print();
    };
    document.getElementById('aioRepDone').onclick=function(){
      ov.remove();
      if(thenGate)showOpenGate();
    };
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
        if(!window.__AIO_SHIFT_ID){showOpenGate();if(typeof toast==='function')toast('Pehle shift open karein');return false;}
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
        if(!window.__AIO_SHIFT_ID){showOpenGate();if(typeof toast==='function')toast('Pehle shift open karein');return false;}
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
