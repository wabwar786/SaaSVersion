<?php
// CLOUD config for hosting (Railway/VPS). Reads secrets from ENVIRONMENT.
// Set AIO_CONFIG=config/cloud.php in the hosting environment to use this.
$env = fn($k,$d=null)=>(getenv($k)!==false && getenv($k)!=='')?getenv($k):$d;
return [
    'app' => [
        'name' => $env('APP_NAME','All-in-One Platform (Cloud)'),
        'env' => 'production',
        'role' => 'cloud',
        'debug' => $env('APP_DEBUG','0')==='1',
        'timezone' => $env('APP_TZ','Asia/Karachi'),
        'base_url' => rtrim($env('APP_BASE_URL','https://your-app.up.railway.app'),'/'),
        // Cloud is multi-tenant: these are only fallbacks; real tenant comes from login/slug.
        'tenant_id' => '11111111-1111-1111-1111-111111111111',
        'organization_id' => '22222222-2222-2222-2222-222222222222',
        'site_id' => '33333333-3333-3333-3333-333333333333',
    ],
    'db' => [
        // Supports explicit DB_* or Railway's MYSQL* variable names.
        'host' => $env('DB_HOST', $env('MYSQLHOST','127.0.0.1')),
        'port' => (int)$env('DB_PORT', $env('MYSQLPORT','3306')),
        'database' => $env('DB_NAME', $env('MYSQLDATABASE','aio_cloud')),
        'username' => $env('DB_USER', $env('MYSQLUSER','root')),
        'password' => $env('DB_PASS', $env('MYSQLPASSWORD','')),
        'charset' => 'utf8mb4',
    ],
    'sync' => [
        'enabled' => true,
        'cloud_api_url' => '',            // cloud is the cloud
        'token' => $env('SYNC_TOKEN','CHANGE-THIS-LONG-RANDOM-SYNC-TOKEN'),
        'interval_minutes' => 5,
        'batch' => 300,
        'push_tables' => [],
        'pull_tables' => [],
    ],
];
