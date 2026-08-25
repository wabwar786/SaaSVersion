
(function(){
 const KEY='urban_spoon_live_v5';
 const defaults={netSales:282930,grossSales:297790,orders:184,runningOrders:24,preparing:11,ready:6,delayed:3,onlineNew:8,deliveryReady:5,ridersRoad:5,occupiedTables:18,totalTables:30,cash:82340,card:66820,raast:38240,walletBank:95530,dineIn:158420,takeaway:61870,delivery:62640,online:48520,expenses:18420,foodCost:89340,shift:'S-2048',openingCash:25000,best:[{name:'Chicken Biryani',qty:46,sales:34500,cost:31.8},{name:'Fajita Pizza',qty:31,sales:47120,cost:28.2},{name:'Mint Margarita',qty:29,sales:8120,cost:27.9},{name:'Zinger Burger',qty:27,sales:15120,cost:35.9}],hourly:[23000,31000,41500,53800,45200,62400,68300,50600,36700],updatedAt:new Date().toISOString()};
 const clone=o=>JSON.parse(JSON.stringify(o));
 function save(s){s.updatedAt=new Date().toISOString();localStorage.setItem(KEY,JSON.stringify(s));return s}
 function get(){try{const v=localStorage.getItem(KEY);if(v)return JSON.parse(v)}catch(e){}return save(clone(defaults))}
 function postSale(amount,mode,payment){const s=get(),a=Number(amount||0);s.netSales+=a;s.grossSales+=a;s.orders++;if(mode==='Dine In')s.dineIn+=a;else if(mode==='Takeaway')s.takeaway+=a;else{s.delivery+=a;s.online+=a}if(payment==='Cash')s.cash+=a;else if(payment==='Card')s.card+=a;else if(payment==='Raast')s.raast+=a;else s.walletBank+=a;return save(s)}
 function postOnlineOrder(amount){const s=get();s.onlineNew++;s.runningOrders++;s.online+=Number(amount||0);return save(s)}
 function recordPurchase(){return save(get())}
 function reset(){return save(clone(defaults))}
 window.RestaurantLive={KEY,get,save,postSale,postOnlineOrder,recordPurchase,reset};
})();

/* build: V17.1 build 2026-08-25 */
