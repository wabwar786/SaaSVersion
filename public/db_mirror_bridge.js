
(function(){
  function safeReq(action,payload){
    try{
      if(!window.DBApi)return {ok:false,message:'DB API unavailable'};
      return DBApi.req(action,payload);
    }catch(e){
      console.warn('DB mirror:',action,e);
      return {ok:false,message:e.message||'DB mirror failed'};
    }
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

    if(original.saveState){
      RestaurantStore.saveState=function(state){
        const local=original.saveState(state);
        safeReq('store-save-state',state);
        return local;
      };
    }

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
