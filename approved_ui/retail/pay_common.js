/* ============================================================
   V212 — RETAIL SUPPLIER PAYABLES: shared helpers for the back-office
   pages (Suppliers, Balances, Ledger, Payment, Payment Slips, Purchase
   Register) and the "Payment slip" button on Purchase Entry.
   Server (RetailPayables) checks everything again.
   ============================================================ */
(function () {
  'use strict';
  var P = window.PAY = {};
  var $ = P.$ = function (id) { return document.getElementById(id); };

  P.api = function (action, payload) {
    var x = new XMLHttpRequest(), get = payload === undefined;
    x.open(get ? 'GET' : 'POST', '/api.php?action=' + action + (get ? '&_=' + Date.now() : ''), false);
    x.setRequestHeader('Accept', 'application/json');
    if (!get) { x.setRequestHeader('Content-Type', 'application/json'); x.setRequestHeader('X-CSRF-Token', window.APP_CSRF || ''); }
    try { x.send(get ? null : JSON.stringify(payload)); } catch (e) { return { ok: false, message: 'Server not reachable' }; }
    var r = {}; try { r = JSON.parse(x.responseText || '{}'); } catch (e) { r = { ok: false, message: 'HTTP ' + x.status }; }
    if (r && r.csrf && !P.api._retry) {
      P.api._retry = true;
      try { var t = new XMLHttpRequest(); t.open('GET', '/api.php?action=csrf-token', false); t.send(); var j = JSON.parse(t.responseText || '{}'); if (j.token) window.APP_CSRF = j.token; } catch (e) { }
      var again = P.api(action, payload); P.api._retry = false; return again;
    }
    return r;
  };
  P.q = function (o) { var a = []; for (var k in o) if (o[k] !== '' && o[k] != null) a.push(k + '=' + encodeURIComponent(o[k])); return a.join('&'); };
  P.esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  /* Pakistani grouping: 1,84,250 */
  P.f = function (x, dec) {
    var n = Number(x || 0), neg = n < 0; n = Math.abs(n);
    var s = n.toFixed(dec === undefined ? (Math.round(n) === n ? 0 : 2) : dec), parts = s.split('.'), i = parts[0];
    var last3 = i.slice(-3), rest = i.slice(0, -3);
    if (rest) last3 = ',' + last3;
    rest = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',');
    return (neg ? '−' : '') + rest + last3 + (parts[1] ? '.' + parts[1] : '');
  };
  P.num = function (v) { var n = parseFloat(String(v).replace(/[,\s]/g, '').replace('−', '-')); return isNaN(n) ? 0 : n; };
  P.fmtD = function (v) { var m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']; v = String(v || ''); return v.length >= 10 ? v.slice(8, 10) + '-' + m[+v.slice(5, 7) - 1] + '-' + v.slice(2, 4) : v; };
  P.toast = function (m, e) {
    var t = $('toast'); if (!t) { alert(m); return; }
    t.textContent = m; t.className = 'toast2' + (e ? ' err' : ''); t.style.display = 'block';
    clearTimeout(P.toast.t); P.toast.t = setTimeout(function () { t.style.display = 'none'; }, e ? 5000 : 2600);
  };
  P.daysTo = function (a, b) { return Math.round((Date.parse(b) - Date.parse(a)) / 86400000); };

  /* Rupees in words, lakh / crore. */
  P.words = function (n) {
    n = Math.round(Number(n || 0));
    if (!n) return 'Zero rupees only';
    var o = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    var t = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    function two(x) { return x < 20 ? o[x] : t[Math.floor(x / 10)] + (x % 10 ? ' ' + o[x % 10] : ''); }
    function three(x) { return (x >= 100 ? o[Math.floor(x / 100)] + ' hundred' + (x % 100 ? ' ' : '') : '') + (x % 100 ? two(x % 100) : ''); }
    var parts = [], cr = Math.floor(n / 10000000); n %= 10000000;
    var lk = Math.floor(n / 100000); n %= 100000; var th = Math.floor(n / 1000); n %= 1000;
    if (cr) parts.push(three(cr) + ' crore'); if (lk) parts.push(two(lk) + ' lakh'); if (th) parts.push(two(th) + ' thousand'); if (n) parts.push(three(n));
    var s = parts.join(' ') + ' rupees only';
    return s.charAt(0).toUpperCase() + s.slice(1);
  };
  P.modeLab = { CASH: 'Cash', CHEQUE: 'Cheque', BANK: 'Bank', JAZZCASH: 'JazzCash', EASYPAISA: 'Easypaisa' };

  /* ---------- printing (hidden iframe; nothing leaves the page) ---------- */
  var PCSS = 'body{font:12.5px/1.4 "Segoe UI",Arial,sans-serif;color:#111;margin:14px}h3{margin:0 0 2px;font-size:15px}' +
    '.mini{font-size:11px;color:#555}table{width:100%;border-collapse:collapse;margin-top:6px}th,td{padding:4px 6px;border-bottom:1px solid #ddd;text-align:left}' +
    'th{font-size:11px;color:#333;background:#f3f3f3}.n{text-align:right}.box{border:2px solid #111;border-radius:4px;padding:7px 10px;display:flex;margin:10px 0}' +
    '.box b{font-size:16px}.sp{flex:1}.sig{display:flex;gap:30px;margin-top:34px}.sig div{flex:1;border-top:1px solid #333;padding-top:4px;font-size:11px;color:#555}' +
    '.bc{font-family:"Libre Barcode 39",monospace}.dup{display:inline-block;font-weight:800;color:#b3261e;border:2px solid #b3261e;padding:2px 10px;letter-spacing:2px}' +
    '.slip{width:520px;page-break-inside:avoid;margin-bottom:26px}.cut{border-top:1px dashed #999;margin:10px 0 20px;width:520px}@page{margin:10mm}';
  P.print = function (html) {
    var fr = document.getElementById('payPrintFrame');
    if (!fr) { fr = document.createElement('iframe'); fr.id = 'payPrintFrame'; fr.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0'; document.body.appendChild(fr); }
    var doc = fr.contentWindow.document;
    doc.open(); doc.write('<!doctype html><html><head><meta charset="utf-8"><style>' + PCSS + '</style></head><body>' + html + '</body></html>'); doc.close();
    setTimeout(function () { try { fr.contentWindow.focus(); fr.contentWindow.print(); } catch (e) { } }, 150);
  };
  /* Code 39 as bars (no font needed): each char = 9 elements, 3 wide. */
  P.code39 = function (txt) {
    var C = { '0': 'nnnwwnwnn', '1': 'wnnwnnnnw', '2': 'nnwwnnnnw', '3': 'wnwwnnnnn', '4': 'nnnwwnnnw', '5': 'wnnwwnnnn', '6': 'nnwwwnnnn', '7': 'nnnwnnwnw', '8': 'wnnwnnwnn', '9': 'nnwwnnwnn',
      'P': 'nnwnnnwnw', 'S': 'nnwnwnnwn', '-': 'nwnnnnwnw', '*': 'nwnnwnwnn', 'B': 'nnwnnwnnw', 'V': 'nwwnnnnnw', 'C': 'wnwnnwnnn' };
    var s = '*' + String(txt).toUpperCase() + '*', x = 0, bars = '';
    for (var i = 0; i < s.length; i++) {
      var p = C[s.charAt(i)]; if (!p) continue;
      for (var j = 0; j < 9; j++) { var w = p.charAt(j) === 'w' ? 3 : 1; if (j % 2 === 0) bars += '<rect x="' + x + '" y="0" width="' + w + '" height="40"/>'; x += w; }
      x += 1;
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' + x + '" height="40" viewBox="0 0 ' + x + ' 40" style="max-width:230px">' + bars + '</svg>';
  };
  P.build = '';
  P.biz = '';
  P.slipHtml = function (s, copy) {
    var now = s.slip_type === 'NOW';
    var rows = (s.bills || []).map(function (b) { return '<tr><td>' + P.esc(b.ref) + '</td><td>' + P.esc(b.bill_no || '—') + '</td><td>' + P.fmtD(b.bill_date) + '</td><td class="n">' + P.f(b.amount) + '</td></tr>'; }).join('');
    return '<div class="slip"><div style="display:flex"><div><h3>PAYMENT SLIP</h3><div class="mini">' + P.esc(P.biz) + ' · Inventory Dept.</div></div><div class="sp"></div>' +
      '<div style="text-align:right"><b style="font-size:15px">' + P.esc(s.slip_no) + '</b><div class="mini">Issued ' + P.fmtD(String(s.created_at).slice(0, 10)) + ' ' + String(s.created_at).slice(11, 16) + '</div></div></div>' +
      '<div class="box"><b>' + (now ? 'PAY NOW' : 'PAY ON DATE') + '</b><div class="sp"></div><b>' + (now ? 'Today ' + P.fmtD(s.pay_on) : 'On / after ' + P.fmtD(s.pay_on)) + '</b></div>' +
      '<table><tr><td style="width:110px;color:#555;border:0">Supplier</td><td style="border:0"><b>' + P.esc(s.supplier_name) + '</b> · ' + P.esc(s.supplier_phone || '') + '</td></tr>' +
      '<tr><td style="color:#555;border:0">Amount</td><td style="border:0"><b style="font-size:16px">Rs ' + P.f(s.amount) + '</b><div class="mini">' + P.words(s.amount) + '</div></td></tr></table>' +
      '<table><thead><tr><th>Our ref no.</th><th>Supplier bill no.</th><th>Bill date</th><th class="n">Amount</th></tr></thead><tbody>' + rows + '</tbody></table>' +
      '<div style="display:flex;gap:14px;align-items:flex-end;margin-top:10px"><div>' + P.code39(s.slip_no) + '<div class="mini" style="text-align:center">' + P.esc(s.slip_no) + '</div></div>' +
      '<div class="mini" style="flex:1">' + (now ? 'Take this slip to the Accounts Department for payment.' : 'Bring this slip to the Accounts Department on or after the date above.') + ' Payment is made against this slip only once.' + (s.note ? '<br>Note: ' + P.esc(s.note) : '') + '</div></div>' +
      '<div class="sig"><div>Issued by (Inventory) ' + P.esc(s.created_by_name || '') + '</div><div>Supplier signature</div></div>' +
      '<div class="mini" style="display:flex;margin-top:8px"><span>' + copy + '</span><div class="sp"></div><span>Retail OS · ' + P.esc(P.build) + '</span></div></div>';
  };
  P.printSlip = function (s) { P.print(P.slipHtml(s, 'Supplier copy') + '<div class="cut"></div>' + P.slipHtml(s, 'Office copy')); };

  P.voucherHtml = function (v) {
    var title = v.voucher_type === 'CPV' ? 'CASH PAYMENT VOUCHER (CPV)' : 'BANK PAYMENT VOUCHER (BPV)';
    var rows = (v.alloc || []).map(function (a) { return '<tr><td>' + P.esc(a.ref) + '</td><td>' + P.esc(a.bill_no || '—') + '</td><td>' + P.fmtD(a.bill_date) + '</td><td class="n">' + P.f(a.amount) + (a.from_advance ? ' <span class="mini">(from advance)</span>' : '') + '</td></tr>'; }).join('');
    if (v.advance_left > 0.004) rows += '<tr><td colspan="3">Advance (kept for the next bill)</td><td class="n">' + P.f(v.advance_left) + '</td></tr>';
    var det = [P.modeLab[v.mode] || v.mode];
    if (v.cheque_no) det.push('Cheque ' + v.cheque_no + (v.cheque_date ? ' dated ' + P.fmtD(v.cheque_date) : ''));
    if (v.bank_name) det.push(v.bank_name); if (v.txn_ref) det.push('TID ' + v.txn_ref); if (v.our_account) det.push('from ' + v.our_account);
    return '<div style="width:720px">' + (v.status === 'CANCELLED' ? '<div class="dup">CANCELLED</div> ' : '') +
      '<div style="display:flex"><div><h3>' + title + '</h3><div class="mini">' + P.esc(P.biz) + ' · Accounts</div></div><div class="sp"></div>' +
      '<div style="text-align:right"><b style="font-size:15px">' + P.esc(v.voucher_no) + '</b><div class="mini">' + P.fmtD(v.pay_date) + '</div></div></div>' +
      '<table><tr><td style="width:150px;color:#555">Paid to</td><td><b>' + P.esc(v.supplier_name) + '</b> · ' + P.esc(v.supplier_phone || '') + '</td></tr>' +
      '<tr><td style="color:#555">Mode</td><td>' + P.esc(det.join(' · ')) + '</td></tr>' +
      (v.slip_no ? '<tr><td style="color:#555">Against slip</td><td>' + P.esc(v.slip_no) + ' (' + (v.slip_type === 'NOW' ? 'Pay now' : 'Pay on ' + P.fmtD(v.slip_pay_on)) + ' · issued ' + P.fmtD(v.slip_issued) + ')' + (v.early_ok_name ? ' · paid early, OK by ' + P.esc(v.early_ok_name) : '') + '</td></tr>' : '') +
      '<tr><td style="color:#555">Amount</td><td><b style="font-size:15px">Rs ' + P.f(v.amount) + '</b> (' + P.words(v.amount) + ')</td></tr>' +
      (v.note ? '<tr><td style="color:#555">Note</td><td>' + P.esc(v.note) + '</td></tr>' : '') + '</table>' +
      '<table><thead><tr><th>Our ref no.</th><th>Supplier bill no.</th><th>Bill date</th><th class="n">Paid</th></tr></thead><tbody>' + (rows || '<tr><td colspan="4">Advance payment</td></tr>') + '</tbody></table>' +
      '<div class="mini" style="margin-top:8px">Balance before ' + P.f(v.balance_before) + ' · after ' + P.f(v.balance_after) + '</div>' +
      (v.status === 'CANCELLED' ? '<div class="mini">Cancelled by ' + P.esc(v.cancelled_by_name) + ' · ' + P.esc(v.cancel_reason || '') + '</div>' : '') +
      '<div class="sig"><div>Prepared by (Accounts) ' + P.esc(v.created_by_name || '') + '</div><div>Approved by</div><div>Received by (supplier)</div></div>' +
      '<div class="mini" style="text-align:right;margin-top:8px">Retail OS · ' + P.esc(P.build) + '</div></div>';
  };
  P.printVoucher = function (idOrNo) {
    var r = P.api('rpay-payment&id=' + encodeURIComponent(idOrNo));
    if (!r.ok) { P.toast(r.message || 'Voucher not found', true); return; }
    P.print(P.voucherHtml(r.payment));
  };

  /* Purchase copy: always marked DUPLICATE; each print is logged on the server. */
  P.printPurchase = function (id) {
    var r = P.api('rpay-print-doc', { id: id });
    if (!r.ok) { P.toast(r.message || 'Could not print', true); return; }
    var d = r.doc, ln = (d.lines || []).map(function (l, i) {
      return '<tr><td class="n">' + (i + 1) + '</td><td>' + P.esc(l.barcode || l.sku || '') + '</td><td>' + P.esc(l.product_name || l.name || '') + '</td><td class="n">' + P.f(l.qty) + '</td><td class="n">' + P.f(Number(l.rate) || l.unit_cost, 2) + '</td><td class="n">' + P.f(Number(l.disc1_amt || 0) + Number(l.disc2_amt || 0), 2) + '</td><td class="n">' + P.f(l.gst_amt || 0, 2) + '</td><td class="n">' + P.f(Number(l.net_amt) || l.line_total, 2) + '</td></tr>';
    }).join('');
    P.print('<div style="width:760px"><div style="display:flex;align-items:center"><div><h3>PURCHASE — ' + P.esc(d.doc_no) + '</h3><div class="mini">' + P.esc(P.biz) + '</div></div><div class="sp"></div><span class="dup">DUPLICATE</span></div>' +
      '<table><tr><td style="width:150px;color:#555">Supplier</td><td><b>' + P.esc(d.supplier_name) + '</b></td><td style="width:120px;color:#555">Supplier bill no.</td><td><b>' + P.esc(d.ref_no || '—') + '</b></td></tr>' +
      '<tr><td style="color:#555">Bill date</td><td>' + P.fmtD(d.bill_date || d.doc_date) + '</td><td style="color:#555">Status</td><td>' + P.esc(d.status) + '</td></tr></table>' +
      '<table><thead><tr><th class="n">#</th><th>Barcode</th><th>Item</th><th class="n">Qty</th><th class="n">Rate</th><th class="n">Disc.</th><th class="n">GST</th><th class="n">Net amount</th></tr></thead><tbody>' + ln + '</tbody></table>' +
      '<table style="width:330px;margin-left:auto"><tr><td>Sub total</td><td class="n">' + P.f(d.subtotal, 2) + '</td></tr><tr><td>Discount</td><td class="n">' + P.f(d.discount, 2) + '</td></tr>' +
      '<tr><td>GST</td><td class="n">' + P.f(d.tax_amount, 2) + '</td></tr>' + (Number(d.adv_tax) ? '<tr><td>Adv. tax</td><td class="n">' + P.f(d.adv_tax, 2) + '</td></tr>' : '') +
      '<tr><td><b>Invoice total</b></td><td class="n"><b>' + P.f(d.bill_total != null && d.bill_total !== '' ? d.bill_total : d.total, 2) + '</b></td></tr></table>' +
      '<div class="mini" style="margin-top:8px">Created by ' + P.esc(d.created_by_name || '—') + ' · Verified by ' + P.esc(d.verified_by_name || '—') + ' · Approved by ' + P.esc(d.approved_by_name || '—') + ' · Posted by ' + P.esc(d.posted_by_name || '—') + '</div>' +
      '<div class="mini">Copy no. ' + d.print_no + ' printed ' + P.esc(d.printed_at) + ' by ' + P.esc(d.printed_by_name) + ' · Retail OS ' + P.esc(P.build) + '</div></div>');
  };

  /* ---------- "Print payment slip" dialog (Inventory) ---------- */
  P.slipDialog = function (supplierId, preselect, onDone) {
    var info = P.api('rpay-open&supplier_id=' + encodeURIComponent(supplierId));
    if (!info.ok) { P.toast(info.message || 'Could not load bills', true); return; }
    var ov = $('slipOv');
    if (!ov) { ov = document.createElement('div'); ov.className = 'ov'; ov.id = 'slipOv'; (document.querySelector('.pw') || document.body).appendChild(ov); }
    var s = info.supplier, items = info.items, today = new Date().toISOString().slice(0, 10);
    var due = today; items.forEach(function (it) { if ((preselect || []).indexOf(it.doc_id) > -1 && it.due_date > due) due = it.due_date; });
    var rows = items.map(function (it, i) {
      var busy = it.on_slip !== '', on = !busy && (preselect || []).indexOf(it.doc_id) > -1;
      return '<tr><td><input type="checkbox" data-i="' + i + '"' + (on ? ' checked' : '') + (busy ? ' disabled' : '') + '></td><td class="code">' + P.esc(it.ref) + '</td><td class="bc">' + P.esc(it.bill_no || '—') + '</td><td>' + P.fmtD(it.bill_date) + '</td><td>' + P.fmtD(it.due_date) + '</td><td class="n">' + P.f(it.balance) + '</td>' +
        '<td class="n">' + (busy ? '<span class="mini">on slip ' + P.esc(it.on_slip) + '</span>' : '<input class="payin' + (on ? ' on' : '') + '" data-a="' + i + '" value="' + (on ? P.f(it.balance) : '') + '">') + '</td></tr>';
    }).join('') || '<tr><td colspan="7" class="empty">No open bill for this supplier.</td></tr>';
    ov.innerHTML = '<div class="md"><div class="ch"><h2>Print payment slip — ' + P.esc(s.name) + '</h2><div class="sp"></div><button class="btn sm" type="button" data-x>✕</button></div>' +
      '<form onsubmit="return false" style="grid-template-columns:1fr 1fr">' +
      '<div class="f full"><label>Slip type <b>*</b></label><div class="modeb" style="grid-template-columns:1fr 1fr" id="slT"><span data-t="NOW">Pay now</span><span data-t="DATE" class="on">Pay on date</span></div><span class="hint">Pay now: supplier goes to Accounts today. Pay on date: supplier comes back on the date with this slip.</span></div>' +
      '<div class="f"><label>Pay on date <b>*</b></label><input type="date" id="slD" value="' + due + '" min="' + today + '"><span class="hint">Due date from terms (' + (s.credit_days ? 'Credit ' + s.credit_days + ' days' : 'Cash') + ')</span></div>' +
      '<div class="f"><label>Amount</label><input id="slA" readonly style="font-weight:700"><span class="hint">Total of the bills below</span></div>' +
      '<div class="f full"><label>Bills on this slip</label><div class="tw" style="max-height:260px"><table><thead><tr><th></th><th>Our ref no.</th><th>Supplier bill no.</th><th>Bill date</th><th>Due date</th><th class="n">Balance</th><th class="n">On slip</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<span class="hint">More than one bill of the same supplier can go on one slip. A bill already on an open slip cannot go on another.</span></div>' +
      '<div class="f full"><label>Note for Accounts</label><input id="slN" maxlength="200" placeholder="Optional"></div></form>' +
      '<div class="acts"><button class="btn" type="button" data-x>Cancel</button><button class="btn primary" type="button" id="slGo">Print slip (2 copies)</button></div></div>';
    ov.classList.add('show');
    var type = 'DATE';
    function tot() {
      var t = 0; ov.querySelectorAll('[data-a]').forEach(function (x) { var cb = ov.querySelector('[data-i="' + x.getAttribute('data-a') + '"]'); if (cb && cb.checked) t += P.num(x.value); });
      $('slA').value = P.f(t); return t;
    }
    ov.querySelectorAll('[data-x]').forEach(function (b) { b.onclick = function () { ov.classList.remove('show'); }; });
    $('slT').onclick = function (e) {
      var sp = e.target.closest('[data-t]'); if (!sp) return; type = sp.getAttribute('data-t');
      $('slT').querySelectorAll('span').forEach(function (x) { x.className = x === sp ? 'on' : ''; });
      $('slD').disabled = type === 'NOW'; if (type === 'NOW') $('slD').value = today;
    };
    ov.querySelectorAll('[data-i]').forEach(function (cb) {
      cb.onchange = function () {
        var i = +cb.getAttribute('data-i'), a = ov.querySelector('[data-a="' + i + '"]');
        if (a) { a.value = cb.checked ? P.f(items[i].balance) : ''; a.classList.toggle('on', cb.checked); }
        tot();
      };
    });
    ov.querySelectorAll('[data-a]').forEach(function (a) { a.oninput = function () { var cb = ov.querySelector('[data-i="' + a.getAttribute('data-a') + '"]'); if (cb && P.num(a.value) > 0) cb.checked = true; tot(); }; });
    tot();
    $('slGo').onclick = function () {
      var bills = [];
      ov.querySelectorAll('[data-i]').forEach(function (cb) {
        if (!cb.checked) return; var i = +cb.getAttribute('data-i'), a = ov.querySelector('[data-a="' + i + '"]');
        bills.push({ doc_id: items[i].doc_id, amount: a ? P.num(a.value) : 0 });
      });
      if (!bills.length) { P.toast('Select at least one bill', true); return; }
      $('slGo').disabled = true;
      var r = P.api('rpay-slip-create', { supplier_id: supplierId, slip_type: type, pay_on: $('slD').value, note: $('slN').value, bills: bills });
      $('slGo').disabled = false;
      if (!r.ok) { P.toast(r.message || 'Could not make the slip', true); return; }
      ov.classList.remove('show');
      P.toast('Slip ' + r.slip.slip_no + ' made');
      P.printSlip(r.slip);
      if (onDone) onDone(r.slip);
    };
  };

  P.boot = function () {
    var r = P.api('rpay-suppliers');
    if (!r.ok) { P.toast(r.message || 'Could not load', true); return null; }
    P.build = r.build || ''; P.biz = r.biz || '';
    var v = $('ver'), h = document.querySelector('.pw h1'); if (v && h) v.textContent = h.textContent + ' \u00b7 ' + P.build;
    return r;
  };
})();
// build: V212 build 2026-11-25
