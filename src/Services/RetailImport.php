<?php
namespace Aio\Services;

use Aio\Auth;
use Aio\DB;
use PDO;

/**
 * RetailImport — Excel / CSV se items (V192).
 *
 * File browser mein parhi jati hai (SheetJS) aur columns ka jor
 * (mapping) bhi wahin malik khud lagata hai. Server ke paas sirf saaf
 * rows aati hain — 200-200 ke batch mein — aur yeh class har batch ek
 * transaction mein likhti hai: ya poora batch, ya kuch bhi nahi.
 *
 * MALIK KE QAWAID (13 Nov 2026):
 *  1. Barcode dohraya nahi jata. Barcode pehle se ho to wohi item hai.
 *  2. Item pehle se system mein ho to SIRF qty aur price badalte hain
 *     (sale price aur cost). Naam, department waghera nahi chhue jate.
 *  3. Sale price 0 ho to item Inactive — POS par muft na bike.
 *  4. Department naam se milaya jata hai; na mile to ban jata hai.
 *
 * STOCK SIRF RetailStock SE: naye item ki qty OPENING move se, purane
 * item ki qty IMPORT move se (pehle aur baad ki ginti ke sath), taake
 * stock card par saaf nazar aaye ke stock kahan se aaya.
 *
 * TENANT: har query tenant_id() ke daire mein. Doosre business ka
 * barcode, department ya product kabhi nahi milta.
 */
final class RetailImport
{
    public const MAX_BATCH = 500;

    /** Mapping ke naam se save / parhna (site_settings, group retail_import). */
    public static function maps(): array
    {
        try {
            $v = SiteSetting::one('retail_import', 'mappings', null);
            if (\is_string($v)) $v = \json_decode($v, true);
            return \is_array($v) ? $v : [];
        } catch (\Throwable $e) { return []; }
    }

    public static function saveMap(string $name, array $map): array
    {
        $name = \substr(\trim($name), 0, 60);
        if ($name === '') throw new \RuntimeException('Give the mapping a name');
        $all = self::maps();
        $clean = [];
        foreach ($map as $k => $v) {
            if (!\is_string($k) || \strlen($k) > 40) continue;
            $clean[$k] = \is_scalar($v) ? \substr((string)$v, 0, 200) : '';
        }
        $all[$name] = $clean;
        if (\count($all) > 30) $all = \array_slice($all, -30, null, true);
        SiteSetting::put('retail_import', 'mappings', $all);
        return $all;
    }

    public static function deleteMap(string $name): array
    {
        $all = self::maps();
        unset($all[$name]);
        SiteSetting::put('retail_import', 'mappings', $all);
        return $all;
    }

