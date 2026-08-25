<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\Auth;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /login.html?build=v14');
    exit;
}

$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    header('Location: /login.html?login_error=required&build=v14');
    exit;
}

$bq = '';
if (!empty($_SESSION['login_tenant_slug'])) {
    $bq = '&b=' . rawurlencode((string)$_SESSION['login_tenant_slug']);
}

try {
    if (!Auth::login($email, $password)) {
        header('Location: /login.html?login_error=invalid&build=v14'.$bq);
        exit;
    }

    // Subscription enforcement (cloud): expired / suspended -> block with reason.
    $tid = (string)($_SESSION['user']['tenant_id'] ?? '');
    if ($tid !== '' && ($msg = Auth::subscriptionBlock($tid)) !== null) {
        Auth::logout();
        header('Location: /login.html?login_error=blocked&reason='.rawurlencode($msg).'&build=v14'.$bq);
        exit;
    }

    /*
     * Force PHP to flush the authenticated session to disk BEFORE
     * redirecting to the protected Dashboard request.
     */
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Location: /index.html?build=v14', true, 303);
    exit;
} catch (Throwable $e) {
    /*
     * Do not expose DB/password details in the browser.
     * Full PHP errors stay in storage/logs/php-error.log.
     */
    error_log('LOGIN SUBMIT ERROR: '.$e->getMessage());
    header('Location: /login.html?login_error=server&build=v14');
    exit;
}

// build: V17.1 build 2026-08-25
