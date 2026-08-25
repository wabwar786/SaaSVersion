<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
use Aio\Auth;
if(!Auth::user()){header('Location: /login.html?build=v13');exit;}
?><!doctype html><html><head><meta charset="utf-8"><title>Reset Demo UI</title></head>
<body><script>
try{
 localStorage.removeItem('urban_spoon_restaurant_store_v5');
 localStorage.removeItem('urban_spoon_live_v5');
 localStorage.removeItem('restaurant_item_delete_logs');
 localStorage.removeItem('urban_spoon_v13_generic_rows');
 localStorage.setItem('restaurant_ui_runtime_version','restaurant-ui-v13-workable-reset');
}catch(e){}
location.replace('/index.html?build=v13&reset=1');
</script></body></html>

// build: V17.1 build 2026-08-25
