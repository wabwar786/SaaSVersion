/* ============================================================
   module_config.js — ek config per data screen.
   Engine (module.js) isi se KPIs, table aur add/edit form banata hai.

   Field keys = future DB columns. Dekhein RETAIL_DATA_CONTRACT.md
   ============================================================ */
(function () {
  var S = window.RetailStore, R = window.Region;
  function opts(coll) { return function () { return S.get(coll).map(function (r) { return { value: r.id, label: r.name }; }); }; }
  function nameOf(coll) { return function (row) { return S.name(coll, row[Object.keys(row)[0]]); }; }

  window.MODULE_CONFIGS = {

    /* ---------------- Product Catalog ---------------- */
    products: {
      key: 'products', title: 'Product Catalog', storeKey: 'products',
      recordName: 'Product', addLabel: '+ New product', wideForm: true,
      listTitle: 'All products', listSub: 'Barcode, pricing, tax and stock levels — POS isi list se chalti hai',
      searchPlaceholder: 'Search naam ya barcode', searchFields: ['name', 'sku'], emptyIcon: '▣',
      /* Bare catalog par sirf search kaafi nahi — "Bakery ka low stock"
         search se dhoonda hi nahi ja sakta tha. */
      filters: [
        { key: 'department_id', label: 'Department',
          options: function () { return S.get('departments').map(function (d) { return { value: d.id, label: d.name }; }); } },
        { key: 'category_id', label: 'Category',
          options: function () { return S.get('categories').map(function (c) { return { value: c.id, label: c.name }; }); } },
        { key: 'brand_id', label: 'Brand',
          options: function () { return S.get('brands').map(function (b) { return { value: b.id, label: b.name }; }); } },
        { key: 'stock_state', label: 'Stock', options: ['Out of stock', 'Low stock', 'In stock'],
          test: function (r, v) {
            var q = Number(r.stock_qty || 0), m = Number(r.min_stock || 0);
            if (v === 'Out of stock') return q <= 0;
            if (v === 'Low stock') return q > 0 && q <= m;
            return q > m;
          } },
        { key: 'status', label: 'Status', options: ['Active', 'Inactive'] }
      ],
      kpis: [
        { label: 'Products', calc: function (r) { return r.length; } },
        { label: 'Stock value', tone: 'ok', calc: function (r, M) { return M.money(r.reduce(function (a, p) { return a + p.stock_qty * p.cost_price; }, 0)); } },
        { label: 'Low stock', tone: 'warn', calc: function (r) { return r.filter(function (p) { return p.stock_qty > 0 && p.stock_qty <= p.min_stock; }).length; } },
        { label: 'Out of stock', tone: 'info', calc: function (r) { return r.filter(function (p) { return Number(p.stock_qty) <= 0; }).length; } },
        { label: 'Scale items', tone: 'violet', calc: function (r) { return r.filter(function (p) { return p.is_scale_item; }).length; } }
      ],
      columns: [
        {
          label: 'Product', field: 'name', render: function (r, M) {
            var b = (r.barcodes || [])[0] || (r.is_scale_item ? 'PLU ' + r.plu_code : '');
            return '<span class="t-main">' + M.esc(r.name) + '</span><span class="t-sub">' +
              (b ? M.esc(b) : '<span style="color:var(--warn)">barcode nahi</span>') + '</span>';
          }
        },
        { label: 'Department', render: function (r, M) { return M.esc(S.name('departments', r.department_id)); } },
        { label: 'Brand', render: function (r, M) { return M.esc(S.name('brands', r.brand_id)); } },
        { label: 'Cost', field: 'cost_price', format: 'money', align: 'num' },
        { label: 'Retail', field: 'retail_price', format: 'money', align: 'num' },
        { label: 'Wholesale', field: 'wholesale_price', format: 'money', align: 'num' },
        {
          label: 'Margin', align: 'num', render: function (r) {
            var m = r.retail_price ? ((r.retail_price - r.cost_price) / r.retail_price * 100) : 0;
            var tone = m < 8 ? 'danger' : (m < 15 ? 'warn' : 'ok');
            return '<span class="tag ' + tone + ' plain">' + m.toFixed(1) + '%</span>';
          }
        },
        {
          label: 'Stock', align: 'num', render: function (r, M) {
            var u = S.unitCode(r.base_unit_id), q = Number(r.stock_qty || 0);
            if (q <= 0) return '<span class="tag danger">Out</span>';
            if (q <= Number(r.min_stock || 0)) return '<b style="color:var(--warn)">' + M.qty(q) + ' ' + u + '</b>';
            return M.qty(q) + ' ' + u;
          }
        },
        { label: 'Status', field: 'status', format: 'tag', tags: { Active: 'ok', Inactive: 'neutral' } }
      ],
      fields: [
        /* Sirf 12 fields — wahi jo item banate waqt waqai chahiye.
           Zaroori: barcode, naam, cost, sale price.
           Wholesale, scale/PLU, batch tracking, shelf life yahan se
           nikal diye — woh baad mein edit se set hote hain. Counter par
           ek item banane mein aadha minute nahi lagna chahiye. */
        { key: 'barcode', label: 'Barcode', type: 'text', required: true,
          hint: 'scanner se scan kar dein', placeholder: '8964000112233' },
        { key: 'name', label: 'Item name', type: 'text', required: true, full: true,
          placeholder: 'Falak Super Basmati Rice 5 KG' },
        { key: 'department_id', label: 'Department', type: 'select', options: opts('departments'), addable: 'departments' },
        { key: 'category_id', label: 'Category', type: 'select', options: opts('categories'), addable: 'categories' },
        { key: 'brand_id', label: 'Brand', type: 'select', options: opts('brands'), addable: 'brands' },
        { key: 'base_unit_id', label: 'Unit', type: 'select', options: opts('units'), addable: 'uom' },
        { key: 'cost_price', label: 'Cost price', type: 'money', required: true, default: 0 },
        { key: 'retail_price', label: 'Sale price', type: 'money', required: true, default: 0 },
        { key: 'mrp', label: 'MRP / printed price', type: 'money', default: 0,
          autoFrom: 'retail_price', hint: 'sale price se khud bharta hai' },
        { key: 'tax_rate', label: 'Tax %', type: 'number', default: 0 },
        { key: 'stock_qty', label: 'Opening stock', type: 'number', default: 0 },
        { key: 'min_stock', label: 'Reorder level', type: 'number', default: 0 }
      ],
            onCreate: function (d) { d.barcodes = d.barcode ? [d.barcode] : []; delete d.barcode; }
    },

    /* ---------------- Departments ---------------- */
    departments: {
      key: 'departments', title: 'Departments', storeKey: 'departments',
      recordName: 'Department', addLabel: '+ New department',
      listTitle: 'Store departments', listSub: 'Har product ek department se juda hota hai — reports isi se banti hain',
      searchPlaceholder: 'Search department', searchFields: ['name', 'code'], emptyIcon: '☰',
      kpis: [
        { label: 'Departments', calc: function (r) { return r.length; } },
        { label: 'Categories', tone: 'info', calc: function () { return S.get('categories').length; } },
        { label: 'Products mapped', tone: 'ok', calc: function () { return S.get('products').length; } }
      ],
      columns: [
        { label: 'Department', field: 'name', sub: 'code' },
        { label: 'Categories', align: 'num', render: function (r) { return S.get('categories').filter(function (c) { return c.department_id === r.id; }).length; } },
        { label: 'Products', align: 'num', render: function (r) { return S.get('products').filter(function (p) { return p.department_id === r.id; }).length; } },
        {
          label: 'Stock value', align: 'num', render: function (r, M) {
            return M.money(S.get('products').filter(function (p) { return p.department_id === r.id; })
              .reduce(function (a, p) { return a + p.stock_qty * p.cost_price; }, 0));
          }
        },
        { label: 'Sort', field: 'sort_order', align: 'num' }
      ],
      fields: [
        { key: 'name', label: 'Department name', type: 'text', required: true, full: true, placeholder: 'Grocery' },
        { key: 'code', label: 'Short code', type: 'text', placeholder: 'GRO' },
        { key: 'sort_order', label: 'Sort order', type: 'number', default: 99 }
      ]
    },

    /* ---------------- Categories ---------------- */
    categories: {
      key: 'categories', title: 'Categories', storeKey: 'categories',
      recordName: 'Category', addLabel: '+ New category',
      listTitle: 'Product categories', listSub: 'Department ke andar dusra level',
      searchPlaceholder: 'Search category', searchFields: ['name'], emptyIcon: '☰',
      kpis: [
        { label: 'Categories', calc: function (r) { return r.length; } },
        { label: 'Avg products / category', tone: 'info', calc: function (r) { return r.length ? Math.round(S.get('products').length / r.length) : 0; } }
      ],
      columns: [
        { label: 'Category', field: 'name' },
        { label: 'Department', render: function (r, M) { return M.esc(S.name('departments', r.department_id)); } },
        { label: 'Products', align: 'num', render: function (r) { return S.get('products').filter(function (p) { return p.category_id === r.id; }).length; } }
      ],
      fields: [
        { key: 'name', label: 'Category name', type: 'text', required: true, full: true },
        { key: 'department_id', label: 'Department', type: 'select', options: opts('departments'), addable: 'departments', full: true }
      ]
    },

    /* ---------------- Brands ---------------- */
    brands: {
      key: 'brands', title: 'Brands', storeKey: 'brands',
      recordName: 'Brand', addLabel: '+ New brand',
      listTitle: 'Brand list', listSub: 'Supplier aur reporting dono brand se filter hote hain',
      searchPlaceholder: 'Search brand', searchFields: ['name'], emptyIcon: '◈',
      kpis: [
        { label: 'Brands', calc: function (r) { return r.length; } },
        { label: 'Products', tone: 'ok', calc: function () { return S.get('products').length; } }
      ],
      columns: [
        { label: 'Brand', field: 'name' },
        { label: 'Products', align: 'num', render: function (r) { return S.get('products').filter(function (p) { return p.brand_id === r.id; }).length; } },
        {
          label: 'Stock value', align: 'num', render: function (r, M) {
            return M.money(S.get('products').filter(function (p) { return p.brand_id === r.id; })
              .reduce(function (a, p) { return a + p.stock_qty * p.cost_price; }, 0));
          }
        }
      ],
      fields: [{ key: 'name', label: 'Brand name', type: 'text', required: true, full: true }]
    },

    /* ---------------- Units & pack sizes ---------------- */
    uom: {
      key: 'uom', title: 'Units & Pack Sizes', storeKey: 'units',
      recordName: 'Unit', addLabel: '+ New unit',
      note: '<b>Multi-UOM ka usool:</b> stock hamesha <b>base unit</b> mein girta hai. Carton kharida, piece becha — conversion factor yahin se lagta hai.',
      listTitle: 'Units of measure', listSub: 'Base units aur pack conversions',
      searchPlaceholder: 'Search unit', searchFields: ['name', 'code'], emptyIcon: '⇹',
      kpis: [
        { label: 'Units', calc: function (r) { return r.length; } },
        { label: 'Pack units', tone: 'info', calc: function (r) { return r.filter(function (u) { return u.unit_type === 'PACK'; }).length; } },
        { label: 'Product packs', tone: 'ok', calc: function () { return S.get('product_uom').length; } }
      ],
      columns: [
        { label: 'Unit', field: 'name', sub: 'code' },
        { label: 'Type', field: 'unit_type', format: 'tag', tags: { COUNT: 'info', WEIGHT: 'ok', VOLUME: 'brand', PACK: 'warn' } },
        { label: 'Base unit', render: function (r, M) { return r.base_unit_id ? M.esc(S.unitCode(r.base_unit_id)) : '<span style="color:var(--faint)">— is base</span>'; } },
        {
          label: 'Conversion', align: 'num', render: function (r, M) {
            if (!r.base_unit_id) return '1';
            return '<b>1 ' + M.esc(r.code) + ' = ' + r.conversion_factor + ' ' + M.esc(S.unitCode(r.base_unit_id)) + '</b>';
          }
        },
        { label: 'Decimals', field: 'decimal_places', align: 'num' }
      ],
      fields: [
        { key: 'code', label: 'Unit code', type: 'text', required: true, placeholder: 'CTN24' },
        { key: 'name', label: 'Unit name', type: 'text', required: true, placeholder: 'Carton (24)' },
        { key: 'unit_type', label: 'Type', type: 'select', options: ['COUNT', 'WEIGHT', 'VOLUME', 'PACK'] },
        { key: 'base_unit_id', label: 'Base unit', type: 'select', options: opts('units'), hint: 'pack units ke liye' },
        { key: 'conversion_factor', label: 'Conversion factor', type: 'number', default: 1, hint: '1 pack = kitne base units' },
        { key: 'decimal_places', label: 'Decimal places', type: 'number', default: 0 }
      ]
    },

    /* ---------------- Batch & expiry ---------------- */
    batches: {
      key: 'batches', title: 'Batch & Expiry', storeKey: 'batches',
      recordName: 'Batch', addLabel: '+ New batch',
      note: 'FIFO: sale par sab se pehle <b>expire hone wala batch</b> khatam hota hai. Near-expiry alert dashboard par bhi aata hai.',
      listTitle: 'Stock batches', listSub: 'Expiry-tracked items ka batch-wise stock',
      searchPlaceholder: 'Search batch number', searchFields: ['batch_no'], emptyIcon: '◷',
      kpis: [
        { label: 'Live batches', calc: function (r) { return r.filter(function (b) { return b.qty > 0; }).length; } },
        { label: 'Expiring ≤ 7 days', tone: 'warn', calc: function (r) { return r.filter(function (b) { var d = S.daysTo(b.expiry_date); return b.qty > 0 && d >= 0 && d <= 7; }).length; } },
        { label: 'Expired', tone: 'info', calc: function (r) { return r.filter(function (b) { return b.qty > 0 && S.daysTo(b.expiry_date) < 0; }).length; } },
        { label: 'Batch stock value', tone: 'ok', calc: function (r, M) { return M.money(r.reduce(function (a, b) { return a + b.qty * b.cost_price; }, 0)); } }
      ],
      columns: [
        { label: 'Product', render: function (r, M) { var p = S.byId('products', r.product_id); return '<span class="t-main">' + M.esc(p ? p.name : '—') + '</span><span class="t-sub">Batch ' + M.esc(r.batch_no) + '</span>'; } },
        { label: 'Received', field: 'received_on' },
        { label: 'Expiry', field: 'expiry_date' },
        {
          label: 'Days left', align: 'num', render: function (r) {
            var d = S.daysTo(r.expiry_date);
            if (r.qty <= 0) return '<span style="color:var(--faint)">consumed</span>';
            if (d < 0) return '<span class="tag danger">Expired ' + (-d) + 'd</span>';
            if (d <= 7) return '<span class="tag warn">' + d + ' days</span>';
            if (d <= 30) return '<span class="tag info">' + d + ' days</span>';
            return '<span class="tag ok plain">' + d + ' days</span>';
          }
        },
        { label: 'Qty', field: 'qty', format: 'qty', align: 'num' },
        { label: 'Cost', field: 'cost_price', format: 'money', align: 'num' },
        { label: 'Value', align: 'num', render: function (r, M) { return '<b>' + M.money(r.qty * r.cost_price) + '</b>'; } }
      ],
      fields: [
        { key: 'product_id', label: 'Product', type: 'select', options: opts('products'), full: true },
        { key: 'batch_no', label: 'Batch number', type: 'text', required: true },
        { key: 'expiry_date', label: 'Expiry date', type: 'date', required: true },
        { key: 'qty', label: 'Quantity', type: 'number', default: 0 },
        { key: 'cost_price', label: 'Cost price', type: 'money', default: 0 },
        { key: 'received_on', label: 'Received on', type: 'date' }
      ]
    },

    /* ---------------- Suppliers ---------------- */
    suppliers: {
      key: 'suppliers', title: 'Suppliers', storeKey: 'suppliers',
      recordName: 'Supplier', addLabel: '+ New supplier',
      listTitle: 'Supplier list', listSub: 'Purchase orders, GRN aur payables sab yahin se jurte hain',
      searchPlaceholder: 'Search name, contact or city', searchFields: ['name', 'contact_person', 'phone', 'city'], emptyIcon: '⌂',
      kpis: [
        { label: 'Suppliers', calc: function (r) { return r.length; } },
        { label: 'Total payable', tone: 'warn', calc: function (r, M) { return M.money(M.sum(r, 'outstanding')); } },
        { label: 'On credit terms', tone: 'info', calc: function (r) { return r.filter(function (s) { return (s.payment_terms || '').indexOf('Credit') > -1; }).length; } },
        { label: 'Active', tone: 'ok', calc: function (r, M) { return M.count(r, 'status', 'Active'); } }
      ],
      columns: [
        { label: 'Supplier', field: 'name', sub: 'contact_person' },
        { label: 'Phone', field: 'phone' },
        { label: 'City', field: 'city' },
        { label: 'Terms', field: 'payment_terms', format: 'tag', tags: { Cash: 'ok', 'Credit 7 days': 'info', 'Credit 15 days': 'info', 'Credit 30 days': 'warn' } },
        { label: 'Outstanding', field: 'outstanding', format: 'money_or_clear', align: 'num' },
        { label: 'Status', field: 'status', format: 'tag', tags: { Active: 'ok', Inactive: 'neutral' } }
      ],
      fields: [
        { key: 'name', label: 'Supplier name', type: 'text', required: true, full: true },
        { key: 'contact_person', label: 'Contact person', type: 'text' },
        { key: 'phone', label: 'Phone', type: 'tel', required: true },
        { key: 'city', label: 'City', type: 'text' },
        { key: 'payment_terms', label: 'Payment terms', type: 'select', options: ['Cash', 'Credit 7 days', 'Credit 15 days', 'Credit 30 days'] },
        { key: 'opening_balance', label: 'Opening balance', type: 'money', default: 0 },
        { key: 'outstanding', label: 'Outstanding', type: 'money', default: 0 },
        { key: 'status', label: 'Status', type: 'select', options: ['Active', 'Inactive'], default: 'Active' }
      ]
    },

    /* ---------------- Customers ---------------- */
    customers: {
      key: 'customers', title: 'Customers', storeKey: 'customers',
      recordName: 'Customer', addLabel: '+ New customer',
      listTitle: 'Customer directory', listSub: 'Retail, wholesale aur credit customers',
      searchPlaceholder: 'Search name, phone or area', searchFields: ['name', 'phone', 'area'], emptyIcon: '☺',
      kpis: [
        { label: 'Customers', calc: function (r) { return r.length; } },
        { label: 'Wholesale', tone: 'info', calc: function (r, M) { return M.count(r, 'customer_type', 'Wholesale'); } },
        { label: 'Credit outstanding', tone: 'warn', calc: function (r, M) { return M.money(M.sum(r, 'balance')); } },
        { label: 'Loyalty points', tone: 'ok', calc: function (r, M) { return M.num(M.sum(r, 'loyalty_points')); } }
      ],
      columns: [
        { label: 'Customer', field: 'name', sub: 'phone' },
        { label: 'Type', field: 'customer_type', format: 'tag', tags: { Retail: 'info', Wholesale: 'brand' } },
        { label: 'Area', field: 'area' },
        { label: 'Credit limit', field: 'credit_limit', format: 'money', align: 'num' },
        {
          label: 'Balance', align: 'num', render: function (r, M) {
            var b = Number(r.balance || 0), lim = Number(r.credit_limit || 0);
            if (b <= 0) return '<span style="color:var(--ok)">Clear</span>';
            var tone = (lim && b >= lim * 0.9) ? 'danger' : 'warn';
            return '<b style="color:var(--' + tone + ')">' + M.money(b) + '</b>';
          }
        },
        { label: 'Points', field: 'loyalty_points', format: 'num', align: 'num' },
        { label: 'Tier', field: 'tier', format: 'tag', tags: { Gold: 'warn', Silver: 'neutral', Bronze: 'info' } }
      ],
      fields: [
        { key: 'name', label: 'Customer name', type: 'text', required: true, full: true },
        { key: 'phone', label: 'Phone', type: 'tel' },
        { key: 'area', label: 'Area', type: 'text' },
        { key: 'customer_type', label: 'Customer type', type: 'select', options: ['Retail', 'Wholesale'], default: 'Retail' },
        { key: 'credit_limit', label: 'Credit limit', type: 'money', default: 0, hint: '0 = cash only' },
        { key: 'balance', label: 'Opening balance', type: 'money', default: 0 },
        { key: 'loyalty_points', label: 'Loyalty points', type: 'number', default: 0 },
        { key: 'tier', label: 'Tier', type: 'select', options: ['Bronze', 'Silver', 'Gold'], default: 'Bronze' },
        { key: 'status', label: 'Status', type: 'select', options: ['Active', 'Inactive'], default: 'Active' }
      ]
    },

    /* ---------------- Counters ---------------- */
    counters: {
      key: 'counters', title: 'Counter Management', storeKey: 'counters',
      recordName: 'Counter', addLabel: '+ New counter',
      listTitle: 'POS counters', listSub: 'Har counter ka apna cashier, printer aur cash drawer',
      searchPlaceholder: 'Search counter or cashier', searchFields: ['name', 'cashier', 'device_name'], emptyIcon: '⊞',
      kpis: [
        { label: 'Counters', calc: function (r) { return r.length; } },
        { label: 'Open now', tone: 'ok', calc: function (r, M) { return M.count(r, 'status', 'Open'); } },
        { label: 'Opening cash', tone: 'info', calc: function (r, M) { return M.money(M.sum(r, 'opening_cash')); } }
      ],
      columns: [
        { label: 'Counter', field: 'name', sub: 'device_name' },
        { label: 'Cashier', render: function (r, M) { return r.cashier ? M.esc(r.cashier) : '<span style="color:var(--faint)">— not assigned</span>'; } },
        { label: 'Printer', field: 'printer' },
        { label: 'Drawer', field: 'drawer', format: 'tag', tags: { Attached: 'ok', None: 'neutral' } },
        { label: 'Opening cash', field: 'opening_cash', format: 'money', align: 'num' },
        { label: 'Status', field: 'status', format: 'tag', tags: { Open: 'ok', Closed: 'neutral' } }
      ],
      fields: [
        { key: 'name', label: 'Counter name', type: 'text', required: true, full: true },
        { key: 'device_name', label: 'Device / PC name', type: 'text' },
        { key: 'printer', label: 'Receipt printer', type: 'text' },
        { key: 'drawer', label: 'Cash drawer', type: 'select', options: ['Attached', 'None'] },
        { key: 'cashier', label: 'Assigned cashier', type: 'text' },
        { key: 'opening_cash', label: 'Opening cash', type: 'money', default: 0 },
        { key: 'status', label: 'Status', type: 'select', options: ['Open', 'Closed'], default: 'Closed' }
      ]
    },

    /* ---------------- Promotions ---------------- */
    promotions: {
      key: 'promotions', title: 'Discounts & Promotions', storeKey: 'promotions',
      recordName: 'Promotion', addLabel: '+ New promotion',
      listTitle: 'Promotion rules', listSub: 'POS par apne aap lagti hain — cashier ko kuch nahi karna parta',
      searchPlaceholder: 'Search promotion', searchFields: ['name', 'target'], emptyIcon: '◎',
      kpis: [
        { label: 'Promotions', calc: function (r) { return r.length; } },
        { label: 'Running now', tone: 'ok', calc: function (r, M) { return M.count(r, 'status', 'Active'); } },
        { label: 'Scheduled', tone: 'info', calc: function (r, M) { return M.count(r, 'status', 'Scheduled'); } },
        { label: 'Expired', tone: 'warn', calc: function (r, M) { return M.count(r, 'status', 'Expired'); } }
      ],
      columns: [
        { label: 'Promotion', field: 'name', sub: 'target' },
        { label: 'Type', field: 'promo_type', format: 'tag', tags: { BOGO: 'brand', Percent: 'info', Bundle: 'warn', Coupon: 'ok' } },
        { label: 'Scope', field: 'scope' },
        { label: 'Value', field: 'value' },
        { label: 'Starts', field: 'starts_on' },
        { label: 'Ends', field: 'ends_on' },
        { label: 'Status', field: 'status', format: 'tag', tags: { Active: 'ok', Scheduled: 'info', Expired: 'neutral' } }
      ],
      fields: [
        { key: 'name', label: 'Promotion name', type: 'text', required: true, full: true },
        { key: 'promo_type', label: 'Type', type: 'select', options: ['Percent', 'BOGO', 'Bundle', 'Coupon'] },
        { key: 'scope', label: 'Applies to', type: 'select', options: ['Product', 'Department', 'Product Set', 'Whole Bill'] },
        { key: 'target', label: 'Target', type: 'text', full: true, placeholder: 'Lifebuoy Soap 130 G' },
        { key: 'value', label: 'Value', type: 'text', placeholder: '10% / Buy 2 Get 1' },
        { key: 'starts_on', label: 'Starts on', type: 'date' },
        { key: 'ends_on', label: 'Ends on', type: 'date' },
        { key: 'status', label: 'Status', type: 'select', options: ['Active', 'Scheduled', 'Expired'], default: 'Scheduled' }
      ]
    },


    /* ---------------- Stock on hand ---------------- */
    stock: {
      key: 'products', title: 'Stock on Hand', storeKey: 'products', canAdd: false,
      listTitle: 'Stock position', listSub: 'Reorder level se neeche wale items sab se upar dekhein',
      filters: [
        { key: 'department_id', label: 'Department',
          options: function () { return S.get('departments').map(function (d) { return { value: d.id, label: d.name }; }); } },
        { key: 'stock_state', label: 'Stock', options: ['Out of stock', 'Low stock', 'In stock'],
          test: function (r, v) {
            var q = Number(r.stock_qty || 0), m = Number(r.min_stock || 0);
            if (v === 'Out of stock') return q <= 0;
            if (v === 'Low stock') return q > 0 && q <= m;
            return q > m;
          } }
      ],
      searchPlaceholder: 'Search product or SKU', searchFields: ['name', 'sku'], emptyIcon: '\u25a6',
      kpis: [
        { label: 'SKUs', calc: function (r) { return r.length; } },
        { label: 'Stock value', tone: 'ok', calc: function (r, M) { return M.money(r.reduce(function (a, p) { return a + p.stock_qty * p.cost_price; }, 0)); } },
        { label: 'Low stock', tone: 'warn', calc: function (r) { return r.filter(function (p) { return p.stock_qty > 0 && p.stock_qty <= p.min_stock; }).length; } },
        { label: 'Out of stock', tone: 'info', calc: function (r) { return r.filter(function (p) { return Number(p.stock_qty) <= 0; }).length; } }
      ],
      columns: [
        { label: 'Product', field: 'name', sub: 'sku' },
        { label: 'Department', render: function (r, M) { return M.esc(S.name('departments', r.department_id)); } },
        { label: 'On hand', align: 'num', render: function (r, M) {
            var q = Number(r.stock_qty || 0), u = S.unitCode(r.base_unit_id);
            if (q <= 0) return '<span class="tag danger">Out</span>';
            if (q <= Number(r.min_stock || 0)) return '<b style="color:var(--warn)">' + M.qty(q) + ' ' + u + '</b>';
            return M.qty(q) + ' ' + u; } },
        { label: 'Reorder at', field: 'min_stock', format: 'qty', align: 'num' },
        { label: 'Max', field: 'max_stock', format: 'qty', align: 'num' },
        { label: 'To order', align: 'num', render: function (r, M) {
            var need = Number(r.max_stock || 0) - Number(r.stock_qty || 0);
            return (Number(r.stock_qty) <= Number(r.min_stock) && need > 0)
              ? '<b style="color:var(--brand-ink)">' + M.qty(need) + '</b>' : '\u2014'; } },
        { label: 'Cost value', align: 'num', render: function (r, M) { return M.money(r.stock_qty * r.cost_price); } }
      ]
    },

    /* ---------------- Price management ---------------- */
    pricing: {
      key: 'products', title: 'Price Management', storeKey: 'products',
      recordName: 'Price', addLabel: '+ New product', wideForm: true,
      note: 'Yahan rate badalne se POS par foran lagta hai. Shelf tag dobara chhapna na bhoolein \u2014 <b>Barcode & Shelf Labels</b>.',
      listTitle: 'Prices & margins', listSub: 'Cost, retail, wholesale aur margin ek jagah',
      searchPlaceholder: 'Search product', searchFields: ['name', 'sku'], emptyIcon: '%',
      filters: [
        { key: 'department_id', label: 'Department',
          options: function () { return S.get('departments').map(function (d) { return { value: d.id, label: d.name }; }); } },
        { key: 'margin_state', label: 'Margin', options: ['Below cost', 'Under 8%', 'Healthy'],
          test: function (r, v) {
            if (!r.retail_price) return false;
            var m = (r.retail_price - r.cost_price) / r.retail_price * 100;
            if (v === 'Below cost') return m < 0;
            if (v === 'Under 8%') return m >= 0 && m < 8;
            return m >= 8;
          } }
      ],
      kpis: [
        { label: 'Products', calc: function (r) { return r.length; } },
        { label: 'Avg margin', tone: 'ok', calc: function (r) {
            var v = r.filter(function (p) { return p.retail_price > 0; });
            if (!v.length) return '0%';
            return (v.reduce(function (a, p) { return a + (p.retail_price - p.cost_price) / p.retail_price * 100; }, 0) / v.length).toFixed(1) + '%'; } },
        { label: 'Below 8% margin', tone: 'warn', calc: function (r) {
            return r.filter(function (p) { return p.retail_price > 0 && (p.retail_price - p.cost_price) / p.retail_price * 100 < 8; }).length; } },
        { label: 'Selling below cost', tone: 'info', calc: function (r) { return r.filter(function (p) { return p.retail_price > 0 && p.retail_price < p.cost_price; }).length; } }
      ],
      columns: [
        { label: 'Product', field: 'name', sub: 'sku' },
        { label: 'Cost', field: 'cost_price', format: 'money', align: 'num' },
        { label: 'Retail', field: 'retail_price', format: 'money', align: 'num' },
        { label: 'Wholesale', field: 'wholesale_price', format: 'money', align: 'num' },
        { label: 'MRP', field: 'mrp', format: 'money', align: 'num' },
        { label: 'Margin', align: 'num', render: function (r) {
            if (!r.retail_price) return '\u2014';
            var m = (r.retail_price - r.cost_price) / r.retail_price * 100;
            var tone = m < 0 ? 'danger' : (m < 8 ? 'danger' : (m < 15 ? 'warn' : 'ok'));
            return '<span class="tag ' + tone + ' plain">' + m.toFixed(1) + '%</span>'; } },
        { label: 'Tax', align: 'num', render: function (r) { return r.tax_rate + '%'; } }
      ],
      fields: [
        { key: 'name', label: 'Product name', type: 'text', required: true, full: true },
        { key: 'barcode', label: 'Barcode', type: 'text', hint: 'scan kar dein' },
        { key: 'cost_price', label: 'Cost price', type: 'money', default: 0 },
        { key: 'retail_price', label: 'Retail price', type: 'money', required: true, default: 0 },
        { key: 'wholesale_price', label: 'Wholesale price', type: 'money', default: 0 },
        { key: 'mrp', label: 'MRP / printed price', type: 'money', default: 0 },
        { key: 'tax_rate', label: 'Tax rate %', type: 'number', default: 0 },
        { key: 'status', label: 'Status', type: 'select', options: ['Active', 'Inactive'], default: 'Active' }
      ]
    },

    /* ---------------- Scale items ---------------- */
    scale: {
      key: 'products', title: 'Weighing Scale Items', storeKey: 'products',
      recordName: 'Scale item', addLabel: '+ New scale item', wideForm: true,
      note: 'Taraazu khud label chhapti hai jismein barcode ke andar weight chhupa hota hai. POS us barcode ko parh kar weight khud le leta hai \u2014 cashier ko kuch type nahi karna parta.',
      listTitle: 'Loose / weighed items', listSub: 'PLU code taraazu mein bhi wahi hona chahiye',
      searchPlaceholder: 'Search item or PLU', searchFields: ['name', 'plu_code'], emptyIcon: '\u2696',
      kpis: [
        { label: 'Scale items', calc: function (r) { return r.filter(function (p) { return p.is_scale_item; }).length; } },
        { label: 'PLU missing', tone: 'warn', calc: function (r) { return r.filter(function (p) { return p.is_scale_item && !p.plu_code; }).length; } },
        { label: 'Stock value', tone: 'ok', calc: function (r, M) { return M.money(r.filter(function (p) { return p.is_scale_item; }).reduce(function (a, p) { return a + p.stock_qty * p.cost_price; }, 0)); } }
      ],
      columns: [
        { label: 'Item', field: 'name', sub: 'sku' },
        { label: 'PLU', render: function (r, M) { return r.plu_code ? '<b>' + M.esc(r.plu_code) + '</b>' : '<span class="tag warn">missing</span>'; } },
        { label: 'Unit', render: function (r, M) { return M.esc(S.unitCode(r.base_unit_id)); } },
        { label: 'Rate', field: 'retail_price', format: 'money', align: 'num' },
        { label: 'On hand', field: 'stock_qty', format: 'qty', align: 'num' },
        { label: 'Scale item', render: function (r) { return r.is_scale_item ? '<span class="tag ok">Yes</span>' : '<span class="tag neutral">No</span>'; } }
      ],
      fields: [
        { key: 'name', label: 'Item name', type: 'text', required: true, full: true, placeholder: 'Tomato (loose)' },
        { key: 'plu_code', label: 'PLU code', type: 'text', required: true, hint: 'taraazu wala', placeholder: '00201' },
        { key: 'base_unit_id', label: 'Unit', type: 'select', options: opts('units'), addable: 'uom' },
        { key: 'retail_price', label: 'Rate per unit', type: 'money', required: true, default: 0 },
        { key: 'cost_price', label: 'Cost per unit', type: 'money', default: 0 },
        { key: 'wholesale_price', label: 'Wholesale rate', type: 'money', default: 0 },
        { key: 'stock_qty', label: 'Stock', type: 'number', default: 0 },
        { key: 'is_scale_item', label: 'Scale item?', type: 'select', options: [{ value: 1, label: 'Yes' }, { value: 0, label: 'No' }], default: 1 },
        { key: 'status', label: 'Status', type: 'select', options: ['Active', 'Inactive'], default: 'Active' }
      ]
    },

    /* ---------------- Khata / credit ---------------- */
    khata: {
      key: 'customers', title: 'Customer Credit / Khata', storeKey: 'customers', canAdd: false,
      note: 'Yeh sirf khaata dikhata hai. Credit sale POS se banti hai aur recovery bhi wahin se \u2014 POS par customer chun kar payment lein.',
      listTitle: 'Udhaar khaata', listSub: 'Jin par baqaya hai wo sab se upar',
      searchPlaceholder: 'Search customer or phone', searchFields: ['name', 'phone', 'area'], emptyIcon: '\u20a8',
      kpis: [
        { label: 'Total receivable', tone: 'warn', calc: function (r, M) { return M.money(M.sum(r, 'balance')); } },
        { label: 'Khata customers', calc: function (r) { return r.filter(function (c) { return Number(c.balance) > 0; }).length; } },
        { label: 'Near limit', tone: 'danger', calc: function (r) { return r.filter(function (c) { return Number(c.credit_limit) > 0 && Number(c.balance) >= Number(c.credit_limit) * 0.9; }).length; } },
        { label: 'Limit se upar', tone: 'info', calc: function (r) { return r.filter(function (c) { return Number(c.credit_limit) > 0 && Number(c.balance) > Number(c.credit_limit); }).length; } }
      ],
      columns: [
        { label: 'Customer', field: 'name', sub: 'phone' },
        { label: 'Type', field: 'customer_type', format: 'tag', tags: { Retail: 'info', Wholesale: 'brand' } },
        { label: 'Area', field: 'area' },
        { label: 'Credit limit', field: 'credit_limit', format: 'money', align: 'num' },
        { label: 'Outstanding', align: 'num', render: function (r, M) {
            var b = Number(r.balance || 0), l = Number(r.credit_limit || 0);
            if (b <= 0) return '<span style="color:var(--ok)">Clear</span>';
            return '<b style="color:var(--' + (l && b >= l * 0.9 ? 'danger' : 'warn') + ')">' + M.money(b) + '</b>'; } },
        { label: 'Available', align: 'num', render: function (r, M) {
            var a = Number(r.credit_limit || 0) - Number(r.balance || 0);
            return Number(r.credit_limit) > 0 ? M.money(Math.max(0, a)) : '\u2014'; } }
      ]
    },

    /* ---------------- Expenses ---------------- */
    expenses: {
      key: 'expenses', title: 'Expenses', storeKey: 'expenses',
      recordName: 'Expense', addLabel: '+ New expense',
      listTitle: 'Store expenses', listSub: 'Cash drawer se nikla hua paisa yahan darj karein',
      searchPlaceholder: 'Search expense', searchFields: ['title', 'category', 'paid_to'], emptyIcon: '\u25bc',
      kpis: [
        { label: 'Entries', calc: function (r) { return r.length; } },
        { label: 'Total', tone: 'warn', calc: function (r, M) { return M.money(M.sum(r, 'amount')); } }
      ],
      columns: [
        { label: 'Expense', field: 'title', sub: 'category' },
        { label: 'Paid to', field: 'paid_to' },
        { label: 'Method', field: 'method', format: 'tag', tags: { Cash: 'ok', Card: 'info', Bank: 'brand' } },
        { label: 'Date', field: 'spent_on' },
        { label: 'Amount', field: 'amount', format: 'money', align: 'num' }
      ],
      fields: [
        { key: 'title', label: 'Expense title', type: 'text', required: true, full: true },
        { key: 'category', label: 'Category', type: 'select', options: ['Utilities', 'Rent', 'Salaries', 'Transport', 'Repairs', 'Cleaning', 'General'] },
        { key: 'paid_to', label: 'Paid to', type: 'text' },
        { key: 'method', label: 'Payment method', type: 'select', options: ['Cash', 'Card', 'Bank'] },
        { key: 'spent_on', label: 'Date', type: 'date' },
        { key: 'amount', label: 'Amount', type: 'money', required: true, default: 0 }
      ]
    },

    /* ---------------- Staff ---------------- */
    staff: {
      key: 'staff', title: 'Staff / Roles', storeKey: 'staff',
      recordName: 'Staff member', addLabel: '+ New staff',
      listTitle: 'Store staff', listSub: 'Login aur permissions <b>Users & Access</b> se milte hain',
      searchPlaceholder: 'Search name or role', searchFields: ['name', 'role', 'phone'], emptyIcon: '\u2687',
      kpis: [
        { label: 'Staff', calc: function (r) { return r.length; } },
        { label: 'Cashiers', tone: 'info', calc: function (r, M) { return M.count(r, 'role', 'Cashier'); } },
        { label: 'Active', tone: 'ok', calc: function (r, M) { return M.count(r, 'status', 'Active'); } }
      ],
      columns: [
        { label: 'Name', field: 'name', sub: 'phone' },
        { label: 'Role', field: 'role', format: 'tag', tags: { 'Store Manager': 'brand', Cashier: 'info', Storekeeper: 'ok', 'Floor Staff': 'neutral', 'Purchase Officer': 'warn' } },
        { label: 'Shift', field: 'shift' },
        { label: 'Joined', field: 'joined_on' },
        { label: 'Status', field: 'status', format: 'tag', tags: { Active: 'ok', Inactive: 'neutral' } }
      ],
      fields: [
        { key: 'name', label: 'Full name', type: 'text', required: true, full: true },
        { key: 'phone', label: 'Phone', type: 'tel' },
        { key: 'role', label: 'Role', type: 'select', options: ['Store Manager', 'Cashier', 'Floor Staff', 'Storekeeper', 'Purchase Officer'] },
        { key: 'shift', label: 'Shift', type: 'select', options: ['Morning', 'Evening', 'Night', 'Rotating'] },
        { key: 'joined_on', label: 'Joined on', type: 'date' },
        { key: 'status', label: 'Status', type: 'select', options: ['Active', 'Inactive'], default: 'Active' }
      ]
    },

    /* ---------------- Printers ---------------- */
    printers: {
      key: 'printers', title: 'Printers & Devices', storeKey: 'printers',
      recordName: 'Printer', addLabel: '+ New printer',
      note: 'Receipt printer aur cash drawer ek hi cable par hote hain \u2014 drawer printer ke kick command se khulta hai. Label printer alag hota hai (TSPL/ZPL).',
      listTitle: 'Connected devices', listSub: 'Counter-wise printer aur drawer',
      searchPlaceholder: 'Search printer', searchFields: ['name', 'model', 'connection'], emptyIcon: '\u2399',
      kpis: [
        { label: 'Devices', calc: function (r) { return r.length; } },
        { label: 'Online', tone: 'ok', calc: function (r, M) { return M.count(r, 'status', 'Online'); } },
        { label: 'Label printers', tone: 'info', calc: function (r, M) { return M.count(r, 'type', 'Label'); } }
      ],
      columns: [
        { label: 'Device', field: 'name', sub: 'model' },
        { label: 'Type', field: 'type', format: 'tag', tags: { Receipt: 'ok', Label: 'info', Kitchen: 'neutral' } },
        { label: 'Connection', field: 'connection' },
        { label: 'Assigned to', field: 'assigned_to' },
        { label: 'Status', field: 'status', format: 'tag', tags: { Online: 'ok', Offline: 'danger' } }
      ],
      fields: [
        { key: 'name', label: 'Device name', type: 'text', required: true, full: true },
        { key: 'model', label: 'Model', type: 'text', placeholder: 'Epson TM-T88' },
        { key: 'type', label: 'Type', type: 'select', options: ['Receipt', 'Label', 'Kitchen'] },
        { key: 'connection', label: 'Connection', type: 'select', options: ['USB', 'Network (IP)', 'Bluetooth', 'Serial'] },
        { key: 'assigned_to', label: 'Assigned to', type: 'text', placeholder: 'Counter 1' },
        { key: 'status', label: 'Status', type: 'select', options: ['Online', 'Offline'], default: 'Online' }
      ]
    },

    /* ---------------- Branches ---------------- */
    branches: {
      key: 'branches', title: 'Multi-Branch', storeKey: 'branches',
      recordName: 'Branch', addLabel: '+ New branch',
      listTitle: 'Store branches', listSub: 'Har branch ka apna stock aur counters hote hain',
      searchPlaceholder: 'Search branch', searchFields: ['name', 'city'], emptyIcon: '\u2317',
      kpis: [
        { label: 'Branches', calc: function (r) { return r.length; } },
        { label: 'Active', tone: 'ok', calc: function (r, M) { return M.count(r, 'status', 'Active'); } }
      ],
      columns: [
        { label: 'Branch', field: 'name', sub: 'city' },
        { label: 'Phone', field: 'phone' },
        { label: 'Manager', field: 'manager' },
        { label: 'Status', field: 'status', format: 'tag', tags: { Active: 'ok', Inactive: 'neutral' } }
      ],
      fields: [
        { key: 'name', label: 'Branch name', type: 'text', required: true, full: true },
        { key: 'city', label: 'City', type: 'text' },
        { key: 'phone', label: 'Phone', type: 'tel' },
        { key: 'manager', label: 'Manager', type: 'text' },
        { key: 'status', label: 'Status', type: 'select', options: ['Active', 'Inactive'], default: 'Active' }
      ]
    },

    /* ---------------- Loyalty ---------------- */
    loyalty: {
      key: 'loyalty', title: 'Loyalty / Membership', storeKey: 'customers', canAdd: false,
      listTitle: 'Loyalty members', listSub: 'Points POS par bill ke sath jama hote hain',
      searchPlaceholder: 'Search member', searchFields: ['name', 'phone'], emptyIcon: '\u2605',
      kpis: [
        { label: 'Members', calc: function (r) { return r.filter(function (c) { return Number(c.loyalty_points) > 0; }).length; } },
        { label: 'Total points', tone: 'info', calc: function (r, M) { return M.num(M.sum(r, 'loyalty_points')); } }
      ],
      columns: [
        { label: 'Member', field: 'name', sub: 'phone' },
        { label: 'Area', field: 'area' },
        { label: 'Points', field: 'loyalty_points', format: 'num', align: 'num' },
        { label: 'Tier', field: 'tier', format: 'tag', tags: { Gold: 'warn', Silver: 'neutral', Bronze: 'info' } }
      ]
    },


    /* ============ Document screens ============
       Yeh generic record store par chalte hain (wahi jagah jahan
       restaurant ke kai modules abhi hain). Data mehfooz rehta hai aur
       sync hota hai; deep flows (line-by-line GRN posting, PO se stock
       auto-receive) agli batch mein real tables par jayenge. */

    purchasing: {
      key: 'purchasing', title: 'Purchasing', storeKey: 'rtl_purchasing',
      recordName: 'Purchase', addLabel: '+ New purchase',
      listTitle: 'Purchase entries', listSub: 'Supplier se aaya hua maal',
      searchPlaceholder: 'Search supplier or ref', searchFields: ['supplier', 'ref_no'], emptyIcon: '\u21e9',
      kpis: [
        { label: 'Entries', calc: function (r) { return r.length; } },
        { label: 'Total value', tone: 'ok', calc: function (r, M) { return M.money(M.sum(r, 'amount')); } },
        { label: 'Unpaid', tone: 'warn', calc: function (r, M) { return M.money(r.filter(function (x) { return x.status !== 'Paid'; }).reduce(function (a, x) { return a + Number(x.amount || 0); }, 0)); } }
      ],
      columns: [
        { label: 'Reference', field: 'ref_no', sub: 'purchased_on' },
        { label: 'Supplier', field: 'supplier' },
        { label: 'Items', field: 'line_count', align: 'num' },
        { label: 'Amount', field: 'amount', format: 'money', align: 'num' },
        { label: 'Status', field: 'status', format: 'tag', tags: { Paid: 'ok', Partial: 'warn', Unpaid: 'danger' } }
      ],
      fields: [
        { key: 'ref_no', label: 'Reference / invoice no.', type: 'text', required: true },
        { key: 'supplier', label: 'Supplier', type: 'select', options: opts('suppliers') },
        { key: 'purchased_on', label: 'Date', type: 'date' },
        { key: 'line_count', label: 'Items', type: 'number', default: 0 },
        { key: 'amount', label: 'Total amount', type: 'money', required: true, default: 0 },
        { key: 'status', label: 'Payment status', type: 'select', options: ['Unpaid', 'Partial', 'Paid'], default: 'Unpaid' },
        { key: 'note', label: 'Note', type: 'textarea', full: true }
      ]
    },

    po: {
      key: 'po', title: 'Purchase Orders', storeKey: 'rtl_po',
      recordName: 'Purchase order', addLabel: '+ New PO',
      note: 'Reorder level se neeche wale items <b>Stock on Hand</b> par dikhte hain \u2014 wahan se PO ki list banayein.',
      listTitle: 'Purchase orders', listSub: 'Supplier ko bheje gaye orders',
      searchPlaceholder: 'Search PO or supplier', searchFields: ['po_no', 'supplier'], emptyIcon: '\u25a5',
      kpis: [
        { label: 'Orders', calc: function (r) { return r.length; } },
        { label: 'Pending receive', tone: 'warn', calc: function (r, M) { return M.count(r, 'status', 'Pending'); } },
        { label: 'Value', tone: 'ok', calc: function (r, M) { return M.money(M.sum(r, 'amount')); } }
      ],
      columns: [
        { label: 'PO', field: 'po_no', sub: 'ordered_on' },
        { label: 'Supplier', field: 'supplier' },
        { label: 'Expected', field: 'expected_on' },
        { label: 'Items', field: 'line_count', align: 'num' },
        { label: 'Amount', field: 'amount', format: 'money', align: 'num' },
        { label: 'Status', field: 'status', format: 'tag', tags: { Pending: 'warn', Received: 'ok', Cancelled: 'neutral' } }
      ],
      fields: [
        { key: 'po_no', label: 'PO number', type: 'text', required: true },
        { key: 'supplier', label: 'Supplier', type: 'select', options: opts('suppliers') },
        { key: 'ordered_on', label: 'Ordered on', type: 'date' },
        { key: 'expected_on', label: 'Expected on', type: 'date' },
        { key: 'line_count', label: 'Items', type: 'number', default: 0 },
        { key: 'amount', label: 'Amount', type: 'money', default: 0 },
        { key: 'status', label: 'Status', type: 'select', options: ['Pending', 'Received', 'Cancelled'], default: 'Pending' }
      ]
    },

    grn: {
      key: 'grn', title: 'Goods Receipt (GRN)', storeKey: 'rtl_grn',
      recordName: 'GRN', addLabel: '+ New GRN',
      note: 'GRN wo lamha hai jab maal <b>waqai</b> store mein aata hai. PO order hai, GRN receiving \u2014 dono ka farq stock ki sachai tay karta hai.',
      listTitle: 'Goods received', listSub: 'Supplier se receive kiya gaya maal',
      searchPlaceholder: 'Search GRN or supplier', searchFields: ['grn_no', 'supplier', 'po_no'], emptyIcon: '\u2295',
      kpis: [
        { label: 'Receipts', calc: function (r) { return r.length; } },
        { label: 'Value received', tone: 'ok', calc: function (r, M) { return M.money(M.sum(r, 'amount')); } },
        { label: 'With discrepancy', tone: 'warn', calc: function (r, M) { return M.count(r, 'status', 'Discrepancy'); } }
      ],
      columns: [
        { label: 'GRN', field: 'grn_no', sub: 'received_on' },
        { label: 'Supplier', field: 'supplier' },
        { label: 'Against PO', field: 'po_no' },
        { label: 'Items', field: 'line_count', align: 'num' },
        { label: 'Amount', field: 'amount', format: 'money', align: 'num' },
        { label: 'Status', field: 'status', format: 'tag', tags: { Posted: 'ok', Draft: 'neutral', Discrepancy: 'warn' } }
      ],
      fields: [
        { key: 'grn_no', label: 'GRN number', type: 'text', required: true },
        { key: 'supplier', label: 'Supplier', type: 'select', options: opts('suppliers') },
        { key: 'po_no', label: 'Against PO', type: 'text' },
        { key: 'received_on', label: 'Received on', type: 'date' },
        { key: 'line_count', label: 'Items', type: 'number', default: 0 },
        { key: 'amount', label: 'Amount', type: 'money', default: 0 },
        { key: 'status', label: 'Status', type: 'select', options: ['Draft', 'Posted', 'Discrepancy'], default: 'Draft' },
        { key: 'note', label: 'Note', type: 'textarea', full: true }
      ]
    },

    preturn: {
      key: 'preturn', title: 'Purchase Return', storeKey: 'rtl_preturn',
      recordName: 'Return', addLabel: '+ New return',
      note: 'Kharab ya expired maal supplier ko wapas. Debit note supplier ke khaate se kam hota hai.',
      listTitle: 'Returns to supplier', listSub: 'Debit notes',
      searchPlaceholder: 'Search return or supplier', searchFields: ['ref_no', 'supplier'], emptyIcon: '\u21e7',
      kpis: [
        { label: 'Returns', calc: function (r) { return r.length; } },
        { label: 'Debit value', tone: 'warn', calc: function (r, M) { return M.money(M.sum(r, 'amount')); } }
      ],
      columns: [
        { label: 'Reference', field: 'ref_no', sub: 'returned_on' },
        { label: 'Supplier', field: 'supplier' },
        { label: 'Reason', field: 'reason', format: 'tag', tags: { Expired: 'danger', Damaged: 'warn', 'Wrong item': 'info', Excess: 'neutral' } },
        { label: 'Items', field: 'line_count', align: 'num' },
        { label: 'Amount', field: 'amount', format: 'money', align: 'num' },
        { label: 'Status', field: 'status', format: 'tag', tags: { Settled: 'ok', Pending: 'warn' } }
      ],
      fields: [
        { key: 'ref_no', label: 'Reference', type: 'text', required: true },
        { key: 'supplier', label: 'Supplier', type: 'select', options: opts('suppliers') },
        { key: 'returned_on', label: 'Date', type: 'date' },
        { key: 'reason', label: 'Reason', type: 'select', options: ['Expired', 'Damaged', 'Wrong item', 'Excess'] },
        { key: 'line_count', label: 'Items', type: 'number', default: 0 },
        { key: 'amount', label: 'Amount', type: 'money', default: 0 },
        { key: 'status', label: 'Status', type: 'select', options: ['Pending', 'Settled'], default: 'Pending' }
      ]
    },

    transfer: {
      key: 'transfer', title: 'Stock Transfer', storeKey: 'rtl_transfer',
      recordName: 'Transfer', addLabel: '+ New transfer',
      listTitle: 'Branch transfers', listSub: 'Ek branch se doosre branch maal',
      searchPlaceholder: 'Search transfer', searchFields: ['ref_no', 'from_branch', 'to_branch'], emptyIcon: '\u21c4',
      kpis: [
        { label: 'Transfers', calc: function (r) { return r.length; } },
        { label: 'In transit', tone: 'warn', calc: function (r, M) { return M.count(r, 'status', 'In transit'); } },
        { label: 'Value', tone: 'ok', calc: function (r, M) { return M.money(M.sum(r, 'amount')); } }
      ],
      columns: [
        { label: 'Reference', field: 'ref_no', sub: 'transfer_date' },
        { label: 'From', field: 'from_branch' },
        { label: 'To', field: 'to_branch' },
        { label: 'Items', field: 'line_count', align: 'num' },
        { label: 'Value', field: 'amount', format: 'money', align: 'num' },
        { label: 'Status', field: 'status', format: 'tag', tags: { Received: 'ok', 'In transit': 'warn', Draft: 'neutral' } }
      ],
      fields: [
        { key: 'ref_no', label: 'Reference', type: 'text', required: true },
        { key: 'from_branch', label: 'From branch', type: 'text' },
        { key: 'to_branch', label: 'To branch', type: 'text' },
        { key: 'transfer_date', label: 'Date', type: 'date' },
        { key: 'line_count', label: 'Items', type: 'number', default: 0 },
        { key: 'amount', label: 'Value', type: 'money', default: 0 },
        { key: 'status', label: 'Status', type: 'select', options: ['Draft', 'In transit', 'Received'], default: 'Draft' }
      ]
    },

    count: {
      key: 'count', title: 'Physical Stock Count', storeKey: 'rtl_count',
      recordName: 'Count session', addLabel: '+ New count',
      note: 'Scanner se ginti karein. System qty aur counted qty ka farq hi <b>shrinkage</b> hai \u2014 supermarket mein yehi sab se bara chhupa hua nuqsan hota hai.',
      listTitle: 'Count sessions', listSub: 'Department-wise ginti',
      searchPlaceholder: 'Search session', searchFields: ['ref_no', 'department', 'counted_by'], emptyIcon: '\u2611',
      kpis: [
        { label: 'Sessions', calc: function (r) { return r.length; } },
        { label: 'Open', tone: 'warn', calc: function (r, M) { return M.count(r, 'status', 'Open'); } },
        { label: 'Shrinkage value', tone: 'danger', calc: function (r, M) { return M.money(M.sum(r, 'variance_value')); } }
      ],
      columns: [
        { label: 'Session', field: 'ref_no', sub: 'count_date' },
        { label: 'Department', field: 'department' },
        { label: 'Counted by', field: 'counted_by' },
        { label: 'Items', field: 'line_count', align: 'num' },
        { label: 'Variance', align: 'num', render: function (r, M) {
            var v = Number(r.variance_value || 0);
            return v ? '<b style="color:var(--danger)">' + M.money(v) + '</b>' : '<span style="color:var(--ok)">Match</span>'; } },
        { label: 'Status', field: 'status', format: 'tag', tags: { Posted: 'ok', Open: 'warn' } }
      ],
      fields: [
        { key: 'ref_no', label: 'Reference', type: 'text', required: true },
        { key: 'department', label: 'Department', type: 'select', options: opts('departments') },
        { key: 'count_date', label: 'Date', type: 'date' },
        { key: 'counted_by', label: 'Counted by', type: 'text' },
        { key: 'line_count', label: 'Items counted', type: 'number', default: 0 },
        { key: 'variance_value', label: 'Variance value', type: 'money', default: 0 },
        { key: 'status', label: 'Status', type: 'select', options: ['Open', 'Posted'], default: 'Open' }
      ]
    },

    wastage: {
      key: 'wastage', title: 'Damage / Expiry Write-off', storeKey: 'wastage',
      recordName: 'Write-off', addLabel: '+ New write-off',
      note: 'Expire hone wale batches <b>Batch & Expiry</b> par dikhte hain \u2014 wahan se yahan write-off karein.',
      listTitle: 'Write-offs', listSub: 'Kharab, expired ya toota hua maal',
      searchPlaceholder: 'Search write-off', searchFields: ['item', 'reason'], emptyIcon: '\u2298',
      kpis: [
        { label: 'Entries', calc: function (r) { return r.length; } },
        { label: 'Loss value', tone: 'danger', calc: function (r, M) { return M.money(M.sum(r, 'value')); } }
      ],
      columns: [
        { label: 'Item', field: 'item', sub: 'wasted_on' },
        { label: 'Reason', field: 'reason', format: 'tag', tags: { Expired: 'danger', Damaged: 'warn', Broken: 'warn', Theft: 'danger' } },
        { label: 'Qty', field: 'qty', format: 'qty', align: 'num' },
        { label: 'Value', field: 'value', format: 'money', align: 'num' },
        { label: 'Approved by', field: 'approved_by' }
      ],
      fields: [
        { key: 'item', label: 'Item', type: 'text', required: true, full: true },
        { key: 'reason', label: 'Reason', type: 'select', options: ['Expired', 'Damaged', 'Broken', 'Theft'] },
        { key: 'qty', label: 'Quantity', type: 'number', required: true, default: 0 },
        { key: 'value', label: 'Value', type: 'money', default: 0 },
        { key: 'wasted_on', label: 'Date', type: 'date' },
        { key: 'approved_by', label: 'Approved by', type: 'text' }
      ]
    },

    returns: {
      key: 'void', title: 'Return / Refund / Void', storeKey: 'rtl_returns',
      recordName: 'Return', addLabel: '+ New return',
      note: 'Bill wapas karne se stock <b>wapas</b> aata hai. Har return ka wajah likhna zaroori hai \u2014 yehi cashier ki jawabdehi hai.',
      listTitle: 'Customer returns', listSub: 'Refund, exchange aur void bills',
      searchPlaceholder: 'Search bill or customer', searchFields: ['bill_no', 'customer', 'reason'], emptyIcon: '\u2297',
      kpis: [
        { label: 'Returns', calc: function (r) { return r.length; } },
        { label: 'Refund value', tone: 'warn', calc: function (r, M) { return M.money(M.sum(r, 'amount')); } },
        { label: 'Voids', tone: 'danger', calc: function (r, M) { return M.count(r, 'type', 'Void'); } }
      ],
      columns: [
        { label: 'Bill', field: 'bill_no', sub: 'returned_on' },
        { label: 'Customer', field: 'customer' },
        { label: 'Type', field: 'type', format: 'tag', tags: { Refund: 'warn', Exchange: 'info', Void: 'danger' } },
        { label: 'Reason', field: 'reason' },
        { label: 'Amount', field: 'amount', format: 'money', align: 'num' },
        { label: 'By', field: 'handled_by' }
      ],
      fields: [
        { key: 'bill_no', label: 'Original bill no.', type: 'text', required: true },
        { key: 'customer', label: 'Customer', type: 'text' },
        { key: 'type', label: 'Type', type: 'select', options: ['Refund', 'Exchange', 'Void'] },
        { key: 'reason', label: 'Reason', type: 'text', required: true, full: true },
        { key: 'amount', label: 'Amount', type: 'money', default: 0 },
        { key: 'returned_on', label: 'Date', type: 'date' },
        { key: 'handled_by', label: 'Handled by', type: 'text' }
      ]
    },

    closing: {
      key: 'closing', title: 'Shift Closing History', storeKey: 'rtl_closing', canAdd: false,
      listTitle: 'Closed shifts', listSub: 'Counter-wise cash reconciliation',
      searchPlaceholder: 'Search cashier or counter', searchFields: ['cashier', 'counter'], emptyIcon: '\u25d1',
      kpis: [
        { label: 'Shifts', calc: function (r) { return r.length; } },
        { label: 'Cash collected', tone: 'ok', calc: function (r, M) { return M.money(M.sum(r, 'cash_sales')); } },
        { label: 'Total variance', tone: 'warn', calc: function (r, M) { return M.money(M.sum(r, 'variance')); } }
      ],
      columns: [
        { label: 'Shift', field: 'shift_no', sub: 'closed_at' },
        { label: 'Counter', field: 'counter', sub: 'cashier' },
        { label: 'Opening', field: 'opening_cash', format: 'money', align: 'num' },
        { label: 'Cash sales', field: 'cash_sales', format: 'money', align: 'num' },
        { label: 'Counted', field: 'counted_cash', format: 'money', align: 'num' },
        { label: 'Variance', align: 'num', render: function (r, M) {
            var v = Number(r.variance || 0);
            if (!v) return '<span style="color:var(--ok)">Match</span>';
            return '<b style="color:var(--' + (v < 0 ? 'danger' : 'warn') + ')">' + M.money(v) + '</b>'; } }
      ]
    },

    whatsapp: {
      key: 'whatsapp', title: 'WhatsApp / Notifications', storeKey: 'rtl_whatsapp',
      recordName: 'Template', addLabel: '+ New template',
      note: 'Offline node par messages queue mein jate hain aur internet aate hi bhej diye jate hain.',
      listTitle: 'Message templates', listSub: 'Bill, khata reminder aur promotions',
      searchPlaceholder: 'Search template', searchFields: ['name', 'trigger'], emptyIcon: '\u2706',
      kpis: [
        { label: 'Templates', calc: function (r) { return r.length; } },
        { label: 'Active', tone: 'ok', calc: function (r, M) { return M.count(r, 'status', 'Active'); } }
      ],
      columns: [
        { label: 'Template', field: 'name', sub: 'trigger' },
        { label: 'Trigger', field: 'trigger', format: 'tag', tags: { 'Bill sent': 'ok', 'Khata reminder': 'warn', Promotion: 'info', 'Low stock': 'neutral' } },
        { label: 'Status', field: 'status', format: 'tag', tags: { Active: 'ok', Paused: 'neutral' } }
      ],
      fields: [
        { key: 'name', label: 'Template name', type: 'text', required: true, full: true },
        { key: 'trigger', label: 'Send when', type: 'select', options: ['Bill sent', 'Khata reminder', 'Promotion', 'Low stock'] },
        { key: 'body', label: 'Message', type: 'textarea', full: true, placeholder: 'Assalam o alaikum {name}, aapka bill {amount} ka hai.' },
        { key: 'status', label: 'Status', type: 'select', options: ['Active', 'Paused'], default: 'Active' }
      ]
    },

    /* ---------------- Sales / invoices (read-only) ---------------- */
    sales: {
      key: 'sales', title: 'Sales / Invoices', storeKey: 'sales', canAdd: false,
      listTitle: 'Today\u2019s bills', listSub: 'Sab counters ki sale — click kar ke receipt dobara print ho sakti hai',
      searchPlaceholder: 'Search bill, customer or cashier', searchFields: ['bill_no', 'customer', 'cashier', 'counter'], emptyIcon: '≣',
      kpis: [
        { label: 'Bills', calc: function (r) { return r.length; } },
        { label: 'Gross sale', tone: 'ok', calc: function (r, M) { return M.money(M.sum(r, 'total')); } },
        { label: 'Avg basket', tone: 'info', calc: function (r, M) { return M.money(r.length ? M.sum(r, 'total') / r.length : 0); } },
        { label: 'On credit', tone: 'warn', calc: function (r, M) { return M.money(r.filter(function (s) { return s.status === 'Credit'; }).reduce(function (a, s) { return a + s.total; }, 0)); } }
      ],
      columns: [
        { label: 'Bill', field: 'bill_no', sub: 'sold_at' },
        { label: 'Customer', field: 'customer', sub: 'price_level' },
        { label: 'Counter', field: 'counter', sub: 'cashier' },
        { label: 'Lines', field: 'lines', align: 'num' },
        { label: 'Discount', field: 'discount', format: 'money', align: 'num' },
        { label: 'Tax', field: 'tax', format: 'money', align: 'num' },
        { label: 'Total', align: 'num', render: function (r, M) { return '<b>' + M.money(r.total) + '</b>'; } },
        { label: 'Status', field: 'status', format: 'tag', tags: { Completed: 'ok', Credit: 'warn', Refunded: 'danger' } }
      ]
    }
  };
})();
