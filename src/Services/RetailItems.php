<?php
namespace Aio\Services;

use Aio\DB;
use Aio\Auth;
use PDO;

/**
 * RetailItems — Item Creation screen + department discounts (V209). RETAIL ONLY.
 *
 * ITEM CODE = BARCODE
 * One field. A new item gets the next free auto code (100001, 100002 …, the
 * number after the highest short numeric code); scanning the item's own
 * barcode replaces it. The code lives in rtl_product_barcodes (unique per
 * business), so two items can never share a code. Box code = a pack row in
 * rtl_product_uom (factor = packing size), the same row the POS already reads
 * for carton scans.
 *
 * Saving goes through RetailCatalog::saveProduct, so the existing rules stay:
 * opening qty only for a new item, written as an OPENING stock move; on edit,
 * stock is never touched here.
 *
 * DEPARTMENT DISCOUNT
 * Rules (department, optional category, %, from, to, name) are kept in
 * site_settings group 'retail' key 'dept_discounts' — a table every shop PC
 * already syncs, so no sync list changes and nothing reaches restaurant
 * businesses. The POS applies the rule on the server (RetailPos::cleanLines)
 * from the first to the last day, both whole days. The sale price itself is
 * never changed.
 */
final class RetailItems
{
    public const AUTO_START = 100001;

    /* ======================= lookups ======================= */

    public static function boot(): array
    {
        $tid = tenant_id(); $p = DB::pdo();
        $d = $p->prepare("SELECT id,name FROM rtl_departments WHERE tenant_id=? AND deleted_at IS NULL ORDER BY sort_order,name");
        $d->execute([$tid]);
        $c = $p->prepare("SELECT id,name,department_id FROM rtl_categories WHERE tenant_id=? AND deleted_at IS NULL ORDER BY sort_order,name");
        $c->execute([$tid]);
        $b = $p->prepare("SELECT id,name FROM rtl_brands WHERE tenant_id=? AND deleted_at IS NULL ORDER BY name");
        $b->execute([$tid]);
        $units = \array_values(\array_filter(RetailCatalog::units(), fn($u) => $u['unit_type'] !== 'PACK'));
        $n = $p->prepare("SELECT COUNT(*) FROM rtl_products WHERE tenant_id=? AND deleted_at IS NULL");
        $n->execute([$tid]);
        return [
            'departments' => $d->fetchAll(PDO::FETCH_ASSOC),
            'categories'  => $c->fetchAll(PDO::FETCH_ASSOC),
            'companies'   => $b->fetchAll(PDO::FETCH_ASSOC),
            'units'       => \array_map(fn($u) => ['id' => $u['id'], 'code' => $u['code'], 'type' => $u['unit_type']], $units),
            'total'       => (int)$n->fetchColumn(),
            'next_code'   => self::nextCode($p),
            'discounts'   => self::discounts(),
            'today'       => \date('Y-m-d'),
            'is_manager'  => Auth::isManager() || Auth::isAdmin(),
        ];
    }

