/* ============================================================
   module.js — config-driven page engine (Retail).
   Ek config object se KPIs, table, search aur add/edit form
   ban jate hain. Data RetailStore (localStorage) se aata hai.

   Layer 2 mein sirf adapter badlega (records-list / records-save
   ki jagah ModuleBridge). Screens aur config waise ke waise.
   ============================================================ */
(function () {
  function $(s, r) { return (r || document).querySelector(s); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function money(n) { return window.Region ? Region.money(n) : Number(n || 0).toLocaleString(); }
  function num(n) { return window.Region ? Region.num(n) : Number(n || 0).toLocaleString(); }
  function qty(n) { return window.Region ? Region.qty(n) : String(n); }
  function sum(rows, f) { return rows.reduce(function (a, r) { return a + Number(r[f] || 0); }, 0); }
  function count(rows, f, v) { return rows.filter(function (r) { return r[f] === v; }).length; }
  function uid() { return 'r-' + Date.now() + '-' + Math.floor(Math.random() * 9999); }
  var M = { money: money, num: num, qty: qty, sum: sum, count: count, esc: esc, store: window.RetailStore };

  /* Server par ho to seedha MySQL (records-* endpoints RetailCatalog par
     jate hain). File:// ya login ke baghair -> localStorage demo.
     Screens dono halaton mein bilkul same hain. */
  function adapter(cfg) {
    var k = cfg.storeKey;
    var mod = cfg.key || k;
    var online = !!(window.RetailStore && RetailStore.online);

    if (online) {
      return {
        list: function () {
          RetailStore.invalidate(k);
          return RetailStore.get(k);
        },
        create: function (d) {
          var r = RetailStore.api('records-save', { module: mod, data: d });
          RetailStore.invalidate(k);
          return r.id;
        },
        update: function (id, d) {
          d.id = id;
          RetailStore.api('records-save', { module: mod, data: d });
          RetailStore.invalidate(k);
        },
        remove: function (id) {
          RetailStore.api('records-delete', { module: mod, id: id });
          RetailStore.invalidate(k);
        }
      };
    }
    return {
      list: function () { return RetailStore.get(k); },
      create: function (d) { var l = RetailStore.get(k); d.id = uid(); l.unshift(d); RetailStore.set(k, l); return d.id; },
      update: function (id, d) {
        var l = RetailStore.get(k);
        l.forEach(function (r) { if (r.id === id) Object.assign(r, d); });
        RetailStore.set(k, l);
      },
      remove: function (id) { RetailStore.set(k, RetailStore.get(k).filter(function (r) { return r.id !== id; })); }
    };
  }

  /* select ke options banao (function ya array dono chalte hain) */
  function selectOptions(f, selected) {
    var opts = typeof f.options === 'function' ? f.options() : (f.options || []);
    return opts.map(function (o) {
      var v = (typeof o === 'object') ? o.value : o;
      var l = (typeof o === 'object') ? o.label : o;
      return '<option value="' + esc(v) + '"' + (String(v) === String(selected) ? ' selected' : '') + '>' + esc(l) + '</option>';
    }).join('');
  }

  function toast(m, err) {
    var t = $('#toast');
    if (!t) { t = document.createElement('div'); t.id = 'toast'; document.body.appendChild(t); }
    t.textContent = m; t.className = 'toast show' + (err ? ' err' : '');
    clearTimeout(window.__tt); window.__tt = setTimeout(function () { t.className = 'toast'; }, 1900);
  }
  function closeM() { document.querySelectorAll('.modal').forEach(function (m) { m.classList.remove('show'); }); }
  function openM(id) { $('#' + id).classList.add('show'); }

  function fmtCell(row, col) {
    if (col.render) return col.render(row, M);
    var v = row[col.field];
    if (col.format === 'money') return money(v);
    if (col.format === 'num') return num(v);
    if (col.format === 'qty') return qty(v);
    if (col.format === 'tag') { var tone = (col.tags && col.tags[v]) || 'neutral'; return '<span class="tag ' + tone + '">' + esc(v) + '</span>'; }
    if (col.format === 'money_or_clear') return Number(v) > 0 ? '<b style="color:var(--warn)">' + money(v) + '</b>' : '<span style="color:var(--ok)">Clear</span>';
    var main = esc(v == null || v === '' ? '—' : v);
    if (col.sub) main = '<span class="t-main">' + main + '</span><span class="t-sub">' + esc(typeof col.sub === 'function' ? col.sub(row) : (row[col.sub] || '')) + '</span>';
    return main;
  }

  function render(cfg) {
    var db = adapter(cfg), rows = [], editId = null, delId = null;
    var canAdd = cfg.canAdd !== false;

    var kpiHtml = (cfg.kpis || []).map(function (k) {
      return '<div class="kpi ' + (k.tone || '') + '"><div class="lab">' + esc(k.label) + '</div>' +
        '<div class="val" data-kpi="' + esc(k.label) + '">—</div>' +
        (k.sub ? '<div class="sub">' + esc(k.sub) + '</div>' : '') + '</div>';
    }).join('');
    var colHead = (cfg.columns || []).map(function (c) { return '<th' + (c.align === 'num' ? ' class="num"' : '') + '>' + esc(c.label) + '</th>'; }).join('');

    /* Filters — har list screen apne filters config kar sakti hai.
       Bare catalog par sirf search kaafi nahi hoti: "Bakery ka low stock"
       dhoondna search se mumkin hi nahi tha. */
    var filterHtml = (cfg.filters || []).map(function (f) {
      return '<select data-flt="' + f.key + '" style="width:auto;min-width:150px">' +
        '<option value="">' + esc(f.label) + ': sab</option></select>';
    }).join('');

    $('.content').innerHTML =
      (kpiHtml ? '<div class="kpis">' + kpiHtml + '</div>' : '') +
      (cfg.note ? '<div class="note" style="margin-bottom:14px">' + cfg.note + '</div>' : '') +
      '<section class="panel"><div class="panel-head" style="flex-wrap:wrap;gap:8px">' +
      '<div><h2>' + esc(cfg.listTitle || cfg.title) + '</h2>' + (cfg.listSub ? '<p>' + esc(cfg.listSub) + '</p>' : '') + '</div>' +
      '<span class="spacer"></span>' +
      filterHtml +
      '<div class="search" style="width:250px"><input id="mSearch" placeholder="' + esc(cfg.searchPlaceholder || 'Search…') + '"></div>' +
      '</div><div class="panel-body flush">' +
      '<div class="table-wrap"><table class="table"><thead><tr>' + colHead +
      (canAdd ? '<th style="text-align:right">Actions</th>' : '') + '</tr></thead><tbody id="mRows"></tbody></table></div>' +
      '<div id="mEmpty" class="empty" style="display:none"><div class="ico">' + (cfg.emptyIcon || '▦') + '</div>' +
      '<h3>Kuch nahi mila</h3><p>' + esc(cfg.emptyText || 'Filter badal kar dekhein ya naya record add karein.') + '</p>' +
      (canAdd ? '<button class="btn primary" id="mEmptyAdd">' + esc(cfg.addLabel || '+ New') + '</button>' : '') + '</div>' +
      '<div id="mPager" style="display:flex;align-items:center;gap:10px;padding:11px 14px;border-top:1px solid var(--line)">' +
      '<span id="mCount" style="color:var(--muted);font-size:12px"></span>' +
      '<span style="flex:1"></span>' +
      '<button class="btn sm" id="mPrev">← Pichla</button>' +
      '<span id="mPageNo" style="font-size:12px;font-weight:600"></span>' +
      '<button class="btn sm" id="mNext">Agla →</button>' +
      '<select id="mPageSize" style="width:auto"><option>50</option><option>100</option><option>200</option></select>' +
      '</div></div></section>';

    var head = $('.header');
    if (canAdd && head && !$('#mNewBtn')) {
      var b = document.createElement('button');
      b.className = 'btn primary'; b.id = 'mNewBtn'; b.textContent = cfg.addLabel || '+ New';
      head.appendChild(b);
    }

    if (canAdd) {
      var fieldsHtml = (cfg.fields || []).map(function (f) {
        var lab = '<span>' + esc(f.label) + (f.required ? ' <span class="hint">· required</span>' : '') +
          (f.hint ? ' <span class="hint">· ' + esc(f.hint) + '</span>' : '') + '</span>';
        var inp;
        if (f.type === 'select') {
          inp = '<select data-f="' + f.key + '">' + selectOptions(f) + '</select>';
          /* addable = is dropdown ke saath "+" aata hai. Naya department
             ya brand banane ke liye product form chhorna na pare —
             counter par item banate waqt yehi sab se zyada rukawat thi. */
          if (f.addable) {
            inp = '<div style="display:flex;gap:6px">' + inp +
              '<button type="button" class="btn icon" data-addopt="' + f.key + '" ' +
              'data-mod="' + f.addable + '" title="Naya add karein" ' +
              'style="flex-shrink:0;width:38px">+</button></div>';
          }
        }
        else if (f.type === 'textarea') { inp = '<textarea data-f="' + f.key + '" placeholder="' + esc(f.placeholder || '') + '"></textarea>'; }
        else {
          var t = (f.type === 'number' || f.type === 'money') ? 'number' : (f.type || 'text');
          inp = '<input data-f="' + f.key + '" type="' + t + '"' +
            (f.autoFrom ? ' data-auto="' + f.autoFrom + '"' : '') +
            (f.type === 'money' || f.type === 'number' ? ' step="' + (f.step || 'any') + '" min="0"' : '') +
            ' placeholder="' + esc(f.placeholder || '') + '">';
        }
        return '<label class="field' + (f.full ? ' full' : '') + '">' + lab + inp + '</label>';
      }).join('');

      var holder = document.createElement('div');
      holder.innerHTML =
        '<div class="modal" id="mForm"><div class="dialog"' + (cfg.wideForm ? ' style="width:min(760px,100%)"' : '') + '><div class="dialog-head">' +
        '<div><h3 id="mFormTitle">' + esc(cfg.addLabel || 'New') + '</h3><p>' + esc(cfg.formSub || 'Fill in the details below') + '</p></div>' +
        '<button class="close" data-close>×</button></div>' +
        '<div class="dialog-body"><div class="form-grid">' + fieldsHtml + '</div></div>' +
        '<div class="dialog-foot"><button class="btn" data-close>Cancel</button>' +
        '<button class="btn primary" id="mSave">Save</button></div></div></div>' +
        '<div class="modal" id="mDel"><div class="dialog" style="width:min(420px,100%)"><div class="dialog-head">' +
        '<div><h3>Remove record?</h3><p id="mDelName"></p></div><button class="close" data-close>×</button></div>' +
        '<div class="dialog-body"><div class="note" style="background:var(--danger-soft);border-color:var(--danger-line);color:var(--danger)">' +
        'This removes the record from this list.</div></div>' +
        '<div class="dialog-foot"><button class="btn" data-close>Keep</button><button class="btn danger" id="mConfirmDel">Remove</button></div></div></div>';
      document.body.appendChild(holder);
    }
    if (!$('#toast')) { var tt = document.createElement('div'); tt.className = 'toast'; tt.id = 'toast'; document.body.appendChild(tt); }

    var page = 1, pageSize = 50;

    function fillFilters() {
      (cfg.filters || []).forEach(function (f) {
        var el = document.querySelector('[data-flt="' + f.key + '"]');
        if (!el) return;
        var cur = el.value;
        var opts = typeof f.options === 'function' ? f.options(rows) : (f.options || []);
        el.innerHTML = '<option value="">' + esc(f.label) + ': sab</option>' +
          opts.map(function (o) {
            var v = (typeof o === 'object') ? o.value : o;
            var l = (typeof o === 'object') ? o.label : o;
            return '<option value="' + esc(v) + '"' + (String(v) === String(cur) ? ' selected' : '') + '>' + esc(l) + '</option>';
          }).join('');
      });
    }

    function reload() { rows = db.list() || []; fillFilters(); paint(); }

    function paint() {
      var q = ($('#mSearch').value || '').toLowerCase();
      var f = rows.filter(function (r) {
        if (q && !(cfg.searchFields || []).some(function (k) { return String(r[k] || '').toLowerCase().indexOf(q) > -1; })) return false;
        /* har active filter ka apna test */
        return (cfg.filters || []).every(function (flt) {
          var el = document.querySelector('[data-flt="' + flt.key + '"]');
          var v = el ? el.value : '';
          if (!v) return true;
          return flt.test ? flt.test(r, v) : String(r[flt.key] || '') === v;
        });
      });
      (cfg.kpis || []).forEach(function (k) {
        var el = document.querySelector('[data-kpi="' + k.label.replace(/"/g, '') + '"]');
        if (el) el.textContent = k.calc(rows, M);
      });
      $('#mEmpty').style.display = f.length ? 'none' : 'block';

      /* Pagination — 50 per page default. Pehle poori list ek saath
         render hoti thi; 50,000 products par browser hi jam jata. */
      var pages = Math.max(1, Math.ceil(f.length / pageSize));
      if (page > pages) page = pages;
      var from = (page - 1) * pageSize;
      var slice = f.slice(from, from + pageSize);
      $('#mPager').style.display = f.length ? 'flex' : 'none';
      $('#mCount').textContent = f.length
        ? (from + 1) + '–' + (from + slice.length) + ' of ' + f.length +
          (f.length !== rows.length ? ' (filtered from ' + rows.length + ')' : '')
        : '';
      $('#mPageNo').textContent = 'Page ' + page + ' / ' + pages;
      $('#mPrev').disabled = page <= 1;
      $('#mNext').disabled = page >= pages;

      $('#mRows').innerHTML = slice.map(function (r) {
        var tds = (cfg.columns || []).map(function (c) { return '<td' + (c.align === 'num' ? ' class="num"' : '') + '>' + fmtCell(r, c) + '</td>'; }).join('');
        var act = canAdd ? '<td><div class="row-actions"><button title="Edit" data-edit="' + r.id + '">✎</button>' +
          '<button title="Remove" data-del="' + r.id + '">🗑</button></div></td>' : '';
        return '<tr>' + tds + act + '</tr>';
      }).join('');
    }

    function openForm(id) {
      editId = id || null;
      var r = id ? rows.filter(function (x) { return x.id === id; })[0] : null;
      $('#mFormTitle').textContent = r ? ('Edit ' + (cfg.recordName || 'record')) : (cfg.addLabel || 'New');
      (cfg.fields || []).forEach(function (f) {
        var el = document.querySelector('[data-f="' + f.key + '"]');
        if (!el) return;
        var val = r ? (r[f.key] != null ? r[f.key] : '') : (f.default != null ? f.default : '');
        /* Product ke barcodes alag table mein hain (ek product ke kai ho
           sakte hain). Edit form ek hi dikhata hai — pehla. Warna edit
           par barcode khali aata aur save karne par gum ho jata. */
        if (f.key === 'barcode' && r && Array.isArray(r.barcodes)) val = r.barcodes[0] || '';
        /* Dropdowns har dafa taza — "+" se jo abhi add hua wo bhi dikhe */
        if (f.type === 'select' && typeof f.options === 'function') el.innerHTML = selectOptions(f, val);
        el.value = val;
      });
      /* MRP jaise fields: source badle to sath chalein — jab tak user ne
         khud haath na lagaya ho. Zyadatar dukanon mein MRP aur sale
         price ek hi hoti hai, is liye dobara likhwana faltu kaam hai. */
      document.querySelectorAll('[data-auto]').forEach(function (tgt) {
        var srcKey = tgt.getAttribute('data-auto');
        var src = document.querySelector('[data-f="' + srcKey + '"]');
        if (!src) return;
        tgt.dataset.touched = (r && Number(r[tgt.getAttribute('data-f')]) &&
                               Number(r[tgt.getAttribute('data-f')]) !== Number(r[srcKey])) ? '1' : '';
        tgt.oninput = function () { this.dataset.touched = '1'; };
        src.oninput = function () { if (!tgt.dataset.touched) tgt.value = this.value; };
      });

      openM('mForm');
      var first = document.querySelector('[data-f]');
      if (first) setTimeout(function () { first.focus(); first.select && first.select(); }, 60);
    }

    function save() {
      var data = {}, ok = true;
      (cfg.fields || []).forEach(function (f) {
        var el = document.querySelector('[data-f="' + f.key + '"]');
        if (!el) return;
        var v = String(el.value).trim();
        if (f.required && !v) { ok = false; toast(f.label + ' is required', true); el.focus(); }
        data[f.key] = (f.type === 'number' || f.type === 'money') ? Number(v || 0) : v;
      });
      if (!ok) return;
      try {
        if (editId) { db.update(editId, data); toast((cfg.recordName || 'Record') + ' updated'); }
        else { (cfg.onCreate || function () { })(data); db.create(data); toast((cfg.recordName || 'Record') + ' added'); }
      } catch (e) { toast(e.message || 'Could not save', true); return; }
      closeM(); reload();
    }

    document.addEventListener('click', function (e) {
      /* dropdown ke saath wala "+" */
      var ao = e.target.closest('[data-addopt]');
      if (ao) {
        var fkey = ao.getAttribute('data-addopt'), mod = ao.getAttribute('data-mod');
        var name = prompt('Naya ' + mod.replace(/s$/, '') + ' ka naam:');
        if (!name || !name.trim()) return;
        var newId;
        try {
          if (window.RetailStore && RetailStore.online) {
            newId = RetailStore.api('records-save', { module: mod, data: { name: name.trim() } }).id;
            RetailStore.invalidate(mod === 'uom' ? 'units' : mod);
          } else {
            var coll = (mod === 'uom') ? 'units' : mod;
            var rows = RetailStore.get(coll);
            newId = 'r-' + Date.now();
            rows.unshift({ id: newId, name: name.trim() });
            RetailStore.set(coll, rows);
          }
        } catch (er) { toast(er.message || 'Add nahi hua', true); return; }
        var fld = (cfg.fields || []).filter(function (x) { return x.key === fkey; })[0];
        var sel = document.querySelector('[data-f="' + fkey + '"]');
        if (fld && sel) sel.innerHTML = selectOptions(fld, newId);
        toast(name.trim() + ' add ho gaya');
        return;
      }
      if (e.target.closest('#mNewBtn') || e.target.closest('#mEmptyAdd')) { openForm(); return; }
      if (e.target.closest('#mSave')) { save(); return; }
      if (e.target.closest('[data-close]')) { closeM(); return; }
      var ed = e.target.closest('[data-edit]'); if (ed) { openForm(ed.getAttribute('data-edit')); return; }
      var dl = e.target.closest('[data-del]');
      if (dl) {
        delId = dl.getAttribute('data-del');
        var r = rows.filter(function (x) { return x.id === delId; })[0];
        $('#mDelName').textContent = r ? (r[cfg.nameField || 'name'] || '') : '';
        openM('mDel'); return;
      }
      if (e.target.closest('#mConfirmDel')) {
        try { db.remove(delId); } catch (er) { toast(er.message || 'Could not remove', true); return; }
        closeM(); reload(); toast((cfg.recordName || 'Record') + ' removed'); return;
      }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeM(); });
    var s = $('#mSearch'); if (s) s.oninput = function () { page = 1; paint(); };
    document.querySelectorAll('[data-flt]').forEach(function (el) {
      el.onchange = function () { page = 1; paint(); };
    });
    if ($('#mPrev')) $('#mPrev').onclick = function () { if (page > 1) { page--; paint(); window.scrollTo(0, 0); } };
    if ($('#mNext')) $('#mNext').onclick = function () { page++; paint(); window.scrollTo(0, 0); };
    if ($('#mPageSize')) $('#mPageSize').onchange = function () { pageSize = Number(this.value) || 50; page = 1; paint(); };
    reload();
  }

  window.RetailModule = { render: render, helpers: M, toast: toast };
})();
