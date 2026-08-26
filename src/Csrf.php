<?php
namespace Aio;

/**
 * CSRF token.
 *
 * V62 fix — PEHLE YEH KHUD EK KHAMOSH FAILURE THI:
 *
 *     http_response_code(419); exit('Invalid CSRF token.');
 *
 * Do masle the:
 *  1) 419 ek non-standard status hai. Apache (mod_php) usay reason-phrase
 *     ke baghair aage nahi bhej pata aur browser tak **500** pohanchta hai.
 *     User ko "Server returned HTTP 500 (not JSON)" dikhta tha — jo ghalat
 *     bhi hai aur be-maani bhi.
 *  2) Jawab plain text tha, JSON nahi. Har client parse par toot jata tha.
 *
 * Ab yeh exception phenkta hai; api.php usay pakar kar saaf JSON deta hai
 * (403, jo Apache theek se aage bhejta hai) aur client naya token le kar
 * ek dafa khud retry kar leta hai.
 */
final class Csrf {
    public static function token(): string {
        if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }

    public static function verifyOrFail(string $token): void {
        if (!$token || !hash_equals($_SESSION['_csrf'] ?? '', $token)) {
            throw new \RuntimeException('CSRF_MISMATCH');
        }
    }

    /** Diagnostics ke liye: session mein token maujood hai ya nahi. */
    public static function has(): bool { return !empty($_SESSION['_csrf']); }
}

// build: V62 build 2026-08-26
