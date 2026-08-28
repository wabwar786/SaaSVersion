<?php
namespace Aio\Services;

use Aio\Auth;

/**
 * Scope — kaun kis ka data dekh sakta hai. EK jagah, sab ke liye.
 *
 * MASLA JO YEH HAL KARTA HAI
 * Ab tak har report aur har list apne tor par filter lagati thi, aur
 * cashier ka user id aksar CLIENT se aata tha. Yani ek cashier request
 * mein user id badal kar doosre cashier ki sales dekh sakta tha. Yeh
 * sirf UI mein button chhupane se theek nahi hota.
 *
 * Ab usool yeh hai:
 *   - Cashier ko HAMESHA sirf apna data milta hai, aur uska user id
 *     SESSION se aata hai — client jo bheje us se koi farq nahi parta.
 *   - Manager / Owner sab dekh sakte hain, aur filter laga sakte hain.
 *
 * Har naye report/list mein `Scope::orderWhere()` lagana lazmi hai.
 * Bhool jane par cashier ko sab dikhne lagega — is liye yeh helper
 * jaan-boojh kar itna chhota rakha hai ke use karna asaan ho.
 */
final class Scope
{
    /** Manager/Owner? (Cashier nahi) */
    public static function isManagement(): bool
    {
        return Auth::isAdmin() || Auth::isManager();
    }

    /** Logged-in user ka id — client se KABHI nahi. */
    public static function userId(): ?string
    {
        return Auth::user()['id'] ?? null;
    }

    /**
     * Orders par WHERE ka tukra.
     *
     * @param string      $alias      orders table ka alias
     * @param string|null $wantUserId manager ka chuna hua cashier (cashier ke liye ignore)
     * @return array{0:string,1:array}  [sql, args]
     */
    public static function orderWhere(string $alias = 'o', ?string $wantUserId = null): array
    {
        if (!self::isManagement()) {
            /* CASHIER — sirf apna. `$wantUserId` jaan-boojh kar nazar-andaz
               kiya jata hai, chahe client kuch bhi bheje. */
            return ["$alias.created_by_user_id = ?", [self::userId()]];
        }
        if ($wantUserId !== null && $wantUserId !== '') {
            return ["$alias.created_by_user_id = ?", [$wantUserId]];
        }
        return ['1=1', []];
    }

    /** Shifts par wahi usool. */
    public static function shiftWhere(string $alias = 'cs', ?string $wantUserId = null): array
    {
        if (!self::isManagement()) {
            return ["$alias.cashier_user_id = ?", [self::userId()]];
        }
        if ($wantUserId !== null && $wantUserId !== '') {
            return ["$alias.cashier_user_id = ?", [$wantUserId]];
        }
        return ['1=1', []];
    }

    /** Management-only kaam — backend par bhi roko, sirf UI mein nahi. */
    public static function requireManagement(string $what = 'this action'): void
    {
        if (!self::isManagement()) {
            throw new \RuntimeException('You do not have permission to perform ' . $what . '.');
        }
    }

    /**
     * Kya yeh shift is user ki hai (ya user management hai)?
     * Closing report reprint aur shift close par lagta hai.
     */
    public static function ownsShift(string $shiftId): bool
    {
        if (self::isManagement()) return true;
        try {
            $q = \Aio\DB::pdo()->prepare("SELECT cashier_user_id FROM cashier_shifts WHERE id=? AND site_id=? LIMIT 1");
            $q->execute([$shiftId, site_id()]);
            return (string)$q->fetchColumn() === (string)self::userId();
        } catch (\Throwable $e) { return false; }
    }

    /**
     * Cashier ki apni khuli shift. Sale Point isi par khulta hai.
     * @return array|null
     */
    public static function openShift(): ?array
    {
        try {
            $q = \Aio\DB::pdo()->prepare(
                "SELECT id, shift_no, opened_at, opening_cash, counter_name
                   FROM cashier_shifts
                  WHERE site_id=? AND cashier_user_id=? AND status='OPEN' AND deleted_at IS NULL
                  ORDER BY opened_at DESC LIMIT 1");
            $q->execute([site_id(), self::userId()]);
            $r = $q->fetch(\PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Bill banane se pehle: shift khuli honi chahiye.
     * Yeh sirf UI ka gate nahi — server par bhi rukta hai, warna koi
     * seedha API par bill bana sakta hai.
     */
    public static function requireOpenShift(): array
    {
        $s = self::openShift();
        if (!$s) {
            throw new \RuntimeException(
                'Your cash counter shift is closed. Please open a new shift before creating a sale.');
        }
        return $s;
    }
}

// build: V77 build 2026-08-28
