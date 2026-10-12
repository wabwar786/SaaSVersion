/* ============================================================
   item_dialog.js — menu item banane / badalne ka EK popup.

   YEH KYUN BANA
   POS ka "+ New item" popup aur left menu ka "Menu & Categories" do
   bilkul alag cheezein thin. POS asli `menu_items` mein likhta tha
   (inventory link ke sath), aur Menu screen `module.js` ke generic CRUD
   se `ui_records` mein — yani menu_items ko chhoota hi nahi tha. Ek he
   restaurant ke do alag menu. Customer ne theek kaha: "dono same hone
   chahiye."

   Ab dono yehi file kholte hain. Ek jagah theek karo, dono jagah theek.

   STOCK KAISE KATEGA — teen tariqe, dono restaurant qism ke liye:
     No stock   kuch nahi katega
     Same item  jo bika WOHI stock se minus (Coke ki tin, paani)
     Recipe     har ingredient alag alag minus (biryani -> chawal,
                murghi, masala), yield aur waste % ke hisaab se

   Istemal:
     ItemDialog.open({ id:'<menu item id>'|null,
                       onSaved:function(res){...},
                       theme:'pos'|'admin' })
   ============================================================ */
(function () {
  'use strict';

  /* ---------- transport: jo bhi is page par mojood ho ---------- */
  function req(action, payload) {
    if (window.DBApi && DBApi.req) return DBApi.req(action, payload);
    var x = new XMLHttpRequest();
    var url = '/api.php?action=' + action + (payload === undefined ? ('&_=' + Date.now()) : '');
    x.open(payload === undefined ? 'GET' : 'POST', url, false);
    x.setRequestHeader('Accept', 'application/json');
    x.setRequestHeader('Cache-Control', 'no-cache');
    if (payload !== undefined) {
      x.setRequestHeader('Content-Type', 'application/json');
      if (window.APP_CSRF) x.setRequestHeader('X-CSRF-Token', window.APP_CSRF);
    }
    try { x.send(payload === undefined ? null : JSON.stringify(payload)); }
    catch (e) { return { ok: false, message: 'Network error' }; }
    try { return JSON.parse(x.responseText || '{}'); }
    catch (e) { return { ok: false, message: 'Bad response from server' }; }
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function num(v, d) { var n = parseFloat(v); return isFinite(n) ? n : (d || 0); }

  /* ---------- CSS ek dafa ---------- */
  function styles() {
    if (document.getElementById('itemDlgCss')) return;
    var st = document.createElement('style');
    st.id = 'itemDlgCss';
    st.textContent = [
      '.idlg-ov{position:fixed;inset:0;background:rgba(20,17,15,.58);z-index:9000;',
      ' display:flex;align-items:center;justify-content:center;padding:18px;overflow:auto}',
      '.idlg{background:#fff;color:#1b1917;border-radius:14px;width:min(620px,96vw);',
      ' max-height:92vh;overflow:auto;box-shadow:0 22px 60px rgba(20,17,15,.3);',
      ' font:14px/1.5 "Inter",system-ui,-apple-system,Segoe UI,sans-serif}',
      '.idlg h3{margin:0;font-size:17px}',
      '.idlg .hd{padding:17px 20px 13px;border-bottom:1px solid #e7e3dd;position:relative}',
      '.idlg .hd p{margin:3px 0 0;color:#78716b;font-size:12.5px}',
      '.idlg .x{position:absolute;right:13px;top:13px;border:0;background:none;font-size:21px;',
      ' line-height:1;color:#a8a29c;cursor:pointer;padding:3px 6px;border-radius:7px}',
      '.idlg .x:hover{background:#f4f2ef;color:#1b1917}',
      '.idlg .bd{padding:16px 20px}',
      '.idlg .ft{padding:13px 20px 17px;border-top:1px solid #e7e3dd;display:flex;gap:9px;',
      ' justify-content:flex-end;position:sticky;bottom:0;background:#fff}',
      '.idlg .g{display:grid;grid-template-columns:1fr 1fr;gap:11px}',
      '.idlg .g .full{grid-column:1/-1}',
      '.idlg label{display:block;font-size:11px;font-weight:700;letter-spacing:.04em;',
      ' text-transform:uppercase;color:#78716b;margin:0 0 4px}',
      '.idlg input,.idlg select,.idlg textarea{width:100%;padding:9px 11px;border:1px solid #d8d2ca;',
      ' border-radius:9px;font:inherit;background:#fff;color:#1b1917}',
      '.idlg input:focus,.idlg select:focus{outline:none;border-color:#c0281a;box-shadow:0 0 0 3px rgba(192,40,26,.1)}',
      '.idlg .btn{padding:9px 17px;border-radius:9px;border:1px solid #d8d2ca;background:#fff;',
      ' font:inherit;font-weight:650;cursor:pointer;color:#413c38}',
      '.idlg .btn:hover{background:#f6f4f1}',
      '.idlg .btn.p{background:#c0281a;border-color:#c0281a;color:#fff}',
      '.idlg .btn.p:hover{background:#a81c0c}',
      '.idlg .btn:disabled{opacity:.55;cursor:default}',
      '.idlg .modes{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:4px 0 2px}',
      '.idlg .mode{border:1.5px solid #e7e3dd;border-radius:10px;padding:10px;cursor:pointer;',
      ' text-align:center;background:#faf9f7}',
      '.idlg .mode b{display:block;font-size:12.5px;margin-bottom:2px}',
      '.idlg .mode span{font-size:10.6px;color:#78716b;line-height:1.35;display:block}',
      '.idlg .mode.on{border-color:#15804a;background:#e7f5ec}',
      '.idlg .mode.on b{color:#15804a}',
      '.idlg .sec{margin-top:15px;padding-top:14px;border-top:1px solid #efece8}',
      '.idlg .sec>h4{margin:0 0 9px;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#78716b}',
      '.idlg .ing{display:grid;grid-template-columns:1fr 92px 78px 34px;gap:7px;margin-bottom:7px;align-items:end}',
      '.idlg .ing .rm{border:1px solid #f2c4bd;background:#fcebe8;color:#c02617;border-radius:8px;',
      ' height:37px;cursor:pointer;font-size:15px;line-height:1}',
      '.idlg .hint{font-size:11.6px;color:#78716b;margin:7px 0 0}',
      '.idlg .err{background:#fcebe8;border:1px solid #f2c4bd;color:#c02617;padding:9px 12px;',
      ' border-radius:9px;font-size:12.8px;margin-bottom:12px}',
      '@media(max-width:560px){.idlg .g{grid-template-columns:1fr}.idlg .modes{grid-template-columns:1fr}',
      '.idlg .ing{grid-template-columns:1fr 1fr;}}'
    ].join('');
    document.head.appendChild(st);
  }

  /* ============================================================ */
  function open(opts) {
    opts = opts || {};
    styles();

    var form = req('menu-item-form');
    if (!form || !form.ok) { alert((form && form.message) || 'Could not load the form'); return; }

    var cats  = form.categories || [];
    var inv   = form.inventory || [];
    var units = form.units || [];

    var item = null;
    if (opts.id) {
      var g = req('menu-item-get&id=' + encodeURIComponent(opts.id));
      if (!g || !g.ok) { alert((g && g.message) || 'Could not load the item'); return; }
      item = g.item;
    }
    var editing = !!item;
    var iv = (item && item.inventory) || { mode: 'none', qty: 1, yield_qty: 1, ingredients: [] };
    var mode = iv.mode === 'direct' ? 'existing' : (iv.mode || 'none');

    var ov = document.createElement('div');
    ov.className = 'idlg-ov';

    function catOpts(sel) {
      var seen = {}, out = '';
      cats.forEach(function (c) { seen[c.name] = 1; });
      if (sel && !seen[sel]) out += '<option value="' + esc(sel) + '" selected>' + esc(sel) + '</option>';
      cats.forEach(function (c) {
        out += '<option value="' + esc(c.name) + '"' + (c.name === sel ? ' selected' : '') + '>' + esc(c.name) + '</option>';
      });
      return out;
    }
    function invOpts(sel) {
      var out = '<option value="">— choose —</option>';
      inv.forEach(function (i) {
        out += '<option value="' + esc(i.id) + '"' + (i.id === sel ? ' selected' : '') + '>' + esc(i.name) + '</option>';
      });
      return out;
    }
    function unitOpts() {
      if (!units.length) return '<option value="PCS">PCS</option>';
      return units.map(function (u) {
        return '<option value="' + esc(u.code) + '"' + (u.code === 'PCS' ? ' selected' : '') + '>' + esc(u.code) + '</option>';
      }).join('');
    }

    ov.innerHTML =
      '<div class="idlg" role="dialog" aria-modal="true">' +
      '<div class="hd"><button class="x" data-x aria-label="Close">&times;</button>' +
      '<h3>' + (editing ? 'Edit item' : 'New item') + '</h3>' +
      '<p>The same item the Sale Point sells. Stock is taken off when the bill is closed.</p></div>' +
      '<div class="bd">' +
      '<div class="err" id="idErr" style="display:none"></div>' +
      '<div class="g">' +
      '<div class="full"><label>Item name</label><input id="idName" value="' + esc(item ? item.name : '') + '" placeholder="Chicken Biryani"></div>' +
      '<div><label>Selling price</label><input id="idPrice" type="number" step="0.01" min="0" value="' + (item ? item.price : '') + '"></div>' +
      '<div><label>Category</label><select id="idCat">' + catOpts(item ? item.category : '') + '</select></div>' +
      '<div><label>Sold by</label><select id="idType">' +
        '<option value="standard"' + (item && item.type === 'standard' ? ' selected' : '') + '>Each (standard)</option>' +
        '<option value="weighted"' + (item && item.type === 'weighted' ? ' selected' : '') + '>Weight</option>' +
      '</select></div>' +
      '<div><label>Status</label><select id="idActive">' +
        '<option value="1"' + (!item || item.active ? ' selected' : '') + '>Active</option>' +
        '<option value="0"' + (item && !item.active ? ' selected' : '') + '>Inactive</option>' +
      '</select></div>' +
      '</div>' +

      '<div class="sec"><h4>When this item is sold, take stock off&hellip;</h4>' +
      '<div class="modes">' +
      '<div class="mode" data-m="none"><b>No stock</b><span>Nothing is deducted</span></div>' +
      '<div class="mode" data-m="existing"><b>The same item</b><span>Coke tin, water bottle</span></div>' +
      '<div class="mode" data-m="recipe"><b>By recipe</b><span>Rice, chicken, masala&hellip;</span></div>' +
      '</div>' +

      /* ---- same item: pehle se mojood ---- */
      '<div id="paneExisting" style="display:none">' +
      '<div class="g" style="margin-top:11px">' +
      '<div class="full"><label>Inventory item</label><select id="idEx">' + invOpts(iv.item_id) + '</select></div>' +
      '<div><label>Units used per sale</label><input id="idQty" type="number" step="0.001" min="0.001" value="' + (iv.qty || 1) + '"></div>' +
      '</div>' +
      '<p class="hint">Not in the list? <a href="#" id="idNewLink">Create it with this item</a>.</p></div>' +

      /* ---- same item: naya banao ---- */
      /* V147 — "New inventory item name" ka khana hata diya gaya.
         Customer ne theek kaha: jab "The same item" chuna hai to iska
         matlab hi yeh hai ke YEH item khud stock hai — alag naam
         maangna be-maani tha aur uljhan paida karta tha. Naam ab khud
         item ka chala jata hai (server bhi yehi karta hai). */
      '<div id="paneNew" style="display:none">' +
      '<p class="hint" id="idNewNote" style="margin:11px 0 0"></p>' +
      '<div class="g" style="margin-top:9px">' +
      '<div><label>Unit</label><select id="idNu">' + unitOpts() + '</select></div>' +
      '<div><label>Opening stock</label><input id="idNo" type="number" step="0.001" min="0" value="0"></div>' +
      '<div><label>Cost per unit</label><input id="idNc" type="number" step="0.01" min="0" value="0"></div>' +
      '<div><label>Units used per sale</label><input id="idNq" type="number" step="0.001" min="0.001" value="1"></div>' +
      '</div>' +
      '<p class="hint" id="idExLinkWrap"><a href="#" id="idExLink">Pick an existing inventory item instead</a></p></div>' +

      /* ---- recipe ---- */
      '<div id="paneRecipe" style="display:none">' +
      '<div class="g" style="margin-top:11px"><div><label>This recipe makes (yield)</label>' +
      '<input id="idYield" type="number" step="0.001" min="0.001" value="' + (iv.yield_qty || 1) + '"></div></div>' +
      '<div style="margin-top:12px"><label>Ingredients</label><div id="idIngs"></div>' +
      '<button type="button" class="btn" id="idAddIng" style="margin-top:4px">+ Add ingredient</button></div>' +
      '<p class="hint">Qty is per yield. Waste % is added on top when stock is taken off.</p></div>' +
      '</div>' +

      '<div class="sec"><h4>Options (optional)</h4>' +
      '<input id="idVars" placeholder="Small:250, Large:450" value="' +
        esc(item && item.variants && item.variants.length
            ? item.variants.map(function (v) { return v.name + ':' + v.price; }).join(', ') : '') + '">' +
      '<p class="hint">Name:price, separated by commas. Leave blank if the item has no sizes.</p></div>' +
      '</div>' +

      '<div class="ft"><button class="btn" data-x>Cancel</button>' +
      '<button class="btn p" id="idSave">' + (editing ? 'Save changes' : 'Create item') + '</button></div>' +
      '</div>';

    document.body.appendChild(ov);

    function close() { ov.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    Array.prototype.forEach.call(ov.querySelectorAll('[data-x]'), function (b) { b.onclick = close; });

    var err = ov.querySelector('#idErr');
    function showErr(m) { err.textContent = m; err.style.display = 'block'; err.scrollIntoView({ block: 'nearest' }); }
    function clearErr() { err.style.display = 'none'; }

    /* ---------- mode switching ---------- */
    function paint() {
      Array.prototype.forEach.call(ov.querySelectorAll('.mode'), function (m) {
        var k = m.getAttribute('data-m');
        m.classList.toggle('on', k === mode || (k === 'existing' && mode === 'new'));
      });
      ov.querySelector('#paneExisting').style.display = (mode === 'existing') ? '' : 'none';
      ov.querySelector('#paneNew').style.display      = (mode === 'new')      ? '' : 'none';
      ov.querySelector('#paneRecipe').style.display   = (mode === 'recipe')   ? '' : 'none';

      if (mode === 'new') {
        var nm = (ov.querySelector('#idName').value || '').trim();
        ov.querySelector('#idNewNote').innerHTML =
          'Stock will be kept as <b>' + esc(nm || 'this item') + '</b> — the same name as the item.';
        /* Koi inventory item hai hi nahi to "existing chunein" ka link
           bekar hai; dikhana sirf uljhan hai. */
        ov.querySelector('#idExLinkWrap').style.display = inv.length ? '' : 'none';
      }
    }
    Array.prototype.forEach.call(ov.querySelectorAll('.mode'), function (m) {
      m.onclick = function () {
        var k = m.getAttribute('data-m');
        mode = (k === 'existing' && !inv.length) ? 'new' : k;
        paint();
      };
    });
    ov.querySelector('#idNewLink').onclick = function (e) { e.preventDefault(); mode = 'new'; paint(); };
    ov.querySelector('#idExLink').onclick  = function (e) { e.preventDefault(); mode = 'existing'; paint(); };

    /* ---------- ingredients ---------- */
    var ingBox = ov.querySelector('#idIngs');
    function addIng(pre) {
      var row = document.createElement('div');
      row.className = 'ing';
      row.innerHTML =
        '<div><label>Raw material</label><select class="ii">' + invOpts(pre && pre.item_id) + '</select></div>' +
        '<div><label>Qty</label><input class="iq" type="number" step="0.000001" min="0" value="' + (pre ? pre.qty : 1) + '"></div>' +
        '<div><label>Waste %</label><input class="iw" type="number" step="0.01" min="0" value="' + (pre ? (pre.waste_pct || 0) : 0) + '"></div>' +
        '<button type="button" class="rm" title="Remove">&times;</button>';
      row.querySelector('.rm').onclick = function () { row.remove(); };
      ingBox.appendChild(row);
    }
    (iv.ingredients || []).forEach(addIng);
    if (!(iv.ingredients || []).length) addIng(null);
    ov.querySelector('#idAddIng').onclick = function () { addIng(null); };

    if (!inv.length && mode === 'existing') mode = 'new';
    ov.querySelector('#idName').addEventListener('input', function () { if (mode === 'new') paint(); });
    paint();
    setTimeout(function () { try { ov.querySelector('#idName').focus(); } catch (e) {} }, 30);

    /* ---------- save ---------- */
    ov.querySelector('#idSave').onclick = function () {
      clearErr();
      var btn = this;
      var d = {
        name: (ov.querySelector('#idName').value || '').trim(),
        price: num(ov.querySelector('#idPrice').value, 0),
        category: ov.querySelector('#idCat').value,
        type: ov.querySelector('#idType').value,
        active: ov.querySelector('#idActive').value === '1'
      };
      if (editing) d.id = item.id;
      if (!d.name) { showErr('Item name is required'); return; }
      if (!(d.price > 0)) { showErr('A valid selling price is required'); return; }

      if (mode === 'existing') {
        var ex = ov.querySelector('#idEx').value;
        if (!ex) { showErr('Choose the inventory item, or create a new one'); return; }
        d.inventory = { mode: 'existing', item_id: ex, qty: num(ov.querySelector('#idQty').value, 1) };
      } else if (mode === 'new') {
        d.inventory = {
          mode: 'new',
          name: d.name,                       /* wahi naam — alag poochna hata diya */
          unit: ov.querySelector('#idNu').value,
          opening_qty: num(ov.querySelector('#idNo').value, 0),
          cost: num(ov.querySelector('#idNc').value, 0),
          qty: num(ov.querySelector('#idNq').value, 1)
        };
      } else if (mode === 'recipe') {
        var ings = [];
        Array.prototype.forEach.call(ingBox.querySelectorAll('.ing'), function (r) {
          var id = r.querySelector('.ii').value, q = num(r.querySelector('.iq').value, 0);
          if (id && q > 0) ings.push({ item_id: id, qty: q, waste_pct: num(r.querySelector('.iw').value, 0) });
        });
        if (!ings.length) { showErr('A recipe needs at least one ingredient with a quantity'); return; }
        d.inventory = { mode: 'recipe', yield_qty: num(ov.querySelector('#idYield').value, 1), ingredients: ings };
      } else {
        d.inventory = { mode: 'none' };
      }

      var vs = (ov.querySelector('#idVars').value || '').trim();
      if (vs || editing) {
        d.variants = vs.split(',').map(function (s) {
          var q = s.split(':');
          return { name: (q[0] || '').trim(), price: num(q[1], 0) };
        }).filter(function (v) { return v.name && v.price > 0; });
      }

      btn.disabled = true;
      btn.textContent = editing ? 'Saving…' : 'Creating…';
      var r = req('menu-item-save', d);
      btn.disabled = false;
      btn.textContent = editing ? 'Save changes' : 'Create item';

      if (!r || !r.ok) { showErr((r && r.message) || 'Could not save the item'); return; }
      close();
      if (opts.onSaved) opts.onSaved(r, d);
    };

    return ov;
  }

  window.ItemDialog = { open: open };
})();

/* build: V146 build 2026-10-11 */
