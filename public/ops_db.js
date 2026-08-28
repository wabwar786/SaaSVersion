/* ============================================================
   ops_db.js — Shift / Running Orders / Void ab ASLI tables par.

   MASLA JO YEH THEEK KARTA HAI
   Teenon pages `module.js` ke generic UI par the aur `ui_records` mein
   likhte the. Yani:
     • Shift mein cash count karein  -> `cashier_shifts` khali rehti
     • Running Orders mein order banayein -> POS ko pata hi nahi chalta
     • Void mein entry karein        -> bill waqai void hota hi nahi

   Teenon rozana ke kaam hain, aur teenon jhoot bol rahe the.

   TAREEQA
   Page ka design nahi badla. `module.js` ki render ho jane ke baad us ka
   content asli data se badal diya jata hai, aur actions asli endpoints
   par jate hain (`shift-open`, `shift-close`, `running-orders`,
   `void-log`, `order-void`).
   ============================================================ */
(function () {
  'use strict';

  var page = (location.pathname.split('/').pop() || '').toLowerCase();

  function req(a, p) {
    try { return window.DBApi ? DBApi.req(a, p) : { ok: false, message: 'No server connection' }; }
    catch (e) { return { ok: false, message: e.message || 'Request failed' }; }
  }
  function esc(t) {
    return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function money(n) { return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }); }
  function toast(m, err) {
    var t = document.getElementById('toast');
    if (!t) { t = document.createElement('div'); t.id = 'toast'; document.body.appendChild(t); }
    t.textContent = m; t.className = 'toast show' + (err ? ' err' : '');
    clearTimeout(window.__ot); window.__ot = setTimeout(function () { t.className = 'toast'; }, 2600);
  }

  /* module.js ka table jahan render hota hai — usi jagah apna content. */
  function host() {
    return document.querySelector('.content') || document.querySelector('.main') || document.body;
  }
  function panel(title, sub, body, tools) {
    return '<section class="panel"><div class="panel-head"><div><h2>' + esc(title) + '</h2>'
      + '<p>' + esc(sub) + '</p></div>' + (tools ? ('<div class="tools">' + tools + '</div>') : '')
      + '</div><div class="panel-body flush">' + body + '</div></section>';
  }
  function table(cols, rows) {
    if (!rows.length) return '<div style="padding:18px" class="hint">Nothing to show yet.</div>';
    var h = '<div class="table-wrap"><table class="table"><thead><tr>';
    cols.forEach(function (c) { h += '<th' + (c.n ? ' class="num"' : '') + '>' + esc(c.l) + '</th>'; });
    h += '</tr></thead><tbody>';
    rows.forEach(function (r) {
      h += '<tr>';
      cols.forEach(function (c, i) {
        var v = c.f ? c.f(r) : r[c.k];
        h += '<td' + (c.n ? ' class="num"' : (i === 0 ? ' class="t-main"' : '')) + '>' + v + '</td>';
      });
      h += '</tr>';
    });
    return h + '</tbody></table></div>';
  }
  function replace(html) {
    var el = host();
    if (el) el.innerHTML = html;
  }

  /* ==================== SHIFT ==================== */
  function shiftPage() {
    var r = req('shift-list');
    if (!r || !r.ok) { replace(panel('Shifts', '', '<div style="padding:18px" class="hint">'
      + esc((r && r.message) || 'Could not load shifts') + '</div>')); return; }

    var open = r.open;
    var tools = open
      ? '<button class="btn primary" id="shClose">Close shift</button>'
      : '<button class="btn primary" id="shOpen">Open shift</button>';

    var top = open
      ? '<div class="note" style="margin:12px">Shift <b>' + esc(open.shift) + '</b> is open since '
        + esc(open.opened) + ' &middot; opening float <b>' + money(open.opening) + '</b></div>'
      : '<div class="note" style="margin:12px">No shift is open. Open one before taking payments.</div>';

    replace(panel('Opening &amp; Closing Shift', 'Cashier tills, opening float and closing cash',
      top + table([
        { l: 'Shift', k: 'shift' }, { l: 'Date', k: 'date' }, { l: 'Cashier', k: 'cashier' },
        { l: 'Opened', k: 'opened' }, { l: 'Closed', f: function (x) { return esc(x.closed || '-'); } },
        { l: 'Opening', n: 1, f: function (x) { return money(x.opening); } },
        { l: 'Expected', n: 1, f: function (x) { return x.status === 'Open' ? '-' : money(x.expected); } },
        { l: 'Counted', n: 1, f: function (x) { return x.status === 'Open' ? '-' : money(x.counted); } },
        { l: 'Variance', n: 1, f: function (x) {
            if (x.status === 'Open') return '-';
            var v = Number(x.variance || 0);
            var col = v === 0 ? '' : (v < 0 ? 'color:#a3222d' : 'color:#b3541e');
            return '<span style="' + col + '">' + (v > 0 ? '+' : '') + money(v) + '</span>';
          } },
        { l: 'Status', f: function (x) {
            return '<span class="tag ' + (x.status === 'Open' ? 'green' : '') + '">' + esc(x.status) + '</span>';
          } }
      ], r.shifts || []), tools));

    var ob = document.getElementById('shOpen');
    if (ob) ob.onclick = function () {
      var v = prompt('Opening cash in the till (PKR):', '0');
      if (v === null) return;
      var res = req('shift-open', { opening_cash: Number(v || 0) });
      if (!res.ok) { toast(res.message || 'Could not open shift', true); return; }
      toast(res.message); shiftPage();
    };

    var cb = document.getElementById('shClose');
    if (cb) cb.onclick = function () {
      var exp = req('shift-expected&id=' + encodeURIComponent(open.id));
      var expected = (exp && exp.ok) ? Number(exp.expected || 0) : null;
      /* Expected pehle DIKHATE hain, phir counted maangte hain — yehi
         tareeqa cash chori pakarta hai. Ulta karein to cashier expected
         dekh kar wahi likh deta hai. */
      var msg = 'Count the cash in the till and enter the total (PKR).';
      var v = prompt(msg, '');
      if (v === null) return;
      var res = req('shift-close', { id: open.id, counted: Number(v || 0) });
      if (!res.ok) { toast(res.message || 'Could not close shift', true); return; }
      alert('Expected in till: ' + money(res.expected)
          + '\nYou counted:     ' + money(res.counted)
          + '\nDifference:      ' + (res.variance > 0 ? '+' : '') + money(res.variance));
      toast(res.message); shiftPage();
    };
  }

  /* ==================== RUNNING ORDERS ==================== */
  function ordersPage() {
    var r = req('running-orders');
    if (!r || !r.ok) { replace(panel('Running orders', '', '<div style="padding:18px" class="hint">'
      + esc((r && r.message) || 'Could not load orders') + '</div>')); return; }

    var rows = r.orders || [];
    var total = rows.reduce(function (a, x) { return a + Number(x.amount || 0); }, 0);
    var head = '<div class="note" style="margin:12px">' + rows.length + ' open bill(s) &middot; '
             + 'value <b>' + money(total) + '</b></div>';

    replace(panel('Running Orders', 'Bills that are still open on the POS',
      head + table([
        { l: 'Bill', k: 'bill' },
        { l: 'Mode', k: 'mode' },
        { l: 'Table', f: function (x) { return esc(x.table || '-'); } },
        { l: 'Waiter', f: function (x) { return esc(x.waiter || '-'); } },
        { l: 'Items', n: 1, k: 'items' },
        { l: 'Open for', f: function (x) {
            var m = Number(x.open_min || 0);
            var s = m >= 60 ? (Math.floor(m / 60) + 'h ' + (m % 60) + 'm') : (m + 'm');
            return m >= 45 ? '<span style="color:#a3222d">' + s + '</span>' : s;
          } },
        { l: 'Amount', n: 1, f: function (x) { return money(x.amount); } }
      ], rows),
      '<button class="btn" id="ordRefresh">Refresh</button>'));

    var b = document.getElementById('ordRefresh');
    if (b) b.onclick = ordersPage;
  }

  /* ==================== VOID / REFUND ==================== */
  function voidPage() {
    var r = req('void-log');
    if (!r || !r.ok) { replace(panel('Void / Refund', '', '<div style="padding:18px" class="hint">'
      + esc((r && r.message) || 'Could not load bills') + '</div>')); return; }

    var rows = r.rows || [];
    replace(panel('Void / Refund', 'Last 7 days of closed and voided bills',
      table([
        { l: 'Date', k: 'date' },
        { l: 'Bill', k: 'bill' },
        { l: 'Cashier', f: function (x) { return esc(x.cashier); } },
        { l: 'Amount', n: 1, f: function (x) { return money(x.amount); } },
        { l: 'Status', f: function (x) {
            return '<span class="tag ' + (x.status === 'Voided' ? 'red' : 'green') + '">' + esc(x.status) + '</span>';
          } },
        { l: 'Reason', f: function (x) { return esc(x.reason || '-'); } },
        { l: '', f: function (x) {
            return x.status === 'Voided' ? ''
              : '<button class="btn sm danger" data-void="' + esc(x.id) + '" data-bill="' + esc(x.bill) + '">Void</button>';
          } }
      ], rows)));

    host().addEventListener('click', function (e) {
      var b = e.target.closest('[data-void]');
      if (!b) return;
      /* Bill DELETE nahi hota — VOID hota hai, wajah aur manager password
         ke saath. Bill number history mein rehta hai aur stock wapas aata
         hai (DeleteService::voidOrder). */
      var reason = prompt('Why is bill ' + b.getAttribute('data-bill') + ' being voided?');
      if (reason === null || !reason.trim()) { toast('A reason is required', true); return; }
      var pw = prompt('Manager password:');
      if (pw === null || !pw) { toast('Manager password is required', true); return; }
      var res = req('order-void', { id: b.getAttribute('data-void'), reason: reason, manager_password: pw });
      if (!res.ok) { toast(res.message || 'Could not void the bill', true); return; }
      toast(res.message || 'Bill voided'); voidPage();
    });
  }

  function boot() {
    if (!window.DBApi) return;
    if (page === 'shift_management.html') shiftPage();
    else if (page === 'orders_management.html') ordersPage();
    else if (page === 'void_refund.html') voidPage();
  }

  /* module.js ke render ke BAAD chalna hai, warna wo hamara content
     dobara likh deta hai. */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { setTimeout(boot, 30); });
  } else {
    setTimeout(boot, 30);
  }
})();

/* build: V70 build 2026-08-27 */
