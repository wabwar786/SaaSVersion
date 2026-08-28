/* ============================================================
   module_config.js — one config per data screen.
   Each drives KPIs, table columns and the add/edit form
   through the shared engine in module.js.
   ============================================================ */
window.MODULE_CONFIGS={

  suppliers:{
    key:'suppliers',title:'Suppliers',storeKey:'suppliers',recordName:'Supplier',addLabel:'+ New supplier',
    listTitle:'Supplier list',listSub:'Add, edit or remove suppliers — changes save on this device',
    searchPlaceholder:'Search name, contact or city',searchFields:['name','person','phone','city'],emptyIcon:'⌂',
    kpis:[
      {label:'Suppliers',calc:function(r){return r.length}},
      {label:'Open payables',tone:'warn',calc:function(r,M){return M.money(M.sum(r,'bal'))}},
      {label:'Purchases (MTD)',tone:'info',calc:function(r,M){return M.money(M.sum(r,'mtd'))}},
      {label:'Items supplied',tone:'ok',calc:function(r,M){return M.num(M.sum(r,'items'))}}
    ],
    columns:[
      {label:'Supplier',field:'name',sub:'cat'},{label:'Contact',field:'person',sub:'phone'},
      {label:'City',field:'city'},{label:'Items',field:'items',align:'num'},
      {label:'MTD',field:'mtd',format:'money',align:'num'},{label:'Outstanding',field:'bal',format:'money_or_clear',align:'num'},
      {label:'Status',field:'status',format:'tag',tags:{Active:'ok',Inactive:'neutral'}}
    ],
    fields:[
      {key:'name',label:'Supplier name',type:'text',required:true,full:true,placeholder:'Fresh Foods Traders'},
      {key:'person',label:'Contact person',type:'text'},{key:'phone',label:'Phone',type:'tel',required:true},
      {key:'city',label:'City',type:'text'},{key:'cat',label:'Category',type:'select',options:['General','Poultry & Meat','Vegetables & Fruit','Dairy','Dry Grocery','Beverages','Packaging']},
      {key:'items',label:'Items supplied',type:'number',default:0},{key:'mtd',label:'Purchases (MTD)',type:'money',default:0},
      {key:'bal',label:'Outstanding',type:'money',default:0},{key:'status',label:'Status',type:'select',options:['Active','Inactive'],default:'Active'}
    ],
    seed:[
      {name:'Fresh Foods Traders',person:'Bilal Ahmed',phone:'051-5551188',city:'Islamabad',cat:'Vegetables & Fruit',items:24,mtd:348700,bal:124500,status:'Active'},
      {name:'Islamabad Poultry',person:'Usman Tariq',phone:'0300-5552211',city:'Islamabad',cat:'Poultry & Meat',items:6,mtd:289400,bal:86200,status:'Active'},
      {name:'Metro Wholesale',person:'Sana Malik',phone:'051-4447733',city:'Rawalpindi',cat:'Dry Grocery',items:31,mtd:421600,bal:213700,status:'Active'},
      {name:'Murree Dairy',person:'Kamran Shah',phone:'0333-9081122',city:'Murree',cat:'Dairy',items:9,mtd:96400,bal:0,status:'Active'}
    ]
  },

  customers:{
    key:'customers',title:'Customers',storeKey:'customers',recordName:'Customer',addLabel:'+ New customer',
    listTitle:'Customer directory',listSub:'Walk-in, delivery and app customers',
    searchPlaceholder:'Search name, phone or area',searchFields:['name','phone','area'],emptyIcon:'☺',
    kpis:[
      {label:'Customers',calc:function(r){return r.length}},
      {label:'Total spend',tone:'ok',calc:function(r,M){return M.money(M.sum(r,'spent'))}},
      {label:'Loyalty points',tone:'info',calc:function(r,M){return M.num(M.sum(r,'points'))}},
      {label:'Repeat customers',tone:'warn',calc:function(r){return r.filter(function(x){return Number(x.orders)>=5}).length}}
    ],
    columns:[
      {label:'Customer',field:'name',sub:'phone'},{label:'Area',field:'area'},
      {label:'Orders',field:'orders',align:'num'},{label:'Total spent',field:'spent',format:'money',align:'num'},
      {label:'Points',field:'points',align:'num'},{label:'Tier',field:'tier',format:'tag',tags:{Gold:'warn',Silver:'neutral',Bronze:'info'}}
    ],
    fields:[
      {key:'name',label:'Full name',type:'text',required:true,full:true},{key:'phone',label:'Phone',type:'tel',required:true},
      {key:'area',label:'Area',type:'text'},{key:'orders',label:'Orders',type:'number',default:0},
      {key:'spent',label:'Total spent',type:'money',default:0},{key:'points',label:'Loyalty points',type:'number',default:0},
      {key:'tier',label:'Tier',type:'select',options:['Bronze','Silver','Gold'],default:'Bronze'}
    ],
    seed:[
      {name:'Ayesha Khan',phone:'0300-1234567',area:'F-10',orders:18,spent:42600,points:426,tier:'Gold'},
      {name:'Hamza Iqbal',phone:'0321-9988776',area:'G-11',orders:7,spent:15400,points:154,tier:'Silver'},
      {name:'Zara Ali',phone:'0333-4455667',area:'Bahria',orders:3,spent:5200,points:52,tier:'Bronze'}
    ]
  },

  staff:{
    key:'staff',title:'Staff / Roles',storeKey:'staff',recordName:'Staff member',addLabel:'+ Add staff',
    listTitle:'Staff',listSub:'Employees at this branch',
    searchPlaceholder:'Search name or code',searchFields:['name','code','role'],emptyIcon:'\u263A',
    kpis:[
      {label:'Staff',calc:function(r){return r.length}},
      {label:'Active',tone:'ok',calc:function(r){return r.filter(function(x){return x.status==='Active'}).length}},
      {label:'With login',tone:'info',calc:function(r){return r.filter(function(x){return x.login==='Yes'}).length}}
    ],
    columns:[
      {label:'Name',field:'name',sub:'role'},{label:'Code',field:'code'},
      {label:'Joined',field:'joined'},
      {label:'Has login',field:'login',format:'tag',tags:{Yes:'ok',No:'info'}},
      {label:'Status',field:'status',format:'tag',tags:{Active:'ok',Inactive:'danger',Left:'danger'}}
    ],
    fields:[
      {key:'name',label:'Full name',type:'text',required:true,full:true},
      {key:'code',label:'Employee code',type:'text',placeholder:'EMP-001'},
      {key:'role',label:'Job title',type:'text',placeholder:'Waiter'},
      {key:'joined',label:'Joining date',type:'date'},
      {key:'status',label:'Status',type:'select',options:['Active','Inactive','Left'],default:'Active'}
    ],
    /* Login banane ka kaam Users & Access page karta hai — yahan sirf
       employee record. Do jagah login banane se roles ka nizam toot
       jata hai. */
    seed:[]
  },

  riders:{
    key:'riders',title:'Rider Management',storeKey:'riders',recordName:'Rider',addLabel:'+ Add rider',
    listTitle:'Delivery riders',listSub:'Riders, live jobs and cash held',
    searchPlaceholder:'Search rider or phone',searchFields:['name','phone','vehicle'],emptyIcon:'\u26FD',
    kpis:[
      {label:'Riders',calc:function(r){return r.length}},
      {label:'Available',tone:'ok',calc:function(r){return r.filter(function(x){return x.status==='Available'}).length}},
      {label:'On delivery',tone:'info',calc:function(r){return r.reduce(function(a,x){return a+(+x.active||0)},0)}}
    ],
    columns:[
      {label:'Rider',field:'name',sub:'phone'},{label:'Vehicle',field:'vehicle'},
      {label:'Active jobs',field:'active',format:'number'},{label:'Delivered today',field:'today',format:'number'},
      {label:'Status',field:'status',format:'tag',tags:{Available:'ok',Busy:'info',Off:'danger'}}
    ],
    fields:[
      {key:'name',label:'Rider name',type:'text',required:true,full:true},
      {key:'phone',label:'Phone',type:'text'},
      {key:'vehicle',label:'Vehicle number',type:'text',placeholder:'ABC-123'},
      {key:'status',label:'Status',type:'select',options:['Available','Busy','Off'],default:'Available'}
    ],
    seed:[]
  },

  printers:{
    key:'printers',title:'Printers / Devices',storeKey:'printers',recordName:'Printer',addLabel:'+ Add printer',
    listTitle:'Printers & devices',listSub:'Receipt, KOT and label printers on the branch network',
    searchPlaceholder:'Search printer or location',searchFields:['name','location','ip'],emptyIcon:'\u2399',
    kpis:[
      {label:'Printers',calc:function(r){return r.length}},
      {label:'Active',tone:'ok',calc:function(r){return r.filter(function(x){return x.status==='Active'}).length}},
      {label:'Network',tone:'info',calc:function(r){return r.filter(function(x){return x.ip}).length}}
    ],
    columns:[
      {label:'Printer',field:'name',sub:'type'},{label:'Location',field:'location'},
      {label:'Address',field:'ip'},
      {label:'Status',field:'status',format:'tag',tags:{Active:'ok',Inactive:'danger'}}
    ],
    /* V66 — "Status" ka Online/Offline dropdown HATA diya gaya. Woh user
       ki apni raye thi, haqeeqat nahi: printer band pare hone par bhi
       "Online" likha rehta tha. Ab asli haalat "Check" button se aati
       hai (TCP connect), aur "Test print" se kaghaz nikalta hai. */
    /* V69 — form chhota kar diya. Pehle 10 fields the (connection type,
       Windows printer name, paper width, status...) jinme se aksar
       customer ko samajh hi nahi aate the aur ghalat bhar dete the.
       Ab sirf wo 5 jo waqai chahiyen; baqi ki mehfooz defaults server
       khud laga deta hai (port 9100, NETWORK, paper Settings se). */
    fields:[
      {key:'name',label:'Printer name',type:'text',required:true,full:true,placeholder:'Kitchen KOT 1'},
      {key:'type',label:'Type',type:'select',options:['Receipt Printer','KOT Printer','Bar Printer','Label Printer']},
      {key:'location',label:'Location',type:'text',placeholder:'Kitchen'},
      {key:'ip',label:'IP address',type:'text',required:true,placeholder:'192.168.1.20'},
      {key:'default',label:'Default receipt printer',type:'select',options:['No','Yes'],default:'No'}
    ],
    rowActions:[
      {label:'Check',action:'printer-check'},
      {label:'Test print',action:'printer-test'}
    ],
    seed:[]
  },

  branches:{
    key:'branches',title:'Multi-Branch',storeKey:'branches',recordName:'Branch',addLabel:'',
    listTitle:'Branches',listSub:'All branches of this business',
    searchPlaceholder:'Search branch',searchFields:['name','code'],emptyIcon:'\u2317',
    canAdd:false,
    kpis:[
      {label:'Branches',calc:function(r){return r.length}},
      {label:'Active',tone:'ok',calc:function(r){return r.filter(function(x){return x.status==='Active'}).length}},
      {label:'Sales today',tone:'info',calc:function(r){return r.reduce(function(a,x){return a+(+x.today||0)},0)}}
    ],
    columns:[
      {label:'Branch',field:'name',sub:'type'},{label:'Code',field:'code'},
      {label:'Phone',field:'phone'},{label:'Sales today',field:'today',format:'number'},
      {label:'Status',field:'status',format:'tag',tags:{Active:'ok',Suspended:'danger'}}
    ],
    fields:[],
    seed:[]
  },

  tables:{
    key:'tables',title:'Tables & Floors',storeKey:'tables',recordName:'Table',addLabel:'+ Add table',
    listTitle:'Tables',listSub:'Floor plan and live table status',
    searchPlaceholder:'Search table or floor',searchFields:['name','floor'],emptyIcon:'▦',
    kpis:[
      {label:'Tables',calc:function(r){return r.length}},
      {label:'Occupied',tone:'warn',calc:function(r){return r.filter(function(x){return x.status==='Occupied'}).length}},
      {label:'Free',tone:'ok',calc:function(r){return r.filter(function(x){return x.status==='Free'}).length}},
      {label:'Reserved',tone:'info',calc:function(r){return r.filter(function(x){return x.status==='Reserved'}).length}}
    ],
    columns:[
      {label:'Table',field:'name',sub:'floor'},{label:'Seats',field:'seats',align:'num'},{label:'Current bill',field:'bill',format:'money',align:'num'},
      {label:'Status',field:'status',format:'tag',tags:{Free:'ok',Occupied:'warn',Reserved:'info'}}
    ],
    fields:[
      {key:'name',label:'Table name / no.',type:'text',required:true,placeholder:'T-01'},{key:'floor',label:'Floor',type:'select',options:['Ground Floor','First Floor','Rooftop','Outdoor']},
      {key:'seats',label:'Seats',type:'number',default:4},{key:'bill',label:'Current bill',type:'money',default:0},
      {key:'status',label:'Status',type:'select',options:['Free','Occupied','Reserved'],default:'Free'}
    ],
    seed:[
      {name:'T-01',floor:'Ground Floor',seats:4,bill:0,status:'Free'},
      {name:'T-02',floor:'Ground Floor',seats:2,bill:2450,status:'Occupied'},
      {name:'T-05',floor:'First Floor',seats:6,bill:0,status:'Reserved'},
      {name:'R-01',floor:'Rooftop',seats:8,bill:7820,status:'Occupied'}
    ]
  },

  menu:{
    key:'menu',title:'Menu & Categories',storeKey:'menu',recordName:'Menu item',addLabel:'+ Add item',
    listTitle:'Menu items',listSub:'Selling price, food cost and availability',
    searchPlaceholder:'Search item or category',searchFields:['name','category'],emptyIcon:'☰',
    kpis:[
      {label:'Menu items',calc:function(r){return r.length}},
      {label:'Active',tone:'ok',calc:function(r){return r.filter(function(x){return x.status==='Active'}).length}},
      {label:'Avg. price',tone:'info',calc:function(r,M){return r.length?M.money(M.sum(r,'price')/r.length):'PKR 0'}}
    ],
    columns:[
      {label:'Item',field:'name',sub:'category'},{label:'Price',field:'price',format:'money',align:'num'},
      {label:'Food cost',field:'cost',format:'money',align:'num'},
      {label:'Margin',field:'name',align:'num',render:function(r){var m=r.price?Math.round((1-r.cost/r.price)*100):0;return '<b style="color:'+(m>=60?'var(--ok)':'var(--warn)')+'">'+m+'%</b>'}},
      {label:'Status',field:'status',format:'tag',tags:{Active:'ok',Inactive:'neutral'}}
    ],
    fields:[
      {key:'name',label:'Item name',type:'text',required:true,full:true},{key:'category',label:'Category',type:'select',options:['Pakistani','Pizza','BBQ','Fast Food','Drinks','Desserts','Sides']},
      {key:'price',label:'Selling price',type:'money',required:true},{key:'cost',label:'Food cost',type:'money',default:0},
      {key:'status',label:'Status',type:'select',options:['Active','Inactive'],default:'Active'}
    ],
    seed:[
      {name:'Chicken Biryani',category:'Pakistani',price:750,cost:238,status:'Active'},
      {name:'Fajita Pizza',category:'Pizza',price:1520,cost:428,status:'Active'},
      {name:'Chicken Seekh Kabab',category:'BBQ',price:560,cost:196,status:'Active'},
      {name:'Zinger Burger',category:'Fast Food',price:560,cost:201,status:'Active'},
      {name:'Mint Margarita',category:'Drinks',price:280,cost:78,status:'Active'}
    ]
  },

  reservations:{
    key:'reservations',title:'Reservations',storeKey:'reservations',recordName:'Reservation',addLabel:'+ New reservation',
    listTitle:'Table reservations',listSub:'Bookings from today onward',
    searchPlaceholder:'Search guest or phone',searchFields:['name','phone','ref'],emptyIcon:'\u25F7',
    kpis:[
      {label:'Upcoming',calc:function(r){return r.filter(function(x){return x.status==='Booked'}).length}},
      {label:'Seated',tone:'ok',calc:function(r){return r.filter(function(x){return x.status==='Seated'}).length}},
      {label:'Guests',tone:'info',calc:function(r){return r.reduce(function(a,x){return a+(+x.guests||0)},0)}}
    ],
    columns:[
      {label:'Guest',field:'name',sub:'phone'},{label:'Reference',field:'ref'},
      {label:'When',field:'when'},{label:'Guests',field:'guests',format:'number'},
      {label:'Status',field:'status',format:'tag',tags:{Booked:'info',Seated:'ok',Cancelled:'danger',No_show:'danger'}}
    ],
    fields:[
      {key:'name',label:'Guest name',type:'text',required:true,full:true},
      {key:'phone',label:'Phone',type:'text'},
      {key:'when',label:'Date & time',type:'datetime-local',required:true},
      {key:'guests',label:'Number of guests',type:'number',default:2},
      {key:'deposit',label:'Deposit taken',type:'number',default:0},
      {key:'status',label:'Status',type:'select',options:['Booked','Seated','Cancelled','No_show'],default:'Booked'},
      {key:'notes',label:'Notes',type:'textarea',full:true}
    ],
    seed:[]
  },

  expenses:{
    key:'expenses',title:'Expenses',storeKey:'expenses',recordName:'Expense',addLabel:'+ New expense',
    listTitle:'Expenses',listSub:'Daily running costs and petty cash',
    searchPlaceholder:'Search description or category',searchFields:['description','category','paidBy'],emptyIcon:'▼',
    kpis:[
      {label:'Total expenses',tone:'warn',calc:function(r,M){return M.money(M.sum(r,'amount'))}},
      {label:'Entries',calc:function(r){return r.length}},
      {label:'Pending',tone:'danger',calc:function(r,M){return M.money(M.sum(r.filter(function(x){return x.status==='Pending'}),'amount'))}}
    ],
    columns:[
      {label:'Date',field:'date'},{label:'Description',field:'description',sub:'category'},{label:'Paid by',field:'paidBy'},
      {label:'Amount',field:'amount',format:'money',align:'num'},
      {label:'Status',field:'status',format:'tag',tags:{Paid:'ok',Pending:'warn'}}
    ],
    fields:[
      {key:'date',label:'Date',type:'text',placeholder:'24 Aug 2026'},{key:'category',label:'Category',type:'select',options:['Utilities','Rent','Salaries','Repairs','Fuel','Marketing','Misc']},
      {key:'description',label:'Description',type:'text',required:true,full:true},{key:'paidBy',label:'Paid by',type:'text'},
      {key:'amount',label:'Amount',type:'money',required:true},{key:'status',label:'Status',type:'select',options:['Paid','Pending'],default:'Paid'}
    ],
    seed:[
      {date:'24 Aug 2026',category:'Utilities',description:'Electricity bill — August',paidBy:'Ali Raza',amount:64200,status:'Paid'},
      {date:'24 Aug 2026',category:'Fuel',description:'Generator diesel',paidBy:'Ali Raza',amount:8400,status:'Paid'},
      {date:'23 Aug 2026',category:'Repairs',description:'Fridge compressor',paidBy:'Manager',amount:15600,status:'Pending'}
    ]
  },

  promotions:{
    key:'promotions',title:'Discounts / Promotions',storeKey:'promotions',recordName:'Promotion',addLabel:'+ Add promotion',
    listTitle:'Promotions',listSub:'Discounts that the POS can apply',
    searchPlaceholder:'Search promotion or code',searchFields:['name','code'],emptyIcon:'\u2605',
    kpis:[
      {label:'Total',calc:function(r){return r.length}},
      {label:'Live now',tone:'ok',calc:function(r){return r.filter(function(x){return x.status==='Live'}).length}},
      {label:'Scheduled',tone:'info',calc:function(r){return r.filter(function(x){return x.status==='Scheduled'}).length}}
    ],
    columns:[
      {label:'Promotion',field:'name',sub:'type'},{label:'Code',field:'code'},
      {label:'Value',field:'value',format:'number'},{label:'From',field:'from'},{label:'To',field:'to'},
      {label:'Status',field:'status',format:'tag',tags:{Live:'ok',Scheduled:'info',Inactive:'danger'}}
    ],
    fields:[
      {key:'name',label:'Promotion name',type:'text',required:true,full:true,placeholder:'Weekend 10% off'},
      {key:'type',label:'Type',type:'select',options:['Percent off','Amount off','Buy one get one','Combo deal']},
      {key:'value',label:'Value',type:'number',placeholder:'10'},
      {key:'code',label:'Code (optional)',type:'text',placeholder:'WEEKEND10'},
      {key:'min',label:'Minimum bill',type:'number',default:0},
      {key:'from',label:'Starts',type:'date'},{key:'to',label:'Ends',type:'date'},
      {key:'status',label:'Status',type:'select',options:['Live','Inactive'],default:'Live'}
    ],
    seed:[]
  },

  loyalty:{
    key:'loyalty',title:'Loyalty / Membership',storeKey:'loyalty',recordName:'Member',addLabel:'',
    listTitle:'Customer loyalty',listSub:'Tiers and points from real spending',
    searchPlaceholder:'Search customer or phone',searchFields:['name','phone'],emptyIcon:'\u2661',
    canAdd:false,
    kpis:[
      {label:'Members',calc:function(r){return r.length}},
      {label:'Gold',tone:'ok',calc:function(r){return r.filter(function(x){return x.tier==='Gold'}).length}},
      {label:'Total spend',tone:'info',calc:function(r){return r.reduce(function(a,x){return a+(+x.spend||0)},0)}}
    ],
    columns:[
      {label:'Customer',field:'name',sub:'phone'},{label:'Visits',field:'visits',format:'number'},
      {label:'Spend',field:'spend',format:'number'},{label:'Points',field:'points',format:'number'},
      {label:'Last visit',field:'last'},
      {label:'Tier',field:'tier',format:'tag',tags:{Gold:'ok',Silver:'info',Bronze:'info'}}
    ],
    fields:[],
    seed:[]
  },

  whatsapp:{
    key:'whatsapp',title:'WhatsApp / Notifications',storeKey:'whatsapp',recordName:'Notification',addLabel:'+ New notification',
    listTitle:'Notification rules',listSub:'Automated WhatsApp and SMS messages',
    searchPlaceholder:'Search event or audience',searchFields:['event','audience'],emptyIcon:'✆',
    kpis:[
      {label:'Rules',calc:function(r){return r.length}},
      {label:'Active',tone:'ok',calc:function(r){return r.filter(function(x){return x.status==='Active'}).length}},
      {label:'Sent (MTD)',tone:'info',calc:function(r,M){return M.num(M.sum(r,'sent'))}}
    ],
    columns:[
      {label:'Trigger',field:'event',sub:'channel'},{label:'Audience',field:'audience'},{label:'Sent (MTD)',field:'sent',align:'num'},
      {label:'Status',field:'status',format:'tag',tags:{Active:'ok',Paused:'neutral'}}
    ],
    fields:[
      {key:'event',label:'Trigger event',type:'select',options:['Order confirmed','Out for delivery','Order delivered','Reservation reminder','Promotion blast','Feedback request']},
      {key:'channel',label:'Channel',type:'select',options:['WhatsApp','SMS','Both']},{key:'audience',label:'Audience',type:'text',default:'All customers'},
      {key:'sent',label:'Sent (MTD)',type:'number',default:0},{key:'status',label:'Status',type:'select',options:['Active','Paused'],default:'Active'}
    ],
    seed:[
      {event:'Order confirmed',channel:'WhatsApp',audience:'All customers',sent:1240,status:'Active'},
      {event:'Out for delivery',channel:'WhatsApp',audience:'Delivery orders',sent:860,status:'Active'},
      {event:'Promotion blast',channel:'Both',audience:'Loyalty members',sent:420,status:'Paused'}
    ]
  },

  delivery:{
    key:'delivery',title:'Delivery',storeKey:'delivery',recordName:'Delivery',addLabel:'+ New delivery',
    listTitle:'Delivery orders',listSub:'Live delivery queue and rider assignment',
    searchPlaceholder:'Search order, customer or area',searchFields:['orderId','customer','area','rider'],emptyIcon:'➤',
    kpis:[
      {label:'Deliveries',calc:function(r){return r.length}},
      {label:'On the way',tone:'info',calc:function(r){return r.filter(function(x){return x.status==='On the way'}).length}},
      {label:'Delivered',tone:'ok',calc:function(r){return r.filter(function(x){return x.status==='Delivered'}).length}},
      {label:'Value',tone:'warn',calc:function(r,M){return M.money(M.sum(r,'amount'))}}
    ],
    columns:[
      {label:'Order',field:'orderId',sub:'customer'},{label:'Area',field:'area'},{label:'Rider',field:'rider'},
      {label:'Amount',field:'amount',format:'money',align:'num'},
      {label:'Status',field:'status',format:'tag',tags:{Assigned:'warn','On the way':'info',Delivered:'ok',Cancelled:'danger'}}
    ],
    fields:[
      {key:'orderId',label:'Order #',type:'text',required:true,placeholder:'D-2048'},{key:'customer',label:'Customer',type:'text',required:true},
      {key:'area',label:'Area',type:'text'},{key:'rider',label:'Rider',type:'text'},{key:'amount',label:'Amount',type:'money',default:0},
      {key:'status',label:'Status',type:'select',options:['Assigned','On the way','Delivered','Cancelled'],default:'Assigned'}
    ],
    seed:[
      {orderId:'D-2051',customer:'Ayesha Khan',area:'F-10',rider:'Waqas Ahmed',amount:1840,status:'On the way'},
      {orderId:'D-2050',customer:'Hamza Iqbal',area:'G-11',rider:'Bilal Hussain',amount:2760,status:'On the way'},
      {orderId:'D-2049',customer:'Zara Ali',area:'Bahria',rider:'Naveed Khan',amount:980,status:'Delivered'}
    ]
  },

  orders:{
    key:'orders',title:'Running Orders',storeKey:'orders',recordName:'Order',addLabel:'+ New order',
    listTitle:'Running orders',listSub:'Open bills across dine-in, takeaway and delivery',
    searchPlaceholder:'Search order or table',searchFields:['orderId','ref','type'],emptyIcon:'≣',
    kpis:[
      {label:'Open orders',calc:function(r){return r.length}},
      {label:'Preparing',tone:'warn',calc:function(r){return r.filter(function(x){return x.status==='Preparing'}).length}},
      {label:'Ready',tone:'ok',calc:function(r){return r.filter(function(x){return x.status==='Ready'}).length}},
      {label:'Open value',tone:'info',calc:function(r,M){return M.money(M.sum(r,'amount'))}}
    ],
    columns:[
      {label:'Order',field:'orderId',sub:'ref'},{label:'Type',field:'type'},{label:'Items',field:'items',align:'num'},
      {label:'Amount',field:'amount',format:'money',align:'num'},
      {label:'Status',field:'status',format:'tag',tags:{Preparing:'warn',Ready:'ok',Served:'info'}}
    ],
    fields:[
      {key:'orderId',label:'Order #',type:'text',required:true,placeholder:'O-2048'},
      {key:'type',label:'Type',type:'select',options:['Dine In','Takeaway','Delivery','Online']},{key:'ref',label:'Table / ref',type:'text'},
      {key:'items',label:'Items',type:'number',default:1},{key:'amount',label:'Amount',type:'money',default:0},
      {key:'status',label:'Status',type:'select',options:['Preparing','Ready','Served'],default:'Preparing'}
    ],
    seed:[
      {orderId:'O-2048',type:'Dine In',ref:'T-02',items:5,amount:2450,status:'Preparing'},
      {orderId:'O-2047',type:'Takeaway',ref:'Counter',items:2,amount:1120,status:'Ready'},
      {orderId:'O-2046',type:'Delivery',ref:'D-2051',items:4,amount:1840,status:'Preparing'}
    ]
  },

  online:{
    key:'online',title:'Online Orders',storeKey:'online',recordName:'Online order',addLabel:'+ Add order',
    listTitle:'Online orders',listSub:'Incoming orders from app, web and aggregators',
    searchPlaceholder:'Search order or customer',searchFields:['orderId','customer','platform'],emptyIcon:'◈',
    kpis:[
      {label:'Online orders',calc:function(r){return r.length}},
      {label:'New — accept',tone:'warn',calc:function(r){return r.filter(function(x){return x.status==='New'}).length}},
      {label:'Value',tone:'info',calc:function(r,M){return M.money(M.sum(r,'amount'))}}
    ],
    columns:[
      {label:'Order',field:'orderId',sub:'customer'},{label:'Platform',field:'platform'},{label:'Amount',field:'amount',format:'money',align:'num'},
      {label:'Status',field:'status',format:'tag',tags:{New:'warn',Accepted:'ok',Rejected:'danger'}}
    ],
    fields:[
      {key:'orderId',label:'Order #',type:'text',required:true,placeholder:'ON-3092'},{key:'customer',label:'Customer',type:'text',required:true},
      {key:'platform',label:'Platform',type:'select',options:['Own App','Own Web / QR','Foodpanda','Cheetay','Call']},
      {key:'amount',label:'Amount',type:'money',default:0},{key:'status',label:'Status',type:'select',options:['New','Accepted','Rejected'],default:'New'}
    ],
    seed:[
      {orderId:'ON-3092',customer:'Ayesha Khan',platform:'Own App',amount:1840,status:'New'},
      {orderId:'ON-3091',customer:'Bilal Q',platform:'Foodpanda',amount:2260,status:'New'},
      {orderId:'ON-3090',customer:'Sara M',platform:'Own Web / QR',amount:760,status:'Accepted'}
    ]
  },

  wastage:{
    key:'wastage',title:'Wastage / Adjustment',storeKey:'wastage',recordName:'Wastage entry',addLabel:'+ New entry',
    listTitle:'Wastage & adjustments',listSub:'Spoilage, breakage and stock corrections',
    searchPlaceholder:'Search item or reason',searchFields:['item','reason'],emptyIcon:'⊘',
    kpis:[
      {label:'Entries',calc:function(r){return r.length}},
      {label:'Cost impact',tone:'danger',calc:function(r,M){return M.money(M.sum(r,'cost'))}}
    ],
    columns:[
      {label:'Date',field:'date'},{label:'Item',field:'item'},{label:'Qty',field:'qty'},{label:'Reason',field:'reason'},
      {label:'Cost',field:'cost',format:'money',align:'num'}
    ],
    fields:[
      {key:'date',label:'Date',type:'text',placeholder:'24 Aug 2026'},{key:'item',label:'Item',type:'text',required:true,full:true},
      {key:'qty',label:'Quantity',type:'text',placeholder:'2 KG'},{key:'reason',label:'Reason',type:'select',options:['Spoiled','Expired','Breakage','Over-portion','Staff meal','Count correction']},
      {key:'cost',label:'Cost impact',type:'money',default:0}
    ],
    seed:[
      {date:'24 Aug 2026',item:'Tomato',qty:'3 KG',reason:'Spoiled',cost:540},
      {date:'24 Aug 2026',item:'Mozzarella Cheese',qty:'0.5 KG',reason:'Expired',cost:945},
      {date:'23 Aug 2026',item:'Coke Tin 330ml',qty:'4 pcs',reason:'Breakage',cost:380}
    ]
  },

  transfer:{
    key:'transfer',title:'Stock Transfer',storeKey:'transfer',recordName:'Transfer',addLabel:'+ New transfer',
    listTitle:'Stock transfers',listSub:'Movements between branches and stores',
    searchPlaceholder:'Search item or branch',searchFields:['item','from','to'],emptyIcon:'⇄',
    kpis:[
      {label:'Transfers',calc:function(r){return r.length}},
      {label:'In transit',tone:'warn',calc:function(r){return r.filter(function(x){return x.status==='In transit'}).length}},
      {label:'Received',tone:'ok',calc:function(r){return r.filter(function(x){return x.status==='Received'}).length}}
    ],
    columns:[
      {label:'Date',field:'date'},{label:'Item',field:'item',sub:'qty'},{label:'From',field:'from'},{label:'To',field:'to'},
      {label:'Status',field:'status',format:'tag',tags:{'In transit':'warn',Received:'ok'}}
    ],
    fields:[
      {key:'date',label:'Date',type:'text',placeholder:'24 Aug 2026'},{key:'item',label:'Item',type:'text',required:true,full:true},
      {key:'qty',label:'Quantity',type:'text',placeholder:'10 KG'},{key:'from',label:'From',type:'text',default:'Islamabad — F10'},
      {key:'to',label:'To',type:'text',default:'Rawalpindi — Saddar'},{key:'status',label:'Status',type:'select',options:['In transit','Received'],default:'In transit'}
    ],
    seed:[
      {date:'24 Aug 2026',item:'Basmati Rice',qty:'25 KG',from:'Islamabad — F10',to:'Rawalpindi — Saddar',status:'In transit'},
      {date:'23 Aug 2026',item:'Cooking Oil',qty:'20 L',from:'Islamabad — F10',to:'Bahria Town',status:'Received'}
    ]
  },

  count:{
    key:'count',title:'Physical Stock Count',storeKey:'count',recordName:'Count line',addLabel:'+ New count',
    listTitle:'Stock count sheet',listSub:'System vs counted quantity with variance',
    searchPlaceholder:'Search item',searchFields:['item'],emptyIcon:'▤',
    kpis:[
      {label:'Items counted',calc:function(r){return r.length}},
      {label:'With variance',tone:'warn',calc:function(r){return r.filter(function(x){return Number(x.variance)!==0}).length}}
    ],
    columns:[
      {label:'Item',field:'item'},{label:'System',field:'system',align:'num'},{label:'Counted',field:'counted',align:'num'},
      {label:'Variance',field:'variance',align:'num',render:function(r){var v=Number(r.counted)-Number(r.system);return '<b style="color:'+(v===0?'var(--ok)':'var(--danger)')+'">'+(v>0?'+':'')+v+'</b>'}},
      {label:'Status',field:'status',format:'tag',tags:{Matched:'ok',Variance:'warn'}}
    ],
    fields:[
      {key:'item',label:'Item',type:'text',required:true,full:true},{key:'system',label:'System qty',type:'number',default:0},
      {key:'counted',label:'Counted qty',type:'number',default:0},{key:'status',label:'Status',type:'select',options:['Matched','Variance'],default:'Matched'}
    ],
    onCreate:function(d){d.variance=Number(d.counted)-Number(d.system);d.status=d.variance===0?'Matched':'Variance'},
    seed:[
      {item:'Boneless Chicken',system:15800,counted:15800,variance:0,status:'Matched'},
      {item:'Basmati Rice',system:42000,counted:41200,variance:-800,status:'Variance'},
      {item:'Mozzarella Cheese',system:2400,counted:2450,variance:50,status:'Variance'}
    ]
  },

  void:{
    key:'void',title:'Void / Refund',storeKey:'void',recordName:'Void',addLabel:'+ New void / refund',
    listTitle:'Voids & refunds',listSub:'Cancelled items and returned bills',
    searchPlaceholder:'Search order or reason',searchFields:['orderId','reason','approvedBy'],emptyIcon:'⊗',
    kpis:[
      {label:'Entries',calc:function(r){return r.length}},
      {label:'Refunded value',tone:'danger',calc:function(r,M){return M.money(M.sum(r,'amount'))}}
    ],
    columns:[
      {label:'Date',field:'date'},{label:'Order',field:'orderId'},{label:'Reason',field:'reason'},{label:'Approved by',field:'approvedBy'},
      {label:'Amount',field:'amount',format:'money',align:'num'},
      {label:'Type',field:'type',format:'tag',tags:{Void:'warn',Refund:'danger'}}
    ],
    fields:[
      {key:'date',label:'Date',type:'text',placeholder:'24 Aug 2026'},{key:'orderId',label:'Order #',type:'text',required:true},
      {key:'type',label:'Type',type:'select',options:['Void','Refund']},{key:'reason',label:'Reason',type:'select',options:['Wrong order','Customer left','Kitchen delay','Quality issue','Duplicate','Other']},
      {key:'approvedBy',label:'Approved by',type:'text',default:'Manager'},{key:'amount',label:'Amount',type:'money',default:0}
    ],
    seed:[
      {date:'24 Aug 2026',orderId:'O-2033',type:'Void',reason:'Wrong order',approvedBy:'Manager',amount:750},
      {date:'23 Aug 2026',orderId:'O-2012',type:'Refund',reason:'Quality issue',approvedBy:'Owner',amount:1520}
    ]
  },

  accounting:{
    key:'accounting',title:'Accounting / Cash',storeKey:'accounting',recordName:'Ledger entry',addLabel:'+ New entry',
    listTitle:'Cash ledger',listSub:'Simple debit / credit book for the branch',
    searchPlaceholder:'Search account or reference',searchFields:['account','ref'],emptyIcon:'§',
    kpis:[
      {label:'Credits',tone:'ok',calc:function(r,M){return M.money(M.sum(r.filter(function(x){return x.type==='Credit'}),'amount'))}},
      {label:'Debits',tone:'danger',calc:function(r,M){return M.money(M.sum(r.filter(function(x){return x.type==='Debit'}),'amount'))}},
      {label:'Balance',tone:'info',calc:function(r,M){return M.money(M.sum(r.filter(function(x){return x.type==='Credit'}),'amount')-M.sum(r.filter(function(x){return x.type==='Debit'}),'amount'))}}
    ],
    columns:[
      {label:'Date',field:'date'},{label:'Account',field:'account',sub:'ref'},
      {label:'Type',field:'type',format:'tag',tags:{Credit:'ok',Debit:'danger'}},
      {label:'Amount',field:'amount',format:'money',align:'num'}
    ],
    fields:[
      {key:'date',label:'Date',type:'text',placeholder:'24 Aug 2026'},{key:'account',label:'Account',type:'text',required:true,full:true},
      {key:'type',label:'Type',type:'select',options:['Credit','Debit']},{key:'ref',label:'Reference',type:'text'},
      {key:'amount',label:'Amount',type:'money',required:true}
    ],
    seed:[
      {date:'24 Aug 2026',account:'Cash sales',ref:'Shift S-2048',type:'Credit',amount:282930},
      {date:'24 Aug 2026',account:'Supplier payment — Metro',ref:'PO-1040',type:'Debit',amount:31290},
      {date:'24 Aug 2026',account:'Electricity bill',ref:'EXP-081',type:'Debit',amount:64200}
    ]
  },

  fbr:{
    key:'fbr',title:'FBR / Digital Invoice',storeKey:'fbr',recordName:'Invoice',addLabel:'+ New invoice',
    listTitle:'FBR digital invoices',listSub:'Point-of-sale invoices posted to FBR',
    searchPlaceholder:'Search invoice or FBR ref',searchFields:['invoiceNo','fbrRef'],emptyIcon:'✓',
    kpis:[
      {label:'Invoices',calc:function(r){return r.length}},
      {label:'Posted',tone:'ok',calc:function(r){return r.filter(function(x){return x.status==='Posted'}).length}},
      {label:'Tax collected',tone:'info',calc:function(r,M){return M.money(M.sum(r,'tax'))}}
    ],
    columns:[
      {label:'Invoice',field:'invoiceNo',sub:'date'},{label:'Amount',field:'amount',format:'money',align:'num'},
      {label:'Tax',field:'tax',format:'money',align:'num'},{label:'FBR ref',field:'fbrRef'},
      {label:'Status',field:'status',format:'tag',tags:{Posted:'ok',Pending:'warn',Failed:'danger'}}
    ],
    fields:[
      {key:'invoiceNo',label:'Invoice #',type:'text',required:true,placeholder:'INV-10482'},{key:'date',label:'Date',type:'text',placeholder:'24 Aug 2026'},
      {key:'amount',label:'Amount',type:'money',required:true},{key:'tax',label:'Tax',type:'money',default:0},
      {key:'fbrRef',label:'FBR reference',type:'text'},{key:'status',label:'Status',type:'select',options:['Posted','Pending','Failed'],default:'Posted'}
    ],
    seed:[
      {invoiceNo:'INV-10482',date:'24 Aug 2026',amount:2450,tax:392,fbrRef:'FBR-PS-88213',status:'Posted'},
      {invoiceNo:'INV-10481',date:'24 Aug 2026',amount:1120,tax:179,fbrRef:'FBR-PS-88212',status:'Posted'},
      {invoiceNo:'INV-10480',date:'24 Aug 2026',amount:7820,tax:1251,fbrRef:'—',status:'Pending'}
    ]
  },

  shift:{
    key:'shift',title:'Opening & Closing Shift',storeKey:'shift',recordName:'Shift',addLabel:'+ Open shift',
    listTitle:'Shift history',listSub:'Cashier tills, opening float and closing cash',
    searchPlaceholder:'Search shift or cashier',searchFields:['shiftId','cashier'],emptyIcon:'◔',
    kpis:[
      {label:'Shifts',calc:function(r){return r.length}},
      {label:'Open now',tone:'warn',calc:function(r){return r.filter(function(x){return x.status==='Open'}).length}},
      {label:'Cash collected',tone:'ok',calc:function(r,M){return M.money(M.sum(r,'expected'))}}
    ],
    columns:[
      {label:'Shift',field:'shiftId',sub:'cashier'},{label:'Opened',field:'openTime'},
      {label:'Opening cash',field:'opening',format:'money',align:'num'},{label:'Expected cash',field:'expected',format:'money',align:'num'},
      {label:'Status',field:'status',format:'tag',tags:{Open:'warn',Closed:'ok'}}
    ],
    fields:[
      {key:'shiftId',label:'Shift #',type:'text',required:true,placeholder:'S-2049'},{key:'cashier',label:'Cashier',type:'text',required:true},
      {key:'openTime',label:'Open time',type:'text',placeholder:'11:00 AM'},{key:'opening',label:'Opening cash',type:'money',default:25000},
      {key:'expected',label:'Expected cash',type:'money',default:0},{key:'status',label:'Status',type:'select',options:['Open','Closed'],default:'Open'}
    ],
    seed:[
      {shiftId:'S-2048',cashier:'Ali Raza',openTime:'11:00 AM',opening:25000,expected:82340,status:'Open'},
      {shiftId:'S-2047',cashier:'Ali Raza',openTime:'Yesterday 11:00 AM',opening:25000,expected:214600,status:'Closed'}
    ]
  }

};

/* build: V17.1 build 2026-08-25 */
