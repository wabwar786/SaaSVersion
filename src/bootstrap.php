<?php
declare(strict_types=1);

$sessionDir = dirname(__DIR__) . '/storage/sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0775, true);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('AIO_RESTAURANT_V14_SESSION');
    session_save_path($sessionDir);

    /* ============================================================
       V62.1 — 24 MINUTE WALA KHAMOSH LOGOUT.

       Docker image (php:8.2-apache) mein koi php.ini hai hi nahi, is
       liye PHP ke built-in defaults chalte the:

         session.gc_maxlifetime = 1440   (SIRF 24 MINUTE)
         session.gc_probability = 1
         session.gc_divisor     = 100    (~har 100 requests par GC)
         session.lazy_write     = 1

       Do alag tareeqon se yeh maar deta tha:

       1) 24 minute ki khamoshi ke baad session file GC uda deta tha.
          Restaurant mein yeh rozana hota hai - cashier zara der bill
          na kaate, aur wapas aa kar sab kuch fail.

       2) `lazy_write=1` ka matlab: agar session ka DATA na badle to PHP
          file dobara likhta hi nahi, is liye uska mtime purana rehta
          hai. Yani BAR BAR ISTEMAL HOTA HUA session bhi 24 minute baad
          GC ka shikaar ho jata tha, chahe user musalsal kaam kar raha ho.

       Nateeja user ke liye: login "nahi lagta", aur har save/delete par
       "Invalid CSRF token" (jo Apache 500 bana kar dikhata tha). Asli
       wajah kahin nazar nahi aati thi - PHP ka session warning
       display_errors=0 ki wajah se khamosh tha.

       Ab: 12 ghante ka session (ek poori shift), aur har request par
       mtime refresh taake active user kabhi na nikale.
       ============================================================ */
    $sessionLife = 12 * 60 * 60;   // 12 ghante = ek poori shift
    @ini_set('session.gc_maxlifetime', (string)$sessionLife);
    @ini_set('session.gc_probability', '1');
    @ini_set('session.gc_divisor', '1000');
    /* Har request par file dobara likho taake mtime taza rahe. Iske
       baghair chalta hua session bhi GC kha jata hai. */
    @ini_set('session.lazy_write', '0');

    /* Railway HTTPS par hai; proxy ke peechhe HTTPS ka pata
       X-Forwarded-Proto se chalta hai. Cookie sirf tab Secure ho jab
       waqai HTTPS ho, warna local HTTP node par login toot jayega. */
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'cookie_path'     => '/',
        'cookie_secure'   => $https,
        'cookie_lifetime' => $sessionLife,
        'gc_maxlifetime'  => $sessionLife,
    ]);

    /* Cookie ki expiry bhi aage barhao, warna browser 12 ghante ki
       ginti login ke waqt se karta hai aur beech shift mein cookie
       khatam ho jati hai. */
    if (session_id() !== '') {
        @setcookie(session_name(), session_id(), [
            'expires'  => time() + $sessionLife,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $https,
        ]);
    }
}

// AIO_CONFIG env var can point to an alternate config (e.g. cloud deployment).
// Falls back to the standard local branch config.
$envConfig = getenv('AIO_CONFIG');
if ($envConfig && !preg_match('#^(/|[A-Za-z]:)#', $envConfig)) {
    $envConfig = dirname(__DIR__) . '/' . ltrim($envConfig, '/');
}
$configFile = ($envConfig && is_file($envConfig)) ? $envConfig : (dirname(__DIR__) . '/config/local.php');
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Missing config/local.php');
}
$GLOBALS['config'] = require $configFile;
date_default_timezone_set($GLOBALS['config']['app']['timezone'] ?? 'UTC');

spl_autoload_register(function(string $class): void {
    $prefix = 'Aio\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require $file;
});

require_once __DIR__ . '/helpers.php';

// build: V17.1 build 2026-08-25
