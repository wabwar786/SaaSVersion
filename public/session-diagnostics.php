<?php
/**
 * session-diagnostics.php — SESSION AUR CSRF KI ASLI HAALAT.
 *
 * V62 mein barha diya gaya. Reason: "Invalid CSRF token" aur "login nahi ho
 * raha" dono ek hi cheez ki alamat hain — session persist nahi ho rahi —
 * magar yeh baat kahin nazar nahi aati thi. session_start() ka failure
 * khamosh hota hai (display_errors=0), is liye app bilkul theek lagti hai
 * aur har POST khamoshi se fail hota rehta hai.
 *
 * Kholein:  https://<app>/session-diagnostics.php
 *
 * Sab se ahem line `session_dir_writable` hai. `false` ho to session har
 * request par nayi banti hai — CSRF kabhi match nahi karega aur login
 * kabhi tikega nahi, chahe password bilkul sahi ho.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\Auth;
use Aio\Csrf;
use Aio\Services\Platform;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dir = session_save_path() ?: '(php default)';
$writable = is_dir($dir) && is_writable($dir);

$files = -1;
if (is_dir($dir)) {
    $g = @glob(rtrim($dir, '/') . '/sess_*');
    $files = is_array($g) ? count($g) : -1;
}

$cookieName = session_name();
$cookieSent = $_COOKIE[$cookieName] ?? null;
$sid        = session_id();

$u = Auth::user();
$su = null;
try { $su = Platform::superUser(); } catch (\Throwable $e) {}

/* Sab se kaam ki alamat: browser ne cookie bheji thi magar server ne naya
   session id bana diya = purani session file gayab hai (container restart)
   YA session likhi hi nahi ja rahi (permissions). */
$sessionLost = ($cookieSent !== null && $cookieSent !== $sid);

$verdict = 'OK';
$advice  = 'Sessions are working fine.';
if (!$writable) {
    $verdict = 'BROKEN';
    $advice  = 'The session directory is not writable. A new session is created on every request, '
             . 'so the sign-in never sticks and CSRF fails on every POST. '
             . 'Run inside the container: mkdir -p storage/sessions && chown -R www-data:www-data storage';
} elseif ($sessionLost) {
    $verdict = 'SESSION_RESET';
    $advice  = 'The browser is sending an old session cookie, but that session no longer exists on the server '
             . '(aam tor par deploy / container restart ke after). Ek dafa logout kar ke again login please, '
             . 'or clear cookies for this site. The V62 client fetches a new CSRF token itself, '
             . 'but signing in needs a fresh page.';
} elseif (((int)ini_get('session.gc_maxlifetime')) < 3600) {
    $verdict = 'SHORT_LIFETIME';
    $advice  = 'session.gc_maxlifetime only ' . ini_get('session.gc_maxlifetime') . ' seconds. '
             . 'After that much idle time the user is silently signed out and every POST '
             . 'CSRF fails. the bootstrap.php in V62.1 raises this to 12 hours — '
             . 'an old build is probably running.';
} elseif (!Csrf::has()) {
    $verdict = 'NO_TOKEN';
    $advice  = 'No CSRF token has been created in this session yet. Open an app page (e.g. /login.html) '
             . '— the token is created there.';
}

echo json_encode([
    'ok'      => true,
    'verdict' => $verdict,
    'advice'  => $advice,

    'session' => [
        'name'                  => $cookieName,
        'id_present'            => $sid !== '',
        'cookie_received'       => $cookieSent !== null,
        'cookie_matches_id'     => $cookieSent === null ? null : ($cookieSent === $sid),
        'save_path'             => $dir,
        'session_dir_writable'  => $writable,
        'session_files_on_disk' => $files,
        'csrf_token_present'    => Csrf::has(),
        /* V62.1 — yahi wo do settings thin jinhon ne 24 minute baad
           khamoshi se logout kar dena tha. */
        'gc_maxlifetime_sec'    => (int)ini_get('session.gc_maxlifetime'),
        'gc_maxlifetime_human'  => round(((int)ini_get('session.gc_maxlifetime')) / 3600, 1) . ' hours',
        'lazy_write'            => (string)ini_get('session.lazy_write'),
        'cookie_secure'         => (bool)ini_get('session.cookie_secure'),
        'cookie_lifetime_sec'   => (int)ini_get('session.cookie_lifetime'),
    ],

    'auth' => [
        'restaurant_user' => $u ? ($u['email'] ?? $u['username'] ?? 'unknown') : null,
        'is_tenant_admin' => $u ? (bool)($u['is_tenant_admin'] ?? false) : false,
        'super_admin'     => $su ? ($su['email'] ?? 'unknown') : null,
    ],

    'app' => [
        'role'     => (string)cfg('app.role'),
        'php'      => PHP_VERSION,
        'build'    => trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION') ?: 'unknown'),
        'writable' => [
            'storage'      => is_writable(dirname(__DIR__) . '/storage'),
            'storage/logs' => is_writable(dirname(__DIR__) . '/storage/logs'),
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// build: V62 build 2026-08-26
