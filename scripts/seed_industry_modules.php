<?php
/**
 * MODULE CATALOG — industry ke hisaab se.
 *
 * MASLA JO YEH HAL KARTA HAI:
 * `platform_modules` mein `industry_code` column pehle din se maujood tha,
 * magar teeno seed scripts har module ko hard-coded 'RESTAURANT' likh rahi
 * thin. Nateeja: agar aaj Super Admin mein RETAIL business banayein to us
 * supermarket ko bhi KDS, Waiter, Tables aur Recipe mil jate.
 *
 * Ab teen buckets hain:
 *   COMMON      -> har vertical ko milta hai (settings, users, reports...)
 *   RESTAURANT  -> sirf restaurant (kds, tables, recipe, riders...)
 *   RETAIL      -> sirf supermarket (rpos, products, grn, khata...)
 *
 * AHEM: module ki id `module_uuid($key)` se banti hai (V62.2 fix). Naye
 * modules bhi isi se — warna cloud aur offline node par ek hi module ki
 * id alag hoti hai aur role_modules khamoshi se toot jata hai.
 *
 * Chalane ka tareeqa:  php scripts/seed_industry_modules.php
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;

$pdo = DB::pdo();

/* ---------- COMMON: har business ko ---------- */
$COMMON = [
    'dashboard'  => 'Dashboard',
    'shift'      => 'Opening & Closing Shift',
    'closing'    => 'Shift Closing History',
    'inventory'  => 'Inventory / Stock',
    'purchasing' => 'Purchasing',
    'po'         => 'Purchase Orders',
    'suppliers'  => 'Suppliers',
    'customers'  => 'Customers',
    'transfer'   => 'Stock Transfer',
    'count'      => 'Physical Stock Count',
    'wastage'    => 'Wastage / Adjustment',
    'expenses'   => 'Expenses',
    'accounting' => 'Accounting / Cash',
    'promotions' => 'Discounts / Promotions',
    'loyalty'    => 'Loyalty / Membership',
    'void'       => 'Void / Refund',
    'reports'    => 'Reports',
    'staff'      => 'Staff / Roles',
    'users'      => 'Users & Access',
    'settings'   => 'Settings',
    'printers'   => 'Printers / Devices',
    'branches'   => 'Multi-Branch',
    'offline'    => 'Offline / Sync',
    'activity'   => 'User Activity Log',
    'whatsapp'   => 'WhatsApp / Notifications',
    'fbr'        => 'Tax / Digital Invoice',
];

/* ---------- RESTAURANT only ---------- */
$RESTAURANT = [
    'pos'          => 'Sale Point / POS',
    'tablet'       => 'Order Taker Tablet',
    'kds'          => 'Kitchen / KDS',
    'tables'       => 'Tables & Floors',
    'orders'       => 'Running Orders',
    'online'       => 'Online Orders',
    'recipe'       => 'Recipe & Food Cost',
    'menu'         => 'Menu & Categories',
    'delivery'     => 'Delivery',
    'riders'       => 'Rider Management',
    'reservations' => 'Reservations',
    'customer_app' => 'Customer Mobile App',
    'customer_web' => 'Customer Web / QR',
];

/* ---------- RETAIL (supermarket) only ----------
   NOTE: POS ki key `rpos` hai, `pos` nahi. Supermarket ka counter
   restaurant POS se bilkul alag screen hai — dono ko ek hi
   module_key par rakhna baad mein permissions ko uljha deta. */
$RETAIL = [
    'rpos'        => 'Retail POS Counter',
    'counters'    => 'Counter Management',
    'sales'       => 'Sales / Invoices',
    'khata'       => 'Customer Credit / Khata',
    'products'    => 'Product Catalog',
    'departments' => 'Departments & Categories',
    'brands'      => 'Brands',
    'uom'         => 'Units & Pack Sizes',
    'pricing'     => 'Price Management',
    'scale'       => 'Weighing Scale Items',
    'labels'      => 'Barcode & Shelf Labels',
    'batches'     => 'Batch & Expiry',
    'grn'         => 'Goods Receipt (GRN)',
    'preturn'     => 'Purchase Return',
];

$all = [];
foreach ($COMMON     as $k => $n) $all[$k] = ['name' => $n, 'industry' => 'COMMON'];
foreach ($RESTAURANT as $k => $n) $all[$k] = ['name' => $n, 'industry' => 'RESTAURANT'];
foreach ($RETAIL     as $k => $n) $all[$k] = ['name' => $n, 'industry' => 'RETAIL'];

$ins = 0; $upd = 0; $sort = 1;
foreach ($all as $key => $m) {
    $q = $pdo->prepare("SELECT id, industry_code FROM platform_modules WHERE module_key=?");
    $q->execute([$key]);
    $row = $q->fetch();

    if (!$row) {
        $pdo->prepare("INSERT INTO platform_modules(id,module_key,name,industry_code,sort_order,is_active)
                       VALUES(?,?,?,?,?,1)")
            ->execute([module_uuid($key), $key, $m['name'], $m['industry'], $sort]);
        $ins++;
    } elseif ((string)$row['industry_code'] !== $m['industry']) {
        /* Purane installs par sab kuch 'RESTAURANT' likha hai — sahi
           bucket mein le aao. Yehi wo step hai jo supermarket tenant ko
           KDS dikhne se rokta hai. */
        $pdo->prepare("UPDATE platform_modules SET industry_code=?, name=? WHERE id=?")
            ->execute([$m['industry'], $m['name'], $row['id']]);
        $upd++;
    }
    $sort++;
}

$counts = $pdo->query("SELECT industry_code, COUNT(*) c FROM platform_modules
                        WHERE is_active=1 GROUP BY industry_code ORDER BY industry_code")->fetchAll();

echo "INDUSTRY_MODULES_READY inserted=$ins reclassified=$upd\n";
foreach ($counts as $c) echo sprintf("  %-12s %d\n", $c['industry_code'], $c['c']);
echo "  restaurant tenant sees: " . (int)$pdo->query(
    "SELECT COUNT(*) FROM platform_modules WHERE is_active=1 AND industry_code IN('RESTAURANT','COMMON')")->fetchColumn() . "\n";
echo "  retail tenant sees:     " . (int)$pdo->query(
    "SELECT COUNT(*) FROM platform_modules WHERE is_active=1 AND industry_code IN('RETAIL','COMMON')")->fetchColumn() . "\n";
