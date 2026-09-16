/* ============================================================
   Service worker — app ka khol (shell) offline rakhta hai.

   Sirf ISKI apni files cache hoti hain. API ke jawab kabhi cache nahi
   hote: menu badal sakta hai, aur order ka status to har dafa taza hona
   hi chahiye. Purana menu dikhana customer ko us cheez ka order karwa
   dega jo maujood hi nahi.
   ============================================================ */
var CACHE = 'order-app-v1';
var SHELL = ['/app.html', '/app-manifest.json', '/app-icon.png'];

self.addEventListener('install', function (e) {
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL).catch(function(){}); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (ks) {
    return Promise.all(ks.filter(function (k) { return k !== CACHE; })
                         .map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var url = new URL(e.request.url);
  if (e.request.method !== 'GET') return;
  if (url.pathname.indexOf('/api.php') === 0) return;      /* API hamesha network se */

  e.respondWith(
    fetch(e.request).then(function (res) {
      if (res && res.status === 200 && url.origin === location.origin) {
        var copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put(e.request, copy); });
      }
      return res;
    }).catch(function () {
      return caches.match(e.request).then(function (hit) {
        return hit || caches.match('/app.html');
      });
    })
  );
});
