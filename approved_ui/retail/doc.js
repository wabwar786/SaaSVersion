/* ============================================================
   doc.js — RETAIL KE DOCUMENTS KA ENGINE.   (V154)

   KYUN ALAG ENGINE
   `module.js` ek row = ek record wali screens banata hai: supplier,
   brand, department. Us shakal mein GRN nahi banta. GRN ek HEADER hai
   (supplier, tareekh, bill number) jis ke neeche KAI LINES hoti hain
   (kaun sa maal, kitna, kis bhao). Us ko ek-row wale form mein thoosne
   ki koshish hi wo wajah thi ke yeh paanch screens `ui_records` ke blob
   par chhor di gayi thin.

   EK ENGINE, PAANCH SCREEN: GRN, Purchase Return, Returns/Void,
   Stock Count, Purchase Order. Config `doc_config.js` mein hai.

   DO HALATEIN
       DRAFT   -> banti rehti hai, stock ko kuch nahi hota
       POSTED  -> yahan stock hilta hai; is ke baad badalti nahi

   Draft ka faida: aadha bhara hua form, ghalat qty, counter par rush —
   in mein se koi stock kharab nahi kar sakta. Jab line-by-line milan ho
   jaye, tab Post.

   POST HO CHUKA DOCUMENT WAPAS NAHI HOTA — jaan boojh kar. Us waqt tak
   stock hil chuka, cost badal chuki aur supplier ka khata chal chuka
   hota hai. "Undo" aisa hisaab banata hai jis par kabhi bharosa na ho.
   Ghalti ho to ulta document post karein; dukan ka register bhi yehi
   karta hai aur dono entries nazar aati hain.

   SIRF RETAIL. Yeh file `approved_ui/retail/` mein hai aur restaurant
   ki koi screen is ko load nahi karti.
   ============================================================ */
