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

    $('.content').innerHTML =
      (kpiHtml ? '<div class="kpis">' + kpiHtml + '</div>' : '') +
      (cfg.note ? '<div class="note" style="margin-bottom:14px">' + cfg.note + '</div>' : '') +
      '<section class="panel"><div class="panel-head">' +
      '<div><h2>' + esc(cfg.listTitle || cfg.title) + '</h2>' + (cfg.listSub ? '<p>' + esc(cfg.listSub) + '</p>' : '') + '</div>' +
      '<span class="spacer"></span>' +
      '<div class="search" style="width:280px"><input id="mSearch" placeholder="' + esc(cfg.searchPlaceholder || 'Search…') + '"></div>' +
      '</div><div class="panel-body flush">' +
      '<div class="table-wrap"><table class="table"><thead><tr>' + colHead +
      (canAdd ? '<th style="text-align:right">Actions</th>' : '') + '</tr></thead><tbody id="mRows"></tbody></table></div>' +
      '<div id="mEmpty" class="empty" style="display:none"><div class="ico">' + (cfg.emptyIcon || '▦') + '</div>' +
      '<h3>Nothing here yet</h3><p>' + esc(cfg.emptyText || 'No records match. Add a new one to get started.') + '</p>' +
      (canAdd ? '<button class="btn primary" id="mEmptyAdd">' + esc(cfg.addLabel || '+ New') + '</button>' : '') + '</div>' +
      '</div></section>';

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
          var opts = typeof f.options === 'function' ? f.options() : (f.options || []);
          inp = '<select data-f="' + f.key + '">' + opts.map(function (o) {
            return typeof o === 'object' ? '<option value="' + esc(o.value) + '">' + esc(o.label) + '</option>'
              : '<option>' + esc(o) + '</option>';
          }).join('') + '</select>';
        }
        else if (f.type === 'textarea') { inp = '<textarea data-f="' + f.key + '" placeholder="' + esc(f.placeholder || '') + '"></textarea>'; }
        else {
          var t = (f.type === 'number' || f.type === 'money') ? 'number' : (f.type || 'text');
          inp = '<input data-f="' + f.key + '" type="' + t + '"' +
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
        '<div class="dialog-foot"><button class="btn" data-close>Cancel</button><button class="btn primary" id="mSave">Save</button></div></div></div>' +
        '<div class="modal" id="mDel"><div class="dialog" style="width:min(420px,100%)"><div class="dialog-head">' +
        '<div><h3>Remove record?</h3><p id="mDelName"></p></div><button class="close" data-close>×</button></div>' +
        '<div class="dialog-body"><div class="note" style="background:var(--danger-soft);border-color:var(--danger-line);color:var(--danger)">' +
        'This removes the record from this list.</div></div>' +
        '<div class="dialog-foot"><button class="btn" data-close>Keep</button><button class="btn danger" id="mConfirmDel">Remove</button></div></div></div>';
      document.body.appendChild(holder);
    }
    if (!$('#toast')) { var tt = document.createElement('div'); tt.className = 'toast'; tt.id = 'toast'; document.body.appendChild(tt); }

    function reload() { rows = db.list() || []; paint(); }
    function paint() {
      var q = ($('#mSearch').value || '').toLowerCase();
      var f = rows.filter(function (r) {
        if (!q) return true;
        return (cfg.searchFields || []).some(function (k) { return String(r[k] || '').toLowerCase().indexOf(q) > -1; });
      });
      (cfg.kpis || []).forEach(function (k) {
        var el = document.querySelector('[data-kpi="' + k.label.replace(/"/g, '') + '"]');
        if (el) el.textContent = k.calc(rows, M);
      });
      $('#mEmpty').style.display = f.length ? 'none' : 'block';
      $('#mRows').innerHTML = f.map(function (r) {
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
        el.value = r ? (r[f.key] != null ? r[f.key] : '') : (f.default != null ? f.default : '');
      });
      openM('mForm');
      var first = document.querySelector('[data-f]');
      if (first) setTimeout(function () { first.focus(); }, 60);
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
    var s = $('#mSearch'); if (s) s.oninput = paint;
    reload();
  }

  window.RetailModule = { render: render, helpers: M, toast: toast };
})();
