<?php
// ============================================================
//  LOCAL (branch) configuration — offline-first node.
//  Ye branch PC par chalta hai. Sab reads/writes LOCAL MySQL
//  par hote hain (internet ke baghair bhi). Jab internet ho,
//  sync worker local changes ko CLOUD par bhej deta hai.
// ============================================================
return [
    'app' => [
        'name' => 'All-in-One Restaurant OS',
        'env' => 'local',
        'role' => 'local',           // 'local' (branch)  |  'cloud' (hosting)
        'debug' => true,
        'timezone' => 'Asia/Karachi',
        'base_url' => 'http://127.0.0.1:8940',
        'tenant_id' => '11111111-1111-1111-1111-111111111111',
        'organization_id' => '22222222-2222-2222-2222-222222222222',
        'site_id' => '33333333-3333-3333-3333-333333333333',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'aio_local',
        'username' => 'root',
        'password' => 'Pakistan_123#',
        'charset' => 'utf8mb4',
    ],
    // ---------------- CLOUD SYNC ----------------
    'sync' => [
        'enabled' => true,
        // Hosting par deploy karne ke baad yahan apna cloud URL dalein, e.g.
        //   'https://app.yourdomain.com'
        // Khaali chhod dein to app 100% local chalega (sync skip ho jayega).
        'cloud_api_url' => '',
        // Cloud config mein isi token ko match karwana hai (shared secret).
        'token' => 'CHANGE-THIS-LONG-RANDOM-SYNC-TOKEN',
        'interval_seconds' => 60,    // V64: local ka bill 1 minute ke andar cloud par
        'interval_minutes' => 5,     // purani key - ab sirf fallback     // har kitne minute baad auto-sync ho
        'batch' => 300,              // ek batch mein max rows
        // Local -> Cloud PUSH: ye tables upar bheji jaati hain (jinme updated_at hai).
        'push_tables' => [
            'ui_records', 'orders', 'order_items', 'order_payments',
            'inventory_items', 'stock_movements', 'suppliers', 'customers',
            'menu_categories', 'menu_items', 'recipes', 'recipe_ingredients',
            'expenses', 'cashier_shifts', 'reservations', 'riders',
            'promotions', 'printers', 'devices', 'employee_profiles',
            'floors', 'dining_tables', 'stock_adjustments', 'notification_queue',
            // V62.2 — staff aur unki permissions. Yeh pehle list mein thin
            // hi nahi, is liye node par banaye gaye users/modules kabhi
            // cloud par nazar nahi aate the.
            'users', 'user_roles', 'roles', 'role_modules',
            'user_module_access', 'user_form_permissions',
            'sync_tombstones',
        ],
        // Cloud -> Local PULL: sirf master/reference data neeche aati hai
        // (head-office se edit hone wali). ui_records per-branch hai -> pull nahi.
        'pull_tables' => [
            'menu_categories', 'menu_items', 'suppliers',
            'promotions', 'printers',
            // V62.2 — head office se banaye gaye staff aur unki permissions
            // branch par bhi aani chahiyen.
            'users', 'user_roles', 'roles', 'role_modules',
            'user_module_access', 'user_form_permissions',
            'sync_tombstones',
        ],
    ],
];

// build: V17.1 build 2026-08-25
