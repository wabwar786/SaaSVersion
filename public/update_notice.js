/* ============================================================
   update_notice.js — "naya version aa gaya hai" ki khabar POS ke
   ANDAR, screen ke neeche bayen kone mein.

   MASLA JO YEH HAL KARTA HAI
   Node pehle bhi poochta tha ke cloud par naya build hai ya nahi.
   Magar us ka jawab SIRF launcher ki kaali window mein chhapta tha —
   aur woh bhi sirf start ke waqt. Teen surakh the:

     1. V164 ke baad woh window tray mein chhup jati hai. Paigham
        dekhne ka imkan pehle se bhi kam ho gaya.
     2. Jo dukan hafton computer band nahi karti, us par check kabhi
        chalta hi nahi tha (ab sync loop har 6 ghante poochta hai).
     3. Jo banda saara din POS par kaam karta hai, woh launcher ki
        window kabhi dekhta hi nahi.

   Ab khabar wahan aati hai jahan banda pehle se dekh raha hai.

   JAAN BOOJH KAR KYA NAHI KIYA
   - Yeh patti kuch DOWNLOAD nahi karti aur kuch INSTALL nahi karti.
     Dono faisle insan ke dabane par hi rehte hain (V90). Yeh sirf
     khabar deti hai aur bata deti hai ke karna kya hai.
   - z-index 700: drawer (790/800) aur modal (900) se NEECHE. Yani
     koi dialog khulе to yeh us ke peeche chali jati hai — bill ya
     payment ke waqt kabhi raasta nahi rokti.
   - `fetch` (async). Sochi samjhi baat hai: `db_api.js` ka `req()`
     SYNCHRONOUS XHR hai, aur page load ke waqt woh poore safhe ko
     jam kar deta. Ek khabar ke liye POS ko rokna theek nahi.
   ============================================================ */
