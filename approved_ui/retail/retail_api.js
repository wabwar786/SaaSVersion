/* ============================================================
   retail_api.js — RetailStore ko server par le jata hai.

   MASLA JO YEH HAL KARTA HAI:
   Screens demo data (localStorage) par bani thin. Ab wohi screens asli
   MySQL par chalni hain — magar screens dobara likhna ghalti hai.
   Is liye yahan sirf RetailStore ke data functions ki jagah API calls
   rakh di jati hain. Field keys wahi hain jo DB columns hain, is liye
   kisi screen ko chhoona nahi parta.

   Router yeh file sirf tab inject karta hai jab tenant RETAIL ho aur
   user logged-in ho. File:// par kholein to purana demo store chalta
   rehta hai — designer bina server ke bhi kaam kar sake.

   SPEED: har scan par network round trip hai (localhost par ~1-3 ms).
   Us ke upar ek chhota memory cache hai — ek hi barcode dobara scan ho
   (jo counter par aam hai) to doosri dafa 0 ms.
   ============================================================ */
(function () {
  if (!window.RetailStore) return;
  if (location.protocol === 'file:') return;      // demo mode — chhero mat

  var S = window.RetailStore;

  /* ---------- transport ---------- */
  function api(action, payload) {
    if (window.DBApi && window.DBApi.req) {
      var r = window.DBApi.req(action, payload);
      if (r && r.ok) return r;
      throw new Error((r && r.message) || 'Request failed');
    }
    var x = new XMLHttpRequest();
    /* GET par cache-buster — warna browser purana jawab apne paas se de
       deta hai aur abhi-abhi kiya hua change nazar hi nahi aata. */
    var url = '/api.php?action=' + action + (payload === undefined ? ('&_=' + Date.now()) : '');
    x.open(payload === undefined ? 'GET' : 'POST', url, false);
    x.setRequestHeader('Cache-Control', 'no-cache');
    x.setRequestHeader('Accept', 'application/json');
    if (payload !== undefined) {
      x.setRequestHeader('Content-Type', 'application/json');
      x.setRequestHeader('X-CSRF-Token', window.APP_CSRF || '');
      x.send(JSON.stringify(payload));
    } else x.send();
    var r = {};
    try { r = JSON.parse(x.responseText || '{}'); } catch (e) { }
    if (!r.ok) throw new Error(r.message || ('HTTP ' + x.status));
    return r;
  }
  function quiet(action, payload) { try { return api(action, payload); } catch (e) { return null; } }

  /* ---------- boot: business, region, counters ---------- */
  var boot = quiet('retail-boot');
  if (!boot) return;                              // login nahi / retail nahi — demo hi rehne do

  S.online = true;
  S.boot = boot;

  /* Region ab tenant ke record se aata hai, localStorage se nahi.
     Super Admin ne business banate waqt jo chuna, wahi chalta hai. */
  if (boot.region && window.Region) {
    window.Region.current = function () { return boot.region; };
    window.Region.set = function () { };           // client region badal nahi sakta
  }

  /* ============================================================
     SERVER-BACKED LISTS — V154 MEIN YAHAN DOOSRA BARA BUG THA.

     Pehle yahan ek HAATH SE LIKHI fehrist thi:

         var MODULE_OF = { products:'products', departments:'departments',
           categories:'categories', brands:'brands', units:'uom',
           batches:'batches', counters:'counters' };
         ...
         var mod = MODULE_OF[key];
         if (!mod) return [];              // <- yahan sab mar jate the

     Jis bhi storeKey ka naam us saat ki fehrist mein nahi tha — suppliers,
     expenses, staff, printers, branches, promotions, grn, po, returns —
     us ka `S.get()` KHALI ARRAY wapas karta tha, chahe server par kitni
     bhi rows hon. Aur likhna (`records-save`) bilkul alag raaste se jata
     tha, is liye SAVE CHALTA THA AUR LIST NAHI: customer supplier add
     karta, "Saved" dekhta, reload karta — row ghayab. Data chup chaap
     jama hota rehta jo kisi ko nazar hi na aata.

     Saboot (audit mein): server par suppliers/expenses/staff/branches
     har ek mein 1 row, screen par 0.

     ASAL WAJAH: ek hi fehrist DO jagah likhi hui thi. storeKey -> module
     ka naqsha pehle se `module_config.js` mein mojood hai (har config
     mein `key` aur `storeKey` dono likhe hain) — yahan us ki doosri copy
     rakhna sirf drift paida karta tha.

     AB: naqsha `MODULE_CONFIGS` se KHUD banta hai. Ek fehrist, ek jagah.
     Naya module add karne par yahan kuch likhna hi nahi parta.

     LAZY kyun: yeh file `module_config.js` se PEHLE load hoti hai, is
     liye naqsha boot par nahi ban sakta. `S.get()` ke pehle call par
     banta hai — us waqt tak configs mojood hoti hain.
     ============================================================ */
  var cache = {};
  var modMap = null;

  function moduleOf(key) {
    if (modMap === null) {
      modMap = {};
      var C = window.MODULE_CONFIGS || {};
      for (var name in C) {
        if (!Object.prototype.hasOwnProperty.call(C, name)) continue;
        var cfg = C[name] || {};
        var sk = cfg.storeKey, mod = cfg.key || name;
        if (!sk) continue;
        /* Ek storeKey par kai screens ho sakti hain: `stock`, `pricing`
           aur `scale` teeno `products` par bani hain; `khata` aur
           `loyalty` `customers` par. Aise mein wo config jeetti hai jis
           ka apna key hi storeKey ho — yani asal maalik. Warna pehli
           milne wali. */
        if (modMap[sk] === undefined || mod === sk) modMap[sk] = mod;
      }
    }
    /* Config na mile to key ko hi module maan lo — `records-list` ka
       `ui_records` wala rasta har naam par chalta hai, is liye nayi
       screen khali nahi rahegi. */
    return modMap[key] || key;
  }
  S.moduleOf = moduleOf;

  S.get = function (key) {
    if (key === 'customers') {
      if (!cache.customers) {
        var c = quiet('retail-customers');
        cache.customers = c ? c.rows : [];
      }
      return cache.customers;
    }
    if (key === 'held_bills') {
      var h = quiet('rpos-held');
      return h ? h.rows : [];
    }
    if (key === 'sales') {
      var s = quiet('rpos-sales&limit=100');
      return s ? s.rows.map(normalizeSale) : [];
    }
    if (!cache[key]) {
      var r = quiet('records-list&module=' + encodeURIComponent(moduleOf(key)));
      cache[key] = r ? r.rows : [];
    }
    return cache[key];
  };

  S.set = function (key, rows) { cache[key] = rows; return rows; };
  S.commit = function () { };
  S.invalidate = function (key) { if (key) delete cache[key]; else cache = {}; };

  function normalizeSale(s) {
    s.total = Number(s.total || 0);
    s.subtotal = Number(s.subtotal || 0);
    s.tax = Number(s.tax_amount != null ? s.tax_amount : s.tax || 0);
    s.discount = Number(s.discount || 0);
    s.lines = Number(s.line_count || 0);
    s.customer = s.customer_name || 'Walk-in Customer';
    s.counter = s.counter_name || '';
    s.cashier = s.cashier_name || '';
    s.paid_cash = Number(s.paid_cash || 0);
    s.paid_card = Number(s.paid_card || 0);
    s.change = Number(s.change_amount || 0);
    s.reprint_count = Number(s.reprint_count || 0);
    s.sold_at = String(s.sold_at || '').slice(0, 16).replace('T', ' ');
    return s;
  }
  S.normalizeSale = normalizeSale;

  /* ---------- lookup (scan) ---------- */
  var codeCache = {};
  S.findByCode = function (code) {
    code = String(code || '').trim();
    if (!code) return null;
    if (codeCache[code] !== undefined) return codeCache[code];
    var r = null;
    try { r = api('retail-lookup&code=' + encodeURIComponent(code)); } catch (e) { r = null; }
    var hit = r ? r.hit : null;
    /* "Nahi mila" bhi cache hota hai — warna ek ghalat barcode baar baar
       scan ho kar har dafa server tak jata hai. */
    codeCache[code] = hit;
    return hit;
  };
  S.searchProducts = function (q) {
    q = String(q || '').trim();
    if (q.length < 2) return [];
    var r = quiet('retail-search&q=' + encodeURIComponent(q));
    return r ? r.rows : [];
  };
  S.byId = function (coll, id) {
    var rows = S.get(coll) || [];
    for (var i = 0; i < rows.length; i++) if (rows[i].id === id) return rows[i];
    return null;
  };
  S.name = function (coll, id, fb) { var r = S.byId(coll, id); return r ? r.name : (fb || '\u2014'); };
  S.unitCode = function (id) {
    var u = S.byId('units', id);
    return u ? u.code : 'PCS';
  };
  S.rebuildIndex = function () { codeCache = {}; };

  /* ---------- POS writes ---------- */
  S.api = api;
  S.saveSale = function (payload) { return api('rpos-sale', payload).sale; };
  S.reprint = function (id, reason) { return api('rpos-reprint', { id: id, reason: reason || '' }).sale; };
  S.holdBill = function (payload) { return api('rpos-hold', payload).id; };
  S.releaseHold = function (id) { return api('rpos-hold-release', { id: id }); };
})();

// build: V154 build 2026-10-13
