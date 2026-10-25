/* ============================================================
   delete_kit.js — POORE SOFTWARE KA EK HI DELETE CONFIRM.

   Yeh file is liye bani ke purana delete KHAMOSHI SE fail hota tha:
   `module.js` server ka jawab discard kar deta tha, user ko
   "Record removed" toast dikhta tha, aur reload par row wapas aa
   jati thi. Reason kabhi kisi tak nahi pohanchti thi.

   Ab har delete yahan se guzarta hai aur teen mein se EK natija
   dikhata hai:
     DELETED / DEACTIVATED / RESTORED  -> sabz, screen refresh
     BLOCKED                            -> ASLI wajah, + "Sirf Inactive"
                                           aur "Force delete" ke options
     error                              -> server ka asli message, laal

   Design system: sirf shared.css ki mojooda classes
   (.modal .dialog .dialog-head/.dialog-body/.dialog-foot .btn
    .btn.primary .btn.danger .tag .field). Koi nayi class nahi.
   ============================================================ */
(function () {
  'use strict';
  if (window.DeleteKit) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function toast(msg, isErr) {
    var t = document.getElementById('toast');
    if (!t) { t = document.createElement('div'); t.id = 'toast'; document.body.appendChild(t); }
    t.textContent = msg;
    t.className = 'toast show' + (isErr ? ' err' : '');
    clearTimeout(window.__dkToast);
    window.__dkToast = setTimeout(function () { t.className = 'toast'; }, 2600);
  }

  /* Har call ka apna overlay — purana koi modal khula ho to takra na jaye. */
  function overlay(html) {
    var ov = document.createElement('div');
    ov.className = 'modal show';
    ov.innerHTML = '<div class="dialog" style="width:min(520px,96vw)">' + html + '</div>';
    document.body.appendChild(ov);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    function close() { if (ov.parentNode) ov.parentNode.removeChild(ov); document.removeEventListener('keydown', key); }
    function key(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', key);
    return { el: ov, close: close, q: function (s) { return ov.querySelector(s); } };
  }

  /* ---------------- server call ----------------
     DBApi.req() har page par maujood hai (router.php <head> mein
     db_api.js inject karta hai). Agar kisi wajah se na mile to
     saaf error dena hai, chupchaap "done" kehna nahi. */
  function call(action, payload) {
    if (!window.DBApi || !window.DBApi.req) {
      return { ok: false, message: 'No server connection (db_api.js did not load). Please refresh the page.' };
    }
    try {
      var r = window.DBApi.req(action, payload);
      return r && typeof r === 'object' ? r : { ok: false, message: 'The server response could not be understood.' };
    } catch (e) {
      return { ok: false, message: 'Could not send the request: ' + (e && e.message ? e.message : e) };
    }
  }

  /* ---------------- blocked screen ---------------- */
  function showBlocked(res, opts, retryForce) {
    var reasons = (res.blockers && res.blockers.length ? res.blockers : [res.message || 'Could not delete.']);
    var body = '<div class="dialog-head"><div><h3>Could not delete</h3>'
             + '<p>' + esc(opts.name || res.label || '') + '</p></div>'
             + '<button class="close" data-dk="x">&times;</button></div>'
             + '<div class="dialog-body">'
             + '<div class="note" style="background:var(--danger-soft);border-color:var(--danger-line);color:var(--danger)">'
             + reasons.map(function (r) { return '<div style="margin:3px 0">&bull; ' + esc(r) + '</div>'; }).join('')
             + '</div>';

    if (res.can_deactivate) {
      body += '<p style="font-size:11px;margin-top:10px">To hide it from the list while keeping the data, mark it '
            + '<b>Inactive</b> instead - history stays intact and bills/reports keep working.</p>';
    }
    if (res.can_force) {
      body += '<label class="field" style="margin-top:12px"><span>Manager password for force delete</span>'
            + '<input type="password" data-dk="pw" placeholder="Manager password" autocomplete="off"></label>'
            + '<label class="field"><span>Reason (for the audit log)</span>'
            + '<input data-dk="reason" placeholder="e.g. entered by mistake"></label>'
            + '<div class="note" style="background:var(--danger-soft);border-color:var(--danger-line);color:var(--danger);margin-top:8px">'
            + 'Force delete removes related rows too and <b>cannot be undone</b>. '
            + 'It will also apply on branch computers.</div>';
    }
    body += '</div><div class="dialog-foot"><button class="btn" data-dk="x">Cancel</button>'
          + (res.can_deactivate ? '<button class="btn" data-dk="deact">Mark inactive only</button>' : '')
          + (res.can_force ? '<button class="btn danger" data-dk="force">Force delete</button>' : '')
          + '</div>';

    var m = overlay(body);
    m.el.addEventListener('click', function (e) {
      var b = e.target.closest('[data-dk]');
      if (!b) return;
      var a = b.getAttribute('data-dk');
      if (a === 'x') { m.close(); return; }
      if (a === 'deact') { m.close(); retryForce('deactivate', '', ''); return; }
      if (a === 'force') {
        var pw = (m.q('[data-dk="pw"]') || {}).value || '';
        var rs = (m.q('[data-dk="reason"]') || {}).value || '';
        if (!pw) { toast('Enter the manager password', true); return; }
        m.close();
        retryForce('force', pw, rs);
      }
    });
  }

  /* ---------------- main ----------------
     opts:
       entity   : DeleteService entity ('menu_item', 'user', 'table' ...)
       module   : ya phir module.js wala storeKey ('suppliers' ...)
       id, name : row
       action   : override endpoint (default 'entity-delete' / 'records-delete')
       onDone   : function(res)  — na dein to page reload ho jata hai
  */
  function confirmDelete(opts) {
    opts = opts || {};
    if (!opts.id) { toast('Record id is missing', true); return; }

    var action = opts.action || (opts.entity ? 'entity-delete' : 'records-delete');
    var what = opts.what || (opts.entity ? opts.entity.replace(/_/g, ' ') : 'record');

    function send(mode, pw, reason) {
      var payload = { id: opts.id, mode: mode };
      if (opts.entity) payload.entity = opts.entity;
      if (opts.module) payload.module = opts.module;
      if (pw) payload.manager_password = pw;
      if (reason) payload.reason = reason;

      var res = call(action, payload);

      if (!res.ok) {
        /* Server ka ASLI message. "rejected by cloud" jaisa be-maani
           message kabhi nahi. */
        toast(res.message || 'Could not delete', true);
        return;
      }
      if (res.result === 'BLOCKED') { showBlocked(res, opts, send); return; }

      toast(res.message || 'Deleted');
      if (typeof opts.onDone === 'function') opts.onDone(res);
      else setTimeout(function () { location.reload(); }, 450);
    }

    var m = overlay(
      '<div class="dialog-head"><div><h3>' + esc(opts.title || 'Delete this?') + '</h3>'
      + '<p>' + esc(what) + (opts.name ? ': ' + esc(opts.name) : '') + '</p></div>'
      + '<button class="close" data-dk="x">&times;</button></div>'
      + '<div class="dialog-body">'
      + '<div class="note" style="background:var(--danger-soft);border-color:var(--danger-line);color:var(--danger)">'
      + esc(opts.warning || 'This record will be removed from the list. If it is in use, the system will stop and tell you why.')
      + '</div>'
      + '<label class="field" style="margin-top:12px"><span>Reason <span class="hint">&middot; optional, for the audit log</span></span>'
      + '<input data-dk="reason" placeholder="e.g. duplicate entry"></label>'
      + '</div>'
      + '<div class="dialog-foot"><button class="btn" data-dk="x">Cancel</button>'
      + '<button class="btn danger" data-dk="go">Delete</button></div>'
    );

    m.el.addEventListener('click', function (e) {
      var b = e.target.closest('[data-dk]');
      if (!b) return;
      if (b.getAttribute('data-dk') === 'x') { m.close(); return; }
      if (b.getAttribute('data-dk') === 'go') {
        var rs = (m.q('[data-dk="reason"]') || {}).value || '';
        m.close();
        send('auto', '', rs);
      }
    });
    var f = m.q('[data-dk="reason"]');
    if (f) setTimeout(function () { f.focus(); }, 60);
  }

  /* ============================================================
     V166 — EK SATH KAI RECORD.

     Pehle menu saaf karne ke liye har item par alag Delete dabana
     parta tha (Akorwal: 49 items + 17 categories = 66 confirm). Ab
     checkbox se chun kar ek hi dafa.

     opts:
       entity   : 'menu_item' | 'menu_category'  (server par bhi yehi do)
       items    : [{id, name}]
       title    : dialog ka unwan
       note     : peela paigham (misal "purane bill salamat rehte hain")
       cascade  : true ho to "andar ke items bhi" ka checkbox dikhta hai
                  (sirf categories ke liye)
       onDone   : function(summary) — na dein to page reload

     CHUNK 100 KA KYUN: `DBApi.req()` synchronous XHR hai. 500 record
     ek hi request mein bhejne par browser us poore arse ke liye jam
     jata hai aur PHP ka waqt bhi khatam ho sakta hai. 100 ke tukron
     mein har request choti rehti hai, aur agar beech mein kuch toote
     to pata chal jata hai ke kahan tak hua.
     ============================================================ */
  var CHUNK = 100;

  function confirmMany(opts) {
    opts = opts || {};
    var items = (opts.items || []).filter(function (x) { return x && x.id; });
    if (!items.length) { toast('Pehle kuch select karein', true); return; }

    var n = items.length;
    var shown = items.slice(0, 8);
    var rest = n - shown.length;

    var body =
      '<div class="dialog-head"><div><h3>' + esc(opts.title || ('Delete ' + n + ' records?')) + '</h3>'
      + '<p>' + n + ' selected</p></div>'
      + '<button class="close" data-dk="x">&times;</button></div>'
      + '<div class="dialog-body">'
      + '<div style="background:var(--surface-2,#fbfcfd);border:1px solid var(--line,#ebedf0);'
      +   'border-radius:8px;padding:9px 11px;font-size:12px;max-height:150px;overflow:auto">'
      + shown.map(function (x) { return '<div style="padding:2px 0">&bull; ' + esc(x.name || x.id) + '</div>'; }).join('')
      + (rest > 0 ? '<div style="padding:3px 0;opacity:.65">+ ' + rest + ' more</div>' : '')
      + '</div>';

    if (opts.cascade) {
      /* Checkbox par inline naap lazmi hai: shared.css `.dialog-body input`
         ko poori chaurai aur apna border deti hai, is liye bina is ke
         yeh ek bara gulabi dabba ban jata tha (test mein pakra gaya). */
      body += '<label style="display:flex;gap:8px;align-items:flex-start;margin-top:12px;font-size:12px;cursor:pointer">'
            + '<input type="checkbox" data-dk="casc" style="width:16px;height:16px;flex:0 0 16px;'
            +   'margin:2px 0 0;padding:0;border:0;border-radius:0;background:none;appearance:auto;'
            +   '-webkit-appearance:checkbox;box-shadow:none">'
            + '<span>Inside items bhi delete karein'
            + '<br><span class="hint">Bina is ke, jis category mein items paray hon wo delete nahi hoti.</span></span></label>';
    }

    body += '<label class="field" style="margin-top:12px"><span>Admin / Manager password</span>'
          + '<input type="password" data-dk="pw" placeholder="Password" autocomplete="off"></label>'
          + '<label class="field"><span>Reason <span class="hint">&middot; optional, for the audit log</span></span>'
          + '<input data-dk="reason" placeholder="e.g. menu dobara banana hai"></label>'
          + '<div class="note" style="background:var(--warn-soft,#fff6e5);border-color:var(--warn-line,#f0d9a8);'
          +   'color:var(--warn,#8a5a00);margin-top:10px">'
          + esc(opts.note || 'Purane bill mehfooz rehte hain — yeh un par waise hi chhapte rahenge.')
          + '</div>'
          + '</div>'
          + '<div class="dialog-foot"><button class="btn" data-dk="x">Cancel</button>'
          + '<button class="btn danger" data-dk="go">Delete ' + n + '</button></div>';

    var m = overlay(body);

    m.el.addEventListener('click', function (e) {
      var b = e.target.closest('[data-dk]');
      if (!b) return;
      var a = b.getAttribute('data-dk');
      if (a === 'x') { m.close(); return; }
      if (a !== 'go') return;

      var pw = (m.q('[data-dk="pw"]') || {}).value || '';
      if (!pw) { toast('Admin ya manager ka password likhein', true); return; }
      var rs = (m.q('[data-dk="reason"]') || {}).value || '';
      var cc = m.q('[data-dk="casc"]');
      var casc = !!(cc && cc.checked);
      m.close();
      run(pw, rs, casc);
    });

    var pwf = m.q('[data-dk="pw"]');
    if (pwf) setTimeout(function () { pwf.focus(); }, 60);

    function run(pw, reason, casc) {
      var ids = items.map(function (x) { return x.id; });
      var sum = { deleted: 0, failed: 0, rows: [] };

      /* Categories + "andar ke items bhi": pehle items, phir category.
         Ulta karne par har category BLOCKED hoti hai. */
      if (casc && typeof opts.itemIdsFor === 'function') {
        var inner = opts.itemIdsFor(ids);
        if (inner && inner.length) {
          var ri = send('menu_item', inner, pw, reason);
          if (ri === null) return;
          sum.deleted += ri.deleted; sum.failed += ri.failed;
          sum.rows = sum.rows.concat(ri.rows);
        }
      }

      var r = send(opts.entity, ids, pw, reason);
      if (r === null) return;
      sum.deleted += r.deleted; sum.failed += r.failed;
      sum.rows = sum.rows.concat(r.rows);

      report(sum);
    }

    /* null = request hi nakaam (password ghalat waghera) — toast ho chuka. */
    function send(entity, ids, pw, reason) {
      var out = { deleted: 0, failed: 0, rows: [] };
      for (var i = 0; i < ids.length; i += CHUNK) {
        var res = call('entity-delete-bulk', {
          entity: entity, ids: ids.slice(i, i + CHUNK),
          manager_password: pw, reason: reason
        });
        if (!res.ok) { toast(res.message || 'Could not delete', true); return null; }
        out.deleted += (res.deleted || 0);
        out.failed += (res.failed || 0);
        out.rows = out.rows.concat(res.rows || []);
      }
      return out;
    }

    function report(sum) {
      if (!sum.failed) {
        toast(sum.deleted + ' deleted');
        if (typeof opts.onDone === 'function') opts.onDone(sum);
        else setTimeout(function () { location.reload(); }, 450);
        return;
      }
      /* Kuch nahi hua to WAJAH dikhani hai — khamoshi se "done" kehna
         wahi purani bimari thi jis ke liye yeh file bani thi. */
      var bad = sum.rows.filter(function (x) { return !x.ok; }).slice(0, 10);
      var m2 = overlay(
        '<div class="dialog-head"><div><h3>' + sum.deleted + ' deleted, ' + sum.failed + ' nahi hue</h3>'
        + '<p>Neeche wajah likhi hai</p></div><button class="close" data-dk="x">&times;</button></div>'
        + '<div class="dialog-body">'
        + '<div class="note" style="background:var(--danger-soft);border-color:var(--danger-line);color:var(--danger)">'
        + bad.map(function (x) {
            return '<div style="margin:3px 0">&bull; <b>' + esc(x.name || x.id) + '</b> — ' + esc(x.message) + '</div>';
          }).join('')
        + (sum.failed > bad.length ? '<div style="margin-top:5px;opacity:.75">+ ' + (sum.failed - bad.length) + ' more</div>' : '')
        + '</div></div>'
        + '<div class="dialog-foot"><button class="btn primary" data-dk="x">Theek hai</button></div>'
      );
      m2.el.addEventListener('click', function (e) {
        if (!e.target.closest('[data-dk="x"]')) return;
        m2.close();
        if (typeof opts.onDone === 'function') opts.onDone(sum);
        else setTimeout(function () { location.reload(); }, 300);
      });
    }
  }

  /* ---------------- restore ---------------- */
  function restore(entity, id, onDone) {
    var res = call('entity-restore', { entity: entity, id: id });
    if (!res.ok) { toast(res.message || 'Could not restore', true); return; }
    toast(res.message || 'Restored');
    if (typeof onDone === 'function') onDone(res);
    else setTimeout(function () { location.reload(); }, 450);
  }

  window.DeleteKit = { confirm: confirmDelete, confirmMany: confirmMany, restore: restore, toast: toast };
})();

/* build: V166 build 2026-10-25 */
