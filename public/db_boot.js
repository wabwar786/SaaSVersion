/* ============================================================
   db_boot.js — DB-FIRST hydration (runs in <head>, before any
   approved-UI page script).

   Pehle localStorage source-of-truth tha aur DB sirf shadow copy.
   Ab har page load par server ki state localStorage cache mein
   likh di jaati hai, is liye:
     • dashboard real orders/payments/expenses dikhata hai
       (fake demo counters nahi),
     • inventory / recipes / purchases har terminal par same hote
       hain (multi-terminal consistent),
     • browser data clear hone par kuch nahi "khota" — agla load
       DB se dubara hydrate ho jata hai.

   Synchronous XHR jaan-boojh kar: hydration page scripts se
   PEHLE mukammal honi zaroori hai. Local node par server
   localhost hai (latency ~0); cloud par yeh sirf logged-in
   pages ke head mein chalta hai.
   ============================================================ */
(function () {
  'use strict';
  if (window.__AIO_DB_BOOT) return;
  window.__AIO_DB_BOOT = true;

  function get(action) {
    try {
      var x = new XMLHttpRequest();
      x.open('GET', '/api.php?action=' + action, false);
      x.setRequestHeader('Accept', 'application/json');
      x.send();
      if (x.status !== 200) return null;
      var r = JSON.parse(x.responseText || '{}');
      return r && r.ok ? r : null;
    } catch (e) { return null; }
  }

  var STORE_KEY = 'urban_spoon_restaurant_store_v5';
  var LIVE_KEY  = 'urban_spoon_live_v5';

  /* ---------- inventory / recipes / purchases ---------- */
  var st = get('store-state');
  if (st && st.state) {
    var cur = {};
    try { cur = JSON.parse(localStorage.getItem(STORE_KEY) || '{}') || {}; } catch (e) {}
    var s = st.state;
    cur.inventoryCategories = s.inventoryCategories || [];
    cur.inventoryItems      = (s.inventoryItems || []).map(function (i) {
      return { id: i.id, name: i.name, category: i.category, stockUnit: i.stockUnit,
               purchaseUnit: i.purchaseUnit, purchaseFactor: i.purchaseFactor,
               stockQty: i.stockQty, avgStockCost: i.avgStockCost, reorderQty: i.reorderQty,
               storage: i.storage, usage: i.usage, batch: !!i.batch, expiry: !!i.expiry };
    });
    cur.menuCategories = s.menuCategories || [];
    cur.recipes        = s.recipes || [];
    cur.purchaseOrders = s.purchaseOrders || [];
    try { localStorage.setItem(STORE_KEY, JSON.stringify(cur)); } catch (e) {}
    window.__AIO_STORE_STATE = s;
  }

  /* ---------- dashboard / live tiles ---------- */
  var db = get('dashboard-state');
  if (db && db.state) {
    var d = db.state;
    d.deliveryReady = d.deliveryReady || 0;
    try { localStorage.setItem(LIVE_KEY, JSON.stringify(d)); } catch (e) {}
    window.__AIO_DASHBOARD = d;
  }

  window.AioDbBoot = {
    refreshLive: function () {
      var r = get('dashboard-state');
      if (r && r.state) {
        try { localStorage.setItem(LIVE_KEY, JSON.stringify(r.state)); } catch (e) {}
        if (window.RestaurantLive && RestaurantLive.save) RestaurantLive.save(r.state);
      }
      return r && r.state;
    }
  };
})();

/* build: V17.1 build 2026-08-25 */
