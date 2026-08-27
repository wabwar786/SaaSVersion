/* ============================================================
   module.js — config-driven page engine.
   DATA LAYER IS OFFLINE-FIRST:
     • Agar local PHP API reachable hai  -> MySQL (ui_records) se
       read/write (yehi cloud ko sync hota hai).
     • Agar API reachable nahi (pure offline / file://) -> localStorage
       par chalta rehta hai, kuch tootta nahi.
   ============================================================ */
(function(){
  function $(s,r){return (r||document).querySelector(s)}
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
  function money(n){return 'PKR '+Number(n||0).toLocaleString('en-PK',{maximumFractionDigits:0})}
  function num(n){return Number(n||0).toLocaleString('en-PK')}
  function sum(rows,f){return rows.reduce(function(a,r){return a+Number(r[f]||0)},0)}
  function count(rows,f,v){return rows.filter(function(r){return r[f]===v}).length}
  function uid(){return 'r-'+Date.now()+'-'+Math.floor(Math.random()*9999)}
  var M={money:money,num:num,sum:sum,count:count,esc:esc};

  /* -------- data adapter: DB-first, localStorage fallback -------- */
  function makeAdapter(cfg){
    var KEY='urban_spoon_'+cfg.storeKey+'_v1';
    var useDB=(location.protocol!=='file:' && typeof window.DBApi!=='undefined');

    function lsGet(){try{var v=localStorage.getItem(KEY);if(v)return JSON.parse(v)}catch(e){}
      var s=JSON.parse(JSON.stringify(cfg.seed||[]));s.forEach(function(r){if(!r.id)r.id=uid()});localStorage.setItem(KEY,JSON.stringify(s));return s}
    function lsSet(l){localStorage.setItem(KEY,JSON.stringify(l));return l}

    function dbList(){
      try{var x=new XMLHttpRequest();x.open('GET','/api.php?action=records-list&module='+encodeURIComponent(cfg.storeKey),false);
        x.setRequestHeader('Accept','application/json');x.send();
        if(x.status===200){var r=JSON.parse(x.responseText||'{}');if(r&&r.ok)return r.rows||[]}}catch(e){}
      return null; // null = API not reachable -> caller falls back
    }
    function dbSave(data){var r=window.DBApi.req('records-save',{module:cfg.storeKey,data:data});return (r&&r.ok)?r.id:null}

    return {
      mode: useDB?'db':'local',
      list:function(){
        if(useDB){
          var rows=dbList();
          if(rows!==null){
            if(rows.length===0 && !localStorage.getItem(KEY+'_seeded')){
              (cfg.seed||[]).forEach(function(s){var c=JSON.parse(JSON.stringify(s));delete c.id;dbSave(c)});
              localStorage.setItem(KEY+'_seeded','1'); rows=dbList()||[];
            }
            return rows;
          }
          this.mode='local'; // API dropped -> offline fallback
        }
        return lsGet();
      },
      create:function(data){
        if(this.mode==='db'){var id=dbSave(data);if(id)return id}
        var l=lsGet();data.id=uid();l.unshift(data);lsSet(l);return data.id;
      },
      update:function(id,data){
        if(this.mode==='db'){data.id=id;if(dbSave(data)!==null)return}
        var l=lsGet();var r=l.find(function(x){return x.id===id});if(r)Object.assign(r,data);lsSet(l);
      },
      /* PEHLE YAHAN KHAMOSH FAILURE THI:
           remove:function(id){ if(this.mode==='db'){dbDelete(id);return} ... }
         Server ka jawab discard ho jata tha. Wastage "delete nahi ho sakti"
         kehta, CSRF token expire ho jata, permission na hoti — UI phir bhi
         "Record removed" dikhata aur reload par row wapas aa jati.
         Ab DB mode mein delete DeleteKit se hota hai (jo jawab parhta hai)
         aur yeh sirf localStorage fallback sambhalta hai. */
      removeLocal:function(id){
        var l=lsGet().filter(function(x){return x.id!==id});lsSet(l);
      }
    };
  }

  function toast(m,err){var t=$('#toast');if(!t){t=document.createElement('div');t.id='toast';document.body.appendChild(t)}
    t.textContent=m;t.className='toast show'+(err?' err':'');clearTimeout(window.__tt);window.__tt=setTimeout(function(){t.className='toast'},1900)}
  function closeM(){document.querySelectorAll('.modal').forEach(function(m){m.classList.remove('show')})}
  function openM(id){$('#'+id).classList.add('show')}

  function fmtCell(row,col){
    var v=row[col.field];
    if(col.format==='money')return money(v);
    if(col.format==='num')return num(v);
    if(col.format==='tag'){var tone=(col.tags&&col.tags[v])||'neutral';return '<span class="tag '+tone+'">'+esc(v)+'</span>'}
    if(col.format==='money_or_clear')return Number(v)>0?'<b style="color:var(--warn)">'+money(v)+'</b>':'<span style="color:var(--ok)">Clear</span>';
    if(col.render)return col.render(row);
    var main=esc(v==null||v===''?'—':v);
    if(col.sub)main='<span class="t-main">'+main+'</span><span class="t-sub">'+esc(row[col.sub]||'')+'</span>';
    return main;
  }

  function render(cfg){
    var db=makeAdapter(cfg), rows=[], editId=null, delId=null;
    var canAdd=cfg.canAdd!==false;

    var kpiHtml=(cfg.kpis||[]).map(function(k){
      return '<div class="kpi '+(k.tone||'')+'"><div class="lab">'+esc(k.label)+'</div>'+
             '<div class="val" data-kpi="'+esc(k.label)+'">—</div>'+
             (k.sub?'<div class="sub">'+esc(k.sub)+'</div>':'')+'</div>';
    }).join('');
    var colHead=(cfg.columns||[]).map(function(c){return '<th'+(c.align==='num'?' class="num"':'')+'>'+esc(c.label)+'</th>'}).join('');

    var content=
      (kpiHtml?'<div class="kpis">'+kpiHtml+'</div>':'')+
      '<section class="panel"><div class="panel-head">'+
        '<div><h2>'+esc(cfg.listTitle||cfg.title)+'</h2>'+(cfg.listSub?'<p>'+esc(cfg.listSub)+'</p>':'')+'</div>'+
        '<span class="spacer"></span>'+
        '<div class="search" style="width:260px"><input id="mSearch" placeholder="'+esc(cfg.searchPlaceholder||'Search…')+'"></div>'+
      '</div><div class="panel-body flush">'+
        '<div class="table-wrap"><table class="table"><thead><tr>'+colHead+
          (canAdd?'<th style="text-align:right">Actions</th>':'')+'</tr></thead><tbody id="mRows"></tbody></table></div>'+
        '<div id="mEmpty" class="empty" style="display:none"><div class="ico">'+(cfg.emptyIcon||'▦')+'</div>'+
          '<h3>Nothing here yet</h3><p>'+esc(cfg.emptyText||'No records match. Add a new one to get started.')+'</p>'+
          (canAdd?'<button class="btn primary" id="mEmptyAdd">'+esc(cfg.addLabel||'+ New')+'</button>':'')+'</div>'+
      '</div></section>';
    $('.content').innerHTML=content;

    var head=$('.header');
    if(canAdd && head && !$('#mNewBtn')){var b=document.createElement('button');b.className='btn primary';b.id='mNewBtn';b.textContent=cfg.addLabel||'+ New';head.appendChild(b);}

    if(canAdd){
      var fieldsHtml=(cfg.fields||[]).map(function(f){
        var wrap='field'+(f.full?' full':'');
        var lab='<span>'+esc(f.label)+(f.required?' <span class="hint">· required</span>':'')+'</span>';
        var inp;
        if(f.type==='select'){inp='<select data-f="'+f.key+'">'+(f.options||[]).map(function(o){return '<option>'+esc(o)+'</option>'}).join('')+'</select>'}
        else if(f.type==='textarea'){inp='<textarea data-f="'+f.key+'" placeholder="'+esc(f.placeholder||'')+'"></textarea>'}
        else{var t=(f.type==='number'||f.type==='money')?'number':(f.type||'text');inp='<input data-f="'+f.key+'" type="'+t+'"'+(f.type==='money'||f.type==='number'?' min="0"':'')+' placeholder="'+esc(f.placeholder||'')+'">'}
        return '<label class="'+wrap+'">'+lab+inp+'</label>';
      }).join('');
      var modal='<div class="modal" id="mForm"><div class="dialog"><div class="dialog-head">'+
        '<div><h3 id="mFormTitle">'+esc(cfg.addLabel||'New')+'</h3><p>'+esc(cfg.formSub||'Fill in the details below')+'</p></div>'+
        '<button class="close" data-close>×</button></div>'+
        '<div class="dialog-body"><div class="form-grid">'+fieldsHtml+'</div></div>'+
        '<div class="dialog-foot"><button class="btn" data-close>Cancel</button><button class="btn primary" id="mSave">Save</button></div></div></div>'+
        '<div class="modal" id="mDel"><div class="dialog" style="width:min(420px,100%)"><div class="dialog-head">'+
        '<div><h3>Remove record?</h3><p id="mDelName"></p></div><button class="close" data-close>×</button></div>'+
        '<div class="dialog-body"><div class="note" style="background:var(--danger-soft);border-color:var(--danger-line);color:var(--danger)">This removes the record from this list.</div></div>'+
        '<div class="dialog-foot"><button class="btn" data-close>Keep</button><button class="btn danger" id="mConfirmDel">Remove</button></div></div></div>';
      var holder=document.createElement('div');holder.innerHTML=modal;document.body.appendChild(holder);
    }
    if(!$('#toast')){var tt=document.createElement('div');tt.className='toast';tt.id='toast';document.body.appendChild(tt)}

    function reload(){rows=db.list()||[];paint();}
    function paint(){
      var q=($('#mSearch').value||'').toLowerCase();
      var f=rows.filter(function(r){if(!q)return true;return (cfg.searchFields||[]).some(function(k){return String(r[k]||'').toLowerCase().indexOf(q)>-1})});
      (cfg.kpis||[]).forEach(function(k){var el=document.querySelector('[data-kpi="'+k.label.replace(/"/g,'')+'"]');if(el)el.textContent=k.calc(rows,M)});
      $('#mEmpty').style.display=f.length?'none':'block';
      $('#mRows').innerHTML=f.map(function(r){
        var tds=(cfg.columns||[]).map(function(c){return '<td'+(c.align==='num'?' class="num"':'')+'>'+fmtCell(r,c)+'</td>'}).join('');
        /* V66 — modules apne extra row buttons de sakte hain (misal
           printers ka Check / Test print). */
        var extra='';
        (cfg.rowActions||[]).forEach(function(a){
          extra+='<button class="btn sm" data-rowact="'+a.action+'" data-rid="'+r.id+'">'+a.label+'</button>';
        });
        var act=canAdd?'<td><div class="row-actions">'+extra+'<button title="Edit" data-edit="'+r.id+'">✎</button><button title="Remove" data-del="'+r.id+'">🗑</button></div></td>':'';
        return '<tr>'+tds+act+'</tr>';
      }).join('');
    }

    function openForm(id){
      editId=id||null;var r=id?rows.find(function(x){return x.id===id}):null;
      $('#mFormTitle').textContent=r?('Edit '+(cfg.recordName||'record')):(cfg.addLabel||'New');
      (cfg.fields||[]).forEach(function(f){var el=document.querySelector('[data-f="'+f.key+'"]');if(!el)return;
        el.value=r?(r[f.key]!=null?r[f.key]:''):(f.default!=null?f.default:'')});
      openM('mForm');var first=document.querySelector('[data-f]');if(first)setTimeout(function(){first.focus()},60);
    }
    function save(){
      var data={},ok=true;
      (cfg.fields||[]).forEach(function(f){var el=document.querySelector('[data-f="'+f.key+'"]');if(!el)return;
        var v=el.value.trim();if(f.required&&!v){ok=false;toast(f.label+' is required',true);el.focus()}
        data[f.key]=(f.type==='number'||f.type==='money')?Number(v||0):v});
      if(!ok)return;
      if(editId){db.update(editId,data);toast((cfg.recordName||'Record')+' updated')}
      else{(cfg.onCreate||function(){})(data);db.create(data);toast((cfg.recordName||'Record')+' added')}
      closeM();reload();
    }

    document.addEventListener('click',function(e){
      if(e.target.closest('#mNewBtn')||e.target.closest('#mEmptyAdd')){openForm();return}
      if(e.target.closest('#mSave')){save();return}
      var c=e.target.closest('[data-close]');if(c){closeM();return}
      var ed=e.target.closest('[data-edit]');if(ed){openForm(ed.getAttribute('data-edit'));return}
      var ra=e.target.closest('[data-rowact]');
      if(ra){
        var act=ra.getAttribute('data-rowact'), rid=ra.getAttribute('data-rid');
        var old=ra.textContent; ra.disabled=true; ra.textContent='...';
        var res=window.DBApi.req(act,{id:rid});
        ra.disabled=false; ra.textContent=old;
        /* Jawab CHECK hota hai — result user tak jata hai, chahe kamyab
           ho ya nakaam, poori wajah ke saath. */
        toast((res&&res.message)?res.message:((res&&res.ok)?'Done':'Request failed'), !(res&&res.ok));
        return;
      }
      var dl=e.target.closest('[data-del]');if(dl){
        delId=dl.getAttribute('data-del');
        var r=rows.find(function(x){return x.id===delId});
        var nm=r?(r[cfg.nameField||'name']||''):'';
        if(db.mode==='db'&&window.DeleteKit){
          /* Asli server delete — natija (aur rukawat ki wajah) user tak. */
          window.DeleteKit.confirm({
            module:cfg.storeKey,id:delId,name:nm,
            what:(cfg.recordName||'Record'),
            title:'Delete '+(cfg.recordName||'record')+'?',
            onDone:function(){reload()}
          });
        }else{
          /* Pure-offline / file:// fallback — localStorage. */
          $('#mDelName').textContent=nm;openM('mDel');
        }
        return;
      }
      if(e.target.closest('#mConfirmDel')){db.removeLocal(delId);closeM();reload();toast((cfg.recordName||'Record')+' removed');return}
    });
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closeM()});
    var srch=$('#mSearch');if(srch)srch.oninput=paint;
    reload();
  }

  window.RestaurantModule={render:render,helpers:M};
})();

/* build: V62 build 2026-08-26 */
