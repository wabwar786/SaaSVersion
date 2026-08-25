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

try {
    if (!Auth::login($email, $password)) {
        header('Location: /login.html?login_error=invalid&build=v14');
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
