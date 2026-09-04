/* ============================================================
   shell.js — Retail OS sidebar.
   Har page ise load karta hai aur data-active="<moduleKey>" deta hai.

   NOTE: yahan sirf RETAIL + COMMON modules hain. Restaurant ke
   kds / tables / recipe / riders yahan MAUJOOD HI NAHI — Batch 0
   ke baad yeh list server se filtered aayegi
   (industry_code IN (tenant_industry,'COMMON')).
   ============================================================ */
(function () {
  var GROUPS = [
    {
      title: 'Sale Counter', items: [
        ['shift', 'Opening & Closing Shift', 'shift.html', '◔'],
        ['rpos', 'Retail POS Counter', 'pos.html', '▤'],
        ['counters', 'Counter Management', 'counters.html', '⊞'],
        ['sales', 'Sales / Invoices', 'sales.html', '≣'],
        ['void', 'Return / Refund / Void', 'returns.html', '⊗'],
        ['khata', 'Customer Credit / Khata', 'khata.html', '₨']
      ]
    },
    {
      title: 'Catalog & Pricing', items: [
        ['products', 'Product Catalog', 'products.html', '▣'],
        ['departments', 'Departments & Categories', 'departments.html', '☰'],
        ['brands', 'Brands', 'brands.html', '◈'],
        ['uom', 'Units & Pack Sizes', 'uom.html', '⇹'],
        ['pricing', 'Price Management', 'pricing.html', '%'],
        ['scale', 'Weighing Scale Items', 'scale.html', '⚖'],
        ['labels', 'Barcode & Shelf Labels', 'labels.html', '⌗']
      ]
    },
    {
      title: 'Stock & Purchasing', items: [
        ['inventory', 'Stock on Hand', 'stock.html', '▦'],
        ['batches', 'Batch & Expiry', 'batches.html', '◷'],
        ['purchasing', 'Purchasing', 'purchasing.html', '⇩'],
        ['po', 'Purchase Orders', 'purchase_orders.html', '▥'],
        ['grn', 'Goods Receipt (GRN)', 'grn.html', '⊕'],
        ['preturn', 'Purchase Return', 'purchase_return.html', '⇧'],
        ['suppliers', 'Suppliers', 'suppliers.html', '⌂'],
        ['transfer', 'Stock Transfer', 'stock_transfer.html', '⇄'],
        ['count', 'Physical Stock Count', 'stock_count.html', '☑'],
        ['wastage', 'Damage / Expiry Write-off', 'wastage.html', '⊘']
      ]
    },
    {
      title: 'Customers & Marketing', items: [
        ['customers', 'Customers', 'customers.html', '☺'],
        ['loyalty', 'Loyalty / Membership', 'loyalty.html', '★'],
        ['promotions', 'Discounts / Promotions', 'promotions.html', '◎'],
        ['whatsapp', 'WhatsApp / Notifications', 'whatsapp.html', '✆']
      ]
    },
    {
      title: 'Finance & Reports', items: [
        ['expenses', 'Expenses', 'expenses.html', '▼'],
        ['accounting', 'Accounting / Cash', 'accounting.html', '§'],
        ['closing', 'Shift Closing History', 'closing.html', '◑'],
        ['reports', 'Reports', 'reports.html', '▨'],
        ['fbr', 'Tax / Digital Invoice', 'tax.html', '✓']
      ]
    },
    {
      title: 'System', items: [
        ['staff', 'Staff / Roles', 'staff.html', '⚇'],
        ['users', 'Users & Access', 'users.html', '⚿'],
        ['printers', 'Printers & Devices', 'printers.html', '⎙'],
        ['branches', 'Multi-Branch', 'branches.html', '⌗'],
        ['offline', 'Offline / Sync', 'offline_sync.html', '⟳'],
        ['activity', 'User Activity Log', 'activity.html', '☰'],
        ['settings', 'Settings', 'settings.html', '⚙']
      ]
    }
  ];

  var script = document.currentScript;
  var active = (script && script.getAttribute('data-active')) || '';
  var badges = {};
  try { badges = JSON.parse((script && script.getAttribute('data-badges')) || '{}'); } catch (e) { }

  var user = { name: 'Ali Raza', role: 'Store Manager', email: 'ali@retailos.local' };
  var allowed = user.modules || null;   // null = demo mein sab dikhao

  function initials(n) { return (n || 'U').split(/\s+/).map(function (w) { return w[0]; }).slice(0, 2).join('').toUpperCase(); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function groupOpen(g) { return g.items.some(function (it) { return it[0] === active; }); }

  var region = window.Region ? Region.current() : { flag: '🇵🇰', label: 'Pakistan' };

  var html = '';
  html += '<div class="brand"><div class="brand-mark">R</div><div><div class="brand-name">Retail OS</div>' +
    '<span class="brand-sub">Supermarket Operating System</span></div></div>';
  html += '<div class="branch-chip"><span class="dot"></span><div><span>Current branch</span>' +
    '<b>Islamabad — F10 Store</b></div><span style="margin-left:auto;font-size:15px" title="' +
    esc(region.label) + '">' + region.flag + '</span></div>';

  html += '<nav class="nav">';
  html += '<a href="index.html" class="nav-home' + (active === 'dashboard' ? ' active' : '') + '"><span class="ic">▦</span>Dashboard</a>';
  GROUPS.forEach(function (g) {
    var items = g.items.filter(function (it) { return !allowed || allowed.indexOf(it[0]) > -1; });
    if (!items.length) return;
    html += '<details class="nav-group"' + (groupOpen(g) ? ' open' : '') + '><summary>' + esc(g.title) +
      '<span class="chev">›</span></summary><div class="nav-sub">';
    items.forEach(function (it) {
      var b = badges[it[0]] ? '<span class="pill">' + esc(badges[it[0]]) + '</span>' : '';
      html += '<a href="' + it[2] + '" class="nav-link' + (it[0] === active ? ' active' : '') +
        '"><span class="ic">' + it[3] + '</span>' + esc(it[1]) + b + '</a>';
    });
    html += '</div></details>';
  });
  html += '</nav>';

  html += '<div class="side-foot"><div class="side-avatar">' + initials(user.name) + '</div>' +
    '<div class="who"><b>' + esc(user.name) + '</b><span>' + esc(user.role) + '</span></div>' +
    '<button class="logout" id="shellLogout" title="Sign out">⏻</button></div>';

  var side = document.getElementById('side') || document.querySelector('.sidebar');
  if (side) { side.className = 'sidebar'; side.innerHTML = html; }

  var scrim = document.getElementById('scrim');
  if (!scrim) { scrim = document.createElement('div'); scrim.className = 'scrim'; scrim.id = 'scrim'; document.body.appendChild(scrim); }
  var mb = document.getElementById('menuBtn');
  function close() { document.body.classList.remove('nav-open'); }
  if (mb) mb.onclick = function () { document.body.classList.toggle('nav-open'); };
  scrim.onclick = close;
  var lo = document.getElementById('shellLogout');
  if (lo) lo.onclick = function () { location.href = 'login.html'; };
  window.addEventListener('resize', function () { if (innerWidth > 1000) close(); });
})();
