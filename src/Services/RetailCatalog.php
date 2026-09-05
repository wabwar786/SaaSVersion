<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * RetailCatalog — supermarket ka catalog: departments, categories,
 * brands, units (pack conversion ke saath), products aur barcodes.
 *
 * DO USOOL:
 *
 * 1. BARCODE LOOKUP EK QUERY HAI, LOOP NAHI.
 *    Counter par rush hoti hai. `rtl_product_barcodes` par unique index
 *    hai, is liye 50,000 SKU ke store mein bhi scan ek index hit hai.
 *
 * 2. STOCK HAMESHA BASE UNIT MEIN.
 *    Carton kharida jata hai, piece becha jata hai. Agar dono apni apni
 *    unit mein likhe jayen to stock kabhi milta hi nahi. Isi liye
 *    `rtl_product_uom.factor` har jagah base unit par le aata hai.
 */
final class RetailCatalog
{
    /* ================= lookups ================= */

    /**
     * Barcode / SKU / PLU / pack-barcode / scale-label — sab yahin se.
     *
     * @return array{product:array,qty:float,source:string,uom:?array}|null
     */
    public static function findByCode(string $code): ?array
    {
        $code = \trim($code);
        if ($code === '') return null;
        $p = DB::pdo();
        $tid = tenant_id();

        /* 1. item barcode */
        $q = $p->prepare(
            "SELECT pr.* FROM rtl_product_barcodes b
               JOIN rtl_products pr ON pr.id = b.product_id AND pr.deleted_at IS NULL
              WHERE b.tenant_id=? AND b.barcode=? AND b.deleted_at IS NULL LIMIT 1");
        $q->execute([$tid, $code]);
        if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            return ['product' => self::hydrate($row), 'qty' => 1.0, 'source' => 'barcode', 'uom' => null];
        }

        /* 2. pack barcode — carton scan par poora carton lagta hai */
        $q = $p->prepare(
            "SELECT u.*, pr.id pid FROM rtl_product_uom u
               JOIN rtl_products pr ON pr.id = u.product_id AND pr.deleted_at IS NULL
              WHERE u.tenant_id=? AND u.barcode=? AND u.deleted_at IS NULL LIMIT 1");
        $q->execute([$tid, $code]);
        if ($u = $q->fetch(PDO::FETCH_ASSOC)) {
            $prod = self::product((string)$u['pid']);
            if ($prod) {
                return ['product' => $prod, 'qty' => (float)$u['factor'], 'source' => 'pack', 'uom' => $u];
            }
        }

        /* 3. SKU ya PLU */
        $q = $p->prepare(
            "SELECT * FROM rtl_products
              WHERE tenant_id=? AND deleted_at IS NULL AND (sku=? OR plu_code=?) LIMIT 1");
        $q->execute([$tid, $code, $code]);
        if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            return ['product' => self::hydrate($row), 'qty' => 1.0,
                    'source' => ((string)$row['plu_code'] === $code ? 'plu' : 'sku'), 'uom' => null];
        }

        /* 4. taraazu ka label — weight barcode ke andar chhupa hota hai */
        $scale = RegionProfile::parseScaleBarcode($code);
        if ($scale) {
            $q = $p->prepare(
                "SELECT * FROM rtl_products
                  WHERE tenant_id=? AND deleted_at IS NULL AND is_scale_item=1 AND plu_code=? LIMIT 1");
            $q->execute([$tid, $scale['plu']]);
            if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                return ['product' => self::hydrate($row), 'qty' => (float)$scale['value'],
                        'source' => 'scale', 'uom' => null];
            }
        }
        return null;
    }

    /**
     * Bill save ke liye halka fetch — barcodes ki alag query NAHI.
     * 8-line bill par yeh 8 faltu queries bachata hai; POS ko barcode
     * ki zaroorat hai hi nahi, sirf rate aur tax chahiye.
     */
    public static function productLite(string $id): ?array
    {
        $q = DB::pdo()->prepare(
            "SELECT id,name,tax_rate,cost_price,retail_price,wholesale_price,stock_qty,base_unit_id,status
               FROM rtl_products WHERE id=? AND tenant_id=? AND deleted_at IS NULL");
        $q->execute([$id, tenant_id()]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        foreach (['tax_rate','cost_price','retail_price','wholesale_price','stock_qty'] as $n) $r[$n] = (float)$r[$n];
        return $r;
    }

    public static function product(string $id): ?array
    {
        $q = DB::pdo()->prepare("SELECT * FROM rtl_products WHERE id=? AND tenant_id=? AND deleted_at IS NULL");
        $q->execute([$id, tenant_id()]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? self::hydrate($r) : null;
    }

    /**
     * POS ke suggestion box ki search.
     *
     * PEHLE: ek hi query `LIKE '%q%'` + barcodes ka LEFT JOIN. 50,000
     * products par 906 ms — cashier har harf par ek second rukta tha.
     *
     * AB teen qadam, sasta se mehnga:
     *   1. barcode / SKU / PLU ka theek theek match  (hash index, ~0.3 ms)
     *   2. naam ka PREFIX — ix_rp_name (tenant_id, name) index range
     *   3. tab hi FULLTEXT (beech ke lafz), aur woh bhi jab natije kam hon
     *
     * Zyadatar scans qadam 1 par hi khatam ho jate hain.
     */
    public static function search(string $q, int $limit = 12): array
    {
        $q = \trim($q);
        if ($q === '') return [];
        $tid = tenant_id();
        $out = []; $seen = [];

        $push = function (array $rows) use (&$out, &$seen, $limit) {
            foreach ($rows as $r) {
                if (isset($seen[$r['id']])) continue;
                $seen[$r['id']] = true;
                $out[] = self::hydrate($r);
                if (\count($out) >= $limit) return true;
            }
            return \count($out) >= $limit;
        };

        /* 1. barcode / SKU / PLU — ek index hit */
        $hit = self::findByCode($q);
        if ($hit) { $seen[$hit['product']['id']] = true; $out[] = $hit['product']; }
        if (\count($out) >= $limit) return $out;

        /* 2. naam ka prefix — index range, poora scan nahi */
        $st = DB::pdo()->prepare(
            "SELECT * FROM rtl_products
              WHERE tenant_id=? AND deleted_at IS NULL AND status='Active' AND name LIKE ?
              ORDER BY name LIMIT " . (int)$limit);
        $st->execute([$tid, $q . '%']);
        if ($push($st->fetchAll(PDO::FETCH_ASSOC))) return $out;

        /* 3. beech ke lafz — FULLTEXT. Sirf tab jab upar se kaam na bana. */
        if (\strlen($q) >= 3) {
            try {
                $st = DB::pdo()->prepare(
                    "SELECT * FROM rtl_products
                      WHERE tenant_id=? AND deleted_at IS NULL AND status='Active'
                        AND MATCH(name) AGAINST (? IN BOOLEAN MODE)
                      LIMIT " . (int)$limit);
                $st->execute([$tid, $q . '*']);
                if ($push($st->fetchAll(PDO::FETCH_ASSOC))) return $out;
            } catch (\Throwable $e) {
                /* fulltext index na ho to purana tareeqa — sirf yahan,
                   aur sirf jab pehle do qadam khali rahe hon. */
                $st = DB::pdo()->prepare(
                    "SELECT * FROM rtl_products
                      WHERE tenant_id=? AND deleted_at IS NULL AND status='Active' AND name LIKE ?
                      ORDER BY name LIMIT " . (int)$limit);
                $st->execute([$tid, '%' . $q . '%']);
                $push($st->fetchAll(PDO::FETCH_ASSOC));
            }
        }
        return $out;
    }

    public static function products(): array
    {
        $q = DB::pdo()->prepare(
            "SELECT * FROM rtl_products WHERE tenant_id=? AND deleted_at IS NULL ORDER BY name");
        $q->execute([tenant_id()]);
        return \array_map([self::class, 'hydrate'], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Product row + uske barcodes (UI barcodes[] expect karta hai). */
    private static function hydrate(array $r): array
    {
        $b = DB::pdo()->prepare(
            "SELECT barcode FROM rtl_product_barcodes
             WHERE product_id=? AND tenant_id=? AND deleted_at IS NULL ORDER BY created_at");
        $b->execute([$r['id'], tenant_id()]);
        $r['barcodes'] = \array_column($b->fetchAll(PDO::FETCH_ASSOC), 'barcode');
        foreach (['tax_rate','cost_price','retail_price','wholesale_price','mrp',
                  'stock_qty','min_stock','max_stock'] as $n) {
            if (isset($r[$n])) $r[$n] = (float)$r[$n];
        }
        $r['is_scale_item'] = (int)$r['is_scale_item'];
        $r['track_batch']   = (int)$r['track_batch'];
        return $r;
    }

    /* ================= simple lists ================= */

    private const LISTS = [
        'departments' => 'rtl_departments',
        'categories'  => 'rtl_categories',
        'brands'      => 'rtl_brands',
        'batches'     => 'rtl_batches',
        'counters'    => 'rtl_counters',
    ];

    public static function handles(string $module): bool
    {
        return isset(self::LISTS[$module]) || $module === 'products' || $module === 'uom';
    }

    public static function listOf(string $module): array
    {
        if ($module === 'products') return self::products();
        if ($module === 'uom')      return self::units();
        $t = self::LISTS[$module] ?? null;
        if (!$t) return [];
        $order = \in_array($module, ['departments','categories'], true) ? 'sort_order, name'
               : ($module === 'batches' ? 'expiry_date' : 'name');
        $q = DB::pdo()->prepare("SELECT * FROM `$t` WHERE tenant_id=? AND deleted_at IS NULL ORDER BY $order");
        $q->execute([tenant_id()]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if ($module === 'batches') {
            foreach ($rows as &$r) { $r['qty'] = (float)$r['qty']; $r['cost_price'] = (float)$r['cost_price']; }
        }
        return $rows;
    }

    public static function units(): array
    {
        $q = DB::pdo()->prepare(
            "SELECT * FROM units WHERE (tenant_id=? OR tenant_id IS NULL) AND deleted_at IS NULL ORDER BY unit_type, code");
        $q->execute([tenant_id()]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 1 CTN24 = 24 PCS — base unit mein badalne ka factor. */
    public static function toBase(string $unitId, float $qty): float
    {
        $q = DB::pdo()->prepare("SELECT conversion_factor FROM units
                                  WHERE id=? AND (tenant_id=? OR tenant_id IS NULL) LIMIT 1");
        $q->execute([$unitId, tenant_id()]);
        $f = (float)($q->fetchColumn() ?: 1);
        return $qty * ($f > 0 ? $f : 1);
    }

    /* ================= writes ================= */

    public static function saveProduct(string $id, array $d): string
    {
        $p = DB::pdo();
        $tid = tenant_id();
        $cols = ['sku','name','department_id','category_id','brand_id','base_unit_id','tax_rate',
                 'cost_price','retail_price','wholesale_price','mrp','stock_qty','min_stock','max_stock',
                 'is_scale_item','plu_code','track_batch','shelf_life_days','status'];

        $vals = [];
        foreach ($cols as $c) $vals[$c] = $d[$c] ?? null;
        $vals['sku']    = \trim((string)($vals['sku'] ?: self::nextSku($d)));
        $vals['name']   = \trim((string)$vals['name']);
        $vals['status'] = $vals['status'] ?: 'Active';
        if ($vals['name'] === '') throw new \RuntimeException('Product name required');
        foreach (['department_id','category_id','brand_id','base_unit_id'] as $fk) {
            if ($vals[$fk] === '') $vals[$fk] = null;
        }
        foreach (['tax_rate','cost_price','retail_price','wholesale_price','mrp',
                  'stock_qty','min_stock','max_stock'] as $n) $vals[$n] = (float)($vals[$n] ?? 0);
        foreach (['is_scale_item','track_batch','shelf_life_days'] as $n) $vals[$n] = (int)($vals[$n] ?? 0);

        return DB::tx(function (PDO $pdo) use ($id, $vals, $cols, $d, $tid) {
            $exists = false;
            if ($id !== '') {
                $c = $pdo->prepare("SELECT COUNT(*) FROM rtl_products WHERE id=? AND tenant_id=?");
                $c->execute([$id, $tid]);
                $exists = (bool)$c->fetchColumn();
            }
            if ($exists) {
                $set = \implode(',', \array_map(fn($c) => "`$c`=?", $cols));
                $pdo->prepare("UPDATE rtl_products SET $set WHERE id=? AND tenant_id=?")
                    ->execute([...\array_values($vals), $id, $tid]);
            } else {
                $id = \uuid();   /* di gayi id is tenant ki nahi thi -> nayi banao */
                $ph = \implode(',', \array_fill(0, \count($cols), '?'));
                $cl = \implode(',', \array_map(fn($c) => "`$c`", $cols));
                $pdo->prepare("INSERT INTO rtl_products(id,tenant_id,site_id,$cl) VALUES(?,?,?,$ph)")
                    ->execute([$id, $tid, site_id(), ...\array_values($vals)]);
            }
            /* Barcode alag table mein — ek product ke kai barcode ho sakte
               hain (purana stock, imported pack). UI ek bhejta hai. */
            $bc = \trim((string)($d['barcode'] ?? ''));
            if ($bc !== '') {
                $c = $pdo->prepare("SELECT COUNT(*) FROM rtl_product_barcodes WHERE tenant_id=? AND barcode=? AND product_id<>?");
                $c->execute([$tid, $bc, $id]);
                if ((int)$c->fetchColumn() > 0) throw new \RuntimeException("Barcode $bc kisi aur product par lagi hai");
                $c = $pdo->prepare("SELECT COUNT(*) FROM rtl_product_barcodes WHERE tenant_id=? AND barcode=? AND product_id=?");
                $c->execute([$tid, $bc, $id]);
                if ((int)$c->fetchColumn() === 0) {
                    $pdo->prepare("INSERT INTO rtl_product_barcodes(id,tenant_id,product_id,barcode) VALUES(?,?,?,?)")
                        ->execute([\uuid(), $tid, $id, $bc]);
                }
            }
            return $id;
        });
    }

    private static function nextSku(array $d): string
    {
        $q = DB::pdo()->prepare("SELECT COUNT(*) FROM rtl_products WHERE tenant_id=?");
        $q->execute([tenant_id()]);
        return 'SKU-' . \str_pad((string)((int)$q->fetchColumn() + 1), 5, '0', STR_PAD_LEFT);
    }

    public static function saveList(string $module, string $id, array $d): string
    {
        if ($module === 'products') return self::saveProduct($id, $d);
        if ($module === 'uom')      return self::saveUnit($id, $d);
        $t = self::LISTS[$module] ?? null;
        if (!$t) throw new \RuntimeException("Unknown module $module");

        $fields = [
            'departments' => ['name','code','sort_order'],
            'categories'  => ['name','department_id','sort_order'],
            'brands'      => ['name'],
            'batches'     => ['product_id','batch_no','expiry_date','qty','cost_price','received_on'],
            'counters'    => ['name','device_name','printer','drawer','cashier','opening_cash','status'],
        ][$module];

        $vals = [];
        foreach ($fields as $f) {
            $v = $d[$f] ?? null;
            if ($v === '') $v = \in_array($f, ['sort_order','qty','cost_price','opening_cash'], true) ? 0 : null;
            $vals[$f] = $v;
        }
        $p = DB::pdo(); $tid = tenant_id();
        $c = $p->prepare("SELECT COUNT(*) FROM `$t` WHERE id=? AND tenant_id=?");
        $c->execute([$id, $tid]);
        if ($id !== '' && (int)$c->fetchColumn() > 0) {
            $set = \implode(',', \array_map(fn($f) => "`$f`=?", $fields));
            $p->prepare("UPDATE `$t` SET $set WHERE id=? AND tenant_id=?")
              ->execute([...\array_values($vals), $id, $tid]);
            return $id;
        }
        $id = \uuid();   /* wahi wajah: kisi aur tenant ki row par likhne ka mauqa na rahe */
        $cl = \implode(',', \array_map(fn($f) => "`$f`", $fields));
        $ph = \implode(',', \array_fill(0, \count($fields), '?'));
        $hasSite = \in_array($module, ['departments','categories','batches','counters'], true);
        if ($hasSite) {
            $p->prepare("INSERT INTO `$t`(id,tenant_id,site_id,$cl) VALUES(?,?,?,$ph)")
              ->execute([$id, $tid, site_id(), ...\array_values($vals)]);
        } else {
            $p->prepare("INSERT INTO `$t`(id,tenant_id,$cl) VALUES(?,?,$ph)")
              ->execute([$id, $tid, ...\array_values($vals)]);
        }
        return $id;
    }

    public static function saveUnit(string $id, array $d): string
    {
        $p = DB::pdo(); $tid = tenant_id();
        $f = ['code','name','unit_type','base_unit_id','conversion_factor','decimal_places'];
        $v = [];
        foreach ($f as $k) $v[$k] = $d[$k] ?? null;
        $v['code'] = \strtoupper(\trim((string)$v['code']));
        if ($v['code'] === '') throw new \RuntimeException('Unit code required');
        if ($v['base_unit_id'] === '') $v['base_unit_id'] = null;
        $v['conversion_factor'] = (float)($v['conversion_factor'] ?: 1);
        $v['decimal_places'] = (int)($v['decimal_places'] ?? 0);
        $v['unit_type'] = $v['unit_type'] ?: 'COUNT';

        /* TENANT GUARD.
           Yeh cross-tenant leak asal test mein pakra gaya: update par koi
           tenant check nahi tha, is liye ek supermarket global KG ka
           conversion_factor 999 kar sakta tha — aur woh HAR business par
           lagta, restaurant samet. Ab:
             - apni unit    -> edit ho jati hai
             - global unit  -> edit nahi hoti; tenant apni copy banaye
             - kisi aur ki  -> milti hi nahi */
        $c = $p->prepare("SELECT tenant_id FROM units WHERE id=? LIMIT 1");
        $c->execute([$id]);
        $owner = $c->fetch(\PDO::FETCH_COLUMN);
        if ($id !== '' && $owner !== false) {
            if ($owner === null) {
                throw new \RuntimeException(
                    'Yeh ek standard (global) unit hai aur sab businesses istemal karti hain. ' .
                    'Isay badla nahi ja sakta — apna naya unit banayein.');
            }
            if ((string)$owner !== (string)$tid) {
                throw new \RuntimeException('Unit not found');
            }
            $p->prepare("UPDATE units SET code=?,name=?,unit_type=?,base_unit_id=?,conversion_factor=?,decimal_places=?
                          WHERE id=? AND tenant_id=?")
              ->execute([...\array_values($v), $id, $tid]);
            return $id;
        }
        $id = $id ?: \uuid();
        $p->prepare("INSERT INTO units(id,tenant_id,code,name,unit_type,base_unit_id,conversion_factor,decimal_places)
                     VALUES(?,?,?,?,?,?,?,?)")
          ->execute([$id, $tid, ...\array_values($v)]);
        return $id;
    }

    /** Soft delete — sync tombstone isi par chalta hai. */
    public static function remove(string $module, string $id): void
    {
        $t = $module === 'products' ? 'rtl_products' : ($module === 'uom' ? 'units' : (self::LISTS[$module] ?? null));
        if (!$t) throw new \RuntimeException("Unknown module $module");
        DB::pdo()->prepare("UPDATE `$t` SET deleted_at=NOW(6) WHERE id=? AND tenant_id=?")
                 ->execute([$id, tenant_id()]);
    }

    /* ================= new business defaults ================= */

    /**
     * Naya supermarket khali shell na ho: departments, units aur ek
     * counter pehle din se mojood hon. Restaurant mein yeh sabaq mehnga
     * para tha — bina payment method aur stock location ke POS bill hi
     * nahi banati thi.
     */
    public static function seedDefaults(PDO $pdo, string $tenantId, string $siteId): void
    {
        $c = $pdo->prepare("SELECT COUNT(*) FROM rtl_departments WHERE tenant_id=?");
        $c->execute([$tenantId]);
        if ((int)$c->fetchColumn() > 0) return;

        $depts = [['Grocery','GRO',1],['Bakery','BAK',2],['Dairy & Chilled','DAI',3],
                  ['Fruits & Vegetables','FNV',4],['Beverages','BEV',5],
                  ['Household & Cleaning','HSE',6],['Personal Care','PCR',7],['Meat & Frozen','MFZ',8]];
        foreach ($depts as [$n,$code,$so]) {
            $pdo->prepare("INSERT INTO rtl_departments(id,tenant_id,site_id,name,code,sort_order) VALUES(?,?,?,?,?,?)")
                ->execute([\uuid(), $tenantId, $siteId, $n, $code, $so]);
        }

        /* Standard units GLOBAL rehti hain (tenant_id NULL). KG har store
           mein KG hi hai — har tenant ki apni copy banane se sirf duplicate
           rows barhti hain. Tenant ke apne pack sizes baad mein tenant_id
           ke saath add ho sakte hain (index ab (code, tenant_id) par hai). */
        $units = [
            ['PCS','Piece','COUNT',null,1,0], ['KG','Kilogram','WEIGHT',null,1,3],
            ['G','Gram','WEIGHT','KG',0.001,0], ['L','Litre','VOLUME',null,1,3],
            ['ML','Millilitre','VOLUME','L',0.001,0], ['DOZ','Dozen','PACK','PCS',12,0],
            ['CTN12','Carton (12)','PACK','PCS',12,0], ['CTN24','Carton (24)','PACK','PCS',24,0],
            ['BAG10','Bag 10 KG','PACK','KG',10,0],
        ];
        $ids = [];
        foreach ($units as [$code,$n,$type,$base,$f,$dp]) {
            $ex = $pdo->prepare("SELECT id FROM units WHERE code=? AND tenant_id IS NULL LIMIT 1");
            $ex->execute([$code]);
            $uid = $ex->fetchColumn();
            if (!$uid) {
                $uid = \uuid();
                $pdo->prepare("INSERT INTO units(id,tenant_id,code,name,unit_type,base_unit_id,conversion_factor,decimal_places)
                               VALUES(?,NULL,?,?,?,?,?,?)")
                    ->execute([$uid, $code, $n, $type, $base ? ($ids[$base] ?? null) : null, $f, $dp]);
            }
            $ids[$code] = (string)$uid;
        }

        $pdo->prepare("INSERT INTO rtl_brands(id,tenant_id,name) VALUES(?,?,?)")
            ->execute([\uuid(), $tenantId, 'No Brand']);

        foreach ([1,2] as $i) {
            $pdo->prepare("INSERT INTO rtl_counters(id,tenant_id,site_id,name,device_name,drawer,opening_cash,status)
                           VALUES(?,?,?,?,?, 'Attached',0,'Closed')")
                ->execute([\uuid(), $tenantId, $siteId, "Counter $i", "POS-0$i"]);
        }
    }
}
