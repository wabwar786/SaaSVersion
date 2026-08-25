
(function(){
 const KEY='urban_spoon_live_v5';
 const defaults={netSales:0,grossSales:0,orders:0,runningOrders:0,preparing:0,ready:0,delayed:0,onlineNew:0,deliveryReady:0,ridersRoad:0,occupiedTables:0,totalTables:0,cash:0,card:0,raast:0,walletBank:0,dineIn:0,takeaway:0,delivery:0,online:0,expenses:0,foodCost:0,shift:'—',openingCash:0,best:[],hourly:[0,0,0,0,0,0,0,0,0],updatedAt:new Date().toISOString()};
 const clone=o=>JSON.parse(JSON.stringify(o));
 function save(s){s.updatedAt=new Date().toISOString();localStorage.setItem(KEY,JSON.stringify(s));return s}
 function get(){try{const v=localStorage.getItem(KEY);if(v)return JSON.parse(v)}catch(e){}return save(clone(defaults))}
 function postSale(amount,mode,payment){const s=get(),a=Number(amount||0);s.netSales+=a;s.grossSales+=a;s.orders++;if(mode==='Dine In')s.dineIn+=a;else if(mode==='Takeaway')s.takeaway+=a;else{s.delivery+=a;s.online+=a}if(payment==='Cash')s.cash+=a;else if(payment==='Card')s.card+=a;else if(payment==='Raast')s.raast+=a;else s.walletBank+=a;return save(s)}
 function postOnlineOrder(amount){const s=get();s.onlineNew++;s.runningOrders++;s.online+=Number(amount||0);return save(s)}
 function recordPurchase(){return save(get())}
 function reset(){return save(clone(defaults))}
 window.RestaurantLive={KEY,get,save,postSale,postOnlineOrder,recordPurchase,reset};
})();
