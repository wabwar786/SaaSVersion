
(function(){
  const KEY='urban_spoon_restaurant_store_v5';
  const defaults={
    inventoryCategories:['Meat & Poultry','Vegetables','Dairy','Dry Grocery','Beverages','Bakery','Packaging','Sauces & Spices'],
    inventoryItems:[
      {id:'chicken',name:'Boneless Chicken',category:'Meat & Poultry',stockUnit:'g',purchaseUnit:'KG',purchaseFactor:1000,stockQty:15800,avgStockCost:.72,reorderQty:8000,storage:'Cold Room',usage:'Recipe Ingredient'},
      {id:'rice',name:'Basmati Rice',category:'Dry Grocery',stockUnit:'g',purchaseUnit:'KG',purchaseFactor:1000,stockQty:42000,avgStockCost:.41,reorderQty:12000,storage:'Dry Store',usage:'Recipe Ingredient'},
      {id:'cheese',name:'Mozzarella Cheese',category:'Dairy',stockUnit:'g',purchaseUnit:'KG',purchaseFactor:1000,stockQty:2400,avgStockCost:1.89,reorderQty:4000,storage:'Cold Room',usage:'Recipe Ingredient'},
      {id:'tomato',name:'Tomato',category:'Vegetables',stockUnit:'g',purchaseUnit:'KG',purchaseFactor:1000,stockQty:18000,avgStockCost:.18,reorderQty:6000,storage:'Cold Room',usage:'Recipe Ingredient'},
      {id:'oil',name:'Cooking Oil',category:'Dry Grocery',stockUnit:'ml',purchaseUnit:'Litre',purchaseFactor:1000,stockQty:38000,avgStockCost:.515,reorderQty:10000,storage:'Dry Store',usage:'Recipe Ingredient'},
      {id:'mint',name:'Mint Leaves',category:'Vegetables',stockUnit:'g',purchaseUnit:'KG',purchaseFactor:1000,stockQty:3200,avgStockCost:.29,reorderQty:1000,storage:'Cold Room',usage:'Recipe Ingredient'},
      {id:'bun',name:'Burger Bun',category:'Bakery',stockUnit:'piece',purchaseUnit:'Piece',purchaseFactor:1,stockQty:86,avgStockCost:42,reorderQty:30,storage:'Dry Store',usage:'Recipe Ingredient'},
      {id:'spice',name:'Biryani Spice Mix',category:'Sauces & Spices',stockUnit:'g',purchaseUnit:'KG',purchaseFactor:1000,stockQty:6500,avgStockCost:1.08,reorderQty:1500,storage:'Dry Store',usage:'Recipe Ingredient'},
      {id:'coke_tin',name:'Coke Tin 330ml',category:'Beverages',stockUnit:'piece',purchaseUnit:'Carton (24)',purchaseFactor:24,stockQty:120,avgStockCost:95,reorderQty:48,storage:'Bar Store',usage:'Both'},
      {id:'water',name:'Mineral Water 500ml',category:'Beverages',stockUnit:'piece',purchaseUnit:'Carton (24)',purchaseFactor:24,stockQty:144,avgStockCost:34,reorderQty:48,storage:'Bar Store',usage:'Direct Sale'}
    ],
    menuCategories:[
      {name:'Pakistani',printer:'main'},{name:'Pizza',printer:'pizza'},{name:'BBQ',printer:'bbq'},
      {name:'Fast Food',printer:'main'},{name:'Drinks',printer:'drinks'},{name:'Desserts',printer:'dessert'},{name:'Sides',printer:'main'}
    ],
    recipes:[
      {menuName:'Chicken Biryani',mode:'recipe',category:'Pakistani',yieldQty:1,ingredients:[{itemId:'chicken',qty:180},{itemId:'rice',qty:160},{itemId:'oil',qty:35},{itemId:'tomato',qty:80},{itemId:'spice',qty:10}]},
      {menuName:'Fajita Pizza',mode:'recipe',category:'Pizza',yieldQty:1,ingredients:[{itemId:'chicken',qty:120},{itemId:'cheese',qty:110},{itemId:'tomato',qty:70},{itemId:'oil',qty:18}]},
      {menuName:'Chicken Seekh Kabab',mode:'recipe',category:'BBQ',yieldQty:1,ingredients:[{itemId:'chicken',qty:220},{itemId:'spice',qty:12}]},
      {menuName:'Zinger Burger',mode:'recipe',category:'Fast Food',yieldQty:1,ingredients:[{itemId:'chicken',qty:150},{itemId:'bun',qty:1},{itemId:'oil',qty:30}]},
      {menuName:'Chicken Karahi',mode:'recipe',category:'Pakistani',yieldQty:1,ingredients:[{itemId:'chicken',qty:500},{itemId:'tomato',qty:250},{itemId:'oil',qty:60},{itemId:'spice',qty:15}]},
      {menuName:'Mint Margarita',mode:'recipe',category:'Drinks',yieldQty:1,ingredients:[{itemId:'mint',qty:12}]},
      {menuName:'Coke Tin',mode:'direct',category:'Drinks',inventoryItemId:'coke_tin',directQty:1},
      {menuName:'Mineral Water',mode:'direct',category:'Drinks',inventoryItemId:'water',directQty:1}
    ],
    purchaseOrders:[
      {id:'PO-1041',supplier:'Islamabad Poultry',date:'24 Aug 2026',amount:68400,status:'Received',lines:3},
      {id:'PO-1040',supplier:'Metro Wholesale',date:'24 Aug 2026',amount:31290,status:'Received',lines:5},
      {id:'PO-1039',supplier:'Fresh Foods Traders',date:'24 Aug 2026',amount:48750,status:'Pending Receive',lines:3}
    ]
  };
  const clone=o=>JSON.parse(JSON.stringify(o));
  const uid=(p='id')=>p+'-'+Date.now()+'-'+Math.floor(Math.random()*100000);
  function saveState(s){localStorage.setItem(KEY,JSON.stringify(s));return s}
  function getState(){try{const v=localStorage.getItem(KEY);if(v)return JSON.parse(v)}catch(e){}return saveState(clone(defaults))}
  function formatStock(i){const q=Number(i.stockQty||0);if(i.stockUnit==='g'&&Math.abs(q)>=1000)return (q/1000).toFixed(q%1000===0?0:2)+' KG';if(i.stockUnit==='ml'&&Math.abs(q)>=1000)return (q/1000).toFixed(q%1000===0?0:2)+' L';if(i.stockUnit==='piece')return q.toFixed(Number.isInteger(q)?0:2)+' pcs';return q.toFixed(2)+' '+i.stockUnit}
  function formatPurchaseCost(i){const n=Number(i.avgStockCost||0)*Number(i.purchaseFactor||1);return 'PKR '+n.toLocaleString('en-PK',{maximumFractionDigits:2})+' / '+i.purchaseUnit}
  function addInventoryCategory(name){const s=getState();name=(name||'').trim();if(!name)return {ok:false,message:'Category name required'};if(s.inventoryCategories.some(x=>x.toLowerCase()===name.toLowerCase()))return {ok:false,message:'Category already exists'};s.inventoryCategories.push(name);saveState(s);return {ok:true,category:name,state:s}}
  function addInventoryItem(data){const s=getState();const item={id:uid('inv'),name:data.name,category:data.category,stockUnit:data.stockUnit,purchaseUnit:data.purchaseUnit,purchaseFactor:Number(data.purchaseFactor||1),stockQty:Number(data.stockQty||0),avgStockCost:Number(data.avgStockCost||0),reorderQty:Number(data.reorderQty||0),storage:data.storage||'Dry Store',usage:data.usage||'Recipe Ingredient',batch:!!data.batch,expiry:!!data.expiry};s.inventoryItems.push(item);saveState(s);return {ok:true,item,state:s}}
  function receivePurchase(lines,meta={}){const s=getState();let amount=0;const movements=[];(lines||[]).forEach(l=>{const i=s.inventoryItems.find(x=>x.id===l.itemId);if(!i)return;const pqty=Number(l.purchaseQty||0),unitCost=Number(l.unitCost||0);if(pqty<=0)return;const add=pqty*Number(i.purchaseFactor||1),old=Number(i.stockQty||0),oldVal=old*Number(i.avgStockCost||0),newPer=unitCost/Number(i.purchaseFactor||1);i.stockQty=old+add;i.avgStockCost=i.stockQty?(oldVal+add*newPer)/i.stockQty:newPer;amount+=pqty*unitCost;movements.push({itemName:i.name,qty:add,unit:i.stockUnit})});s.purchaseOrders.unshift({id:meta.reference||('PO-'+String(Date.now()).slice(-5)),supplier:meta.supplier||'Supplier',date:new Date().toLocaleDateString('en-GB'),amount,status:'Received',lines:(lines||[]).length});saveState(s);return {ok:true,amount,movements,state:s}}
  function addRecipe(r){const s=getState(),idx=s.recipes.findIndex(x=>x.menuName.toLowerCase()===r.menuName.toLowerCase());if(idx>=0)s.recipes[idx]=r;else s.recipes.push(r);saveState(s);return {ok:true,recipe:r,state:s}}
  function findRecipe(name){const s=getState(),n=(name||'').toLowerCase();return s.recipes.slice().sort((a,b)=>b.menuName.length-a.menuName.length).find(r=>n===r.menuName.toLowerCase()||n.startsWith(r.menuName.toLowerCase()+' '))}
  function consumeCart(cart,reference='Sale'){const s=getState(),need={},movements=[],shortages=[];(cart||[]).forEach(line=>{const rec=findRecipe(line.name),qty=Number(line.qty||0);if(!rec||qty<=0)return;if(rec.mode==='direct')need[rec.inventoryItemId]=(need[rec.inventoryItemId]||0)+Number(rec.directQty||1)*qty;else(rec.ingredients||[]).forEach(ing=>need[ing.itemId]=(need[ing.itemId]||0)+Number(ing.qty||0)*qty/Number(rec.yieldQty||1))});Object.entries(need).forEach(([id,qty])=>{const i=s.inventoryItems.find(x=>x.id===id);if(!i)return;if(Number(i.stockQty)<qty)shortages.push({itemName:i.name,required:qty,available:i.stockQty,unit:i.stockUnit});i.stockQty=Number(i.stockQty)-qty;movements.push({itemName:i.name,qty:-qty,unit:i.stockUnit,reference})});saveState(s);return {ok:true,movements,shortages,state:s}}
  function categoryPrinter(cat){const s=getState();return s.menuCategories.find(x=>x.name===cat)?.printer||'main'}
  window.RestaurantStore={KEY,defaults,getState,saveState,formatStock,formatPurchaseCost,addInventoryCategory,addInventoryItem,receivePurchase,addRecipe,consumeCart,categoryPrinter};
})();
