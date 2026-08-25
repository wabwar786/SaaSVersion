<?php
namespace Aio;
final class Csrf {
    public static function token(): string {
        if (empty($_SESSION['_csrf'])) $_SESSION['_csrf']=bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }
    public static function verifyOrFail(string $token): void {
        if (!$token || !hash_equals($_SESSION['_csrf']??'', $token)) { http_response_code(419); exit('Invalid CSRF token.'); }
    }
}