(function () {
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function money(n) { return window.Region ? Region.money(n) : Number(n || 0).toLocaleString(); }
  function qty(n) { return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 3 }); }
  function today() { return new Date().toISOString().slice(0, 10); }

  function toast(m, err) {
    var t = $('#toast');
    if (!t) { t = document.createElement('div'); t.id = 'toast'; document.body.appendChild(t); }
    t.textContent = m; t.className = 'toast show' + (err ? ' err' : '');
    clearTimeout(window.__dt); window.__dt = setTimeout(function () { t.className = 'toast'; }, 2200);
  }
  function api(action, payload) {
    if (!window.RetailStore || !RetailStore.api) throw new Error('No server connection');
    return RetailStore.api(action, payload);
  }
  function openM(id) { var m = $('#' + id); if (m) m.classList.add('show'); }
  function closeM() { $$('.modal').forEach(function (m) { m.classList.remove('show'); }); }

  var STATUS_TAG = {
    DRAFT: '<span class="tag warn">Draft</span>',
    POSTED: '<span class="tag ok">Posted</span>',
    CANCELLED: '<span class="tag neutral">Cancelled</span>'
  };

  window.RetailDoc = {
    render: function (cfg) {
      if (!cfg) { console.warn('doc.js: no config'); return; }

      var rows = [], lines = [], current = null;
      /* Count screen par qty ka matlab "shelf par kitna mila" hai, baqi
         sab par "kitna aaya/gaya". Yeh farq poore engine mein sirf is
         ek jhande se chalta hai. */
      var isCount = cfg.type === 'CNT';
      var priced = cfg.priced !== false;      // PO/GRN/PRET/RET par rate hota hai

      /* ---------------- page ---------------- */
      $('.content').innerHTML =
        (cfg.note ? '<div class="note" style="margin-bottom:14px">' + cfg.note + '</div>' : '') +
        '<div class="kpis">' +
        '<div class="kpi"><div class="lab">' + esc(cfg.kpiCount || 'Documents') + '</div><div class="val" id="kCount">—</div></div>' +
        '<div class="kpi warn"><div class="lab">Draft</div><div class="val" id="kDraft">—</div></div>' +
        (priced ? '<div class="kpi ok"><div class="lab">' + esc(cfg.kpiValue || 'Posted value') + '</div><div class="val" id="kValue">—</div></div>' : '') +
        '</div>' +
        '<section class="panel"><div class="panel-head" style="flex-wrap:wrap;gap:8px">' +
        '<div><h2>' + esc(cfg.listTitle || cfg.title) + '</h2>' +
        (cfg.listSub ? '<p>' + esc(cfg.listSub) + '</p>' : '') + '</div>' +
        '<span class="spacer"></span>' +
        '<select id="dFilter" style="width:auto;min-width:150px">' +
        '<option value="">All</option><option value="DRAFT">Draft</option>' +
        '<option value="POSTED">Posted</option><option value="CANCELLED">Cancelled</option></select>' +
        '<div class="search" style="width:220px"><input id="dSearch" placeholder="Search number or party"></div>' +
        '</div><div class="panel-body flush"><div class="table-wrap"><table class="table"><thead><tr>' +
        '<th>Number</th><th>Date</th><th>' + esc(cfg.partyLabel || 'Reference') + '</th>' +
        '<th class="num">Items</th>' + (priced ? '<th class="num">Value</th>' : '') +
        '<th>Status</th><th style="text-align:right">Actions</th></tr></thead>' +
        '<tbody id="dRows"></tbody></table></div>' +
        '<div id="dEmpty" class="empty" style="display:none"><div class="ico">' + (cfg.emptyIcon || '▤') + '</div>' +
        '<h3>Nothing yet</h3><p>' + esc(cfg.emptyText || 'Create the first one.') + '</p>' +
        '<button class="btn primary" id="dEmptyNew">' + esc(cfg.addLabel || '+ New') + '</button></div>' +
        '</div></section>';

      var head = $('.header');
      if (head && !$('#dNewBtn')) {
        var b = document.createElement('button');
        b.className = 'btn primary'; b.id = 'dNewBtn'; b.textContent = cfg.addLabel || '+ New';
        head.appendChild(b);
      }

      /* ---------------- dialogs ---------------- */
      var holder = document.createElement('div');
      holder.innerHTML =
        '<div class="modal" id="dForm"><div class="dialog" style="width:min(900px,100%)">' +
        '<div class="dialog-head"><div><h3 id="dFormTitle">' + esc(cfg.addLabel || 'New') + '</h3>' +
        '<p id="dFormSub">' + esc(cfg.formSub || 'Add the items, then post it') + '</p></div>' +
        '<button class="close" data-close>×</button></div>' +
        '<div class="dialog-body">' +
        '<div class="form-grid">' +
        (cfg.party === 'supplier'
          ? '<label class="field"><span>Supplier' + (cfg.partyRequired ? ' <span class="hint">· required</span>' : '') +
            '</span><select id="dSupplier"></select></label>' : '') +
        (cfg.party === 'sale'
          ? '<label class="field"><span>Original bill</span><select id="dSale"></select></label>' : '') +
        '<label class="field"><span>Date</span><input id="dDate" type="date"></label>' +
        (cfg.refLabel ? '<label class="field"><span>' + esc(cfg.refLabel) + '</span><input id="dRef" placeholder="' + esc(cfg.refHint || '') + '"></label>' : '') +
        '<label class="field full"><span>Note</span><input id="dNote" placeholder="optional"></label>' +
        '</div>' +

        /* ---- line adder ---- */
        '<div class="panel" style="margin-top:4px;box-shadow:none">' +
        '<div class="panel-head"><div><h2 style="font-size:14px">Items</h2>' +
        '<p>' + esc(isCount ? 'Shelf par jitna mila wo likhein — farq khud nikal aayega'
                            : 'Barcode scan karein ya naam likhein') + '</p></div></div>' +
        '<div class="panel-body">' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">' +
        '<label class="field" style="flex:1 1 260px;margin:0"><span>Product</span>' +
        '<input id="dPick" placeholder="Barcode ya naam" autocomplete="off"></label>' +
        '<label class="field" style="width:120px;margin:0"><span>' + (isCount ? 'Counted' : 'Qty') + '</span>' +
        '<input id="dQty" type="number" step="any" min="0" value="1"></label>' +
        (priced ? '<label class="field" style="width:130px;margin:0"><span>' + esc(cfg.rateLabel || 'Rate') + '</span>' +
          '<input id="dRate" type="number" step="any" min="0"></label>' : '') +
        (cfg.batch ? '<label class="field" style="width:130px;margin:0"><span>Batch</span><input id="dBatch" placeholder="optional"></label>' +
          '<label class="field" style="width:150px;margin:0"><span>Expiry</span><input id="dExp" type="date"></label>' : '') +
        '<button class="btn primary" id="dAdd" style="margin:0">Add</button>' +
        '</div>' +
        '<div id="dHits" style="display:none;border:1px solid var(--line);border-radius:10px;margin-top:8px;max-height:210px;overflow:auto"></div>' +
        '<div class="table-wrap" style="margin-top:12px"><table class="table"><thead><tr>' +
        '<th>Item</th>' +
        (isCount ? '<th class="num">System</th><th class="num">Counted</th><th class="num">Difference</th>'
                 : '<th class="num">Qty</th>') +
        (priced ? '<th class="num">' + esc(cfg.rateLabel || 'Rate') + '</th><th class="num">Amount</th>' : '') +
        '<th></th></tr></thead><tbody id="dLines"></tbody></table></div>' +
        '<div id="dTotals" style="display:flex;justify-content:flex-end;gap:26px;padding:10px 2px 0;font-size:13px"></div>' +
        '</div></div>' +

        '</div><div class="dialog-foot">' +
        '<button class="btn" data-close>Cancel</button>' +
        '<button class="btn" id="dSaveDraft">Save draft</button>' +
        '<button class="btn primary" id="dPost">' + esc(cfg.postLabel || 'Post') + '</button></div></div></div>' +

        '<div class="modal" id="dView"><div class="dialog" style="width:min(820px,100%)">' +
        '<div class="dialog-head"><div><h3 id="dViewTitle">Document</h3><p id="dViewSub"></p></div>' +
        '<button class="close" data-close>×</button></div>' +
        '<div class="dialog-body" id="dViewBody"></div>' +
        '<div class="dialog-foot"><button class="btn" data-close>Close</button></div></div></div>';
      document.body.appendChild(holder);
      if (!$('#toast')) { var tt = document.createElement('div'); tt.className = 'toast'; tt.id = 'toast'; document.body.appendChild(tt); }

      /* ---------------- list ---------------- */
      function load() {
        try {
          var r = api('rdoc-list&type=' + cfg.type + '&status=' + ($('#dFilter').value || ''));
          rows = (r && r.rows) || [];
        } catch (e) { rows = []; toast(e.message || 'Could not load', true); }
        draw();
      }

      function draw() {
        var q = ($('#dSearch').value || '').toLowerCase().trim();
        var list = rows.filter(function (d) {
          if (!q) return true;
          return (d.doc_no || '').toLowerCase().indexOf(q) > -1 ||
                 (d.supplier_name || '').toLowerCase().indexOf(q) > -1 ||
                 (d.ref_no || '').toLowerCase().indexOf(q) > -1;
        });
        $('#kCount').textContent = rows.length;
        $('#kDraft').textContent = rows.filter(function (d) { return d.status === 'DRAFT'; }).length;
        if ($('#kValue')) {
          $('#kValue').textContent = money(rows.reduce(function (a, d) {
            return a + (d.status === 'POSTED' ? Number(d.total || 0) : 0);
          }, 0));
        }
        $('#dEmpty').style.display = list.length ? 'none' : '';
        $('#dRows').innerHTML = list.map(function (d) {
          var acts = '<button class="btn sm" data-view="' + d.id + '">View</button>';
          if (d.status === 'DRAFT') {
            acts = '<button class="btn sm" data-edit="' + d.id + '">Edit</button> ' +
                   '<button class="btn sm primary" data-post="' + d.id + '">' + esc(cfg.postLabel || 'Post') + '</button> ' +
                   '<button class="btn sm" data-cancel="' + d.id + '">Cancel</button>';
          }
          return '<tr><td><b>' + esc(d.doc_no) + '</b></td>' +
            '<td class="hint">' + esc(d.doc_date || '') + '</td>' +
            '<td>' + esc(d.supplier_name || d.ref_no || '—') + '</td>' +
            '<td class="num">' + d.line_count + '</td>' +
            (priced ? '<td class="num">' + money(d.total) + '</td>' : '') +
            '<td>' + (STATUS_TAG[d.status] || esc(d.status)) + '</td>' +
            '<td class="num" style="white-space:nowrap">' + acts + '</td></tr>';
        }).join('');
      }

      /* ---------------- line editor ---------------- */
      function lineTotal(l) { return Math.abs(l.qty) * Number(l.rate || 0); }

      function drawLines() {
        $('#dLines').innerHTML = lines.map(function (l, i) {
          var cells;
          if (isCount) {
            var diff = l.counted - l.system;
            cells = '<td class="num">' + qty(l.system) + '</td>' +
                    '<td class="num">' + qty(l.counted) + '</td>' +
                    '<td class="num" style="color:' + (diff < 0 ? 'var(--danger)' : (diff > 0 ? 'var(--ok)' : 'var(--muted)')) + '">' +
                    (diff > 0 ? '+' : '') + qty(diff) + '</td>';
          } else {
            cells = '<td class="num">' + qty(l.qty) + '</td>';
          }
          if (priced) cells += '<td class="num">' + money(l.rate) + '</td><td class="num">' + money(lineTotal(l)) + '</td>';
          return '<tr><td><span class="t-main">' + esc(l.name) + '</span>' +
            (l.batch ? '<span class="t-sub">Batch ' + esc(l.batch) + (l.expiry ? ' · exp ' + esc(l.expiry) : '') + '</span>' : '') +
            '</td>' + cells +
            '<td class="num"><button class="btn sm" data-rm="' + i + '">Remove</button></td></tr>';
        }).join('');
        var t = lines.reduce(function (a, l) { return a + lineTotal(l); }, 0);
        $('#dTotals').innerHTML = lines.length
          ? '<span style="color:var(--muted)">' + lines.length + ' item(s)</span>' +
            (priced ? '<span><b style="font-size:15px">' + money(t) + '</b></span>' : '')
          : '';
      }

      var picked = null;
      function showHits(list) {
        var box = $('#dHits');
        if (!list || !list.length) { box.style.display = 'none'; return; }
        box.style.display = '';
        box.innerHTML = list.slice(0, 8).map(function (p) {
          return '<div data-hit="' + esc(p.id) + '" style="padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--line)">' +
            '<b>' + esc(p.name) + '</b>' +
            '<span class="hint" style="margin-left:8px">' + esc(p.sku || '') +
            ' · stock ' + qty(p.stock_qty) + ' · cost ' + money(p.cost_price) + '</span></div>';
        }).join('');
      }
      function choose(p) {
        picked = p;
        $('#dPick').value = p.name;
        $('#dHits').style.display = 'none';
        if ($('#dRate')) $('#dRate').value = cfg.rateFrom === 'retail' ? p.retail_price : p.cost_price;
        if (isCount) $('#dQty').value = p.stock_qty;
        $('#dQty').focus();
        $('#dQty').select();
      }

      $('#dPick').addEventListener('input', function () {
        picked = null;
        var q = this.value.trim();
        if (q.length < 2) { showHits([]); return; }
        /* Pehle poora barcode — counter par scanner isi raaste se aata
           hai aur wo ek hi hit deta hai. */
        try {
          var hit = RetailStore.findByCode(q);
          if (hit && hit.product) { choose(hit.product); return; }
        } catch (e) { }
        try { showHits(RetailStore.searchProducts(q)); } catch (e) { showHits([]); }
      });
      $('#dHits').addEventListener('click', function (e) {
        var el = e.target.closest('[data-hit]'); if (!el) return;
        var id = el.getAttribute('data-hit');
        var p = (RetailStore.get('products') || []).filter(function (x) { return x.id === id; })[0];
        if (!p) { try { p = RetailStore.searchProducts($('#dPick').value).filter(function (x) { return x.id === id; })[0]; } catch (err) { } }
        if (p) choose(p);
      });

      $('#dAdd').onclick = function () {
        if (!picked) { toast('Pehle product chunein', true); return; }
        var q = Number($('#dQty').value || 0);
        if (!isCount && q <= 0) { toast('Quantity zero se zyada honi chahiye', true); return; }
        var l = { product_id: picked.id, name: picked.name, qty: q, rate: Number(($('#dRate') || {}).value || 0) };
        if (isCount) { l.counted = q; l.system = Number(picked.stock_qty || 0); l.qty = q - l.system; }
        if (cfg.batch) { l.batch = ($('#dBatch') || {}).value || ''; l.expiry = ($('#dExp') || {}).value || ''; }
        /* Ek hi product do dafa add ho jaye to ginti chup chaap dugni ho
           jati — is liye wahi line barh jati hai, nayi nahi banti. */
        var same = lines.filter(function (x) { return x.product_id === l.product_id; })[0];
        if (same && !isCount) { same.qty += l.qty; } else if (same && isCount) { same.counted = l.counted; same.qty = l.counted - same.system; }
        else lines.push(l);
        picked = null;
        $('#dPick').value = ''; $('#dQty').value = isCount ? '' : 1;
        if ($('#dBatch')) { $('#dBatch').value = ''; $('#dExp').value = ''; }
        drawLines(); $('#dPick').focus();
      };
      $('#dLines').addEventListener('click', function (e) {
        var el = e.target.closest('[data-rm]'); if (!el) return;
        lines.splice(Number(el.getAttribute('data-rm')), 1); drawLines();
      });

      /* ---------------- open / save / post ---------------- */
      function fillParty() {
        if (cfg.party === 'supplier' && $('#dSupplier')) {
          var sup = RetailStore.get('suppliers') || [];
          $('#dSupplier').innerHTML = '<option value="">—</option>' + sup.map(function (s) {
            return '<option value="' + esc(s.id) + '">' + esc(s.name) + '</option>';
          }).join('');
        }
        if (cfg.party === 'sale' && $('#dSale')) {
          var sales = RetailStore.get('sales') || [];
          $('#dSale').innerHTML = '<option value="">—</option>' + sales.slice(0, 50).map(function (s) {
            return '<option value="' + esc(s.id) + '">' + esc(s.bill_no) + ' · ' + money(s.total) + '</option>';
          }).join('');
        }
      }

      function openNew() {
        current = null; lines = []; picked = null;
        $('#dFormTitle').textContent = cfg.addLabel || 'New';
        $('#dDate').value = today();
        if ($('#dRef')) $('#dRef').value = '';
        $('#dNote').value = '';
        fillParty();
        drawLines(); openM('dForm'); setTimeout(function () { $('#dPick').focus(); }, 60);
      }

      function openEdit(id) {
        var r;
        try { r = api('rdoc-get&id=' + encodeURIComponent(id)); } catch (e) { toast(e.message, true); return; }
        var d = r.doc; current = d.id; picked = null;
        $('#dFormTitle').textContent = d.doc_no;
        $('#dDate').value = (d.doc_date || '').slice(0, 10);
        if ($('#dRef')) $('#dRef').value = d.ref_no || '';
        $('#dNote').value = d.note || '';
        fillParty();
        if ($('#dSupplier')) $('#dSupplier').value = d.supplier_id || '';
        if ($('#dSale')) $('#dSale').value = d.source_sale_id || '';
        lines = (d.lines || []).map(function (l) {
          return {
            product_id: l.product_id, name: l.product_name, qty: l.qty,
            rate: cfg.rateFrom === 'retail' ? l.unit_price : l.unit_cost,
            counted: l.counted_qty, system: l.system_qty,
            batch: l.batch_no || '', expiry: (l.expiry_date || '')
          };
        });
        drawLines(); openM('dForm');
      }

      function payload() {
        var d = {
          doc_type: cfg.type, doc_date: $('#dDate').value || today(),
          note: $('#dNote').value, lines: lines.map(function (l) {
            var o = { product_id: l.product_id, qty: Math.abs(l.qty) };
            if (isCount) { o.counted_qty = l.counted; o.system_qty = l.system; }
            if (priced) { if (cfg.rateFrom === 'retail') o.unit_price = l.rate; else o.unit_cost = l.rate; }
            if (cfg.batch) { o.batch_no = l.batch; o.expiry_date = l.expiry; }
            return o;
          })
        };
        if (current) d.id = current;
        if ($('#dRef')) d.ref_no = $('#dRef').value;
        if ($('#dSupplier')) d.supplier_id = $('#dSupplier').value;
        if ($('#dSale')) d.source_sale_id = $('#dSale').value;
        return d;
      }

      function saveDraft(silent) {
        if (!lines.length) { toast('Pehle koi item add karein', true); return null; }
        try {
          var r = api('rdoc-save', payload());
          current = r.doc.id;
          if (!silent) toast('Draft saved ' + r.doc.doc_no);
          return r.doc;
        } catch (e) { toast(e.message || 'Could not save', true); return null; }
      }

      $('#dSaveDraft').onclick = function () { if (saveDraft()) { closeM(); load(); } };

      $('#dPost').onclick = function () {
        var doc = saveDraft(true); if (!doc) return;
        try {
          var r = api('rdoc-post', { id: doc.id });
          toast(r.doc.doc_no + ' posted');
          closeM();
          /* Stock badal chuka hai — purani list dikhate rehna sab se
             bara dhoka hota. */
          if (RetailStore.invalidate) RetailStore.invalidate();
          load();
        } catch (e) { toast(e.message || 'Could not post', true); }
      };

      $('#dRows').addEventListener('click', function (e) {
        var v = e.target.closest('[data-view]'), ed = e.target.closest('[data-edit]'),
            po = e.target.closest('[data-post]'), ca = e.target.closest('[data-cancel]');
        if (ed) return openEdit(ed.getAttribute('data-edit'));
        if (po) {
          try {
            var r = api('rdoc-post', { id: po.getAttribute('data-post') });
            toast(r.doc.doc_no + ' posted');
            if (RetailStore.invalidate) RetailStore.invalidate();
            load();
          } catch (err) { toast(err.message || 'Could not post', true); }
          return;
        }
        if (ca) {
          if (!confirm('Cancel this draft?')) return;
          try { api('rdoc-cancel', { id: ca.getAttribute('data-cancel') }); toast('Cancelled'); load(); }
          catch (err) { toast(err.message, true); }
          return;
        }
        if (v) {
          var rr;
          try { rr = api('rdoc-get&id=' + encodeURIComponent(v.getAttribute('data-view'))); }
          catch (err) { toast(err.message, true); return; }
          var d = rr.doc;
          $('#dViewTitle').textContent = d.doc_no;
          $('#dViewSub').textContent = (d.doc_date || '') + ' · ' + (d.supplier_name || d.ref_no || '') +
            ' · ' + d.status;
          $('#dViewBody').innerHTML =
            '<div class="table-wrap"><table class="table"><thead><tr><th>Item</th>' +
            (isCount ? '<th class="num">System</th><th class="num">Counted</th><th class="num">Difference</th>'
                     : '<th class="num">Qty</th>') +
            (priced ? '<th class="num">Rate</th><th class="num">Amount</th>' : '') +
            '</tr></thead><tbody>' +
            (d.lines || []).map(function (l) {
              var c = isCount
                ? '<td class="num">' + qty(l.system_qty) + '</td><td class="num">' + qty(l.counted_qty) +
                  '</td><td class="num">' + (l.qty > 0 ? '+' : '') + qty(l.qty) + '</td>'
                : '<td class="num">' + qty(l.qty) + '</td>';
              if (priced) c += '<td class="num">' + money(cfg.rateFrom === 'retail' ? l.unit_price : l.unit_cost) +
                '</td><td class="num">' + money(l.line_total) + '</td>';
              return '<tr><td>' + esc(l.product_name) + '</td>' + c + '</tr>';
            }).join('') +
            '</tbody></table></div>' +
            (priced ? '<div style="text-align:right;margin-top:12px;font-size:15px"><b>' + money(d.total) + '</b></div>' : '') +
            (d.note ? '<p class="hint" style="margin-top:10px">' + esc(d.note) + '</p>' : '');
          openM('dView');
        }
      });

      $('#dNewBtn').onclick = openNew;
      $('#dEmptyNew').onclick = openNew;
      $('#dFilter').onchange = load;
      $('#dSearch').oninput = draw;
      document.addEventListener('click', function (e) { if (e.target.closest('[data-close]')) closeM(); });

      load();
    }
  };
})();

// build: V154 build 2026-10-13
