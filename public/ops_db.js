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
    var r = req('shiftmgr-list');
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
          } },
        { l: '', f: function (x) {
            /* Closing report hamesha 80mm par — wahi kaghaz jo counter
               par laga hota hai. */
            return '<button class="btn sm" data-shrep="' + esc(x.id) + '">Print report</button>';
          } }
      ], r.shifts || []), tools));

    host().addEventListener('click', function (e) {
      var b = e.target.closest('[data-shrep]');
      if (!b) return;
      window.open('/api.php?action=shiftmgr-report-pdf&id=' + encodeURIComponent(b.getAttribute('data-shrep')), '_blank');
    });

    var ob = document.getElementById('shOpen');
    if (ob) ob.onclick = function () {
      var v = prompt('Opening cash in the till (PKR):', '0');
      if (v === null) return;
      var res = req('shiftmgr-open', { opening_cash: Number(v || 0) });
      if (!res.ok) { toast(res.message || 'Could not open shift', true); return; }
      toast(res.message); shiftPage();
    };

    var cb = document.getElementById('shClose');
    if (cb) cb.onclick = function () {
      var exp = req('shiftmgr-expected&id=' + encodeURIComponent(open.id));
      var expected = (exp && exp.ok) ? Number(exp.expected || 0) : null;
      /* Expected pehle DIKHATE hain, phir counted maangte hain — yehi
         tareeqa cash chori pakarta hai. Ulta karein to cashier expected
         dekh kar wahi likh deta hai. */
      var msg = 'Count the cash in the till and enter the total (PKR).';
      var v = prompt(msg, '');
      if (v === null) return;
      var res = req('shiftmgr-close', { id: open.id, counted: Number(v || 0) });
      if (!res.ok) { toast(res.message || 'Could not close shift', true); return; }
      alert('Expected in till: ' + money(res.expected)
          + '\nYou counted:     ' + money(res.counted)
          + '\nDifference:      ' + (res.variance > 0 ? '+' : '') + money(res.variance));
      toast(res.message);
      /* Shift close hote hi closing report khud khul jati hai — cashier
         ko yaad rakhna na pare, aur raat ko counter par hamesha ek
         kaghaz nikle. */
      window.open('/api.php?action=shiftmgr-report-pdf&id=' + encodeURIComponent(open.id), '_blank');
      shiftPage();
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

  /* ==================== STOCK TRANSFER ==================== */
  function transferPage() {
    var r = req('transfer-list');
    if (!r || !r.ok) { replace(panel('Stock Transfer', '', '<div style="padding:18px" class="hint">'
      + esc((r && r.message) || 'Could not load transfers') + '</div>')); return; }
    replace(panel('Stock Transfer', 'Stock moved between branches',
      table([
        { l: 'Reference', k: 'ref' }, { l: 'Date', k: 'date' },
        { l: 'From', f: function (x) { return esc(x.from); } },
        { l: 'To', f: function (x) { return esc(x.to); } },
        { l: 'Items', n: 1, k: 'lines' },
        { l: 'By', f: function (x) { return esc(x.by); } },
        { l: 'Status', f: function (x) { return '<span class="tag green">' + esc(x.status) + '</span>'; } }
      ], r.rows || []),
      '<button class="btn primary" id="trNew">New transfer</button>'));

    var b = document.getElementById('trNew');
    if (b) b.onclick = function () {
      alert('To move stock between branches, open the branch that HAS the stock, '
          + 'then choose the item and quantity.\n\n'
          + 'This screen shows every transfer and the stock moves on both sides.');
    };
  }

  /* ==================== PHYSICAL STOCK COUNT ==================== */
  function countPage() {
    var r = req('count-list');
    if (!r || !r.ok) { replace(panel('Physical Stock Count', '', '<div style="padding:18px" class="hint">'
      + esc((r && r.message) || 'Could not load counts') + '</div>')); return; }
    replace(panel('Physical Stock Count', 'Shelf counts and the adjustments they made',
      table([
        { l: 'Reference', k: 'ref' }, { l: 'Location', f: function (x) { return esc(x.location); } },
        { l: 'Started', k: 'started' }, { l: 'Completed', f: function (x) { return esc(x.done || '-'); } },
        { l: 'Items', n: 1, k: 'lines' }, { l: 'By', f: function (x) { return esc(x.by); } },
        { l: 'Status', f: function (x) { return '<span class="tag green">' + esc(x.status) + '</span>'; } }
      ], r.rows || [])));
  }

  /* ==================== ACCOUNTING / CASH ==================== */
  function cashPage() {
    var r = req('cash-book');
    if (!r || !r.ok) { replace(panel('Accounting / Cash', '', '<div style="padding:18px" class="hint">'
      + esc((r && r.message) || 'Could not load the cash book') + '</div>')); return; }
    var rows = r.rows || [];
    var t = rows.reduce(function (a, x) {
      a.ci += +x.cash_in || 0; a.ka += +x.card_in || 0; a.co += +x.cash_out || 0; a.n += +x.net || 0; return a;
    }, { ci: 0, ka: 0, co: 0, n: 0 });

    replace(panel('Accounting / Cash', 'Money in and out, ' + esc(r.from) + ' to ' + esc(r.to),
      '<div class="note" style="margin:12px">Cash in <b>' + money(t.ci) + '</b> &middot; '
      + 'Card / online <b>' + money(t.ka) + '</b> &middot; Expenses <b>' + money(t.co) + '</b> &middot; '
      + 'Net <b>' + money(t.n) + '</b></div>'
      + table([
        { l: 'Date', k: 'date' },
        { l: 'Cash in', n: 1, f: function (x) { return money(x.cash_in); } },
        { l: 'Card / online', n: 1, f: function (x) { return money(x.card_in); } },
        { l: 'Expenses', n: 1, f: function (x) { return money(x.cash_out); } },
        { l: 'Net', n: 1, f: function (x) {
            var v = +x.net || 0;
            return '<span style="' + (v < 0 ? 'color:#a3222d' : '') + '">' + money(v) + '</span>';
          } }
      ], rows)));
  }

  /* ==================== ONLINE ORDERS ==================== */
  function onlinePage() {
    var r = req('online-orders');
    if (!r || !r.ok) { replace(panel('Online Orders', '', '<div style="padding:18px" class="hint">'
      + esc((r && r.message) || 'Could not load orders') + '</div>')); return; }
    replace(panel('Online Orders', 'Delivery and QR orders from the last 3 days',
      table([
        { l: 'Bill', k: 'bill' }, { l: 'Source', k: 'source' }, { l: 'Mode', k: 'mode' },
        { l: 'Customer', f: function (x) { return esc(x.customer) + (x.phone ? ' <span class="hint">' + esc(x.phone) + '</span>' : ''); } },
        { l: 'Rider', f: function (x) { return esc(x.rider || '-'); } },
        { l: 'When', k: 'at' },
        { l: 'Amount', n: 1, f: function (x) { return money(x.amount); } },
        { l: 'Status', f: function (x) {
            var d = x.delivery || x.status;
            return '<span class="tag ' + (String(d).toUpperCase() === 'DELIVERED' ? 'green' : 'info') + '">' + esc(d) + '</span>';
          } }
      ], r.rows || []),
      '<button class="btn" id="onRefresh">Refresh</button>'));
    var b = document.getElementById('onRefresh'); if (b) b.onclick = onlinePage;
  }

  /* ==================== NOTIFICATIONS ==================== */
  function notifyPage() {
    var r = req('notification-log');
    if (!r || !r.ok) { replace(panel('WhatsApp / Notifications', '', '<div style="padding:18px" class="hint">'
      + esc((r && r.message) || 'Could not load messages') + '</div>')); return; }
    var rows = r.rows || [];
    var failed = rows.filter(function (x) { return String(x.status).toUpperCase() === 'FAILED'; }).length;
    replace(panel('WhatsApp / Notifications', 'Messages the system tried to send',
      (failed ? '<div class="note" style="margin:12px;background:var(--danger-soft);color:var(--danger)">'
        + failed + ' message(s) failed to send.</div>' : '')
      + table([
        { l: 'When', k: 'when' }, { l: 'Channel', k: 'channel' },
        { l: 'To', f: function (x) { return esc(x.to); } },
        { l: 'Template', f: function (x) { return esc(x.template); } },
        { l: 'Tries', n: 1, k: 'attempts' },
        { l: 'Status', f: function (x) {
            var st = String(x.status).toUpperCase();
            return '<span class="tag ' + (st === 'SENT' ? 'green' : (st === 'FAILED' ? 'red' : 'info')) + '">'
              + esc(x.status) + '</span>';
          } },
        { l: 'Error', f: function (x) { return esc(x.error || ''); } }
      ], rows)));
  }

  /* ==================== CLOSING HISTORY ====================
     Agar automatic print band ho gaya, printer khali tha, ya kaghaz
     khatam — purani closing report dobara chhapni chahiye. Cashier
     sirf APNI dekhta hai; yeh filter server par lagta hai. */
  function closingPage() {
    var f = document.getElementById('chFrom'), t = document.getElementById('chTo');
    var qs = (f && f.value ? '&from=' + encodeURIComponent(f.value) : '')
           + (t && t.value ? '&to=' + encodeURIComponent(t.value) : '');
    var r = req('closing-history' + qs);
    if (!r || !r.ok) { replace(panel('Closing history', '', '<div style="padding:18px" class="hint">'
      + esc((r && r.message) || 'Could not load closing history') + '</div>')); return; }

    var rows = r.rows || [];
    var tools = '<label class="field" style="margin:0"><span>From</span>'
      + '<input type="date" id="chFrom" value="' + esc(r.from) + '"></label>'
      + '<label class="field" style="margin:0"><span>To</span>'
      + '<input type="date" id="chTo" value="' + esc(r.to) + '"></label>'
      + '<button class="btn" id="chGo">Show</button>';

    replace(panel('Shift Closing History',
      r.can_see_all ? 'All cashiers' : 'Your own closings only',
      table([
        { l: 'Reference', k: 'ref' },
        { l: 'Date', k: 'date' },
        { l: 'Cashier', f: function (x) { return esc(x.cashier); } },
        { l: 'Counter', f: function (x) { return esc(x.counter || '-'); } },
        { l: 'Closed', k: 'closed' },
        { l: 'Invoices', n: 1, k: 'invoices' },
        { l: 'Sales', n: 1, f: function (x) { return money(x.sales); } },
        { l: 'Variance', n: 1, f: function (x) {
            var v = Number(x.variance || 0);
            return '<span style="' + (v === 0 ? '' : (v < 0 ? 'color:#a3222d' : 'color:#b3541e')) + '">'
              + (v > 0 ? '+' : '') + money(v) + '</span>';
          } },
        { l: '', f: function (x) {
            return '<button class="btn sm" data-chprint="' + esc(x.id) + '">Print</button>'
              + (x.saved ? '' : ' <span class="hint" title="This shift was closed on an older build, so the report is rebuilt from live data">rebuilt</span>');
          } }
      ], rows), tools));

    var g = document.getElementById('chGo');
    if (g) g.onclick = closingPage;
    host().addEventListener('click', function (e) {
      var b = e.target.closest('[data-chprint]');
      if (!b) return;
      window.open('/api.php?action=shiftmgr-report-pdf&id='
        + encodeURIComponent(b.getAttribute('data-chprint')), '_blank');
    });
  }

  /* ==================== ACTIVITY LOG ====================
     Sirf Owner/Manager. Yeh record MUSTAQIL hai — koi UI se ise badal
     ya mita nahi sakta. */
  function auditPage() {
    var q = document.getElementById('alQ'), a = document.getElementById('alAct');
    var qs = (q && q.value ? '&q=' + encodeURIComponent(q.value) : '')
           + (a && a.value ? '&action=' + encodeURIComponent(a.value) : '');
    var r = req('activity-log' + qs);
    if (!r || !r.ok) { replace(panel('User Activity Log', '',
      '<div style="padding:18px" class="hint">' + esc((r && r.message) || 'Not permitted') + '</div>')); return; }

    var acts = r.actions || [];
    var tools = '<input id="alQ" placeholder="Search user or record" style="height:36px;padding:0 10px;'
      + 'border:1px solid var(--line);border-radius:8px" value="' + esc(q ? q.value : '') + '">'
      + '<select id="alAct"><option value="">All actions</option>'
      + acts.map(function (x) { return '<option value="' + esc(x) + '">' + esc(x) + '</option>'; }).join('')
      + '</select><button class="btn" id="alGo">Search</button>';

    replace(panel('User Activity Log', esc(r.from) + ' to ' + esc(r.to) + ' \u00b7 read-only',
      table([
        { l: 'When', f: function (x) { return esc(String(x.created_at).slice(0, 19)); } },
        { l: 'User', f: function (x) { return esc(x.username) + ' <span class="hint">' + esc(x.role_name || '') + '</span>'; } },
        { l: 'Action', f: function (x) { return '<span class="tag">' + esc(x.action) + '</span>'; } },
        { l: 'Module', f: function (x) { return esc(x.module || '-'); } },
        { l: 'Record', f: function (x) { return esc(x.record_label || '-'); } },
        { l: 'Details', f: function (x) {
            var d = x.description || '';
            if (x.old_value || x.new_value) d += (d ? ' \u00b7 ' : '') + esc(x.old_value || '') + ' \u2192 ' + esc(x.new_value || '');
            return d || '-';
          } }
      ], r.rows || []), tools));

    var g = document.getElementById('alGo');
    if (g) g.onclick = auditPage;
    var qq = document.getElementById('alQ');
    if (qq) qq.onkeydown = function (e) { if (e.key === 'Enter') auditPage(); };
    if (a) document.getElementById('alAct').value = a.value;
  }

  /* ==================== PURCHASE ORDERS ====================
     Ab tak sirf GRN tha ("maal aa gaya"). Supplier ko order bhejne ka
     rasta nahi tha, aur na yeh pata chalta tha ke kya mangwaya hua hai
     magar aaya nahi. */
  function poPage() {
    var r = req('po-list');
    if (!r || !r.ok) { replace(panel('Purchase Orders', '', '<div style="padding:18px" class="hint">'
      + esc((r && r.message) || 'Could not load purchase orders') + '</div>')); return; }
    var rows = r.rows || [];
    var open = rows.filter(function (x) { return x.status !== 'Received' && x.status !== 'Cancelled'; });

    replace(panel('Purchase Orders', open.length + ' order(s) still awaiting delivery',
      table([
        { l: 'PO', k: 'po_no' },
        { l: 'Supplier', f: function (x) { return esc(x.supplier); } },
        { l: 'Ordered', k: 'date' },
        { l: 'Expected', f: function (x) { return esc(x.expected || '-'); } },
        { l: 'Items', n: 1, k: 'items' },
        { l: 'Received', f: function (x) {
            var pc = x.ordered > 0 ? Math.round(x.received / x.ordered * 100) : 0;
            return pc + '%';
          } },
        { l: 'Amount', n: 1, f: function (x) { return money(x.amount); } },
        { l: 'Status', f: function (x) {
            var t = x.status === 'Received' ? 'green' : (x.status === 'Cancelled' ? 'red' : 'info');
            return '<span class="tag ' + t + '">' + esc(x.status) + '</span>';
          } },
        { l: '', f: function (x) {
            return (x.status === 'Received' || x.status === 'Cancelled') ? ''
              : '<button class="btn sm danger" data-pocancel="' + esc(x.id) + '" data-no="' + esc(x.po_no) + '">Cancel</button>';
          } }
      ], rows),
      '<button class="btn primary" id="poNew">New purchase order</button>'));

    var nb = document.getElementById('poNew');
    if (nb) nb.onclick = poNew;

    host().addEventListener('click', function (e) {
      var b = e.target.closest('[data-pocancel]');
      if (!b) return;
      var why = prompt('Why is ' + b.getAttribute('data-no') + ' being cancelled?');
      if (why === null || !why.trim()) { toast('A reason is required', true); return; }
      var res = req('po-cancel', { id: b.getAttribute('data-pocancel'), reason: why });
      if (!res.ok) { toast(res.message || 'Could not cancel', true); return; }
      toast(res.message); poPage();
    });
  }

  function poNew() {
    var st = req('store-state');
    var s = (st && st.ok && st.state) ? st.state : {};
    var sup = s.suppliers || [], items = s.inventoryItems || [];
    if (!sup.length) { toast('Add a supplier first', true); return; }
    if (!items.length) { toast('Add inventory items first', true); return; }

    var ov = document.createElement('div'); ov.className = 'modal show';
    ov.innerHTML = '<div class="dialog" style="width:min(680px,96vw)">'
      + '<div class="dialog-head"><div><h3>New purchase order</h3>'
      + '<p>What to order, and from whom</p></div><button class="close" data-x>&times;</button></div>'
      + '<div class="dialog-body"><div class="form-grid">'
      + '<label class="field"><span>Supplier</span><select id="poSup">'
      + sup.map(function (x) { return '<option value="' + esc(x.id) + '">' + esc(x.name) + '</option>'; }).join('')
      + '</select></label>'
      + '<label class="field"><span>Expected date</span><input id="poExp" type="date"></label>'
      + '</div><div id="poLines" style="margin-top:12px"></div>'
      + '<button class="btn sm" id="poAdd" style="margin-top:8px">+ Add item</button>'
      + '</div><div class="dialog-foot"><button class="btn" data-x>Cancel</button>'
      + '<button class="btn primary" id="poSave">Create order</button></div></div>';
    document.body.appendChild(ov);
    ov.addEventListener('click', function (e) { if (e.target === ov || e.target.closest('[data-x]')) ov.remove(); });

    function addLine() {
      var d = document.createElement('div');
      d.className = 'po-line';
      d.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:center';
      d.innerHTML = '<select class="poItem" style="flex:2">'
        + items.map(function (x) { return '<option value="' + esc(x.id) + '">' + esc(x.name) + '</option>'; }).join('')
        + '</select>'
        + '<input class="poQty" type="number" step="0.01" placeholder="Qty" style="flex:1">'
        + '<input class="poCost" type="number" step="0.01" placeholder="Unit cost" style="flex:1">'
        + '<button class="btn sm" data-rm>&times;</button>';
      d.querySelector('[data-rm]').onclick = function () { d.remove(); };
      document.getElementById('poLines').appendChild(d);
    }
    ov.querySelector('#poAdd').onclick = addLine;
    addLine();

    ov.querySelector('#poSave').onclick = function () {
      var lines = [].slice.call(ov.querySelectorAll('.po-line')).map(function (d) {
        return { item_id: d.querySelector('.poItem').value,
                 qty: Number(d.querySelector('.poQty').value || 0),
                 cost: Number(d.querySelector('.poCost').value || 0) };
      }).filter(function (l) { return l.qty > 0; });
      if (!lines.length) { toast('Add at least one item with a quantity', true); return; }
      var res = req('po-create', { supplier_id: ov.querySelector('#poSup').value,
                                   expected_date: ov.querySelector('#poExp').value, items: lines });
      if (!res.ok) { toast(res.message || 'Could not create the order', true); return; }
      toast(res.message); ov.remove(); poPage();
    };
  }

  function boot() {
    if (!window.DBApi) return;
    if (page === 'shift_management.html') shiftPage();
    else if (page === 'orders_management.html') ordersPage();
    else if (page === 'void_refund.html') voidPage();
    else if (page === 'stock_transfer.html') transferPage();
    else if (page === 'stock_count.html') countPage();
    else if (page === 'accounting.html') cashPage();
    else if (page === 'online_orders.html') onlinePage();
    else if (page === 'whatsapp_notifications.html') notifyPage();
    else if (page === 'closing_history.html') closingPage();
    else if (page === 'activity_log.html') auditPage();
    else if (page === 'purchase_orders.html') poPage();
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
