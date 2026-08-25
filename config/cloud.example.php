<?php
// ============================================================
//  CLOUD (hosting) configuration — online central node.
//  Isko hosting par "config/local.php" ke naam se copy karein
//  (ya deployment mein isi file ko use karwayein) aur apni
//  cloud MySQL details + wahi sync token daal dein.
// ============================================================
return [
    'app' => [
        'name' => 'All-in-One Restaurant OS (Cloud)',
        'env' => 'production',
        'role' => 'cloud',           // cloud node
        'debug' => false,
        'timezone' => 'Asia/Karachi',
        'base_url' => 'https://app.yourdomain.com',
        'tenant_id' => '11111111-1111-1111-1111-111111111111',
        'organization_id' => '22222222-2222-2222-2222-222222222222',
        'site_id' => '33333333-3333-3333-3333-333333333333',
    ],
    'db' => [
        'host' => '127.0.0.1',           // hosting MySQL host
        'port' => 3306,
        'database' => 'aio_cloud',       // hosting database name
        'username' => 'CLOUD_DB_USER',
        'password' => 'CLOUD_DB_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'sync' => [
        'enabled' => true,
        'cloud_api_url' => '',           // cloud khud cloud hai — khaali
        'token' => 'CHANGE-THIS-LONG-RANDOM-SYNC-TOKEN', // wahi token jo local mein hai
        'interval_minutes' => 5,
        'batch' => 300,
        'push_tables' => [],             // cloud kahin push nahi karta
        'pull_tables' => [],
    ],
];
