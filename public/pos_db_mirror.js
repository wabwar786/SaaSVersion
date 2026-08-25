
(function(){
  function req(action,payload){
    try{return DBApi.req(action,payload)}
    catch(e){return {ok:false,message:e.message||'Database request failed'}}
  }

  function productBaseName(line){
    try{
      const p=(typeof P!=='undefined'&&Array.isArray(P))?P.find(x=>x.id===line.id):null;
      return p?.name||line.name||'';
    }catch(e){return line.name||''}
  }

  function buildPayload(){
    const t=typeof tots==='function'?tots():{d:0,sc:0,tax:0,total:0};
    const customer=(typeof selectedCustomer!=='undefined'&&selectedCustomer)?selectedCustomer:null;
    return {
      bill_no:String(typeof activeBillNo!=='undefined'?activeBillNo:1).padStart(4,'0'),
      service_mode:typeof orderMode!=='undefined'?orderMode:'Dine In',
      table_name:typeof selectedTable!=='undefined'?(selectedTable||''):'',
      customer_id:customer?.id||'walkin',
      customer_name:customer?.name||'Walk-in Customer',
      customer_phone:customer?.phone||'',
      shift_id:'',
      guest_count:null,
      discount_amount:Number(t.d||0),
      service_charge:Number(t.sc||0),
      tax_amount:Number(t.tax||0),
      received_amount:Number(document.querySelector('#receivedAmount')?.value||t.total||0),
      payment_code:typeof paymentMethod!=='undefined'?paymentMethod:'Cash',
      items:(typeof C!=='undefined'?C:[]).map(i=>({
        menu_item_id:i.id,
        base_name:productBaseName(i),
        name:i.name,
        qty:Number(i.qty||0),
        unit_price:Number(i.unitPrice||0),
        sent_qty:Number(i.sentQty||0),
        note:i.note||''
      }))
    };
  }

  function install(){
    if(window.__V13_POS_DB_MIRROR)return;
    window.__V13_POS_DB_MIRROR=true;

    if(typeof window.markPendingAsSent==='function'){
      const originalKot=window.markPendingAsSent;
      window.markPendingAsSent=function(){
        const payload=buildPayload();
        const r=req('pos-kot',payload);
        if(!r.ok && typeof toast==='function'){
          toast('Kitchen UI ready · DB mirror: '+(r.message||'pending'));
        }
        return originalKot.apply(this,arguments);
      };
    }

    if(typeof window.completeCharge==='function'){
      const originalCharge=window.completeCharge;
      window.completeCharge=function(action='print'){
        if(typeof validateTender==='function' && !validateTender())return false;
        const payload=buildPayload();
        const r=req('pos-finalize',payload);
        if(!r.ok){
          if(typeof toast==='function')toast(r.message||'Bill could not be saved to database');
          return false;
        }
        return originalCharge.apply(this,arguments);
      };
    }
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install);
  else install();
})();