(function () {
  /* Cloud par is ka koi matlab nahi — wahan UPDATE.bat jaisa kuch hai
     hi nahi. Router bhi yeh file cloud par inject nahi karta; yeh
     doosra pehra hai. */
  try { if (String(window.APP_ROLE || 'local') === 'cloud') return; } catch (e) { return; }

  var HIDE_KEY   = 'smartpos.update.hidden';   /* build|YYYY-MM-DD */
  var POLL_EVERY = 1800000;                    /* 30 minute */
  var el = null, open = false, last = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function today() {
    var d = new Date(), m = d.getMonth() + 1, x = d.getDate();
    return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (x < 10 ? '0' + x : x);
  }

  /* ============================================================
     CHHUPANA — ek din ke liye, hamesha ke liye nahi.

     Nishani mein build KA NAAM aur AAJ KI TAREEKH dono hain, is liye:
       - agli subah patti phir aa jati hai (bhoolne ka ilaj)
       - is se naya build aaye to foran aa jati hai, intezar nahi
     localStorage browser ka hai, is liye malik ke laptop par chhupana
     counter wale computer ko chup nahi karata.
     ============================================================ */
  function hidden(build) {
    try { return localStorage.getItem(HIDE_KEY) === build + '|' + today(); }
    catch (e) { return false; }
  }
  function hide(build) {
    try { localStorage.setItem(HIDE_KEY, build + '|' + today()); } catch (e) {}
  }

  function node() {
    if (el) return el;
    el = document.createElement('div');
    el.id = 'smartposUpdateNotice';
    /* ============================================================
       bottom:46px — 14px NAHI.

       Dono POS screens ke neeche function-key ki patti chalti hai
       (F4 Hold, F5 Recall... / F2 Close Account, F3 Charge...). 14px
       par yeh card un buttons ke upar chadh jata tha — yani khabar
       dene ke chakkar mein cashier ka kaam rok dena. 46px us patti ko
       saaf chhor deta hai. Jin safhon par aisi patti nahi (dashboard,
       settings) wahan bas thora upar reh jata hai — koi harj nahi.
       ============================================================ */
    el.setAttribute('style',
      'position:fixed;left:14px;bottom:46px;z-index:700;max-width:330px;' +
      'font:13px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;' +
      'background:#1f2733;color:#eef2f7;border:1px solid #3a475a;' +
      'border-left:4px solid #e8a33d;border-radius:10px;' +
      'box-shadow:0 10px 28px rgba(0,0,0,.28);overflow:hidden');
    document.body.appendChild(el);
    return el;
  }

  function paint(s) {
    var n = node();
    var ready = s.state === 'ready';
    var b = esc(s.build || '');
    /* Simti hui patti mein sirf "V166" — poora "V166 build 2026-11-01"
       do line le leta tha aur patti bina wajah bari lagti thi. Poora
       naam khulne par nazar aata hai. */
    var shortB = esc(String(s.build || '').split(' ')[0] || '');

    if (!open) {
      /* Simti hui shakl — ek choti patti. POS par jagah nahi khati. */
      n.innerHTML =
        '<div style="display:flex;align-items:center;gap:9px;padding:9px 11px;white-space:nowrap">' +
          '<span style="font-size:15px;line-height:1">&#8593;</span>' +
          '<button type="button" data-u="open" style="all:unset;cursor:pointer;flex:1;' +
            'font:inherit;color:#eef2f7;text-align:left">' +
            '<b>' + (ready ? 'Update ready' : 'New version') + '</b>' +
            (shortB ? '<span style="color:#aab6c6"> &middot; ' + shortB + '</span>' : '') +
          '</button>' +
          '<button type="button" data-u="x" title="Hide until tomorrow" ' +
            'style="all:unset;cursor:pointer;font:inherit;color:#8e9cb0;padding:0 2px">&times;</button>' +
        '</div>';
      return;
    }

    /* Khuli hui shakl — karna kya hai, saaf lafzon mein. */
    n.innerHTML =
      '<div style="padding:13px 14px 12px">' +
        '<div style="display:flex;align-items:flex-start;gap:9px">' +
          '<div style="flex:1">' +
            '<b style="font-size:13.5px">' +
              (ready ? 'Update downloaded and ready' : 'A new version is available') +
            '</b>' +
            '<div style="color:#aab6c6;font-size:11.5px;margin-top:4px;line-height:1.45">' +
              'Now&nbsp;running: ' + esc(s.installed || '?') +
              (b ? '<br>New&nbsp;build: ' + b : '') +
            '</div>' +
          '</div>' +
          '<button type="button" data-u="x" title="Hide until tomorrow" ' +
            'style="all:unset;cursor:pointer;font:inherit;color:#8e9cb0;padding:0 2px">&times;</button>' +
        '</div>' +

        '<div style="margin-top:11px;padding-top:11px;border-top:1px solid #3a475a;' +
             'font-size:12.3px;color:#dde4ee">' +
          '<div style="font-weight:700;margin-bottom:5px">To install it</div>' +
          '<div style="color:#c3cedd">' +
            '1. Right-click the SmartPOS icon near the clock &rarr; <b>Stop SmartPOS</b><br>' +
            '2. Run <b>UPDATE.bat</b><br>' +
            '3. Start the software again' +
          '</div>' +
          '<div style="color:#8e9cb0;font-size:11.3px;margin-top:8px">' +
            (ready
              ? 'The file is already downloaded. Installing takes a minute or two.'
              : 'UPDATE.bat will download it first, then install it.') +
            ' Your sales, stock and settings are not affected.' +
          '</div>' +
          '<div style="color:#8e9cb0;font-size:11.3px;margin-top:6px">' +
            'Do this when the counter is free &mdash; the software is off for a minute or two.' +
          '</div>' +
        '</div>' +

        '<div style="margin-top:11px;display:flex;gap:7px">' +
          '<button type="button" data-u="later" style="all:unset;cursor:pointer;font:inherit;' +
            'font-size:12px;font-weight:700;padding:7px 12px;border-radius:7px;' +
            'background:#e8a33d;color:#2a1e08">Hide until tomorrow</button>' +
          '<button type="button" data-u="close" style="all:unset;cursor:pointer;font:inherit;' +
            'font-size:12px;padding:7px 10px;border-radius:7px;color:#aab6c6;' +
            'border:1px solid #3a475a">Keep showing</button>' +
        '</div>' +
      '</div>';
  }

  function remove() {
    if (el && el.parentNode) el.parentNode.removeChild(el);
    el = null; open = false;
  }

  function show(s) {
    paint(s);
    node().onclick = function (ev) {
      var t = ev.target;
      while (t && t !== el && !t.getAttribute('data-u')) t = t.parentNode;
      var a = t && t.getAttribute ? t.getAttribute('data-u') : null;
      if (!a) return;
      if (a === 'open')  { open = true;  paint(s); return; }
      if (a === 'close') { open = false; paint(s); return; }
      if (a === 'x' || a === 'later') { hide(s.build || ''); remove(); }
    };
  }

  function look() {
    fetch('/api.php?action=update-status&_=' + Date.now(), {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      /* Server `ok(...)` ke andar jawab bhejta hai; dono shaklon ko
         sambhal lete hain taake aage kabhi badle to patti na toote. */
      var s = (j && j.result) ? j.result : j;
      if (!j || j.ok === false || !s) return;

      /* `offline` ka matlab sirf yeh hai ke portal tak pohanch nahi
         hui — yeh koi khabar nahi, is liye kuch nahi dikhate. */
      if (s.state !== 'available' && s.state !== 'ready') { remove(); return; }
      if (hidden(s.build || '')) { remove(); return; }

      /* Halat badli ho (available -> ready) to patti dobara banao;
         warna khuli hui patti band na ho jaye. */
      var sig = s.state + '|' + (s.build || '');
      if (sig !== last) { remove(); last = sig; }
      show(s);
    })
    .catch(function () { /* network/JSON — khabar na mile to khamoshi */ });
  }

  function start() { look(); setInterval(look, POLL_EVERY); }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();

/* build: V165 build 2026-10-25 */