    /** Next free auto code: after the highest numeric code of up to 6 digits, never below 100001. */
    public static function nextCode(?PDO $p = null): string
    {
        $p = $p ?: DB::pdo();
        $q = $p->prepare("SELECT MAX(CAST(barcode AS UNSIGNED)) FROM rtl_product_barcodes
                           WHERE tenant_id=? AND barcode REGEXP '^[0-9]{1,6}$'");
        $q->execute([tenant_id()]);
        $n = \max(self::AUTO_START, (int)$q->fetchColumn() + 1);
        $c = $p->prepare("SELECT COUNT(*) FROM rtl_product_barcodes WHERE tenant_id=? AND barcode=?");
        for ($i = 0; $i < 50; $i++, $n++) {
            $c->execute([tenant_id(), (string)$n]);
            if (!(int)$c->fetchColumn() && !self::codeUsed($p, (string)$n, '')) return (string)$n;
        }
        return (string)$n;
    }

    /** Code used by another item (item barcode, box code, or SKU)? Returns [id, name] or null. */
    private static function codeUsed(PDO $p, string $code, string $exceptId): ?array
    {
        $tid = tenant_id();
        $q = $p->prepare("SELECT pr.id, pr.name FROM rtl_product_barcodes b JOIN rtl_products pr ON pr.id=b.product_id
                           WHERE b.tenant_id=? AND b.barcode=? AND b.product_id<>? LIMIT 1");
        $q->execute([$tid, $code, $exceptId]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) return $r;
        $q = $p->prepare("SELECT pr.id, pr.name FROM rtl_product_uom u JOIN rtl_products pr ON pr.id=u.product_id
                           WHERE u.tenant_id=? AND u.barcode=? AND u.deleted_at IS NULL AND u.product_id<>?
                             AND pr.deleted_at IS NULL LIMIT 1");
        $q->execute([$tid, $code, $exceptId]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) return $r;
        return null;
    }

    public static function check(string $code, string $exceptId = ''): array
    {
        $hit = self::codeUsed(DB::pdo(), \trim($code), $exceptId);
        return $hit ? ['used' => true, 'id' => $hit['id'], 'name' => $hit['name']] : ['used' => false];
    }

    /** Quick add from the "+" next to Department / Category. Same name = the existing one. */
    public static function addGroup(string $kind, string $name, string $deptId = ''): array
    {
        $tid = tenant_id(); $p = DB::pdo();
        $name = \mb_strtoupper(\preg_replace('/\s+/', ' ', \trim($name)));
        if ($name === '') throw new \RuntimeException('Enter a name');
        if (\mb_strlen($name) > 120) throw new \RuntimeException('Name is too long');
        if ($kind === 'department') {
            $q = $p->prepare("SELECT id,name FROM rtl_departments WHERE tenant_id=? AND deleted_at IS NULL AND name=? LIMIT 1");
            $q->execute([$tid, $name]);
            if ($r = $q->fetch(PDO::FETCH_ASSOC)) return $r;
            $id = \uuid();
            $p->prepare("INSERT INTO rtl_departments(id,tenant_id,site_id,name) VALUES(?,?,?,?)")->execute([$id, $tid, site_id(), $name]);
            return ['id' => $id, 'name' => $name];
        }
        $dq = $p->prepare("SELECT COUNT(*) FROM rtl_departments WHERE id=? AND tenant_id=? AND deleted_at IS NULL");
        $dq->execute([$deptId, $tid]);
        if (!(int)$dq->fetchColumn()) throw new \RuntimeException('Select the department first');
        $q = $p->prepare("SELECT id,name,department_id FROM rtl_categories WHERE tenant_id=? AND deleted_at IS NULL AND department_id=? AND name=? LIMIT 1");
        $q->execute([$tid, $deptId, $name]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) return $r;
        $id = \uuid();
        $p->prepare("INSERT INTO rtl_categories(id,tenant_id,site_id,department_id,name) VALUES(?,?,?,?,?)")->execute([$id, $tid, site_id(), $deptId, $name]);
        return ['id' => $id, 'name' => $name, 'department_id' => $deptId];
    }

    /* ======================= grid ======================= */

    /**
     * One page for the grid. Filters: code (prefix of the item code), name,
     * department_id, category_id. Uses the indexes uq_rpb (tenant, barcode)
     * and ix_rp_name (tenant, name) — no full scans.
     */
    public static function page(array $f): array
    {
        $tid = tenant_id(); $p = DB::pdo();
        $size = (int)($f['size'] ?? 100); if (!\in_array($size, [50, 100, 200], true)) $size = 100;
        $page = \max(1, (int)($f['page'] ?? 1));
        $w = "p.tenant_id=? AND p.deleted_at IS NULL"; $a = [$tid];
        foreach (['department_id', 'category_id'] as $k) if (!empty($f[$k])) { $w .= " AND p.`$k`=?"; $a[] = (string)$f[$k]; }
        $code = \trim((string)($f['code'] ?? ''));
        if ($code !== '') {
            $w .= " AND (EXISTS (SELECT 1 FROM rtl_product_barcodes b WHERE b.tenant_id=p.tenant_id AND b.product_id=p.id
                                   AND b.deleted_at IS NULL AND b.barcode LIKE ?)
                        OR EXISTS (SELECT 1 FROM rtl_product_uom u WHERE u.tenant_id=p.tenant_id AND u.product_id=p.id
                                   AND u.deleted_at IS NULL AND u.barcode LIKE ?))";
            $like = \addcslashes($code, '%_\\').'%';
            $a[] = $like; $a[] = $like;
        }
        $name = \trim((string)($f['name'] ?? ''));
        if ($name !== '') {
            if (\mb_strlen($name) >= 3) {
                $terms = \array_filter(\preg_split('/\s+/', \preg_replace('/[+\-<>()~*"@]+/', ' ', $name)) ?: [], fn($t) => $t !== '');
                $w .= " AND (p.name LIKE ? OR MATCH(p.name) AGAINST (? IN BOOLEAN MODE))";
                $a[] = \addcslashes($name, '%_\\').'%';
                $a[] = \implode(' ', \array_map(fn($t) => '+'.$t.'*', $terms));
            } else { $w .= " AND p.name LIKE ?"; $a[] = \addcslashes($name, '%_\\').'%'; }
        }
        $c = $p->prepare("SELECT COUNT(*) FROM rtl_products p WHERE $w"); $c->execute($a);
        $total = (int)$c->fetchColumn();
        $pages = \max(1, (int)\ceil($total / $size)); if ($page > $pages) $page = $pages;
        $q = $p->prepare("SELECT p.id, p.name, p.department_id, p.category_id, p.brand_id, p.base_unit_id,
                                 p.cost_price, p.retail_price, p.stock_qty, p.status,
                                 d.name dept, c.name cat, br.name company, un.code unit
                            FROM rtl_products p
                       LEFT JOIN rtl_departments d ON d.id=p.department_id
                       LEFT JOIN rtl_categories c ON c.id=p.category_id
                       LEFT JOIN rtl_brands br ON br.id=p.brand_id
                       LEFT JOIN units un ON un.id=p.base_unit_id
                           WHERE $w ORDER BY p.name, p.id LIMIT $size OFFSET ".(($page - 1) * $size));
        $q->execute($a);
        $rows = self::withCodes($p, $q->fetchAll(PDO::FETCH_ASSOC));
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'size' => $size];
    }

    /** Adds code (first barcode), box code and packing to rows — 2 queries for the whole page. */
    private static function withCodes(PDO $p, array $rows): array
    {
        if (!$rows) return [];
        $ids = \array_column($rows, 'id');
        $ph = \implode(',', \array_fill(0, \count($ids), '?'));
        $b = $p->prepare("SELECT product_id, barcode FROM rtl_product_barcodes WHERE tenant_id=? AND deleted_at IS NULL
                           AND product_id IN ($ph) ORDER BY created_at, id");
        $b->execute(\array_merge([tenant_id()], $ids));
        $codes = [];
        foreach ($b->fetchAll(PDO::FETCH_ASSOC) as $r) if (!isset($codes[$r['product_id']])) $codes[$r['product_id']] = $r['barcode'];
        $u = $p->prepare("SELECT product_id, barcode, factor FROM rtl_product_uom WHERE tenant_id=? AND deleted_at IS NULL
                           AND product_id IN ($ph) ORDER BY created_at, id");
        $u->execute(\array_merge([tenant_id()], $ids));
        $box = [];
        foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $r) if (!isset($box[$r['product_id']])) $box[$r['product_id']] = $r;
        foreach ($rows as &$r) {
            $r['code'] = $codes[$r['id']] ?? '';
            $r['box_code'] = (string)($box[$r['id']]['barcode'] ?? '');
            $r['packing'] = isset($box[$r['id']]) ? (float)$box[$r['id']]['factor'] : 1.0;
            foreach (['cost_price', 'retail_price', 'stock_qty'] as $k) $r[$k] = (float)$r[$k];
        }
        return $rows;
    }

    public static function one(string $id): ?array
    {
        $q = DB::pdo()->prepare("SELECT p.id, p.name, p.department_id, p.category_id, p.brand_id, p.base_unit_id,
                                        p.cost_price, p.retail_price, p.stock_qty, p.status,
                                        d.name dept, c.name cat, br.name company, un.code unit
                                   FROM rtl_products p
                              LEFT JOIN rtl_departments d ON d.id=p.department_id
                              LEFT JOIN rtl_categories c ON c.id=p.category_id
                              LEFT JOIN rtl_brands br ON br.id=p.brand_id
                              LEFT JOIN units un ON un.id=p.base_unit_id
                                  WHERE p.id=? AND p.tenant_id=? AND p.deleted_at IS NULL");
        $q->execute([$id, tenant_id()]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return self::withCodes(DB::pdo(), [$r])[0];
    }

    /** Item by its code (for "existing code -> open for edit"). */
    public static function byCode(string $code): ?array
    {
        $hit = self::codeUsed(DB::pdo(), \trim($code), '');
        return $hit ? self::one((string)$hit['id']) : null;
    }

    /* ======================= save ======================= */

    private static function companyId(PDO $p, string $name): ?string
    {
        $name = \mb_strtoupper(\trim($name));
        if ($name === '') return null;
        $q = $p->prepare("SELECT id FROM rtl_brands WHERE tenant_id=? AND deleted_at IS NULL AND name=? LIMIT 1");
        $q->execute([tenant_id(), $name]);
        if ($id = $q->fetchColumn()) return (string)$id;
        $id = \uuid();
        $p->prepare("INSERT INTO rtl_brands(id,tenant_id,name) VALUES(?,?,?)")->execute([$id, tenant_id(), \mb_substr($name, 0, 120)]);
        return $id;
    }

    /**
     * New or edit. $d: id, code, auto (1 = code was the auto number), name,
     * department_id, category_id, company, cost_price, sale_price, unit_id,
     * packing, box_code, opening_qty.
     */
    public static function save(array $d): array
    {
        /* Auto code + two counters at the same moment: the loser takes the
           next number (the unique index decides, nothing is half-saved). */
        if (!empty($d['auto']) && \trim((string)($d['id'] ?? '')) === '' && empty($d['_try'])) {
            for ($i = 0; ; $i++) {
                try { return self::save(['_try' => 1] + $d); }
                catch (\RuntimeException $e) { if ($e->getCode() !== 1062 || $i >= 7) throw $e; \usleep(\random_int(5000, 40000)); }
            }
        }
        $tid = tenant_id();
        $id = \trim((string)($d['id'] ?? ''));
        $code = \trim((string)($d['code'] ?? ''));
        $name = \mb_strtoupper(\preg_replace('/\s+/', ' ', \trim((string)($d['name'] ?? ''))));
        $dept = \trim((string)($d['department_id'] ?? ''));
        $cat  = \trim((string)($d['category_id'] ?? ''));
        $cost = \round((float)($d['cost_price'] ?? 0), 2);
        $sale = \round((float)($d['sale_price'] ?? 0), 2);
        $pack = (float)($d['packing'] ?? 1); if ($pack <= 0) $pack = 1;
        $box  = \trim((string)($d['box_code'] ?? ''));
        $open = (float)($d['opening_qty'] ?? 0);
        $auto = !empty($d['auto']);

        if ($code === '' && !$auto) throw new \RuntimeException('Enter the item code / barcode');
        if (\mb_strlen($code) > 40) throw new \RuntimeException('Item code is too long (40 characters max)');
        if ($code !== '' && !\preg_match('/^[\x21-\x7E]+$/', $code)) throw new \RuntimeException('Item code cannot contain spaces or special characters');
        if ($name === '') throw new \RuntimeException('Enter the item name');
        if (\mb_strlen($name) > 150) throw new \RuntimeException('Item name is too long');
        if ($dept === '') throw new \RuntimeException('Select the department');
        if ($sale <= 0) throw new \RuntimeException('Enter the sale price');
        if ($cost < 0) throw new \RuntimeException('Cost price cannot be negative');
        if ($open < 0) throw new \RuntimeException('Opening qty cannot be negative');
        if ($box !== '' && $box === $code) throw new \RuntimeException('Box code must be different from the item code');

        $p = DB::pdo();
        $dq = $p->prepare("SELECT COUNT(*) FROM rtl_departments WHERE id=? AND tenant_id=? AND deleted_at IS NULL");
        $dq->execute([$dept, $tid]);
        if (!(int)$dq->fetchColumn()) throw new \RuntimeException('Department not found');
        if ($cat !== '') {
            $cq = $p->prepare("SELECT COUNT(*) FROM rtl_categories WHERE id=? AND tenant_id=? AND deleted_at IS NULL");
            $cq->execute([$cat, $tid]);
            if (!(int)$cq->fetchColumn()) throw new \RuntimeException('Category not found');
        }
        $unit = \trim((string)($d['unit_id'] ?? ''));
        if ($unit !== '') {
            $uq = $p->prepare("SELECT unit_type FROM units WHERE id=? AND (tenant_id=? OR tenant_id IS NULL) AND deleted_at IS NULL");
            $uq->execute([$unit, $tid]);
            if (!$uq->fetchColumn()) throw new \RuntimeException('Unit not found');
        }

        $old = null;
        if ($id !== '') { $old = self::one($id); if (!$old) throw new \RuntimeException('Item not found'); }

        /* A code typed by hand (or the auto number) must be free. The auto
           number is taken again here, so two counters adding at the same time
           get different numbers. */
        if ($auto && !$old) $code = self::nextCode($p);
        if ($hit = self::codeUsed($p, $code, $id)) throw new \RuntimeException("Code $code is already used by ".$hit['name'], 1062);
        if ($box !== '' && ($hit = self::codeUsed($p, $box, $id))) throw new \RuntimeException("Box code $box is already used by ".$hit['name']);

        $brand = self::companyId($p, (string)($d['company'] ?? ''));
        $prodData = [
            'name' => $name, 'department_id' => $dept, 'category_id' => $cat ?: null, 'brand_id' => $brand,
            'base_unit_id' => $unit ?: ($old['base_unit_id'] ?? null), 'cost_price' => $cost, 'retail_price' => $sale,
        ];
        if ($old) {
            /* keep every field this screen does not show */
            $full = RetailCatalog::product($id) ?: [];
            foreach (['sku','tax_rate','wholesale_price','mrp','min_stock','max_stock','is_scale_item','plu_code',
                      'track_batch','shelf_life_days','status'] as $k) $prodData[$k] = $full[$k] ?? null;
            $prodData['stock_qty'] = 0;   /* ignored on edit by saveProduct */
            /* SKU followed the old code: follow the new one (the POS also finds by SKU) */
            if ((string)($full['sku'] ?? '') === (string)$old['code'] && $old['code'] !== $code) {
                $sq = $p->prepare("SELECT COUNT(*) FROM rtl_products WHERE tenant_id=? AND sku=? AND id<>?");
                $sq->execute([$tid, \mb_substr($code, 0, 60), $id]);
                $prodData['sku'] = (int)$sq->fetchColumn() ? $full['sku'].'-'.\substr(\md5($id), 0, 4) : \mb_substr($code, 0, 60);
            }
        } else {
            $prodData['sku'] = \mb_substr($code, 0, 60);
            $prodData['stock_qty'] = $open;
            $prodData['status'] = 'Active';
        }

        if (!$old) {
            $sq = $p->prepare("SELECT COUNT(*) FROM rtl_products WHERE tenant_id=? AND sku=?");
            $sq->execute([$tid, $prodData['sku']]);
            if ((int)$sq->fetchColumn()) $prodData['sku'] = '';   /* SKU taken by an old item: automatic SKU */
        }
        /* The item keeps its current code row while saving; a changed code
           is then renamed in place (not deleted: the unique index also
           covers deleted rows, so the old code stays free for re-use). */
        $keep = ($old && $old['code'] !== '') ? $old['code'] : $code;
        try {
            $pid = RetailCatalog::saveProduct($old ? $id : '', $prodData + ['barcode' => $keep]);
            if ($old && $old['code'] !== '' && $old['code'] !== $code) {
                $p->prepare("UPDATE rtl_product_barcodes SET barcode=?, updated_at=NOW(6)
                              WHERE tenant_id=? AND product_id=? AND barcode=?")
                  ->execute([$code, $tid, $pid, $old['code']]);
            }
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) == 1062) throw new \RuntimeException("Code $code is already used by another item", 1062);
            throw $e;
        }

        /* box code + packing size: one pack row (the one the POS reads for carton scans) */
        DB::tx(function (PDO $p) use ($pid, $box, $pack, $sale, $cost, $tid) {
            $q = $p->prepare("SELECT id FROM rtl_product_uom WHERE tenant_id=? AND product_id=? AND deleted_at IS NULL
                               ORDER BY created_at, id LIMIT 1");
            $q->execute([$tid, $pid]);
            $uid = $q->fetchColumn();
            if ($box === '' && \abs($pack - 1) < 0.000001) {
                if ($uid) $p->prepare("UPDATE rtl_product_uom SET deleted_at=NOW(6), updated_at=NOW(6) WHERE id=?")->execute([$uid]);
                return;
            }
            if ($uid) {
                $p->prepare("UPDATE rtl_product_uom SET barcode=?, factor=?, retail_price=?, cost_price=?, updated_at=NOW(6) WHERE id=?")
                  ->execute([$box ?: null, $pack, \round($sale * $pack, 2), \round($cost * $pack, 2), $uid]);
            } else {
                $p->prepare("INSERT INTO rtl_product_uom(id,tenant_id,product_id,unit_id,barcode,factor,cost_price,retail_price,is_default_purchase)
                             VALUES(?,?,?,NULL,?,?,?,?,0)")
                  ->execute([\uuid(), $tid, $pid, $box ?: null, $pack, \round($cost * $pack, 2), \round($sale * $pack, 2)]);
            }
        });
        try { Audit::log($old ? 'UPDATE' : 'CREATE', 'retail_items', ['id' => $pid, 'label' => $code.' '.$name]); } catch (\Throwable $e) {}
        return ['item' => self::one($pid), 'next_code' => self::nextCode()];
    }

    /* ======================= department discounts ======================= */

    private static ?array $rulesCache = null;

    public static function discounts(): array
    {
        $v = SiteSetting::one('retail', 'dept_discounts', []);
        return \is_array($v) ? \array_values($v) : [];
    }

    public static function status(array $r, ?string $today = null): string
    {
        $today = $today ?: \date('Y-m-d');
        if (!empty($r['stopped'])) return 'ENDED';
        if ($today < (string)$r['from']) return 'SCHEDULED';
        if ($today > (string)$r['to']) return 'ENDED';
        return 'RUNNING';
    }

    public static function saveDiscount(array $d): array
    {
        if (!Auth::isManager() && !Auth::isAdmin()) throw new \RuntimeException('Only a manager or the owner can set a discount');
        $tid = tenant_id(); $p = DB::pdo();
        $dept = \trim((string)($d['department_id'] ?? ''));
        $cat  = \trim((string)($d['category_id'] ?? ''));
        $pct  = \round((float)($d['pct'] ?? 0), 2);
        $from = (string)($d['from'] ?? ''); $to = (string)($d['to'] ?? '');
        $today = \date('Y-m-d');
        $dq = $p->prepare("SELECT name FROM rtl_departments WHERE id=? AND tenant_id=? AND deleted_at IS NULL");
        $dq->execute([$dept, $tid]);
        $dname = (string)($dq->fetchColumn() ?: '');
        if ($dname === '') throw new \RuntimeException('Select the department');
        $cname = '';
        if ($cat !== '') {
            $cq = $p->prepare("SELECT name FROM rtl_categories WHERE id=? AND tenant_id=? AND deleted_at IS NULL");
            $cq->execute([$cat, $tid]);
            $cname = (string)($cq->fetchColumn() ?: '');
            if ($cname === '') throw new \RuntimeException('Category not found');
        }
        if (!($pct >= 1 && $pct <= 90)) throw new \RuntimeException('Discount must be between 1% and 90%');
        if (!\preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !\preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) throw new \RuntimeException('Select the From and To dates');
        if ($to < $from) throw new \RuntimeException('"To" date is before "From" date');
        if ($to < $today) throw new \RuntimeException('These dates are already over');
        $rules = self::discounts();
        foreach ($rules as $r) {
            if (self::status($r, $today) === 'ENDED' || $r['department_id'] !== $dept) continue;
            if ($cat !== '' && ($r['category_id'] ?? '') !== '' && $r['category_id'] !== $cat) continue;
            if ($to < $r['from'] || $from > $r['to'])                                         continue;
            throw new \RuntimeException($dname.' already has '.(float)$r['pct'].'% from '.$r['from'].' to '.$r['to'].' — dates overlap');
        }
        $name = \mb_substr(\trim((string)($d['name'] ?? '')) ?: ($dname.($cname ? ' '.$cname : '').' '.(float)$pct.'% OFF'), 0, 80);
        \array_unshift($rules, ['id' => \uuid(), 'department_id' => $dept, 'department' => $dname,
            'category_id' => $cat, 'category' => $cname, 'pct' => $pct, 'from' => $from, 'to' => $to, 'name' => $name,
            'stopped' => false, 'by' => Auth::user()['full_name'] ?? '', 'at' => \date('Y-m-d H:i:s')]);
        /* ended rules older than 180 days are dropped to keep the row small */
        $cut = \date('Y-m-d', \strtotime('-180 days'));
        $rules = \array_values(\array_filter($rules, fn($r) => (string)$r['to'] >= $cut));
        SiteSetting::put('retail', 'dept_discounts', $rules);
        self::$rulesCache = null;
        try { Audit::log('CREATE', 'retail_discount', ['id' => $rules[0]['id'], 'label' => $name]); } catch (\Throwable $e) {}
        return self::discounts();
    }

    public static function stopDiscount(string $id): array
    {
        if (!Auth::isManager() && !Auth::isAdmin()) throw new \RuntimeException('Only a manager or the owner can stop a discount');
        $rules = self::discounts(); $found = false;
        foreach ($rules as &$r) if (($r['id'] ?? '') === $id) { $r['stopped'] = true; $r['stopped_at'] = \date('Y-m-d H:i:s'); $found = true; }
        unset($r);
        if (!$found) throw new \RuntimeException('Discount not found');
        SiteSetting::put('retail', 'dept_discounts', $rules);
        self::$rulesCache = null;
        try { Audit::log('UPDATE', 'retail_discount', ['id' => $id, 'label' => 'Stopped']); } catch (\Throwable $e) {}
        return self::discounts();
    }

    /** Running rules today — read once per request (POS path). */
    public static function runningRules(): array
    {
        if (self::$rulesCache !== null) return self::$rulesCache;
        $today = \date('Y-m-d');
        $out = [];
        foreach (self::discounts() as $r) if (self::status($r, $today) === 'RUNNING') $out[] = $r;
        return self::$rulesCache = $out;
    }

    /** Best running rule for a product's department/category, or null. */
    public static function ruleFor(?string $deptId, ?string $catId): ?array
    {
        if (!$deptId) return null;
        $best = null;
        foreach (self::runningRules() as $r) {
            if ($r['department_id'] !== $deptId) continue;
            if (($r['category_id'] ?? '') !== '' && $r['category_id'] !== $catId) continue;
            if (!$best || (float)$r['pct'] > (float)$best['pct']) $best = $r;
        }
        return $best;
    }
}

// build: V209 build 2026-11-25
