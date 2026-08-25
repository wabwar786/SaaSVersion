
(function(){
  const page=location.pathname.split('/').pop()||'index.html';
  const title=()=>document.querySelector('.header-title h1')?.textContent?.trim()||document.title||'Restaurant';

  const configs={
    'customers.html':[
      ['name','Full Name','text'],['phone','Phone','text'],['type','Customer Type','select',['Regular','VIP','Corporate']]
    ],
    'suppliers.html':[
      ['name','Supplier Name','text'],['phone','Phone','text'],['email','Email','email']
    ],
    'reservations.html':[
      ['name','Customer Name','text'],['phone','Phone','text'],['datetime','Reservation Date / Time','datetime-local'],['guests','Guests','number']
    ],
    'rider_management.html':[
      ['name','Rider Name','text'],['phone','Phone','text'],['vehicle','Vehicle No','text']
    ],
    'tables_floors.html':[
      ['name','Table Name','text'],['area','Area / Floor','text'],['seats','Seats','number']
    ],
    'expenses.html':[
      ['reference','Expense Reference','text'],['category','Category','text'],['amount','Amount','number'],['description','Description','text']
    ],
    'discounts_promotions.html':[
      ['name','Promotion Name','text'],['type','Type','select',['Percent','Fixed','BOGO','Coupon']],['code','Code','text']
    ],
    'printer_devices.html':[
      ['name','Device / Printer Name','text'],['type','Type','select',['KITCHEN','RECEIPT','KDS','POS']],['address','IP / Device ID','text']
    ],
    'staff_roles.html':[
      ['name','Staff Name','text'],['role','Role','select',['Cashier','Waiter','Chef / Kitchen','Storekeeper','Accountant','Rider']],['shift','Shift','text']
    ],
    'shift_management.html':[
      ['reference','Shift No','text'],['amount','Opening Cash','number']
    ],
    'stock_count.html':[
      ['reference','Count Reference','text'],['location','Stock Location','text']
    ],
    'wastage_adjustment.html':[
      ['item','Inventory Item','text'],['qty','Qty','number'],['reason','Reason','text']
    ],
    'whatsapp_notifications.html':[
      ['event','Event / Template','text'],['channel','Channel','select',['WhatsApp','Push','SMS','Email']],['audience','Audience / Recipient','text']
    ],
    'multi_branch.html':[
      ['name','Branch Name','text'],['code','Branch Code','text'],['status','Status','select',['Active','Inactive']]
    ],
    'accounting.html':[
      ['reference','Reference','text'],['debit','Debit','number'],['credit','Credit','number'],['notes','Narration','text']
    ],
    'stock_transfer.html':[
      ['reference','Transfer Reference','text'],['from','From','text'],['to','To','text'],['items','Items','number']
    ],
    'delivery.html':[
      ['reference','Order Reference','text'],['customer','Customer','text'],['area','Area','text'],['amount','Amount','number']
    ],
    'loyalty.html':[
      ['name','Tier / Membership','text'],['requirement','Requirement','text'],['multiplier','Earn Multiplier','number']
    ],
    'orders_management.html':[
      ['reference','Bill / Order Reference','text'],['mode','Mode','select',['Dine In','Takeaway','Home Delivery']],['target','Table / Customer','text']
    ],
    'void_refund.html':[
      ['reference','Bill No','text'],['type','Action','select',['Item Void','Refund']],['reason','Reason','text']
    ],
    'fbr.html':[
      ['reference','Bill No','text'],['action','Action','select',['Queue Invoice','Retry Invoice','Validate']]
    ],
    'menu_management.html':[
      ['name','Category / Item','text'],['type','Type','select',['Category','Item']],['value','Printer / Price','text']
    ]
  };

  const rowDefaults={
    'customers.html':f=>[f.name,f.phone,'0','PKR 0','0','—',f.type],
    'suppliers.html':f=>[f.name,f.phone||'—','0','PKR 0','PKR 0','Today'],
    'reservations.html':f=>[f.datetime?new Date(f.datetime).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}):'—',f.name,f.guests||1,'—','PKR 0','Confirmed'],
    'rider_management.html':f=>[f.name,f.phone||'—','—','PKR 0','AVAILABLE'],
    'tables_floors.html':f=>[f.name,f.area||'Ground Floor',f.seats||2,'AVAILABLE','—','—'],
    'expenses.html':f=>[f.reference||'EXP',f.category||'General','PKR '+Number(f.amount||0).toLocaleString('en-PK'),'Current User','APPROVED'],
    'discounts_promotions.html':f=>[f.name,f.type||'Percent','All','Current','0','Active'],
    'printer_devices.html':f=>[f.name,(f.type||'KITCHEN')+' / —',f.address||'—','Current Branch','Online'],
    'staff_roles.html':f=>[f.name,f.role||'User',f.shift||'—','Active','Standard'],
    'shift_management.html':f=>['Cash', 'PKR 0','PKR '+Number(f.amount||0).toLocaleString('en-PK'),'PKR 0'],
    'stock_count.html':f=>[f.location||'Stock Item','0','0','0','PKR 0','New Count'],
    'wastage_adjustment.html':f=>[f.item||'Inventory Item',f.qty||0,f.reason||'Adjustment','Current User','Pending'],
    'whatsapp_notifications.html':f=>[f.event||'Notification',f.channel==='WhatsApp'?'Enabled':'—',f.channel==='Push'?'Enabled':'—',f.channel==='SMS'?'Enabled':'—',f.audience||'Customer'],
    'multi_branch.html':f=>[f.name,'PKR 0','0','PKR 0','Closed',f.status||'Active'],
    'accounting.html':f=>[new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}),'Manual',f.reference||'JV','PKR '+Number(f.debit||0).toLocaleString('en-PK'),'PKR '+Number(f.credit||0).toLocaleString('en-PK'),'—'],
    'stock_transfer.html':f=>[f.reference||'TRF',f.from||'Current Branch',f.to||'Other Branch',f.items||0,'PKR 0','Requested'],
    'delivery.html':f=>[f.reference||'Order',f.customer||'Customer',f.area||'—','PKR '+Number(f.amount||0).toLocaleString('en-PK'),'Cash','Not Assigned','Waiting'],
    'loyalty.html':f=>[f.name,f.requirement||'—',(f.multiplier||1)+'×','Custom Benefit'],
    'orders_management.html':f=>[f.reference||'Bill',f.mode||'Dine In',f.target||'—','Current User','PKR 0','New','0m'],
    'void_refund.html':f=>[new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}),f.reference||'Bill',f.type||'Item Void','—',f.reason||'—','Current User','Pending'],
    'fbr.html':f=>[f.reference||'Bill','Pending','QUEUED',f.action||'Queue'],
    'menu_management.html':f=>[f.name,(f.type||'Item'),f.value||'—','Active']
  };

  function css(){
    if(document.getElementById('v13-action-style'))return;
    const s=document.createElement('style');
    s.id='v13-action-style';
    s.textContent=`
      .v13-overlay{position:fixed;inset:0;background:rgba(18,32,25,.38);display:none;align-items:center;justify-content:center;padding:18px;z-index:100000}
      .v13-overlay.show{display:flex}
      .v13-dialog{width:min(620px,96vw);max-height:90vh;overflow:auto;background:#fff;border:1px solid #dfe6e2;box-shadow:0 18px 60px rgba(20,40,31,.2)}
      .v13-head{display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border-bottom:1px solid #e5ebe7}
      .v13-head>div{flex:1}.v13-head h3{margin:0;font-size:16px}.v13-head p{margin:4px 0 0;color:#6f7d75;font-size:11px}
      .v13-x{width:34px;height:34px;border:1px solid #dfe6e2;background:#fff;font-size:20px}
      .v13-body{padding:14px 16px}.v13-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
      .v13-field{display:block}.v13-field span{display:block;font-size:10px;color:#6f7d75;margin-bottom:4px}
      .v13-field input,.v13-field select,.v13-field textarea{width:100%;min-height:40px;border:1px solid #dfe6e2;background:#fff;padding:8px 9px;font:inherit}
      .v13-field textarea{min-height:80px;resize:vertical}.v13-full{grid-column:1/-1}
      .v13-foot{display:flex;justify-content:flex-end;gap:7px;padding:12px 16px;border-top:1px solid #e5ebe7}
      .v13-btn{height:38px;border:1px solid #dfe6e2;background:#fff;padding:0 12px;font-weight:700}
      .v13-primary{background:#0b8f5b;color:#fff;border-color:#0b8f5b}
      .v13-detail{display:grid;grid-template-columns:160px 1fr;border-top:1px solid #edf1ef}
      .v13-detail:first-child{border-top:0}.v13-detail b,.v13-detail span{padding:8px 7px;font-size:11px}.v13-detail b{color:#6f7d75;background:#f8faf9}
      @media(max-width:620px){.v13-grid{grid-template-columns:1fr}.v13-full{grid-column:auto}.v13-detail{grid-template-columns:110px 1fr}}
    `;
    document.head.appendChild(s);
  }

  function ensure(){
    css();
    let o=document.getElementById('v13ActionOverlay');
    if(o)return o;
    o=document.createElement('div');
    o.id='v13ActionOverlay';
    o.className='v13-overlay';
    o.innerHTML='<div class="v13-dialog"><div class="v13-head"><div><h3 id="v13Title"></h3><p id="v13Sub"></p></div><button class="v13-x" id="v13Close">×</button></div><div class="v13-body" id="v13Body"></div><div class="v13-foot" id="v13Foot"></div></div>';
    document.body.appendChild(o);
    o.addEventListener('click',e=>{if(e.target===o)close()});
    o.querySelector('#v13Close').onclick=close;
    return o;
  }

  function close(){document.getElementById('v13ActionOverlay')?.classList.remove('show')}

  function openModal(t,sub,body,buttons=''){
    const o=ensure();
    o.querySelector('#v13Title').textContent=t;
    o.querySelector('#v13Sub').textContent=sub||'';
    o.querySelector('#v13Body').innerHTML=body;
    o.querySelector('#v13Foot').innerHTML=buttons;
    o.classList.add('show');
    return o;
  }

  function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}

  function showFilter(){
    const body='<div class="v13-grid"><label class="v13-field v13-full"><span>Search this list</span><input id="v13FilterSearch" placeholder="Type to filter table rows..."></label><label class="v13-field"><span>Status / Text</span><input id="v13FilterStatus" placeholder="e.g. Active, Pending, Cash"></label></div>';
    const o=openModal(title()+' — Filter','Filter current approved demo records.',body,'<button class="v13-btn" id="v13Clear">Clear</button><button class="v13-btn v13-primary" id="v13Apply">Apply Filter</button>');
    o.querySelector('#v13Clear').onclick=()=>{
      document.querySelectorAll('.table tbody tr').forEach(r=>r.style.display='');
      close();
    };
    o.querySelector('#v13Apply').onclick=()=>{
      const q=(o.querySelector('#v13FilterSearch').value+' '+o.querySelector('#v13FilterStatus').value).trim().toLowerCase();
      document.querySelectorAll('.table tbody tr').forEach(r=>{
        r.style.display=!q||r.textContent.toLowerCase().includes(q)?'':'none';
      });
      close();
    };
  }

  function fieldHtml(f){
    const [key,label,type,options]=f;
    if(type==='select'){
      return '<label class="v13-field"><span>'+esc(label)+'</span><select name="'+esc(key)+'">'+(options||[]).map(x=>'<option>'+esc(x)+'</option>').join('')+'</select></label>';
    }
    return '<label class="v13-field"><span>'+esc(label)+'</span><input name="'+esc(key)+'" type="'+esc(type||'text')+'"></label>';
  }

  function persistGenericRow(row){
    try{
      const key='urban_spoon_v13_generic_rows';
      const all=JSON.parse(localStorage.getItem(key)||'{}');
      (all[page]||(all[page]=[])).push(row);
      localStorage.setItem(key,JSON.stringify(all));
    }catch(e){}
  }

  function appendRow(row){
    const tbody=document.querySelector('.table tbody');
    if(!tbody||!Array.isArray(row))return;
    const cols=document.querySelectorAll('.table thead th').length||row.length;
    const tr=document.createElement('tr');
    for(let i=0;i<cols;i++){
      const td=document.createElement('td');
      td.textContent=row[i]??'—';
      tr.appendChild(td);
    }
    tbody.prepend(tr);
  }

  function restoreRows(){
    try{
      const all=JSON.parse(localStorage.getItem('urban_spoon_v13_generic_rows')||'{}');
      (all[page]||[]).slice().reverse().forEach(appendRow);
    }catch(e){}
  }

  function showNew(){
    const cfg=configs[page]||[['reference','Reference / Name','text'],['notes','Notes','text']];
    const body='<div class="v13-grid">'+cfg.map(fieldHtml).join('')+'</div>';
    const o=openModal(title()+' — New','Create a new record without changing the approved page layout.',body,'<button class="v13-btn" id="v13Cancel">Cancel</button><button class="v13-btn v13-primary" id="v13Save">Save</button>');
    o.querySelector('#v13Cancel').onclick=close;
    o.querySelector('#v13Save').onclick=()=>{
      const fields={};
      o.querySelectorAll('[name]').forEach(x=>fields[x.name]=x.value);
      let r={ok:false};
      try{if(window.DBApi)r=DBApi.req('module-demo-create',{page,fields})}catch(e){}
      const row=Array.isArray(r?.row)?r.row:(rowDefaults[page]?rowDefaults[page](fields):Object.values(fields));
      appendRow(row);
      persistGenericRow(row);
      close();
      if(typeof window.toast==='function')toast(r?.ok===false?'Saved in UI demo · DB action queued':'Saved');
    };
  }

  function showInfo(label){
    openModal(title(),label||'Action opened','<div style="font-size:12px;line-height:1.6;color:#46554d">This approved workflow is active. Database-linked operational modules continue to save through the local PHP/MySQL layer.</div>','<button class="v13-btn v13-primary" id="v13Done">Done</button>');
    document.getElementById('v13Done').onclick=close;
  }

  function showRowDetails(tr){
    const heads=[...document.querySelectorAll('.table thead th')].map(x=>x.textContent.trim());
    const cells=[...tr.children].map(x=>x.textContent.trim());
    const body=cells.map((v,i)=>'<div class="v13-detail"><b>'+esc(heads[i]||('Field '+(i+1)))+'</b><span>'+esc(v)+'</span></div>').join('');
    openModal(title()+' — Details','Selected record',body,'<button class="v13-btn v13-primary" id="v13Done">Close</button>');
    document.getElementById('v13Done').onclick=close;
  }

  document.addEventListener('click',e=>{
    const demo=e.target.closest('[data-demo]');
    if(demo){
      e.preventDefault();
      e.stopImmediatePropagation();
      const txt=(demo.textContent||'').trim().toLowerCase();
      if(txt.includes('filter'))showFilter();
      else if(txt.includes('new'))showNew();
      else showInfo(demo.dataset.demo);
      return;
    }

    if(page==='online_orders.html'){
      const b=e.target.closest('button');
      if(!b)return;
      const t=b.textContent.trim();
      if(t==='Auto Accept: Off'){b.textContent='Auto Accept: On';showInfo('Auto Accept enabled for the UI workflow.');return}
      if(t==='Auto Accept: On'){b.textContent='Auto Accept: Off';showInfo('Auto Accept disabled.');return}
      if(t==='Accept'){b.textContent='Accepted';b.disabled=true;showInfo('Online order accepted and ready for kitchen workflow.');return}
      if(t==='Reject'){b.textContent='Rejected';b.disabled=true;showInfo('Online order rejected.');return}
      if(t==='Assign Rider'){b.textContent='Rider Assigned';b.disabled=true;showInfo('Rider assignment completed for this demo order.');return}
    }

    if(page==='customer_web_qr.html'){
      const b=e.target.closest('button');
      if(!b)return;
      const t=b.textContent.trim();
      if(t==='Print QR Sheets'){window.print();return}
      if(t==='Preview'){showInfo('Customer QR ordering preview opened.');return}
      if(t==='Generate QRs'){showInfo('QR codes generated for active restaurant tables.');return}
    }

    if(!document.querySelector('.modal') && !document.querySelector('.sheet')){
      const tr=e.target.closest('.table tbody tr');
      if(tr && !e.target.closest('button,a,input,select,textarea')){
        showRowDetails(tr);
      }
    }
  },true);

  document.addEventListener('keydown',e=>{
    if(e.key==='Escape'&&document.getElementById('v13ActionOverlay')?.classList.contains('show'))close();
  });

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',restoreRows);
  else restoreRows();
})();

/* build: V17.1 build 2026-08-25 */
