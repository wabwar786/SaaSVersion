
(function(){
  try{
    const VERSION='restaurant-ui-v14-startup-safe';
    if(localStorage.getItem('restaurant_ui_runtime_version')!==VERSION){
      localStorage.removeItem('urban_spoon_restaurant_store_v5');
      localStorage.removeItem('urban_spoon_live_v5');
      localStorage.removeItem('restaurant_item_delete_logs');
      localStorage.removeItem('urban_spoon_v13_generic_rows');
      localStorage.setItem('restaurant_ui_runtime_version',VERSION);
    }
  }catch(e){}
})();

/* build: V17.1 build 2026-08-25 */
