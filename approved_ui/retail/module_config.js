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
      searchPlaceholder: 'Search name, SKU or barcode', searchFields: ['name', 'sku'], emptyIcon: '▣',
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
            var b = (r.barcodes || [])[0] || (r.is_scale_item ? 'PLU ' + r.plu_code : 'no barcode');
            return '<span class="t-main">' + M.esc(r.name) + '</span><span class="t-sub">' + M.esc(r.sku) + ' · ' + M.esc(b) + '</span>';
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
        { key: 'name', label: 'Product name', type: 'text', required: true, full: true, placeholder: 'Falak Super Basmati Rice 5 KG' },
        { key: 'sku', label: 'SKU', type: 'text', required: true, placeholder: 'GRO-0001' },
        { key: 'barcode', label: 'Barcode', type: 'text', hint: 'EAN-13 / UPC-A', placeholder: '8964000112233' },
        { key: 'department_id', label: 'Department', type: 'select', options: opts('departments') },
        { key: 'category_id', label: 'Category', type: 'select', options: opts('categories') },
        { key: 'brand_id', label: 'Brand', type: 'select', options: opts('brands') },
        { key: 'base_unit_id', label: 'Base unit', type: 'select', options: opts('units') },
        { key: 'cost_price', label: 'Cost price', type: 'money', required: true, default: 0 },
        { key: 'retail_price', label: 'Retail price', type: 'money', required: true, default: 0 },
        { key: 'wholesale_price', label: 'Wholesale price', type: 'money', default: 0 },
        { key: 'mrp', label: 'MRP / printed price', type: 'money', default: 0 },
        { key: 'tax_rate', label: 'Tax rate %', type: 'number', default: 0, hint: 'region ke hisaab se' },
        { key: 'stock_qty', label: 'Opening stock', type: 'number', default: 0 },
        { key: 'min_stock', label: 'Reorder level', type: 'number', default: 0 },
        { key: 'max_stock', label: 'Max stock', type: 'number', default: 0 },
        { key: 'is_scale_item', label: 'Scale item?', type: 'select', options: [{ value: 0, label: 'No — barcode item' }, { value: 1, label: 'Yes — weighed at scale' }] },
        { key: 'plu_code', label: 'PLU code', type: 'text', hint: 'scale items ke liye', placeholder: '00201' },
        { key: 'track_batch', label: 'Track batch & expiry?', type: 'select', options: [{ value: 0, label: 'No' }, { value: 1, label: 'Yes' }] },
        { key: 'shelf_life_days', label: 'Shelf life (days)', type: 'number', default: 0 },
        { key: 'status', label: 'Status', type: 'select', options: ['Active', 'Inactive'], default: 'Active' }
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
        { key: 'department_id', label: 'Department', type: 'select', options: opts('departments'), full: true }
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
