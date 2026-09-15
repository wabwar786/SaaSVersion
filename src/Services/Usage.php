<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * USAGE — how much is each business actually using the software?
 *
 * The Businesses list answers "who exists". It never answered "who is
 * alive". A business can look perfectly healthy in the list — licence
 * valid, users created — and not have rung up a single bill in three
 * weeks. That is the account you lose at renewal, and the one you want
 * to hear about first.
 *
 * Definitions used here, stated plainly so the numbers are not argued
 * about later:
 *
 *   ACTIVE DAY   a day with at least one bill. Not a login — signing in
 *                and doing nothing is not usage.
 *   USAGE %      active days ÷ days in the window. 30/30 = every day.
 *   MODULES USED distinct modules touched in the audit log. Shows
 *                whether they bought a POS and use only the POS.
 *
 * Everything is per tenant, over a window of days (default 30).
 */
final class Usage
{
    /** @return array<int,array<string,mixed>> */
    public static function report(int $days = 30): array
    {
        $days = \max(1, \min(365, $days));
        $from = \date('Y-m-d', \strtotime("-{$days} day"));
        $pdo  = DB::pdo();

        $tenants = $pdo->query(
            "SELECT id, COALESCE(NULLIF(display_name,''),name) name, slug,
                    COALESCE(NULLIF(industry_code,''),'RESTAURANT') industry,
                    status, is_demo, created_at
               FROM tenants
              WHERE status <> 'DELETED'
              ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        /* Restaurant aur retail ke bills alag tables mein hain. Dono ko
           ek hi shakl mein la kar jama karte hain. */
        $rest = self::group($pdo,
            "SELECT tenant_id, DATE(COALESCE(closed_at,created_at)) d,
                    COUNT(*) bills, COALESCE(SUM(grand_total),0) amount
               FROM orders
              WHERE DATE(COALESCE(closed_at,created_at)) >= ?
                AND COALESCE(order_status,'') <> 'VOID'
              GROUP BY tenant_id, d", [$from]);

        $retail = self::group($pdo,
            "SELECT tenant_id, DATE(sold_at) d,
                    COUNT(*) bills, COALESCE(SUM(total),0) amount
               FROM rtl_sales
              WHERE DATE(sold_at) >= ? AND deleted_at IS NULL
              GROUP BY tenant_id, d", [$from]);

        $mods  = self::keyed($pdo,
            "SELECT tenant_id, COUNT(DISTINCT module) n
               FROM audit_log WHERE DATE(created_at) >= ? GROUP BY tenant_id", [$from]);

        $users = self::keyed($pdo,
            "SELECT tenant_id, COUNT(*) n FROM users
              WHERE status='ACTIVE' AND deleted_at IS NULL GROUP BY tenant_id", []);

        $out = [];
        foreach ($tenants as $t) {
            $tid  = $t['id'];
            $byDay = ($rest[$tid] ?? []) + ($retail[$tid] ?? []);
            foreach (($retail[$tid] ?? []) as $d => $row) {
                if (isset($rest[$tid][$d])) {
                    $byDay[$d] = ['bills' => $rest[$tid][$d]['bills'] + $row['bills'],
                                  'amount' => $rest[$tid][$d]['amount'] + $row['amount']];
                }
            }

            $activeDays = \count($byDay);
            $bills = 0; $amount = 0.0; $last = null;
            foreach ($byDay as $d => $row) {
                $bills  += (int)$row['bills'];
                $amount += (float)$row['amount'];
                if ($last === null || $d > $last) $last = $d;
            }

            /* Naya business poore window ka zimmedar nahi — jitne din
               se maujood hai utne hi gine jayen, warna kal bana hua
               business hamesha "3% usage" dikhega. */
            $ageDays = \max(1, (int)\floor((\time() - \strtotime((string)$t['created_at'])) / 86400) + 1);
            $window  = \min($days, $ageDays);
            $pct     = $window > 0 ? \round($activeDays / $window * 100) : 0;

            $idle = $last ? (int)\floor((\strtotime(\date('Y-m-d')) - \strtotime($last)) / 86400) : null;

            $out[] = [
                'tenant_id'   => $tid,
                'name'        => $t['name'],
                'slug'        => $t['slug'],
                'industry'    => $t['industry'],
                'status'      => $t['status'],
                'is_demo'     => (int)$t['is_demo'] === 1,
                'window_days' => $window,
                'active_days' => $activeDays,
                'usage_pct'   => $pct,
                'bills'       => $bills,
                'bills_per_day' => $activeDays ? \round($bills / $activeDays, 1) : 0,
                'amount'      => \round($amount, 2),
                'last_bill'   => $last,
                'idle_days'   => $idle,
                'users'       => (int)($users[$tid] ?? 0),
                'modules_used'=> (int)($mods[$tid] ?? 0),
                'health'      => self::health($pct, $idle, $activeDays),
            ];
        }

        \usort($out, fn($a, $b) => $b['usage_pct'] <=> $a['usage_pct']
                                ?: $b['bills'] <=> $a['bills']);
        return $out;
    }

    /**
     * Ek lafz mein haal — taake nazar pehle wahan jaye jahan zaroorat hai.
     * Sirf usage %, kyunke chhutti aur band dukan ka farq usage nahi jaanti.
     */
    private static function health(int $pct, ?int $idle, int $activeDays): string
    {
        if ($activeDays === 0)              return 'NEVER_USED';
        if ($idle !== null && $idle >= 14)  return 'AT_RISK';
        if ($idle !== null && $idle >= 7)   return 'SLOWING';
        if ($pct >= 70)                     return 'HEALTHY';
        if ($pct >= 30)                     return 'LIGHT';
        return 'RARE';
    }

    /** Ek business ki rozana tafseel — chart ke liye. */
    public static function daily(string $tenantId, int $days = 30): array
    {
        $days = \max(1, \min(365, $days));
        $pdo  = DB::pdo();
        $out  = [];
        for ($i = $days - 1; $i >= 0; $i--) $out[\date('Y-m-d', \strtotime("-{$i} day"))] = ['bills' => 0, 'amount' => 0.0];

        foreach ([
            ["SELECT DATE(COALESCE(closed_at,created_at)) d, COUNT(*) bills, COALESCE(SUM(grand_total),0) amount
                FROM orders WHERE tenant_id=? AND DATE(COALESCE(closed_at,created_at)) >= ?
                  AND COALESCE(order_status,'') <> 'VOID' GROUP BY d"],
            ["SELECT DATE(sold_at) d, COUNT(*) bills, COALESCE(SUM(total),0) amount
                FROM rtl_sales WHERE tenant_id=? AND DATE(sold_at) >= ? AND deleted_at IS NULL GROUP BY d"],
        ] as $q) {
            try {
                $st = $pdo->prepare($q[0]);
                $st->execute([$tenantId, \date('Y-m-d', \strtotime("-{$days} day"))]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (!isset($out[$r['d']])) continue;
                    $out[$r['d']]['bills']  += (int)$r['bills'];
                    $out[$r['d']]['amount'] += (float)$r['amount'];
                }
            } catch (\Throwable $e) { /* table na ho to chhor do */ }
        }

        $rows = [];
        foreach ($out as $d => $v) $rows[] = ['date' => $d, 'bills' => $v['bills'], 'amount' => \round($v['amount'], 2)];
        return $rows;
    }

    private static function group(PDO $pdo, string $sql, array $args): array
    {
        $out = [];
        try {
            $st = $pdo->prepare($sql); $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['tenant_id']][$r['d']] = ['bills' => (int)$r['bills'], 'amount' => (float)$r['amount']];
            }
        } catch (\Throwable $e) { /* vertical maujood na ho */ }
        return $out;
    }

    private static function keyed(PDO $pdo, string $sql, array $args): array
    {
        $out = [];
        try {
            $st = $pdo->prepare($sql); $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['tenant_id']] = (int)$r['n'];
        } catch (\Throwable $e) { }
        return $out;
    }
}
