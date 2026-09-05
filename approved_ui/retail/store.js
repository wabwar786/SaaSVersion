/* ============================================================
   store.js — Retail demo data (offline-first, localStorage).

   AHEM USOOL: har field key wahi hai jo aage chal kar DB column
   banega. Isi liye Layer 2 (real MySQL) par sirf ModuleBridge
   likhna paray ga — screens ko haath nahi lagana paray ga.
   Tafseel: RETAIL_DATA_CONTRACT.md
   ============================================================ */
(function () {
  var KEY = 'retail_os_store_v1';

  var defaults = {

    /* ---- rtl_departments ---- */
    departments: [
      { id: 'd1', name: 'Grocery', code: 'GRO', sort_order: 1 },
      { id: 'd2', name: 'Bakery', code: 'BAK', sort_order: 2 },
      { id: 'd3', name: 'Dairy & Chilled', code: 'DAI', sort_order: 3 },
      { id: 'd4', name: 'Fruits & Vegetables', code: 'FNV', sort_order: 4 },
      { id: 'd5', name: 'Beverages', code: 'BEV', sort_order: 5 },
      { id: 'd6', name: 'Household & Cleaning', code: 'HSE', sort_order: 6 },
      { id: 'd7', name: 'Personal Care', code: 'PCR', sort_order: 7 },
      { id: 'd8', name: 'Meat & Frozen', code: 'MFZ', sort_order: 8 }
    ],

    /* ---- rtl_categories ---- */
    categories: [
      { id: 'c1', department_id: 'd1', name: 'Rice & Pulses' },
      { id: 'c2', department_id: 'd1', name: 'Cooking Oil & Ghee' },
      { id: 'c3', department_id: 'd1', name: 'Tea & Coffee' },
      { id: 'c4', department_id: 'd1', name: 'Spices & Masala' },
      { id: 'c5', department_id: 'd2', name: 'Bread & Rusk' },
      { id: 'c6', department_id: 'd3', name: 'Milk & Yogurt' },
      { id: 'c7', department_id: 'd4', name: 'Fresh Vegetables' },
      { id: 'c8', department_id: 'd4', name: 'Fresh Fruit' },
      { id: 'c9', department_id: 'd5', name: 'Soft Drinks' },
      { id: 'c10', department_id: 'd5', name: 'Water & Juice' },
      { id: 'c11', department_id: 'd6', name: 'Detergents' },
      { id: 'c12', department_id: 'd7', name: 'Soap & Shampoo' },
      { id: 'c13', department_id: 'd8', name: 'Frozen Items' }
    ],

    /* ---- rtl_brands ---- */
    brands: [
      { id: 'b1', name: 'Falak' }, { id: 'b2', name: 'Dalda' }, { id: 'b3', name: 'Tapal' },
      { id: 'b4', name: 'Nestlé' }, { id: 'b5', name: 'Olpers' }, { id: 'b6', name: 'Coca-Cola' },
      { id: 'b7', name: 'Surf Excel' }, { id: 'b8', name: 'Lifebuoy' }, { id: 'b9', name: 'National' },
      { id: 'b10', name: 'K&N\u2019s' }, { id: 'b11', name: 'Shan' }, { id: 'b12', name: 'No Brand' }
    ],

    /* ---- units (global + tenant) — conversion_factor base unit ke mutabiq ---- */
    units: [
      { id: 'u1', code: 'PCS', name: 'Piece', unit_type: 'COUNT', base_unit_id: null, conversion_factor: 1, decimal_places: 0 },
      { id: 'u2', code: 'KG', name: 'Kilogram', unit_type: 'WEIGHT', base_unit_id: null, conversion_factor: 1, decimal_places: 3 },
      { id: 'u3', code: 'G', name: 'Gram', unit_type: 'WEIGHT', base_unit_id: 'u2', conversion_factor: 0.001, decimal_places: 0 },
      { id: 'u4', code: 'L', name: 'Litre', unit_type: 'VOLUME', base_unit_id: null, conversion_factor: 1, decimal_places: 3 },
      { id: 'u5', code: 'ML', name: 'Millilitre', unit_type: 'VOLUME', base_unit_id: 'u4', conversion_factor: 0.001, decimal_places: 0 },
      { id: 'u6', code: 'DOZ', name: 'Dozen', unit_type: 'PACK', base_unit_id: 'u1', conversion_factor: 12, decimal_places: 0 },
      { id: 'u7', code: 'CTN24', name: 'Carton (24)', unit_type: 'PACK', base_unit_id: 'u1', conversion_factor: 24, decimal_places: 0 },
      { id: 'u8', code: 'CTN12', name: 'Carton (12)', unit_type: 'PACK', base_unit_id: 'u1', conversion_factor: 12, decimal_places: 0 },
      { id: 'u9', code: 'BAG10', name: 'Bag 10 KG', unit_type: 'PACK', base_unit_id: 'u2', conversion_factor: 10, decimal_places: 0 }
    ],

    /* ---- rtl_products (+ rtl_product_barcodes as `barcodes[]`) ---- */
    products: [
      { id: 'p1', sku: 'GRO-0001', name: 'Falak Super Basmati Rice 5 KG', barcodes: ['8964000112236'], department_id: 'd1', category_id: 'c1', brand_id: 'b1', base_unit_id: 'u1', tax_rate: 0, cost_price: 2180, retail_price: 2450, wholesale_price: 2320, mrp: 2500, stock_qty: 46, min_stock: 12, max_stock: 80, is_scale_item: 0, plu_code: '', track_batch: 0, shelf_life_days: 540, status: 'Active' },
      { id: 'p2', sku: 'GRO-0002', name: 'Dalda Cooking Oil 5 Litre', barcodes: ['8964000445563'], department_id: 'd1', category_id: 'c2', brand_id: 'b2', base_unit_id: 'u1', tax_rate: 17, cost_price: 2680, retail_price: 2975, wholesale_price: 2840, mrp: 3050, stock_qty: 31, min_stock: 10, max_stock: 60, is_scale_item: 0, plu_code: '', track_batch: 1, shelf_life_days: 365, status: 'Active' },
      { id: 'p3', sku: 'GRO-0003', name: 'Tapal Danedar Tea 950 G', barcodes: ['8964000778890'], department_id: 'd1', category_id: 'c3', brand_id: 'b3', base_unit_id: 'u1', tax_rate: 17, cost_price: 1420, retail_price: 1590, wholesale_price: 1510, mrp: 1625, stock_qty: 8, min_stock: 15, max_stock: 50, is_scale_item: 0, plu_code: '', track_batch: 0, shelf_life_days: 730, status: 'Active' },
      { id: 'p4', sku: 'DAI-0001', name: 'Olpers Milk 1 Litre', barcodes: ['8964000221143'], department_id: 'd3', category_id: 'c6', brand_id: 'b5', base_unit_id: 'u1', tax_rate: 0, cost_price: 268, retail_price: 300, wholesale_price: 285, mrp: 310, stock_qty: 96, min_stock: 40, max_stock: 200, is_scale_item: 0, plu_code: '', track_batch: 1, shelf_life_days: 90, status: 'Active' },
      { id: 'p5', sku: 'BEV-0001', name: 'Coca-Cola 1.5 Litre', barcodes: ['5449000054227', '8964000990018'], department_id: 'd5', category_id: 'c9', brand_id: 'b6', base_unit_id: 'u1', tax_rate: 17, cost_price: 168, retail_price: 200, wholesale_price: 182, mrp: 210, stock_qty: 142, min_stock: 48, max_stock: 288, is_scale_item: 0, plu_code: '', track_batch: 0, shelf_life_days: 180, status: 'Active' },
      { id: 'p6', sku: 'FNV-0001', name: 'Tomato (loose)', barcodes: [], department_id: 'd4', category_id: 'c7', brand_id: 'b12', base_unit_id: 'u2', tax_rate: 0, cost_price: 95, retail_price: 140, wholesale_price: 120, mrp: 0, stock_qty: 38.5, min_stock: 15, max_stock: 100, is_scale_item: 1, plu_code: '00201', track_batch: 0, shelf_life_days: 7, status: 'Active' },
      { id: 'p7', sku: 'FNV-0002', name: 'Banana (loose)', barcodes: [], department_id: 'd4', category_id: 'c8', brand_id: 'b12', base_unit_id: 'u2', tax_rate: 0, cost_price: 130, retail_price: 190, wholesale_price: 165, mrp: 0, stock_qty: 22.8, min_stock: 10, max_stock: 60, is_scale_item: 1, plu_code: '00202', track_batch: 0, shelf_life_days: 5, status: 'Active' },
      { id: 'p8', sku: 'HSE-0001', name: 'Surf Excel 1 KG', barcodes: ['8964000334454'], department_id: 'd6', category_id: 'c11', brand_id: 'b7', base_unit_id: 'u1', tax_rate: 17, cost_price: 640, retail_price: 725, wholesale_price: 686, mrp: 750, stock_qty: 54, min_stock: 20, max_stock: 120, is_scale_item: 0, plu_code: '', track_batch: 0, shelf_life_days: 900, status: 'Active' },
      { id: 'p9', sku: 'PCR-0001', name: 'Lifebuoy Soap 130 G', barcodes: ['8964000667781'], department_id: 'd7', category_id: 'c12', brand_id: 'b8', base_unit_id: 'u1', tax_rate: 17, cost_price: 118, retail_price: 140, wholesale_price: 128, mrp: 145, stock_qty: 210, min_stock: 60, max_stock: 480, is_scale_item: 0, plu_code: '', track_batch: 0, shelf_life_days: 1080, status: 'Active' },
      { id: 'p10', sku: 'MFZ-0001', name: 'K&N\u2019s Chicken Nuggets 500 G', barcodes: ['8964000554432'], department_id: 'd8', category_id: 'c13', brand_id: 'b10', base_unit_id: 'u1', tax_rate: 17, cost_price: 745, retail_price: 850, wholesale_price: 800, mrp: 875, stock_qty: 4, min_stock: 12, max_stock: 48, is_scale_item: 0, plu_code: '', track_batch: 1, shelf_life_days: 270, status: 'Active' },
      { id: 'p11', sku: 'BAK-0001', name: 'Bread Large', barcodes: ['8964000889909'], department_id: 'd2', category_id: 'c5', brand_id: 'b12', base_unit_id: 'u1', tax_rate: 0, cost_price: 130, retail_price: 160, wholesale_price: 145, mrp: 165, stock_qty: 27, min_stock: 20, max_stock: 60, is_scale_item: 0, plu_code: '', track_batch: 1, shelf_life_days: 4, status: 'Active' },
      { id: 'p12', sku: 'BEV-0002', name: 'Nestlé Water 1.5 Litre', barcodes: ['8964000101018'], department_id: 'd5', category_id: 'c10', brand_id: 'b4', base_unit_id: 'u1', tax_rate: 17, cost_price: 62, retail_price: 85, wholesale_price: 72, mrp: 90, stock_qty: 188, min_stock: 72, max_stock: 360, is_scale_item: 0, plu_code: '', track_batch: 0, shelf_life_days: 365, status: 'Active' },
      { id: 'p13', sku: 'GRO-0004', name: 'National Chilli Powder 200 G', barcodes: ['8964000131312'], department_id: 'd1', category_id: 'c4', brand_id: 'b9', base_unit_id: 'u1', tax_rate: 17, cost_price: 235, retail_price: 275, wholesale_price: 255, mrp: 285, stock_qty: 63, min_stock: 24, max_stock: 120, is_scale_item: 0, plu_code: '', track_batch: 0, shelf_life_days: 730, status: 'Active' },
      { id: 'p14', sku: 'GRO-0005', name: 'Shan Biryani Masala 65 G', barcodes: ['8964000141410'], department_id: 'd1', category_id: 'c4', brand_id: 'b11', base_unit_id: 'u1', tax_rate: 17, cost_price: 96, retail_price: 120, wholesale_price: 108, mrp: 125, stock_qty: 0, min_stock: 30, max_stock: 150, is_scale_item: 0, plu_code: '', track_batch: 0, shelf_life_days: 730, status: 'Active' },
      { id: 'p15', sku: 'FNV-0003', name: 'Potato (loose)', barcodes: [], department_id: 'd4', category_id: 'c7', brand_id: 'b12', base_unit_id: 'u2', tax_rate: 0, cost_price: 58, retail_price: 90, wholesale_price: 75, mrp: 0, stock_qty: 71.2, min_stock: 25, max_stock: 150, is_scale_item: 1, plu_code: '00203', track_batch: 0, shelf_life_days: 21, status: 'Active' }
    ],

    /* ---- rtl_product_uom — ek product ke multiple pack sizes ---- */
    product_uom: [
      { id: 'pu1', product_id: 'p5', unit_id: 'u7', barcode: '5449000054234', factor: 24, cost_price: 4032, retail_price: 4560, is_default_purchase: 1 },
      { id: 'pu2', product_id: 'p12', unit_id: 'u7', barcode: '8964000101025', factor: 24, cost_price: 1440, retail_price: 1920, is_default_purchase: 1 },
      { id: 'pu3', product_id: 'p4', unit_id: 'u8', barcode: '8964000221150', factor: 12, cost_price: 3180, retail_price: 3480, is_default_purchase: 1 },
      { id: 'pu4', product_id: 'p9', unit_id: 'u6', barcode: '8964000667798', factor: 12, cost_price: 1380, retail_price: 1620, is_default_purchase: 1 }
    ],

    /* ---- stock_batches ---- */
    batches: [
      { id: 'bt1', product_id: 'p2', batch_no: 'DL-2609', expiry_date: '2027-03-14', qty: 31, cost_price: 2680, received_on: '2026-08-12' },
      { id: 'bt2', product_id: 'p4', batch_no: 'OL-0904', expiry_date: '2026-09-11', qty: 96, cost_price: 268, received_on: '2026-08-29' },
      { id: 'bt3', product_id: 'p10', batch_no: 'KN-2288', expiry_date: '2026-09-08', qty: 4, cost_price: 745, received_on: '2026-07-30' },
      { id: 'bt4', product_id: 'p11', batch_no: 'BR-0903', expiry_date: '2026-09-06', qty: 27, cost_price: 130, received_on: '2026-09-02' },
      { id: 'bt5', product_id: 'p2', batch_no: 'DL-2544', expiry_date: '2026-11-02', qty: 0, cost_price: 2610, received_on: '2026-05-18' }
    ],

    /* ---- suppliers ---- */
    suppliers: [
      { id: 's1', name: 'Metro Cash & Carry', contact_person: 'Sana Malik', phone: '051-4447733', city: 'Rawalpindi', payment_terms: 'Credit 30 days', opening_balance: 0, outstanding: 348200, status: 'Active' },
      { id: 's2', name: 'Unilever Distributor', contact_person: 'Bilal Ahmed', phone: '0300-5552211', city: 'Islamabad', payment_terms: 'Credit 15 days', opening_balance: 0, outstanding: 126400, status: 'Active' },
      { id: 's3', name: 'Fresh Mandi Supply', contact_person: 'Kamran Shah', phone: '0333-9081122', city: 'Islamabad', payment_terms: 'Cash', opening_balance: 0, outstanding: 0, status: 'Active' },
      { id: 's4', name: 'Coca-Cola Beverages', contact_person: 'Usman Tariq', phone: '051-5551188', city: 'Islamabad', payment_terms: 'Credit 7 days', opening_balance: 0, outstanding: 84600, status: 'Active' }
    ],

    /* ---- customers (khata / account) ---- */
    customers: [
      { id: 'cu1', name: 'Walk-in Customer', phone: '', area: '', customer_type: 'Retail', credit_limit: 0, balance: 0, loyalty_points: 0, tier: 'Bronze', status: 'Active' },
      { id: 'cu2', name: 'Hameed Kiryana Store', phone: '0300-8811223', area: 'G-11 Markaz', customer_type: 'Wholesale', credit_limit: 200000, balance: 84500, loyalty_points: 0, tier: 'Gold', status: 'Active' },
      { id: 'cu3', name: 'Ayesha Siddiqui', phone: '0321-4455667', area: 'F-10/3', customer_type: 'Retail', credit_limit: 15000, balance: 3200, loyalty_points: 1840, tier: 'Silver', status: 'Active' },
      { id: 'cu4', name: 'Baithak Restaurant', phone: '051-2233445', area: 'F-11 Markaz', customer_type: 'Wholesale', credit_limit: 350000, balance: 218900, loyalty_points: 0, tier: 'Gold', status: 'Active' },
      { id: 'cu5', name: 'Rehan Aslam', phone: '0345-7788990', area: 'E-11/2', customer_type: 'Retail', credit_limit: 0, balance: 0, loyalty_points: 620, tier: 'Bronze', status: 'Active' }
    ],

    /* ---- rtl_counters ---- */
    counters: [
      { id: 'ct1', name: 'Counter 1', device_name: 'POS-01', printer: 'Epson TM-T88 (Counter 1)', drawer: 'Attached', status: 'Open', cashier: 'Ali Raza', opening_cash: 15000 },
      { id: 'ct2', name: 'Counter 2', device_name: 'POS-02', printer: 'Epson TM-T88 (Counter 2)', drawer: 'Attached', status: 'Open', cashier: 'Nida Khan', opening_cash: 15000 },
      { id: 'ct3', name: 'Counter 3', device_name: 'POS-03', printer: 'Epson TM-T88 (Counter 3)', drawer: 'Attached', status: 'Closed', cashier: '', opening_cash: 0 }
    ],

    /* ---- promotions ---- */
    promotions: [
      { id: 'pr1', name: 'Buy 2 Get 1 — Lifebuoy Soap', promo_type: 'BOGO', scope: 'Product', target: 'Lifebuoy Soap 130 G', value: 'Buy 2 Get 1', starts_on: '2026-09-01', ends_on: '2026-09-30', status: 'Active' },
      { id: 'pr2', name: 'Beverages 10% Off', promo_type: 'Percent', scope: 'Department', target: 'Beverages', value: '10%', starts_on: '2026-09-01', ends_on: '2026-09-15', status: 'Active' },
      { id: 'pr3', name: 'Weekend Grocery Bundle', promo_type: 'Bundle', scope: 'Product Set', target: 'Rice + Oil + Tea', value: 'Rs 350 off', starts_on: '2026-09-05', ends_on: '2026-09-08', status: 'Scheduled' },
      { id: 'pr4', name: 'Ramzan Flat 5%', promo_type: 'Percent', scope: 'Whole Bill', target: 'All products', value: '5%', starts_on: '2026-03-01', ends_on: '2026-03-30', status: 'Expired' }
    ],

    /* ---- held / parked carts (POS) ---- */
    held_bills: [],

    /* ---- completed sales (POS demo) ----
       items[] receipt reprint ke liye zaroori hai; reprint_count aur
       last_reprint_at audit ke liye — duplicate bill ginti mein aata hai. */
    sales: [
      {
        id: 'INV-20904', bill_no: 'INV-20904', counter: 'Counter 1', cashier: 'Ali Raza', customer: 'Walk-in Customer', price_level: 'Retail', lines: 4, subtotal: 5985, discount: 0, tax: 403, total: 5985, paid_cash: 6000, paid_card: 0, change: 15, status: 'Completed', sold_at: '2026-09-04 12:41', reprint_count: 0, last_reprint_at: '',
        items: [
          { name: 'Falak Super Basmati Rice 5 KG', unit: 'PCS', qty: 1, price: 2450, disc: 0 },
          { name: 'Dalda Cooking Oil 5 Litre', unit: 'PCS', qty: 1, price: 2975, disc: 200 },
          { name: 'Olpers Milk 1 Litre', unit: 'PCS', qty: 2, price: 300, disc: 0 },
          { name: 'Bread Large', unit: 'PCS', qty: 1, price: 160, disc: 0 }
        ]
      },
      {
        id: 'INV-20903', bill_no: 'INV-20903', counter: 'Counter 2', cashier: 'Nida Khan', customer: 'Hameed Kiryana Store', price_level: 'Wholesale', lines: 3, subtotal: 66552, discount: 1200, tax: 9496, total: 65352, paid_cash: 0, paid_card: 0, change: 0, status: 'Credit', sold_at: '2026-09-04 12:18', reprint_count: 1, last_reprint_at: '2026-09-04 12:26',
        items: [
          { name: 'Coca-Cola 1.5 Litre', unit: 'CTN24', qty: 8, price: 4560, disc: 0 },
          { name: 'Nestl\u00e9 Water 1.5 Litre', unit: 'CTN24', qty: 12, price: 1920, disc: 1200 },
          { name: 'Surf Excel 1 KG', unit: 'PCS', qty: 12, price: 686, disc: 0 }
        ]
      },
      {
        id: 'INV-20902', bill_no: 'INV-20902', counter: 'Counter 1', cashier: 'Ali Raza', customer: 'Walk-in Customer', price_level: 'Retail', lines: 2, subtotal: 640, discount: 0, tax: 61, total: 640, paid_cash: 0, paid_card: 640, change: 0, status: 'Completed', sold_at: '2026-09-04 11:55', reprint_count: 0, last_reprint_at: '',
        items: [
          { name: 'Lifebuoy Soap 130 G', unit: 'PCS', qty: 3, price: 140, disc: 0 },
          { name: 'Tomato (loose)', unit: 'KG', qty: 1.571, price: 140, disc: 0 }
        ]
      },
      {
        id: 'INV-20901', bill_no: 'INV-20901', counter: 'Counter 2', cashier: 'Nida Khan', customer: 'Ayesha Siddiqui', price_level: 'Retail', lines: 3, subtotal: 8130, discount: 400, tax: 1123, total: 7730, paid_cash: 7788, paid_card: 0, change: 58, status: 'Completed', sold_at: '2026-09-04 11:32', reprint_count: 0, last_reprint_at: '',
        items: [
          { name: 'Tapal Danedar Tea 950 G', unit: 'PCS', qty: 2, price: 1590, disc: 0 },
          { name: 'National Chilli Powder 200 G', unit: 'PCS', qty: 4, price: 275, disc: 400 },
          { name: 'K&N\u2019s Chicken Nuggets 500 G', unit: 'PCS', qty: 5, price: 850, disc: 0 }
        ]
      }
    ]
  };

  var clone = function (o) { return JSON.parse(JSON.stringify(o)); };

  /* SPEED: state ek dafa memory mein aata hai. Har get() par JSON.parse
     karna POS ke liye bahut mehnga tha (har scan par kai calls).
     Disk write bhi debounce hai — UI kabhi localStorage ka intezar nahi karta. */
  var _state = null, _flushTimer = null;

  function load() {
    if (_state) return _state;
    try {
      var v = localStorage.getItem(KEY);
      if (v) {
        var s = JSON.parse(v);
        Object.keys(defaults).forEach(function (k) { if (!(k in s)) s[k] = clone(defaults[k]); });
        _state = s;
        return _state;
      }
    } catch (e) { }
    _state = clone(defaults);
    flush(true);
    return _state;
  }
  function flush(now) {
    if (now) {
      if (_flushTimer) { clearTimeout(_flushTimer); _flushTimer = null; }
      try { localStorage.setItem(KEY, JSON.stringify(_state)); } catch (e) { }
      return;
    }
    if (_flushTimer) return;
    _flushTimer = setTimeout(function () { _flushTimer = null; flush(true); }, 400);
  }
  function save(s) { _state = s; flush(true); return _state; }
  function reset() { _state = null; _index = null; try { localStorage.removeItem(KEY); } catch (e) { } return load(); }

  function get(k) { return load()[k] || []; }
  function set(k, rows) { var s = load(); s[k] = rows; if (k === 'products' || k === 'product_uom') _index = null; flush(); return rows; }
  /* memory mein badla hua data disk par likhwana (bill save ke waqt) */
  function commit() { flush(true); }

  /* ---- lookups ---- */
  function byId(coll, id) { return get(coll).filter(function (r) { return r.id === id; })[0] || null; }
  function name(coll, id, fallback) {
    var r = byId(coll, id); return r ? r.name : (fallback || '—');
  }
  function unitCode(id) { var u = byId('units', id); return u ? u.code : 'PCS'; }

  /* Barcode / SKU / PLU ka hash index — POS ka dil.
     Pehle har scan par poori product list par loop chalta tha; ab ek
     Map lookup hai, chahe catalog mein 50,000 items hon. */
  var _index = null;
  function buildIndex() {
    var ix = { code: Object.create(null), plu: Object.create(null) };
    get('products').forEach(function (p) {
      (p.barcodes || []).forEach(function (b) { if (b) ix.code[b] = { product: p, qty: 1, source: 'barcode' }; });
      if (p.sku) ix.code[String(p.sku).toLowerCase()] = { product: p, qty: 1, source: 'sku' };
      if (p.plu_code) {
        ix.plu[p.plu_code] = p;
        ix.code[p.plu_code] = { product: p, qty: 1, source: 'plu' };
      }
    });
    get('product_uom').forEach(function (u) {
      if (!u.barcode) return;
      var p = byId('products', u.product_id);
      if (p) ix.code[u.barcode] = { product: p, qty: u.factor, source: 'pack', uom: u };
    });
    _index = ix;
    return ix;
  }
  function index() { return _index || buildIndex(); }

  function findByCode(code) {
    code = String(code || '').trim();
    if (!code) return null;
    var ix = index();

    var hit = ix.code[code] || ix.code[code.toLowerCase()];
    if (hit) return { product: hit.product, qty: hit.qty, source: hit.source, uom: hit.uom };

    var scale = window.Region ? Region.parseScaleBarcode(code) : null;
    if (scale) {
      var sp = ix.plu[scale.plu];
      if (sp) return { product: sp, qty: scale.value, source: 'scale' };
    }
    return null;
  }

  function searchProducts(q) {
    q = String(q || '').toLowerCase().trim();
    if (!q) return [];
    return get('products').filter(function (p) {
      return p.name.toLowerCase().indexOf(q) > -1 ||
        (p.sku || '').toLowerCase().indexOf(q) > -1 ||
        (p.barcodes || []).some(function (b) { return b.indexOf(q) > -1; });
    }).slice(0, 12);
  }

  function daysTo(dateStr) {
    var d = new Date(dateStr + 'T00:00:00'), now = new Date();
    return Math.round((d - now) / 86400000);
  }

  window.RetailStore = {
    load: load, save: save, reset: reset, get: get, set: set,
    byId: byId, name: name, unitCode: unitCode, commit: commit, rebuildIndex: buildIndex,
    findByCode: findByCode, searchProducts: searchProducts, daysTo: daysTo
  };
})();
