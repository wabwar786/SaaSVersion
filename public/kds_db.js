/* ============================================================
   kds_db.js — Kitchen Display ab ASLI kitchen tickets par.

   MASLA JO YEH THEEK KARTA HAI
   `kds.html` 1,119 lines ka poora page hai, magar us mein:

       const orders=[ {id:'KOT-0128', bill:'#0024', ...} ]

   yani HARDCODED naqli tickets. POS `PosService::sendKot()` se asli KOT
   `kitchen_tickets` mein bhejta hai — aur KDS unhen PARHTA HI NAHI tha.
   Kitchen screen us restaurant ka apna kaam dikhati hi nahi thi.

   TAREEQA
   Page ke UI ko haath nahi lagaya. Wahi tareeqa jo `order_taker_db.js`
   mein hai: `orders` array ko asli data se bhar do aur `render()` ko
   dobara chala do. Is se page ka design, filters, sab waisa hi rehta hai.

   Status badalna ab server par jata hai. Nakami par UI wapas apni
   purani halat par le jaya jata hai — warna cook ko lagta ke ticket
   ready ho gaya jabke database mein kuch nahi badla.
   ============================================================ */
(function () {
  'use strict';

  var POLL_MS = 10000;
  var busy = false;

  function req(action, payload) {
    try { return window.DBApi ? DBApi.req(action, payload)
                              : { ok: false, message: 'No server connection' }; }
    catch (e) { return { ok: false, message: e.message || 'Request failed' }; }
  }

  function say(m, err) {
    if (typeof toast === 'function') { toast(m); return; }
    if (err) console.warn(m);
  }

  /* Server ke ticket ko us shakl mein laana jo page pehle se samajhta hai. */
  function shape(t) {
    return {
      id: t.ticket || t.id,
      _sid: t.id,                       // asli row id — server calls ke liye
      bill: t.bill ? ('#' + t.bill) : '-',
      type: t.mode || '',
      table: t.table || '\u2014',
      waiter: t.waiter || '',
      status: t.status || 'New',
      station: t.station || 'All',
      createdMinutesAgo: t.mins || 0,
      priority: (t.mins || 0) >= 15,
      items: (t.items || []).map(function (i) {
        return {
          qty: i.qty, name: i.name, variant: '',
          note: i.note || '', station: t.station || '',
          newQty: String(i.status || '').toUpperCase() !== 'SERVED'
        };
      })
    };
  }

  function pull(quiet) {
    if (busy) return;
    busy = true;
    var r = req('kds-tickets');
    busy = false;

    if (!r || !r.ok) {
      if (!quiet) say('Kitchen tickets could not be loaded: ' + ((r && r.message) || 'server unreachable'), 1);
      return;
    }
    if (typeof orders === 'undefined' || !Array.isArray(orders)) return;

    var live = (r.tickets || []).filter(function (t) { return t.status !== 'Done'; });
    orders.length = 0;
    live.forEach(function (t) { orders.push(shape(t)); });

    if (typeof render === 'function') render();
  }

  function install() {
    if (window.__AIO_KDS) return;
    if (typeof orders === 'undefined' || typeof render !== 'function') return;
    window.__AIO_KDS = true;

    /* Demo tickets foran hata do — warna ek lamha ke liye naqli
       orders nazar aate hain aur kitchen unhen banane lag sakti hai. */
    orders.length = 0;
    if (typeof completed !== 'undefined' && Array.isArray(completed)) completed.length = 0;
    render();

    /* Status change ab server par. Nakami par wapasi. */
    if (typeof window.moveOrder === 'function') {
      var localMove = window.moveOrder;
      window.moveOrder = function (id) {
        var o = orders.filter(function (x) { return x.id === id; })[0];
        if (!o || !o._sid) { localMove(id); return; }

        var next = o.status === 'New' ? 'PREPARING'
                 : (o.status === 'Preparing' || o.status === 'Delayed') ? 'READY'
                 : 'DONE';
        var before = o.status;

        localMove(id);                                   // screen foran hile
        var r = req('kds-status', { id: o._sid, status: next });
        if (!r || !r.ok) {
          o.status = before;                             // wapas purani halat
          if (typeof render === 'function') render();
          say('Could not update the kitchen: ' + ((r && r.message) || 'server unreachable'), 1);
          return;
        }
        pull(true);
      };
    }

    pull(false);
    setInterval(function () { pull(true); }, POLL_MS);
    window.addEventListener('online', function () { pull(true); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { setTimeout(install, 0); });
  } else {
    setTimeout(install, 0);
  }
})();

/* build: V70 build 2026-08-27 */
