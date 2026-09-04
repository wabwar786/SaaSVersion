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
    x.open(payload === undefined ? 'GET' : 'POST', '/api.php?action=' + action, false);
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

  /* ---------- server-backed lists ---------- */
  var MODULE_OF = {
    products: 'products', departments: 'departments', categories: 'categories',
    brands: 'brands', units: 'uom', batches: 'batches', counters: 'counters'
  };
  var cache = {};

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
    var mod = MODULE_OF[key];
    if (!mod) return [];
    if (!cache[key]) {
      var r = quiet('records-list&module=' + encodeURIComponent(mod));
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
