<?php
return [
    'app' => [
        'name' => 'All-in-One Restaurant OS',
        'env' => 'local',
        'debug' => false,
        'timezone' => 'Asia/Karachi',
        'base_url' => 'http://127.0.0.1:8080',
        'tenant_id' => 'YOUR-TENANT-UUID',
        'organization_id' => 'YOUR-ORG-UUID',
        'site_id' => 'YOUR-SITE-UUID',
        'sync_enabled' => false,
        'cloud_api_url' => '',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'aio_local',
        'username' => 'YOUR_LOCAL_DB_USER',
        'password' => 'YOUR_LOCAL_DB_PASSWORD',
        'charset' => 'utf8mb4',
    ],
];

// build: V17.1 build 2026-08-25
