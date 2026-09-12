<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * TAX MODE — are the prices on the POS tax-inclusive or tax-exclusive?
 *
 * Two ways a shop quotes a price, and they are not interchangeable:
 *
 *   INCLUSIVE  Rs 117 on the shelf already contains 17% tax.
 *              The customer pays 117. Tax is pulled OUT of it:
 *                  tax  = 117 - (117 / 1.17)  = 17.00
 *                  net  = 100.00
 *              Common in Pakistan and the UK.
 *
 *   EXCLUSIVE  Rs 100 on the shelf, tax added at the till.
 *                  tax  = 100 * 0.17 = 17.00
 *                  total= 117.00
 *              Common in the US.
 *
 * WHY THIS HAD TO BECOME A SETTING
 * Retail was deciding this from the region profile (PK/UK inclusive, US
 * exclusive) and the restaurant was letting the POS browser send whatever
 * tax figure it had calculated. So two shops in the same country could not
 * differ, and the server never checked the restaurant's arithmetic.
 *
 * Now it is one setting per business, stored once, used by both verticals
 * and by FBR. Region still supplies the DEFAULT — nothing changes for an
 * existing shop until someone deliberately changes it.
 *
 * FBR: the digital invoice carries the taxable value and the tax amount
 * separately. Get the mode wrong and both numbers are wrong on every
 * invoice, so this setting decides what is reported, not just what prints.
 */
final class TaxMode
{
    public const INCLUSIVE = 'INCLUSIVE';
    public const EXCLUSIVE = 'EXCLUSIVE';

    /** Effective mode for this site. */
    public static function current(): string
    {
        $saved = self::saved();
        if ($saved !== null) return $saved;
        return self::regionDefault();
    }

    /** Explicitly saved value, or null if the business never chose one. */
    public static function saved(): ?string
    {
        try {
            $q = DB::pdo()->prepare(
                "SELECT value_json FROM site_settings
                  WHERE tenant_id=? AND site_id=? AND setting_group='tax' AND setting_key='mode'
                  LIMIT 1");
            $q->execute([tenant_id(), site_id()]);
            $v = $q->fetchColumn();
            if ($v === false || $v === null) return null;
            $d = \json_decode((string)$v, true);
            $d = \is_string($d) ? \strtoupper($d) : '';
            return \in_array($d, [self::INCLUSIVE, self::EXCLUSIVE], true) ? $d : null;
        } catch (\Throwable $e) { return null; }
    }

    /** Region ka default — pehle se chal rahe businesses ka behaviour na badle. */
    public static function regionDefault(): string
    {
        try {
            $q = DB::pdo()->prepare("SELECT region_profile FROM tenants WHERE id=? LIMIT 1");
            $q->execute([tenant_id()]);
            $region = \strtoupper((string)($q->fetchColumn() ?: 'PK'));
        } catch (\Throwable $e) { $region = 'PK'; }

        try {
            $p = RegionProfile::get($region);
            $m = \strtoupper((string)($p['price_mode'] ?? self::INCLUSIVE));
            return \in_array($m, [self::INCLUSIVE, self::EXCLUSIVE], true) ? $m : self::INCLUSIVE;
        } catch (\Throwable $e) { return self::INCLUSIVE; }
    }

    public static function set(string $mode): string
    {
        $mode = \strtoupper(\trim($mode));
        if (!\in_array($mode, [self::INCLUSIVE, self::EXCLUSIVE], true)) {
            throw new \RuntimeException('Tax mode must be INCLUSIVE or EXCLUSIVE');
        }
        $pdo = DB::pdo();
        $pdo->prepare(
            "DELETE FROM site_settings
              WHERE tenant_id=? AND site_id=? AND setting_group='tax' AND setting_key='mode'")
            ->execute([tenant_id(), site_id()]);
        $pdo->prepare(
            "INSERT INTO site_settings(id,tenant_id,site_id,setting_group,setting_key,value_json)
             VALUES(?,?,?,'tax','mode',?)")
            ->execute([\uuid(), tenant_id(), site_id(), \json_encode($mode)]);
        return $mode;
    }

    public static function isInclusive(): bool { return self::current() === self::INCLUSIVE; }

    /**
     * Split a line/bill amount into net and tax according to the mode.
     *
     * @param float $amount  INCLUSIVE: the amount the customer pays.
     *                       EXCLUSIVE: the amount before tax.
     * @return array{net:float,tax:float,gross:float}
     */
    public static function split(float $amount, float $ratePct, ?string $mode = null): array
    {
        $mode = $mode ?: self::current();
        $rate = \max(0.0, $ratePct) / 100;

        if ($rate <= 0) {
            return ['net' => \round($amount, 2), 'tax' => 0.0, 'gross' => \round($amount, 2)];
        }

        if ($mode === self::INCLUSIVE) {
            $net = $amount / (1 + $rate);
            $tax = $amount - $net;
            return ['net' => \round($net, 2), 'tax' => \round($tax, 2), 'gross' => \round($amount, 2)];
        }

        $tax = $amount * $rate;
        return ['net' => \round($amount, 2), 'tax' => \round($tax, 2), 'gross' => \round($amount + $tax, 2)];
    }

    /** Screen par dikhane ke liye. */
    public static function describe(?string $mode = null): array
    {
        $mode = $mode ?: self::current();
        $inc  = $mode === self::INCLUSIVE;
        return [
            'mode'     => $mode,
            'label'    => $inc ? 'Tax included in the price' : 'Tax added at the till',
            'example'  => $inc
                ? 'A shelf price of 117 at 17% means 100 net + 17 tax. The customer pays 117.'
                : 'A shelf price of 100 at 17% means 100 net + 17 tax. The customer pays 117.',
            'saved'    => self::saved() !== null,
            'default'  => self::regionDefault(),
            'fbr_on'   => FiscalService::enabledForTenant(),
        ];
    }
}
