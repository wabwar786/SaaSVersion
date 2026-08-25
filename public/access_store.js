(function(){
 const MODULES=[{"key": "dashboard", "label": "Dashboard", "href": "index.html"}, {"key": "shift", "label": "Opening & Closing Shift", "href": "shift_management.html"}, {"key": "pos", "label": "Sale Point / POS", "href": "restaurant_pos.html"}, {"key": "tablet", "label": "Order Taker Tablet", "href": "restaurant_order_taker_tablet.html"}, {"key": "kds", "label": "Kitchen / KDS", "href": "kds.html"}, {"key": "tables", "label": "Tables & Floors", "href": "tables_floors.html"}, {"key": "orders", "label": "Running Orders", "href": "orders_management.html"}, {"key": "online", "label": "Online Orders", "href": "online_orders.html"}, {"key": "inventory", "label": "Inventory", "href": "inventory_creation.html"}, {"key": "purchasing", "label": "Purchasing", "href": "purchasing.html"}, {"key": "recipe", "label": "Recipe & Food Cost", "href": "recipe_making.html"}, {"key": "menu", "label": "Menu & Categories", "href": "menu_management.html"}, {"key": "wastage", "label": "Wastage / Adjustment", "href": "wastage_adjustment.html"}, {"key": "transfer", "label": "Stock Transfer", "href": "stock_transfer.html"}, {"key": "count", "label": "Physical Stock Count", "href": "stock_count.html"}, {"key": "suppliers", "label": "Suppliers", "href": "suppliers.html"}, {"key": "customers", "label": "Customers", "href": "customers.html"}, {"key": "customer_app", "label": "Customer Mobile App", "href": "customer_mobile_app.html"}, {"key": "customer_web", "label": "Customer Web / QR", "href": "customer_web_qr.html"}, {"key": "delivery", "label": "Delivery", "href": "delivery.html"}, {"key": "riders", "label": "Rider Management", "href": "rider_management.html"}, {"key": "reservations", "label": "Reservations", "href": "reservations.html"}, {"key": "loyalty", "label": "Loyalty / Membership", "href": "loyalty.html"}, {"key": "whatsapp", "label": "WhatsApp / Notifications", "href": "whatsapp_notifications.html"}, {"key": "expenses", "label": "Expenses", "href": "expenses.html"}, {"key": "accounting", "label": "Accounting / Cash", "href": "accounting.html"}, {"key": "promotions", "label": "Discounts / Promotions", "href": "discounts_promotions.html"}, {"key": "staff", "label": "Staff / Roles", "href": "staff_roles.html"}, {"key": "void", "label": "Void / Refund", "href": "void_refund.html"}, {"key": "reports", "label": "Reports", "href": "reports.html"}, {"key": "fbr", "label": "FBR / Digital Invoice", "href": "fbr.html"}, {"key": "printers", "label": "Printers / Devices", "href": "printer_devices.html"}, {"key": "branches", "label": "Multi-Branch", "href": "multi_branch.html"}, {"key": "offline", "label": "Offline / Sync", "href": "offline_sync.html"}, {"key": "users", "label": "Users & Access", "href": "users_access.html"}, {"key": "settings", "label": "Settings", "href": "settings.html"}];
 let state=null;
 function get(){const r=DBApi.req('access-state');if(r.ok){state=r.state;return state}return state||{users:[],requests:[],roles:[]}}
 function roleDefaultsFromState(){const s=get(),o={};(s.roles||[]).forEach(r=>o[r.name]=r.modules||[]);return o}
 const api={MODULES,get,
  signup:data=>DBApi.req('signup',data),
  login:(email,password)=>DBApi.req('login',{email,password}),
  setup:data=>DBApi.req('setup',data),
  createUser:data=>DBApi.req('user-create',data),
  updateUser:(id,data)=>DBApi.req('user-update',{id,...data}),
  approveRequest:(id,data)=>DBApi.req('signup-approve',{id,...data}),
  rejectRequest:id=>DBApi.req('signup-reject',{id}),
  current:()=>{const r=DBApi.req('current-user');return r.ok?r.user:null},
  logout:()=>DBApi.req('logout',{})
 };
 Object.defineProperty(api,'roleDefaults',{get:roleDefaultsFromState});
 window.RestaurantAccess=api;
})();
/* build: V17.1 build 2026-08-25 */
