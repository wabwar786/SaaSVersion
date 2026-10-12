<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * InvoiceService — har business ka mahana parcha.   (V151)
 *
 * KYUN BANI
 * Paisay ka poora hisaab `subscription_payments` ki ek line par tha:
 * "itne rupay aaye". Us se yeh kabhi pata nahi chalta tha ke
 *   - kis mahine ke hain,
 *   - customer ko kabhi parcha bheja bhi gaya ya nahi,
 *   - aur is waqt kis kis ke zimme kitna baqi hai.
 *
 * Ab har mahine ka apna invoice hai, apne number ke saath, aur uski
 * teen halatein: UNPAID -> PAID (ya CANCELLED).
 *
 * TEEN USOOL
 *
 * 1. RATE AUR INVOICE ALAG CHEEZEIN HAIN.
 *    `tenants.billing_amount` aaj ka rate hai. Invoice us rate ki ek
 *    JAMI HUI copy hai. Rate kal badlo to purane invoice nahi badalte —
 *    warna bheja hua parcha aur uska record alag ho jate.
 *
 * 2. LINK PAR TOKEN HOTA HAI, LOGIN NAHI.
 *    Invoice WhatsApp par jata hai; customer ko login karwana wahan
 *    be-maani hai (khaas kar jab uska software expire ho chuka ho).
 *    Is liye har invoice ka apna 32-harf ka token hai. Number andaza
 *    lagaya ja sakta hai, token nahi.
 *
 * 3. DOBARA INVOICE NAHI BANTA.
 *    Ek period ka ek hi invoice. `ensureForExpiry()` dobara chalao to
 *    wahi purana wapas milta hai — warna har page refresh par naya
 *    parcha ban jata.
 */
final class InvoiceService
{
    public const UNPAID    = 'UNPAID';
    public const PAID      = 'PAID';
    public const CANCELLED = 'CANCELLED';

    /** Expiry se itne din pehle invoice khud ban jata hai. */
    public const LEAD_DAYS = 3;

    /* ==================== BANANA ==================== */

