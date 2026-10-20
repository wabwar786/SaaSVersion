/* ============================================================
   stock_in.js — "maal aa gaya" ka ek popup.

   YEH KYUN BANA
   Stock sirf do tarah barh sakta tha: item banate waqt "opening stock",
   ya poora purchase order + GRN. Rozana ki baat — "aaj 20 Coke aa gaye" —
   ke liye dono bohot lambe hain, is liye log stock daalte hi nahi thay
   aur inventory ka number kabhi sahi na hota.

   Popup Day Closing par bana tha. Inventory screen par bhi wahi chahiye
   tha, aur doosri copy banana wahi ghalti hai jo `menu_management` aur
   POS ke do alag item forms mein thi — is liye ek hi file.

   Istemal:
     StockIn.open({ itemId:'<optional>', onDone:function(res){...} })
   ============================================================ */
(function () {
  'use strict';

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
  function n3(v) {
    var n = Number(v || 0);
    return (Math.round(n * 1000) / 1000).toLocaleString(undefined, { maximumFractionDigits: 3 });
  }
  function note(m) {
    var t = document.createElement('div');
    t.textContent = m;
    t.style.cssText = 'position:fixed;left:50%;bottom:26px;transform:translateX(-50%);background:#1b1917;' +
      'color:#fff;padding:10px 17px;border-radius:10px;font-size:13.5px;z-index:9600;' +
      'box-shadow:0 8px 26px rgba(0,0,0,.26);max-width:92vw;text-align:center';
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 3000);
  }

  function styles() {
    if (document.getElementById('stkInCss')) return;
    var st = document.createElement('style');
    st.id = 'stkInCss';
    st.textContent = [
      '.sin-ov{position:fixed;inset:0;background:rgba(20,17,15,.58);z-index:9100;display:flex;',
      ' align-items:center;justify-content:center;padding:18px}',
      '.sin{background:#fff;color:#1b1917;border-radius:14px;width:min(440px,96vw);',
      ' box-shadow:0 22px 60px rgba(20,17,15,.3);font:14px/1.5 "Inter",system-ui,-apple-system,Segoe UI,sans-serif}',
      '.sin .hd{padding:17px 20px 13px;border-bottom:1px solid #e7e3dd;position:relative}',
      '.sin .hd h3{margin:0;font-size:17px}',
      '.sin .hd p{margin:3px 0 0;color:#78716b;font-size:12.5px}',
      '.sin .x{position:absolute;right:13px;top:13px;border:0;background:none;font-size:21px;line-height:1;',
      ' color:#a8a29c;cursor:pointer;padding:3px 6px;border-radius:7px}',
      '.sin .x:hover{background:#f4f2ef;color:#1b1917}',
      '.sin .bd{padding:16px 20px}',
      '.sin label{display:block;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;',
      ' color:#78716b;margin:0 0 4px}',
      '.sin input,.sin select{width:100%;padding:9px 11px;border:1px solid #d8d2ca;border-radius:9px;font:inherit}',
      '.sin input:focus,.sin select:focus{outline:none;border-color:#c0281a;box-shadow:0 0 0 3px rgba(192,40,26,.1)}',
      '.sin .g{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin-top:11px}',
      '.sin .hint{margin:9px 0 0;font-size:11.6px;color:#78716b}',
      '.sin .on-hand{margin-top:10px;background:#f6f4f1;border-radius:9px;padding:9px 12px;font-size:12.6px;color:#413c38}',
      '.sin .on-hand b{font-variant-numeric:tabular-nums}',
      '.sin .err{background:#fcebe8;border:1px solid #f2c4bd;color:#c02617;padding:9px 12px;',
      ' border-radius:9px;font-size:12.8px;margin-bottom:12px}',
      '.sin .ft{padding:13px 20px 17px;border-top:1px solid #e7e3dd;display:flex;gap:9px;justify-content:flex-end}',
      '.sin .btn{padding:9px 17px;border-radius:9px;border:1px solid #d8d2ca;background:#fff;font:inherit;',
      ' font-weight:650;cursor:pointer;color:#413c38}',
      '.sin .btn:hover{background:#f6f4f1}',
      '.sin .btn.p{background:#c0281a;border-color:#c0281a;color:#fff}',
      '.sin .btn.p:hover{background:#a81c0c}',
      '.sin .btn:disabled{opacity:.55;cursor:default}'
    ].join('');
    document.head.appendChild(st);
  }

  function open(opts) {
    opts = opts || {};
    styles();

    var f = req('menu-item-form');
    if (!f || !f.ok) { note((f && f.message) || 'Could not load the item list'); return; }
    var inv = f.inventory || [];
    if (!inv.length) {
      note('No inventory items yet — create one from Menu & Categories or Inventory');
      return;
    }

    var ov = document.createElement('div');
    ov.className = 'sin-ov';
    ov.innerHTML =
      '<div class="sin" role="dialog" aria-modal="true">' +
      '<div class="hd"><button class="x" data-x aria-label="Close">&times;</button>' +
      '<h3>Stock In</h3><p>Goods received &mdash; added to stock straight away</p></div>' +
      '<div class="bd">' +
      '<div class="err" id="sinErr" style="display:none"></div>' +
      '<label>Item</label><select id="sinItem">' +
        inv.map(function (i) {
          return '<option value="' + esc(i.id) + '" data-c="' + Number(i.cost || 0) + '"' +
                 (opts.itemId && opts.itemId === i.id ? ' selected' : '') + '>' + esc(i.name) + '</option>';
        }).join('') +
      '</select>' +
      '<div class="g">' +
      /* V158 — min hata diya. Manfi qty bhi chalni chahiye: galti se
         zyada stock chadh jaye to usay theek karne ka koi rasta hi
         nahi tha (na edit, na ghatao). Manfi daalne par server us ko
         PURCHASE nahi, ADJUSTMENT likhta hai — Purchases report mein
         manfi kharidari nazar nahi aati. */
      '<div><label>Quantity</label><input id="sinQty" type="number" step="0.001" placeholder="0"></div>' +
      '<div><label>Rate per unit</label><input id="sinRate" type="number" step="0.01" min="0"></div>' +
      '</div>' +
      '<div><label style="margin-top:11px">Note (optional)</label><input id="sinNote" placeholder="Supplier, bill no…"></div>' +
      '<p class="hint">Leave the rate blank to keep the current average cost. '+
        'Stock ghatana ho to manfi quantity likhein (jaise <b>-3</b>) &mdash; wo durusti '+
        'ke taur par darj hoti hai, kharidari ke taur par nahi.</p>' +
      '<div class="on-hand" id="sinOn"></div>' +
      '</div>' +
      '<div class="ft"><button class="btn" data-x>Cancel</button>' +
      '<button class="btn p" id="sinGo">Add to stock</button></div></div>';

    document.body.appendChild(ov);
    function close() { ov.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    Array.prototype.forEach.call(ov.querySelectorAll('[data-x]'), function (b) { b.onclick = close; });

    var sel  = ov.querySelector('#sinItem');
    var rate = ov.querySelector('#sinRate');
    var err  = ov.querySelector('#sinErr');

    /* Maujooda stock dikhao — banda dekh kar daale, andhera mein nahi. */
    function refresh() {
      var o = sel.options[sel.selectedIndex];
      var c = o ? Number(o.getAttribute('data-c') || 0) : 0;
      rate.placeholder = c.toFixed(2);
      var d = req('stock-day');
      var row = null;
      if (d && d.ok) (d.rows || []).forEach(function (r) { if (r.id === sel.value) row = r; });
      ov.querySelector('#sinOn').innerHTML = row
        ? ('In stock now: <b>' + n3(row.expected) + '</b> ' + esc(row.unit || '') +
           ' &nbsp;·&nbsp; today received ' + n3(row.received) + ', sold ' + n3(row.sold))
        : 'No movement recorded for this item yet today.';
    }
    sel.onchange = refresh;
    refresh();
    setTimeout(function () { try { ov.querySelector('#sinQty').focus(); } catch (e) {} }, 30);

    ov.querySelector('#sinGo').onclick = function () {
      err.style.display = 'none';
      var qty = Number(ov.querySelector('#sinQty').value || 0);
      /* Sifar se kuch nahi hota, sirf ek khali entry ledger mein parti
         hai — is liye wo mana hai. Manfi chalti hai (durusti). */
      if (!qty) { err.textContent = 'Enter a quantity (ghatane ke liye manfi, jaise -3)';
                  err.style.display = 'block'; return; }
      var btn = this;
      btn.disabled = true; btn.textContent = 'Adding…';
      var r = req('stock-in', {
        item_id: sel.value, qty: qty,
        rate: Number(rate.value || 0),
        note: ov.querySelector('#sinNote').value || ''
      });
      btn.disabled = false; btn.textContent = 'Add to stock';
      if (!r || !r.ok) { err.textContent = (r && r.message) || 'Could not add stock'; err.style.display = 'block'; return; }
      close();
      /* Manfi par "added" likhna jhoot hai — alag jumla. */
      note(r.qty < 0
            ? (n3(Math.abs(r.qty)) + ' × ' + r.name + ' kam kar diya — ab ' + n3(r.on_hand) + ' in stock')
            : (n3(r.qty) + ' × ' + r.name + ' added — now ' + n3(r.on_hand) + ' in stock'));
      if (opts.onDone) opts.onDone(r);
    };

    return ov;
  }

  window.StockIn = { open: open };
})();

/* build: V147 build 2026-10-12 */
