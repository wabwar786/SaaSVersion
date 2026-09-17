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

/* ============================================================
   ERROR HANDLERS

   Pehle errors kahin nahi jate the: `display_errors=Off` (theek hai) aur
   `log_errors=On` unhein PHP ke apne log mein daal deta tha — Railway par
   container logs mein, node par ek file mein jo koi nahi kholta. Yani jo
   cheez toot rahi hoti thi uska pata sirf customer ki shikayat se chalta.

   Ab teen raaste band kiye ja rahe hain:
     - exception jo kisi ne na pakri
     - warnings / notices
     - FATAL error (yeh sab se ahem hai — shutdown ke baghair yeh
       bilkul chup chaap gayab ho jate hain)

   Har handler apne andar se kuch nahi phenkta. Error log ka toot jana
   asal kaam ko na roke.
   ============================================================ */
(static function (): void {

    $log = static function (string $msg, string $level, string $src,
                            ?string $file = null, ?int $line = null, ?string $trace = null): void {
        try {
            if (!class_exists('Aio\\Services\\ErrorLog', true)) return;
            \Aio\Services\ErrorLog::record($msg, $level, $src, $file, $line, $trace);
        } catch (\Throwable $e) { /* chup */ }
    };

    $src = (PHP_SAPI === 'cli') ? 'cli' : (str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), 'api.php') ? 'api' : 'page');

    set_exception_handler(static function (\Throwable $e) use ($log, $src): void {
        $log(get_class($e) . ': ' . $e->getMessage(), 'FATAL', $src,
             $e->getFile(), $e->getLine(),
             implode("\n", array_slice(explode("\n", $e->getTraceAsString()), 0, 6)));
        /* PHP ka apna behaviour barqarar — response wahi rahe jo pehle tha. */
        if (PHP_SAPI === 'cli') { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
    });

    set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0) use ($log, $src): bool {
        /* @ se dabaye gaye errors ko chhor do — wo jaan boojh kar dabaye gaye hain. */
        if (!(error_reporting() & $no)) return false;
        $level = in_array($no, [E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING], true)
                 ? 'WARN' : 'ERROR';
        $log($str, $level, $src, $file, $line);
        return false;   /* PHP apna kaam bhi kare */
    });

    register_shutdown_function(static function () use ($log, $src): void {
        $e = error_get_last();
        if (!$e) return;
        if (!in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        $log($e['message'], 'FATAL', $src, $e['file'] ?? null, (int)($e['line'] ?? 0));
    });
})();