    /**
     * Naya invoice. Raqam na di jaye to business ka apna rate lagta hai.
     *
     * @param array $d tenant_id, months?, amount?, period_from?, note?, due_on?
     */
    public static function create(array $d, ?string $byUser = null): array
    {
        $p   = DB::pdo();
        $tid = (string)($d['tenant_id'] ?? '');
        if ($tid === '') throw new \RuntimeException('Which business is this invoice for?');

        $t = $p->prepare("SELECT id,name,billing_amount,billing_cycle FROM tenants
                           WHERE id=? AND deleted_at IS NULL LIMIT 1");
        $t->execute([$tid]);
        $ten = $t->fetch(PDO::FETCH_ASSOC);
        if (!$ten) throw new \RuntimeException('Business not found.');

        $cycle  = strtoupper((string)($ten['billing_cycle'] ?: 'MONTHLY'));
        $months = (int)($d['months'] ?? ($cycle === 'YEARLY' ? 12 : 1));
        $months = max(1, min(36, $months));

        /* Rate per cycle -> is parche ki raqam. */
        $rate = (float)$ten['billing_amount'];
        if (array_key_exists('amount', $d) && $d['amount'] !== '' && $d['amount'] !== null) {
            $amount = max(0.0, (float)$d['amount']);
        } else {
            $amount = ($cycle === 'YEARLY') ? ($rate / 12) * $months : $rate * $months;
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \RuntimeException(
                'Is business ka monthly rate set nahi hai. Licence control -> Billing rate mein daal dein.');
        }

        $tax     = self::taxPct();
        $taxAmt  = round($amount * $tax / 100, 2);
        $total   = round($amount + $taxAmt, 2);

        /* Period: jahan se licence shuru hoga. */
        $from = self::dateOrNull($d['period_from'] ?? '') ?: self::nextPeriodStart($p, $tid);
        $to   = self::addMonths($from, $months);
        $to   = self::addDays($to, -1);

        /* Ek period ka ek hi parcha. */
        $dupe = $p->prepare("SELECT id, invoice_no, public_token FROM invoices
                              WHERE tenant_id=? AND period_from=? AND status<>'CANCELLED' LIMIT 1");
        $dupe->execute([$tid, $from]);
        if ($old = $dupe->fetch(PDO::FETCH_ASSOC)) return self::get((string)$old['id']);

        $id    = \uuid();
        $token = \bin2hex(\random_bytes(16));
        $no    = self::nextNumber($p);
        $due   = self::dateOrNull($d['due_on'] ?? '') ?: $from;

        $p->prepare(
            "INSERT INTO invoices(id,invoice_no,tenant_id,business_name,period_from,period_to,months,
                                  amount,tax_pct,tax_amount,total,currency,status,note,issued_on,due_on,
                                  public_token,created_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,'PKR','UNPAID',?,CURDATE(),?,?,?)")
          ->execute([$id, $no, $tid, (string)$ten['name'], $from, $to, $months,
                     $amount, $tax, $taxAmt, $total, trim((string)($d['note'] ?? '')), $due,
                     $token, $byUser]);

        Audit::log('INVOICE_CREATE', 'billing', ['id' => $id, 'label' => (string)$ten['name'],
                   'new' => $no.' / PKR '.$total]);
        return self::get($id);
    }

    /**
     * Expiry se pehle ka khud-ba-khud wala parcha.
     * Pehle se mojood ho to wahi, warna naya. Kuch dena na ho to null.
     */
    public static function ensureForExpiry(string $tenantId): ?array
    {
        $p = DB::pdo();
        $q = $p->prepare(
            "SELECT s.expiry_date, t.name, t.billing_amount
               FROM tenants t
               LEFT JOIN tenant_subscriptions s
                      ON s.id = (SELECT s2.id FROM tenant_subscriptions s2
                                  WHERE s2.tenant_id = t.id ORDER BY s2.created_at DESC LIMIT 1)
              WHERE t.id=? AND t.deleted_at IS NULL LIMIT 1");
        $q->execute([$tenantId]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r || empty($r['expiry_date']))      return null;
        if ((float)$r['billing_amount'] <= 0)     return null;   // rate hi nahi, parcha kaisa

        $exp = substr((string)$r['expiry_date'], 0, 10);
        /* Sirf tab jab expiry qareeb ho (ya guzar chuki ho). */
        $lead = self::addDays($exp, -self::LEAD_DAYS);
        if (self::today() < $lead) return null;

        /* ============================================================
           EK WAQT MEIN EK HI BAQAYA PARCHA.

           Yeh shart na hoti to ek bhoolay hue (expired) business ka
           HAR DIN naya invoice ban jata: expiry guzar chuki ho to
           period aaj se shuru hota hai, aur "aaj" roz badalta hai.
           Mahine bhar mein 30 parche — aur hisaab tabah.

           Us ke zimme pehle se kuch baqi hai? Bas wahi.
           ============================================================ */
        $open = $p->prepare("SELECT id FROM invoices WHERE tenant_id=? AND status='UNPAID'
                              ORDER BY created_at DESC LIMIT 1");
        $open->execute([$tenantId]);
        if ($id = $open->fetchColumn()) return self::get((string)$id);

        /* Agle period ka parcha pehle se hai? */
        $from = self::addDays($exp, 1);
        $ex = $p->prepare("SELECT id FROM invoices WHERE tenant_id=? AND period_from=? AND status<>'CANCELLED' LIMIT 1");
        $ex->execute([$tenantId, $from]);
        if ($id = $ex->fetchColumn()) return self::get((string)$id);

        try { return self::create(['tenant_id' => $tenantId, 'period_from' => $from]); }
        catch (\Throwable $e) { return null; }
    }

    /** Saare businesses par ek dafa — cron/startup ke liye. */
    public static function runAutoInvoicing(): array
    {
        $out = ['checked' => 0, 'created' => 0];
        try {
            $rows = DB::pdo()->query(
                "SELECT id FROM tenants WHERE deleted_at IS NULL AND status<>'SUSPENDED'
                   AND billing_amount > 0")->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) { return $out; }
        foreach ($rows as $tid) {
            $out['checked']++;
            $before = self::countFor((string)$tid);
            $inv = self::ensureForExpiry((string)$tid);
            if ($inv && self::countFor((string)$tid) > $before) $out['created']++;
        }
        return $out;
    }

    /* ==================== HALAT ==================== */

    public static function markPaid(string $id, array $d = [], ?string $byUser = null): array
    {
        $p = DB::pdo();
        $inv = self::get($id);
        if (!$inv) throw new \RuntimeException('Invoice not found.');
        if ($inv['status'] === self::CANCELLED) throw new \RuntimeException('This invoice was cancelled.');
        if ($inv['status'] === self::PAID)      return $inv;

        $method = strtoupper(trim((string)($d['method'] ?? 'CASH'))) ?: 'CASH';
        $ref    = trim((string)($d['reference'] ?? ''));
        $amount = ($d['amount'] ?? null) !== null ? (float)$d['amount'] : (float)$inv['total'];

        /* Payment ka record wahi purani jagah par — taake Business
           detail ka payments wala khana aage bhi sahi rahe. */
        $payId = \uuid();
        try {
            $sq = $p->prepare("SELECT id FROM tenant_subscriptions WHERE tenant_id=? ORDER BY created_at DESC LIMIT 1");
            $sq->execute([$inv['tenant_id']]);
            $sub = $sq->fetchColumn() ?: null;
            $p->prepare("INSERT INTO subscription_payments
                           (id,tenant_id,subscription_id,amount,method,reference,payer_name,note,created_by)
                         VALUES(?,?,?,?,?,?,?,?,?)")
              ->execute([$payId, $inv['tenant_id'], $sub, $amount, $method, $ref ?: null,
                         trim((string)($d['payer_name'] ?? '')) ?: null,
                         'Invoice '.$inv['invoice_no'], $byUser]);
        } catch (\Throwable $e) { $payId = null; }

        $p->prepare("UPDATE invoices SET status='PAID', paid_at=NOW(6), paid_method=?, paid_reference=?,
                                         payment_id=?, updated_at=NOW(6) WHERE id=?")
          ->execute([$method, $ref ?: null, $payId, $id]);

        Audit::log('INVOICE_PAID', 'billing', ['id' => $id, 'label' => $inv['business_name'],
                   'new' => $inv['invoice_no'].' / '.$method.($ref ? ' / '.$ref : '')]);
        return self::get($id);
    }

    public static function cancel(string $id, string $why = ''): array
    {
        $inv = self::get($id);
        if (!$inv) throw new \RuntimeException('Invoice not found.');
        if ($inv['status'] === self::PAID) throw new \RuntimeException('A paid invoice cannot be cancelled.');
        DB::pdo()->prepare("UPDATE invoices SET status='CANCELLED', note=CONCAT(COALESCE(note,''),?),
                                                updated_at=NOW(6) WHERE id=?")
                 ->execute([$why !== '' ? (' / cancelled: '.$why) : ' / cancelled', $id]);
        Audit::log('INVOICE_CANCEL', 'billing', ['id' => $id, 'label' => $inv['business_name'],
                   'old' => $inv['invoice_no']]);
        return self::get($id);
    }

    /** Raqam badalna — sirf us parche par jo abhi wasool nahi hua. */
    public static function reprice(string $id, float $amount, string $note = ''): array
    {
        $inv = self::get($id);
        if (!$inv) throw new \RuntimeException('Invoice not found.');
        if ($inv['status'] !== self::UNPAID) throw new \RuntimeException('Only an unpaid invoice can be changed.');
        if ($amount <= 0) throw new \RuntimeException('Enter an amount greater than zero.');
        $tax    = (float)$inv['tax_pct'];
        $taxAmt = round($amount * $tax / 100, 2);
        DB::pdo()->prepare("UPDATE invoices SET amount=?, tax_amount=?, total=?, note=?, updated_at=NOW(6)
                             WHERE id=?")
                 ->execute([round($amount,2), $taxAmt, round($amount+$taxAmt,2),
                            trim($note) ?: $inv['note'], $id]);
        Audit::log('INVOICE_REPRICE', 'billing', ['id' => $id, 'label' => $inv['business_name'],
                   'old' => (string)$inv['total'], 'new' => (string)round($amount+$taxAmt,2)]);
        return self::get($id);
    }

    public static function markSent(string $id, string $channel = 'WHATSAPP'): void
    {
        try {
            DB::pdo()->prepare("UPDATE invoices SET sent_at=NOW(6), sent_channel=?, updated_at=NOW(6) WHERE id=?")
                     ->execute([strtoupper($channel), $id]);
        } catch (\Throwable $e) {}
    }

    /* ==================== PARHNA ==================== */

    public static function get(string $id): ?array
    {
        $q = DB::pdo()->prepare("SELECT * FROM invoices WHERE id=? LIMIT 1");
        $q->execute([$id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? self::shape($r) : null;
    }

    public static function byToken(string $token): ?array
    {
        if (!\preg_match('/^[0-9a-f]{32}$/i', $token)) return null;
        $q = DB::pdo()->prepare("SELECT * FROM invoices WHERE public_token=? LIMIT 1");
        $q->execute([$token]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? self::shape($r) : null;
    }

    /** @param string $status UNPAID | PAID | CANCELLED | ALL */
    public static function listAll(string $status = 'UNPAID', string $tenantId = '', int $limit = 300): array
    {
        $sql = "SELECT * FROM invoices WHERE 1=1";
        $arg = [];
        if ($status !== '' && strtoupper($status) !== 'ALL') { $sql .= " AND status=?"; $arg[] = strtoupper($status); }
        if ($tenantId !== '') { $sql .= " AND tenant_id=?"; $arg[] = $tenantId; }
        $sql .= " ORDER BY created_at DESC LIMIT ".max(1, min(1000, $limit));
        $q = DB::pdo()->prepare($sql);
        $q->execute($arg);
        return array_map([self::class, 'shape'], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Customer ke apne invoices. */
    public static function forTenant(string $tenantId, int $limit = 24): array
    {
        return self::listAll('ALL', $tenantId, $limit);
    }

    public static function totals(): array
    {
        $out = ['unpaid_count' => 0, 'unpaid_total' => 0.0, 'paid_month' => 0.0, 'overdue_count' => 0];
        try {
            $p = DB::pdo();
            $r = $p->query("SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM invoices WHERE status='UNPAID'")
                   ->fetch(PDO::FETCH_ASSOC);
            $out['unpaid_count'] = (int)$r['c']; $out['unpaid_total'] = (float)$r['t'];
            $out['paid_month'] = (float)$p->query(
                "SELECT COALESCE(SUM(total),0) FROM invoices
                  WHERE status='PAID' AND paid_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetchColumn();
            $out['overdue_count'] = (int)$p->query(
                "SELECT COUNT(*) FROM invoices WHERE status='UNPAID' AND due_on < CURDATE()")->fetchColumn();
        } catch (\Throwable $e) {}
        return $out;
    }

    /* ==================== WHATSAPP ==================== */

    /** Invoice ka public link. */
    public static function link(array $inv): string
    {
        $base = \rtrim((string)\cfg('app.base_url'), '/');
        if ($base === '') $base = (isset($_SERVER['HTTP_HOST'])
            ? ((($_SERVER['HTTPS'] ?? '') ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST']) : '');
        return $base.'/invoice.html?t='.$inv['public_token'];
    }

    /** WhatsApp ka tayyar paighaam. */
    public static function waMessage(array $inv, bool $paid = false): string
    {
        $v = Licence::VENDOR;
        $money = 'PKR '.\number_format((float)$inv['total'], 0);
        if ($paid) {
            return $inv['business_name']." — shukriya!\n"
                 . "Payment mil gayi. Invoice ".$inv['invoice_no']." ab PAID hai.\n"
                 . "Raqam: ".$money."\n"
                 . "Software ".$inv['period_to']." tak chalu hai.\n\n"
                 . self::link($inv)."\n\n".$v['company'];
        }
        return $inv['business_name']." — software renewal\n"
             . "Invoice: ".$inv['invoice_no']."\n"
             . "Period: ".$inv['period_from']." se ".$inv['period_to']."\n"
             . "Raqam: ".$money."\n"
             . ($inv['due_on'] ? ("Due: ".$inv['due_on']."\n") : '')
             . "\nTafseel aur bank ki maloomat:\n".self::link($inv)
             . "\n\n".$v['company']." — ".$v['phone'];
    }

    /** wa.me link — bina kisi setup ke, phone par seedha WhatsApp khulta hai. */
    public static function waLink(array $inv, string $phone, bool $paid = false): string
    {
        $num = \preg_replace('/[^0-9]/', '', $phone);
        /* 03001234567 -> 923001234567 */
        if (\strlen($num) === 11 && $num[0] === '0') $num = '92'.\substr($num, 1);
        return 'https://wa.me/'.$num.'?text='.\rawurlencode(self::waMessage($inv, $paid));
    }

    /**
     * Platform ki apni WhatsApp API (agar lagi ho) se khud bhejna.
     * Na lagi ho to false — Super Admin phir bhi wa.me se bhej sakta hai.
     */
    public static function sendWhatsApp(array $inv, string $phone, bool $paid = false): bool
    {
        $cfg = PlatformSetting::all('wa');
        $url = \rtrim((string)($cfg['api_url'] ?? ''), '/');
        $key = (string)($cfg['api_key'] ?? '');
        if ($url === '' || $key === '' || $phone === '') return false;

        $num = \preg_replace('/[^0-9]/', '', $phone);
        if (\strlen($num) === 11 && $num[0] === '0') $num = '92'.\substr($num, 1);

        $body = \json_encode(['to' => $num, 'message' => self::waMessage($inv, $paid)]);
        try {
            $ctx = \stream_context_create(['http' => [
                'method'  => 'POST', 'timeout' => 10, 'ignore_errors' => true,
                'header'  => "Content-Type: application/json\r\nx-api-key: $key\r\n",
                'content' => $body]]);
            $raw = @\file_get_contents($url.'/api/send', false, $ctx);
            if ($raw === false) return false;
            self::markSent($inv['id'], 'WHATSAPP');
            return true;
        } catch (\Throwable $e) { return false; }
    }

    /** Business ka rabta ka number — invoice bhejne ke liye. */
    public static function contactPhone(string $tenantId): string
    {
        try {
            $q = DB::pdo()->prepare("SELECT contact_phone FROM tenants WHERE id=? LIMIT 1");
            $q->execute([$tenantId]);
            $ph = \trim((string)$q->fetchColumn());
            if ($ph !== '') return $ph;
        } catch (\Throwable $e) {}
        /* Fallback: aakhri activation request ka number. */
        try {
            $q = DB::pdo()->prepare("SELECT contact_phone FROM activation_requests
                                      WHERE tenant_id=? AND contact_phone<>'' ORDER BY created_at DESC LIMIT 1");
            $q->execute([$tenantId]);
            return \trim((string)$q->fetchColumn());
        } catch (\Throwable $e) { return ''; }
    }

    /* ==================== andaroon ==================== */

    private static function shape(array $r): array
    {
        foreach (['amount','tax_pct','tax_amount','total'] as $k) $r[$k] = (float)$r[$k];
        $r['months'] = (int)$r['months'];
        $r['overdue'] = ($r['status'] === self::UNPAID && !empty($r['due_on'])
                         && substr((string)$r['due_on'],0,10) < self::today());
        return $r;
    }

    private static function countFor(string $tid): int
    {
        try {
            $q = DB::pdo()->prepare("SELECT COUNT(*) FROM invoices WHERE tenant_id=?");
            $q->execute([$tid]);
            return (int)$q->fetchColumn();
        } catch (\Throwable $e) { return 0; }
    }

    /** INV-2026-0042 — saal ke hisaab se, bina khali jagah ke. */
    private static function nextNumber(PDO $p): string
    {
        $year = \date('Y');
        for ($try = 0; $try < 12; $try++) {
            $q = $p->prepare("SELECT invoice_no FROM invoices WHERE invoice_no LIKE ?
                               ORDER BY invoice_no DESC LIMIT 1");
            $q->execute(['INV-'.$year.'-%']);
            $last = (string)$q->fetchColumn();
            $n = $last !== '' ? ((int)\substr($last, -4) + 1) : 1;
            $no = 'INV-'.$year.'-'.\str_pad((string)$n, 4, '0', STR_PAD_LEFT);
            $c = $p->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_no=?");
            $c->execute([$no]);
            if (!(int)$c->fetchColumn()) return $no;
        }
        return 'INV-'.$year.'-'.\substr(\uuid(), 0, 6);
    }

    /** Agla period kahan se shuru ho — mojooda expiry ke agle din se. */
    private static function nextPeriodStart(PDO $p, string $tid): string
    {
        try {
            $q = $p->prepare("SELECT expiry_date FROM tenant_subscriptions
                               WHERE tenant_id=? ORDER BY created_at DESC LIMIT 1");
            $q->execute([$tid]);
            $exp = (string)$q->fetchColumn();
            if ($exp !== '') {
                $exp = \substr($exp, 0, 10);
                return $exp >= self::today() ? self::addDays($exp, 1) : self::today();
            }
        } catch (\Throwable $e) {}
        return self::today();
    }

    public static function taxPct(): float
    {
        $v = PlatformSetting::one('billing', 'tax_pct', '0');
        return \max(0.0, \min(100.0, (float)$v));
    }

    private static function today(): string { return \date('Y-m-d'); }

    private static function dateOrNull($v): ?string
    {
        $v = \trim((string)$v);
        return \preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    private static function addDays(string $iso, int $n): string
    {
        return \date('Y-m-d', \strtotime($iso.' '.($n >= 0 ? '+' : '-').\abs($n).' day'));
    }

    private static function addMonths(string $iso, int $n): string
    {
        $d = new \DateTime($iso);
        $day = (int)$d->format('j');
        $d->modify('+'.$n.' month');
        if ((int)$d->format('j') < $day) $d->modify('last day of previous month');
        return $d->format('Y-m-d');
    }
}

// build: V151 build 2026-10-12
