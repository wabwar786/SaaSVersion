<?php
namespace Aio\Services;
use Aio\DB;
use PDO;
final class RecipeService {
    public static function save(array $d,array $ingredients): string {
        return DB::tx(function(PDO $pdo) use($d,$ingredients){
            $menuId=$d['menu_item_id']??'';
            if(!$menuId){$menuId=uuid();$pdo->prepare("INSERT INTO menu_items(id,tenant_id,site_id,category_id,code,name,description,item_type,consumption_type,direct_inventory_item_id,direct_inventory_qty,base_price,is_active,is_online,is_pos) VALUES(?,?,?,?,?,?,?,'STANDARD',?,?,?,?,1,1,1)")
                ->execute([$menuId,tenant_id(),site_id(),$d['category_id'],$d['code']?:null,$d['name'],$d['description']?:null,$d['consumption_type'],$d['direct_inventory_item_id']?:null,$d['direct_inventory_qty']?:null,$d['base_price']]);}
            if($d['consumption_type']==='RECIPE'){
                $pdo->prepare('UPDATE recipes SET is_current=0,effective_to=NOW(6) WHERE menu_item_id=? AND is_current=1')->execute([$menuId]);
                $v=(int)$pdo->query("SELECT COALESCE(MAX(version_no),0)+1 FROM recipes WHERE menu_item_id=".$pdo->quote($menuId))->fetchColumn();$rid=uuid();
                $pdo->prepare('INSERT INTO recipes(id,tenant_id,site_id,menu_item_id,version_no,yield_qty,is_current,created_by_user_id) VALUES(?,?,?,?,?,?,1,?)')->execute([$rid,tenant_id(),site_id(),$menuId,$v,$d['yield_qty'],current_user()['id']??null]);
                $cost=0;foreach($ingredients as $i){$q=$pdo->prepare('SELECT avg_cost_per_stock_unit FROM inventory_items WHERE id=?');$q->execute([$i['item_id']]);$uc=(float)$q->fetchColumn();$lc=$uc*(float)$i['qty'];$cost+=$lc;$pdo->prepare('INSERT INTO recipe_ingredients(id,recipe_id,inventory_item_id,qty_per_yield,waste_pct,line_cost) VALUES(?,?,?,?,?,?)')->execute([uuid(),$rid,$i['item_id'],$i['qty'],$i['waste_pct']??0,$lc]);}
                $pdo->prepare('UPDATE recipes SET food_cost_amount=? WHERE id=?')->execute([$cost,$rid]);
            }
            return $menuId;
        });
    }
}
