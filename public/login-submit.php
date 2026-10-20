<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\Auth;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /login.html');
    exit;
}

$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    header('Location: /login.html?login_error=required');
    exit;
}

$bq = '';
if (!empty($_SESSION['login_tenant_slug'])) {
    $bq = '&b=' . rawurlencode((string)$_SESSION['login_tenant_slug']);
}

try {
    if (!Auth::login($email, $password)) {
        header('Location: /login.html?login_error=invalid'.$bq);
        exit;
    }

    // Subscription enforcement (cloud): expired / suspended -> block with reason.
    $tid = (string)($_SESSION['user']['tenant_id'] ?? '');
    if ($tid !== '' && ($msg = Auth::subscriptionBlock($tid)) !== null) {
        Auth::logout();
        header('Location: /login.html?login_error=blocked&reason='.rawurlencode($msg).''.$bq);
        exit;
    }

    /*
     * Force PHP to flush the authenticated session to disk BEFORE
     * redirecting to the protected Dashboard request.
     */
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    /* ============================================================
       V160 — LOGIN KE BAAD WAHIN WAPIS JAHAN SE AAYA THA.

       Pehle login ke baad hamesha `/index.html` khulta tha. Malik ko
       owner app ka link bheja jaye, wo kholay, login karay — aur
       saamne dashboard aa jaye. Phir usay khud dobara link kholna
       parta. Chhoti baat hai, magar pehli hi dafa mein bura tajurba.

       Sirf GINI CHUNI andar ki jagahen qubool hain. "Jo bhi path
       aaye wahan bhej do" wala rasta khula chhorna khatarnak hai:
       link mein bahar ki website daal kar bande ko login ke foran
       baad kisi naqli safhay par bheja ja sakta hai.
       ============================================================ */
    $next = (string)($_SESSION['after_login'] ?? '');
    unset($_SESSION['after_login']);
    $allowed = ['/owner_mobile.html'];

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Location: '.(in_array($next, $allowed, true) ? $next : '/index.html'), true, 303);
    exit;
} catch (Throwable $e) {
    /*
     * Do not expose DB/password details in the browser.
     * Full PHP errors stay in storage/logs/php-error.log.
     */
    error_log('LOGIN SUBMIT ERROR: '.$e->getMessage());
    header('Location: /login.html?login_error=server');
    exit;
}

// build: V17.1 build 2026-08-25
