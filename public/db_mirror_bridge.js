
(function(){
  var __alerted={};
  function safeReq(action,payload){
    var r;
    try{
      if(!window.DBApi)r={ok:false,message:'DB API unavailable'};
      else r=DBApi.req(action,payload);
    }catch(e){
      console.warn('DB mirror:',action,e);
      r={ok:false,message:e.message||'DB mirror failed'};
    }
    if(r&&!r.ok){
      // LOUD failure: pehle sirf console.warn tha — user ko lagta tha save ho
      // gaya, agla page-load DB se hydrate hokar data "gayab" kar deta tha.
      console.warn('DB save failed',action,r.message);
      try{
        if(typeof toast==='function')toast('⚠ Database save failed: '+(r.message||action));
        else if(!__alerted[action]){__alerted[action]=1;alert('Database save failed: '+(r.message||action));}
      }catch(x){}
    }
    return r;
  }

  function installStoreMirror(){
    if(!window.RestaurantStore || RestaurantStore.__dbMirrorInstalled)return;
    RestaurantStore.__dbMirrorInstalled=true;

    const original={
      saveState:RestaurantStore.saveState?.bind(RestaurantStore),
      addInventoryCategory:RestaurantStore.addInventoryCategory?.bind(RestaurantStore),
      addInventoryItem:RestaurantStore.addInventoryItem?.bind(RestaurantStore),
      receivePurchase:RestaurantStore.receivePurchase?.bind(RestaurantStore),
      addRecipe:RestaurantStore.addRecipe?.bind(RestaurantStore),
      consumeCart:RestaurantStore.consumeCart?.bind(RestaurantStore),
      getState:RestaurantStore.getState?.bind(RestaurantStore)
    };

    /* DB-first ke baad whole-state mirror hata diya gaya hai:
       stale browser cache DB ko overwrite kar sakta tha. Writes ab
       sirf explicit actions (inventory/purchase/recipe/pos) se hote hain. */

    if(original.addInventoryCategory){
      RestaurantStore.addInventoryCategory=function(name){
        const local=original.addInventoryCategory(name);
        if(local?.ok)safeReq('inventory-category-create',{name});
        return local;
      };
    }

    if(original.addInventoryItem){
      RestaurantStore.addInventoryItem=function(data){
        const local=original.addInventoryItem(data);
        if(local?.ok)safeReq('inventory-item-create',data);
        return local;
      };
    }

    if(original.receivePurchase){
      RestaurantStore.receivePurchase=function(lines,meta={}){
        const before=original.getState?original.getState():null;
        const enriched=(lines||[]).map(l=>{
          const item=before?.inventoryItems?.find(x=>x.id===l.itemId);
          return {...l,itemName:item?.name||l.itemName||''};
        });
        const local=original.receivePurchase(lines,meta);
        const db=safeReq('purchase-receive',{lines:enriched,meta});
        if(!db.ok)console.warn('Purchase saved in UI; DB mirror:',db.message);
        return local;
      };
    }

    if(original.addRecipe){
      RestaurantStore.addRecipe=function(recipe){
        if(recipe && !recipe.id){
          recipe={...recipe,id:'local-recipe-'+Date.now()+'-'+Math.floor(Math.random()*9999)};
        }
        const state=original.getState?original.getState():null;
        const dbRecipe={...recipe};
        if(Array.isArray(recipe?.ingredients)){
          dbRecipe.ingredients=recipe.ingredients.map(x=>{
            const item=state?.inventoryItems?.find(i=>i.id===x.itemId);
            return {...x,itemName:item?.name||x.itemName||''};
          });
        }
        if(recipe?.inventoryItemId){
          const item=state?.inventoryItems?.find(i=>i.id===recipe.inventoryItemId);
          dbRecipe.inventoryItemName=item?.name||'';
        }
        const local=original.addRecipe(recipe);
        const db=safeReq('recipe-save',dbRecipe);
        if(!db.ok)console.warn('Recipe saved in UI; DB mirror:',db.message);
        return local;
      };
    }

    if(original.consumeCart){
      RestaurantStore.consumeCart=function(cart,reference='Sale'){
        const local=original.consumeCart(cart,reference);
        // Customer App orders are mirrored as pending online orders.
        if(String(reference).toLowerCase().includes('customer app')){
          safeReq('customer-order',{cart,reference});
        }
        return local;
      };
    }
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',installStoreMirror);
  }else{
    installStoreMirror();
  }
})();

/* build: V17.1 build 2026-08-25 */