    /** Kaun se barcode pehle se is business mein hain. */
    public static function existing(array $codes): array
    {
        $codes = \array_values(\array_unique(\array_filter(\array_map(
            fn($c) => \trim((string)$c), $codes), fn($c) => $c !== '')));
        $out = [];
        foreach (\array_chunk($codes, 1000) as $chunk) {
            $ph = \implode(',', \array_fill(0, \count($chunk), '?'));
            $q = DB::pdo()->prepare(
                "SELECT b.barcode, p.id, p.name, p.retail_price, p.cost_price, p.stock_qty
                   FROM rtl_product_barcodes b
                   JOIN rtl_products p ON p.id=b.product_id AND p.tenant_id=b.tenant_id AND p.deleted_at IS NULL
                  WHERE b.tenant_id=? AND b.deleted_at IS NULL AND b.barcode IN ($ph)");
            $q->execute(\array_merge([tenant_id()], $chunk));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['barcode']] = ['id' => $r['id'], 'name' => $r['name'],
                    'retail_price' => (float)$r['retail_price'], 'cost_price' => (float)$r['cost_price'],
                    'stock_qty' => (float)$r['stock_qty']];
            }
        }
        return $out;
    }

    /* ---------------- lookups (har batch ke andar cache) ---------------- */

    private static array $cache = [];

    private static function deptId(PDO $p, string $name): ?string
    {
        $name = \trim($name);
        if ($name === '') return null;
        $k = 'd:' . \mb_strtolower($name);
        if (isset(self::$cache[$k])) return self::$cache[$k];
        $q = $p->prepare("SELECT id FROM rtl_departments WHERE tenant_id=? AND deleted_at IS NULL AND LOWER(name)=LOWER(?) LIMIT 1");
        $q->execute([tenant_id(), $name]);
        $id = $q->fetchColumn();
        if (!$id) {
            $id = \uuid();
            $p->prepare("INSERT INTO rtl_departments(id,tenant_id,site_id,name,code,sort_order) VALUES(?,?,?,?,?,99)")
              ->execute([$id, tenant_id(), site_id(), \mb_substr($name, 0, 120),
                         \strtoupper(\substr(\preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 6))]);
        }
        return self::$cache[$k] = (string)$id;
    }

    private static function catId(PDO $p, string $name, ?string $deptId): ?string
    {
        $name = \trim($name);
        if ($name === '') return null;
        $k = 'c:' . ($deptId ?? '') . ':' . \mb_strtolower($name);
        if (isset(self::$cache[$k])) return self::$cache[$k];
        $sql = "SELECT id FROM rtl_categories WHERE tenant_id=? AND deleted_at IS NULL AND LOWER(name)=LOWER(?)";
        $args = [tenant_id(), $name];
        if ($deptId) { $sql .= " AND department_id=?"; $args[] = $deptId; }
        $q = $p->prepare($sql . " LIMIT 1");
        $q->execute($args);
        $id = $q->fetchColumn();
        if (!$id) {
            $id = \uuid();
            $p->prepare("INSERT INTO rtl_categories(id,tenant_id,site_id,department_id,name,sort_order) VALUES(?,?,?,?,?,99)")
              ->execute([$id, tenant_id(), site_id(), $deptId, \mb_substr($name, 0, 120)]);
        }
        return self::$cache[$k] = (string)$id;
    }

    private static function brandId(PDO $p, string $name): ?string
    {
        $name = \trim($name);
        if ($name === '') return null;
        $k = 'b:' . \mb_strtolower($name);
        if (isset(self::$cache[$k])) return self::$cache[$k];
        $q = $p->prepare("SELECT id FROM rtl_brands WHERE tenant_id=? AND deleted_at IS NULL AND LOWER(name)=LOWER(?) LIMIT 1");
        $q->execute([tenant_id(), $name]);
        $id = $q->fetchColumn();
        if (!$id) {
            $id = \uuid();
            $p->prepare("INSERT INTO rtl_brands(id,tenant_id,name) VALUES(?,?,?)")
              ->execute([$id, tenant_id(), \mb_substr($name, 0, 120)]);
        }
        return self::$cache[$k] = (string)$id;
    }

    /** Unit code (PCS, KG, CTN24...) — na mile to PCS. Naya unit nahi banta. */
    private static function unitId(PDO $p, string $code): ?string
    {
        $code = \strtoupper(\trim($code));
        if ($code === '') $code = 'PCS';
        $k = 'u:' . $code;
        if (isset(self::$cache[$k])) return self::$cache[$k];
        $q = $p->prepare("SELECT id FROM units WHERE (tenant_id=? OR tenant_id IS NULL) AND deleted_at IS NULL
                           AND (UPPER(code)=? OR UPPER(name)=?) ORDER BY tenant_id IS NULL LIMIT 1");
        $q->execute([tenant_id(), $code, $code]);
        $id = $q->fetchColumn();
        if (!$id && $code !== 'PCS') return self::$cache[$k] = self::unitId($p, 'PCS');
        return self::$cache[$k] = ($id ? (string)$id : null);
    }

    private static function skuCounter(PDO $p): int
    {
        if (!isset(self::$cache['sku'])) {
            $q = $p->prepare("SELECT COUNT(*) FROM rtl_products WHERE tenant_id=?");
            $q->execute([tenant_id()]);
            self::$cache['sku'] = (int)$q->fetchColumn();
        }
        return ++self::$cache['sku'];
    }

    private static function nextSku(PDO $p): string
    {
        for ($i = 0; $i < 50; $i++) {
            $sku = 'SKU-' . \str_pad((string)self::skuCounter($p), 5, '0', STR_PAD_LEFT);
            $c = $p->prepare("SELECT COUNT(*) FROM rtl_products WHERE tenant_id=? AND sku=?");
            $c->execute([tenant_id(), $sku]);
            if (!(int)$c->fetchColumn()) return $sku;
        }
        return 'SKU-' . \strtoupper(\substr(\str_replace('-', '', \uuid()), 0, 8));
    }

    private static function num($v): ?float
    {
        if ($v === null) return null;
        if (\is_int($v) || \is_float($v)) return (float)$v;
        $s = \trim(\str_replace([',', 'Rs', 'rs', 'PKR', ' '], '', (string)$v));
        if ($s === '') return null;
        return \is_numeric($s) ? (float)$s : null;
    }

    /**
     * Ek batch likho.
     *
     * @param array $rows  [ {row, barcode, name, cost, sale, wholesale, qty, mrp, tax,
     *                        min_stock, department, category, brand, unit} ]
     * @return array {created, updated, skipped, errors:[{row, message}]}
     */
    public static function commit(array $rows): array
    {
        if (\count($rows) > self::MAX_BATCH) throw new \RuntimeException('Too many rows in one batch');
        self::$cache = [];
        $res = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'inactive' => 0, 'errors' => []];
        $uid = (string)((Auth::user() ?: [])['id'] ?? '');

        DB::tx(function (PDO $p) use ($rows, &$res) {
            $seen = [];
            foreach ($rows as $r) {
                $rowNo = (int)($r['row'] ?? 0);
                $code  = \trim(\preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($r['barcode'] ?? '')));
                $name  = \trim(\preg_replace('/\s+/u', ' ', \preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($r['name'] ?? ''))));
                if ($code === '') { $res['errors'][] = ['row' => $rowNo, 'message' => 'No barcode']; continue; }
                if (\mb_strlen($code) > 64) { $res['errors'][] = ['row' => $rowNo, 'message' => 'Barcode too long']; continue; }
                if (isset($seen[$code])) { $res['skipped']++; continue; }   /* qanoon 1: dohraya nahi */
                $seen[$code] = true;

                $cost = self::num($r['cost'] ?? null);
                $sale = self::num($r['sale'] ?? null);
                $qty  = self::num($r['qty'] ?? null);
                foreach (['cost' => $cost, 'sale' => $sale] as $k => $v) {
                    if ($v !== null && $v < 0) { $res['errors'][] = ['row' => $rowNo, 'message' => "Negative $k"]; continue 2; }
                }

                $f = $p->prepare("SELECT p.id, p.stock_qty, p.status FROM rtl_product_barcodes b
                                    JOIN rtl_products p ON p.id=b.product_id AND p.tenant_id=b.tenant_id AND p.deleted_at IS NULL
                                   WHERE b.tenant_id=? AND b.barcode=? AND b.deleted_at IS NULL LIMIT 1");
                $f->execute([tenant_id(), $code]);
                $ex = $f->fetch(PDO::FETCH_ASSOC);

                if ($ex) {
                    /* qanoon 2: sirf qty aur price */
                    $set = []; $args = [];
                    if ($sale !== null) { $set[] = 'retail_price=?'; $args[] = $sale; }
                    if ($cost !== null) { $set[] = 'cost_price=?';   $args[] = $cost; }
                    if ($sale !== null && $sale <= 0) { $set[] = "status='Inactive'"; $res['inactive']++; }
                    if ($set) {
                        $args[] = $ex['id']; $args[] = tenant_id();
                        $p->prepare("UPDATE rtl_products SET " . \implode(',', $set) . ", updated_at=NOW(6) WHERE id=? AND tenant_id=?")
                          ->execute($args);
                    }
                    if ($qty !== null) {
                        $delta = $qty - (float)$ex['stock_qty'];
                        if (\abs($delta) > 0.000001) {
                            RetailStock::move($p, $ex['id'], $delta, RetailStock::IMPORT,
                                ['table' => 'rtl_products', 'id' => $ex['id'], 'no' => 'IMPORT',
                                 'note' => 'Excel import: ' . (float)$ex['stock_qty'] . ' -> ' . $qty]);
                        }
                    }
                    $res['updated']++;
                    continue;
                }

                if ($name === '') { $res['errors'][] = ['row' => $rowNo, 'message' => 'No item name']; continue; }

                $deptId = self::deptId($p, (string)($r['department'] ?? ''));
                $catId  = self::catId($p, (string)($r['category'] ?? ''), $deptId);
                $brand  = self::brandId($p, (string)($r['brand'] ?? ''));
                $unit   = self::unitId($p, (string)($r['unit'] ?? ''));
                $status = ($sale === null || $sale <= 0) ? 'Inactive' : 'Active';   /* qanoon 3 */
                if ($status === 'Inactive') $res['inactive']++;
                $id = \uuid();
                $p->prepare(
                    "INSERT INTO rtl_products(id,tenant_id,site_id,sku,name,department_id,category_id,brand_id,
                         base_unit_id,tax_rate,cost_price,retail_price,wholesale_price,mrp,stock_qty,min_stock,
                         max_stock,is_scale_item,plu_code,track_batch,shelf_life_days,status)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,0,0,NULL,0,0,?)")
                  ->execute([$id, tenant_id(), site_id(), self::nextSku($p), \mb_substr($name, 0, 190),
                             $deptId, $catId, $brand, $unit,
                             (float)(self::num($r['tax'] ?? null) ?? 0), (float)($cost ?? 0), (float)($sale ?? 0),
                             (float)(self::num($r['wholesale'] ?? null) ?? 0),
                             (float)(self::num($r['mrp'] ?? null) ?? ($sale ?? 0)),
                             (float)(self::num($r['min_stock'] ?? null) ?? 0), $status]);
                $p->prepare("INSERT INTO rtl_product_barcodes(id,tenant_id,product_id,barcode) VALUES(?,?,?,?)")
                  ->execute([\uuid(), tenant_id(), $id, $code]);
                if ($qty !== null && \abs($qty) > 0.000001) {
                    RetailStock::move($p, $id, $qty, RetailStock::OPENING,
                        ['table' => 'rtl_products', 'id' => $id, 'no' => 'IMPORT', 'note' => 'Opening stock (Excel import)']);
                }
                $res['created']++;
            }
        });

        try { Audit::log('IMPORT', 'products', ['label' => 'Excel import batch',
              'new' => $res['created'] . ' new / ' . $res['updated'] . ' updated', 'user' => $uid]); }
        catch (\Throwable $e) {}
        return $res;
    }
}

// build: V192 build 2026-11-13
