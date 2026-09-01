<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * SelfService — customer khud register kare, khud trial le, aur khud
 * activation ki darkhwast bheje.
 *
 * TEEN USOOL:
 *
 * 1. YEH PUBLIC ENDPOINT HAI. Koi bhi, bina login ke, business bana
 *    sakta hai. Is liye rok lazmi hai: ek email par ek business, ek IP
 *    se rozana chand, aur naam/phone ki tasdeeq.
 *
 * 2. TRANSACTION ID SABOOT NAHI HAI. Customer likh sakta hai "12345,
 *    paisay bhej diye". Us par KHUD-BA-KHUD software chalu karna
 *    dene ke barabar hai. Is liye darkhwast PENDING rehti hai aur
 *    malik (aap) ek click mein manzoor karta hai — 12 ghante ka waada
 *    usi ka hai, system ka nahi.
 *
 * 3. TRIAL KHATAM HONE PAR SOFTWARE BAND, DATA NAHI. Customer ka data
 *    wahin rehta hai; payment ke baad wahin se chalta hai. Data mitana
 *    dabao ka tareeqa hai, hamara tareeqa nahi.
 */
final class SelfService
{
    public const TRIAL_DAYS      = 14;
    public const WARN_DAYS       = 3;
    private const MAX_PER_IP_DAY = 3;

    /* ==================== REGISTER ==================== */

    /**
     * Naya business — trial ke saath, foran istemal ke liye tayyar.
     * @return array{slug:string,login:string,password:string,expires:string}
     */
    public static function register(array $d): array
    {
        $name  = trim((string)($d['business_name'] ?? ''));
        $owner = trim((string)($d['owner_name'] ?? ''));
        $email = strtolower(trim((string)($d['email'] ?? '')));
        $phone = preg_replace('/[^0-9+]/', '', (string)($d['phone'] ?? ''));

        if ($name === '')  throw new \RuntimeException('Please enter your business name.');
        /* mbstring har build par nahi hoti — fallback lazmi. */
        if ((function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) < 3) throw new \RuntimeException('That business name looks too short.');
        if ($owner === '') throw new \RuntimeException('Please enter your name.');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Please enter a valid email address.');
        }
        if (strlen($phone) < 10) throw new \RuntimeException('Please enter a valid phone number.');

        $pass = trim((string)($d['password'] ?? ''));
        if (strlen($pass) < 6) throw new \RuntimeException('Choose a password of at least 6 characters.');

        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        self::guard($email, $phone, $ip);

        $p = DB::pdo();

        /* Ek email = ek business. Warna ek hi bandah bar bar naya trial
           le kar hamesha muft chalata rahega. */
        $q = $p->prepare("SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL AND id IN
                           (SELECT tenant_id FROM users WHERE LOWER(email)=? AND deleted_at IS NULL)");
        $q->execute([$email]);
        if ((int)$q->fetchColumn() > 0) {
            throw new \RuntimeException(
                'An account already exists for this email. Sign in instead, or contact support if you cannot get in.');
        }

        $r = Platform::provisionBusiness([
            'business_name' => $name,
            'owner_email'   => $email,
            'owner_name'    => $owner,
        ]);
        $tid  = (string)($r['tenant_id'] ?? '');
        $slug = (string)($r['slug'] ?? '');
        if ($tid === '') throw new \RuntimeException('Could not create your account. Please try again.');

        $expires = date('Y-m-d', strtotime('+' . self::TRIAL_DAYS . ' day'));

        try {
            $p->prepare("UPDATE tenants SET is_demo=1, is_trial=1, trial_ends_at=?, demo_last_reset=NOW(6),
                             signup_source='SELF', signup_ip=?, contact_phone=? WHERE id=?")
              ->execute([$expires, $ip, $phone, $tid]);
        } catch (\Throwable $e) {}

        /* Trial ki expiry wahi nizam use karti hai jo har licence ka hai,
           taake POS ka warning banner bina kisi naye code ke chal jaye. */
        try {
            $p->prepare("INSERT INTO tenant_subscriptions(id,tenant_id,status,amount,start_date,expiry_date)
                         VALUES(?,?,'ACTIVE',0,CURDATE(),?)")->execute([uuid(), $tid, $expires]);
        } catch (\Throwable $e) {}

        /* Sample data — warna customer khali software dekh kar chala
           jayega. Yehi demo ka poora maqsad hai. */
        try {
            $sq = $p->prepare("SELECT id FROM sites WHERE tenant_id=? LIMIT 1");
            $sq->execute([$tid]);
            DemoBusiness::seed($tid, (string)$sq->fetchColumn());
        } catch (\Throwable $e) {}

        /* Owner ka apna password — provisioning ka random password nahi. */
        try {
            $p->prepare("UPDATE users SET password_hash=?, username=COALESCE(NULLIF(username,''),?),
                                 row_version=row_version+1, updated_at=NOW(6)
                          WHERE tenant_id=? AND is_tenant_admin=1")
              ->execute([password_hash($pass, PASSWORD_DEFAULT), explode('@', $email)[0], $tid]);
        } catch (\Throwable $e) {}

        self::note($email, $phone, $ip);
        Audit::log('SELF_SIGNUP', 'platform', ['id' => $tid, 'label' => $name,
                   'desc' => $owner . ' / ' . $phone]);

        return ['slug' => $slug, 'login' => $email, 'business' => $name,
                'expires' => $expires, 'trial_days' => self::TRIAL_DAYS];
    }

    private static function guard(string $email, string $phone, string $ip): void
    {
        try {
            $q = DB::pdo()->prepare("SELECT COUNT(*) FROM signup_throttle
                                      WHERE ip=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
            $q->execute([$ip]);
            if ((int)$q->fetchColumn() >= self::MAX_PER_IP_DAY) {
                throw new \RuntimeException(
                    'Too many accounts have been created from this connection today. Please contact support.');
            }
        } catch (\RuntimeException $e) { throw $e; }
          catch (\Throwable $e) { /* throttle table na ho to signup na ruke */ }
    }

    private static function note(string $email, string $phone, string $ip): void
    {
        try {
            DB::pdo()->prepare("INSERT INTO signup_throttle(ip,email,phone,created_at) VALUES(?,?,?,NOW(6))")
              ->execute([$ip, $email, $phone]);
        } catch (\Throwable $e) {}
    }

    /* ==================== ACTIVATION ==================== */

    /** Customer ko dikhane ke liye: kis ko paisay bhejne hain. */
    public static function paymentInfo(): array
    {
        $v = Licence::VENDOR;
        $out = ['company' => $v['company'], 'phone' => $v['phone'], 'email' => $v['email'],
                'website' => $v['website'], 'bank_name' => '', 'account_title' => '',
                'account_number' => '', 'iban' => '', 'easypaisa' => '', 'jazzcash' => '',
                'monthly_price' => '', 'yearly_price' => ''];
        try {
            $q = DB::pdo()->prepare("SELECT setting_key AS k, value_json AS v FROM platform_settings
                                      WHERE setting_group='billing'");
            $q->execute();
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = (string)$r['k'];
                if (!array_key_exists($k, $out)) continue;
                $d = json_decode((string)$r['v'], true);
                if (is_scalar($d) && (string)$d !== '') $out[$k] = (string)$d;
            }
        } catch (\Throwable $e) { /* table na ho to sirf rabte ki tafseel */ }
        return $out;
    }

    /**
     * "Paisay bhej diye, yeh transaction id hai."
     *
     * Yeh sirf DARKHWAST hai. Licence yahan se nahi barhta — malik
     * tasdeeq kar ke barhata hai.
     */
    public static function requestActivation(array $d): array
    {
        $tid = tenant_id();
        $p = DB::pdo();

        $ref = trim((string)($d['txn_reference'] ?? ''));
        if ($ref === '') throw new \RuntimeException('Please enter the transaction ID or reference number.');
        if ((function_exists('mb_strlen') ? mb_strlen($ref) : strlen($ref)) < 4) throw new \RuntimeException('That transaction reference looks too short.');

        $months = max(1, min(36, (int)($d['months'] ?? 1)));
        $amount = max(0, (float)($d['amount'] ?? 0));

        /* Ek waqt mein ek hi darkhwast — warna customer ghabra kar das
           dafa bhej deta hai aur queue bhar jati hai. */
        $q = $p->prepare("SELECT id, created_at FROM activation_requests
                           WHERE tenant_id=? AND status='PENDING' ORDER BY created_at DESC LIMIT 1");
        $q->execute([$tid]);
        if ($old = $q->fetch(PDO::FETCH_ASSOC)) {
            return ['already' => true, 'submitted_at' => (string)$old['created_at'],
                    'message' => 'Your activation request is already with us. '
                               . 'We usually confirm within 12 hours. You do not need to send it again.'];
        }

        $tq = $p->prepare("SELECT name FROM tenants WHERE id=? LIMIT 1");
        $tq->execute([$tid]);
        $bname = (string)$tq->fetchColumn();

        $id = uuid();
        $p->prepare("INSERT INTO activation_requests
                      (id,tenant_id,business_name,contact_name,contact_phone,contact_email,
                       plan,months,amount,method,txn_reference,paid_on,note,status,created_at)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'PENDING',NOW(6))")
          ->execute([$id, $tid, $bname,
                     trim((string)($d['contact_name'] ?? '')),
                     preg_replace('/[^0-9+]/', '', (string)($d['contact_phone'] ?? '')),
                     trim((string)($d['contact_email'] ?? '')),
                     trim((string)($d['plan'] ?? '')), $months, $amount,
                     trim((string)($d['method'] ?? '')), $ref,
                     preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['paid_on'] ?? '')) ? $d['paid_on'] : null,
                     trim((string)($d['note'] ?? ''))]);

        Audit::log('ACTIVATION_REQUEST', 'billing', ['id' => $id, 'label' => $bname, 'new' => $ref]);

        return ['id' => $id, 'message' =>
            'Thank you. Your payment details have been sent to ' . Licence::VENDOR['company'] . '. '
          . 'Your software will be activated within 12 hours. You can keep working until then.'];
    }

    /** Customer ko apni darkhwast ki halat. */
    public static function myActivation(): ?array
    {
        try {
            $q = DB::pdo()->prepare("SELECT txn_reference, months, amount, status, created_at,
                                            reviewed_at, review_note
                                       FROM activation_requests
                                      WHERE tenant_id=? ORDER BY created_at DESC LIMIT 1");
            $q->execute([tenant_id()]);
            return $q->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) { return null; }
    }

    /* ==================== VENDOR SIDE ==================== */

    public static function pendingActivations(string $status = 'PENDING'): array
    {
        $q = DB::pdo()->prepare(
            "SELECT ar.*, t.slug,
                    (SELECT s.expiry_date FROM tenant_subscriptions s
                      WHERE s.tenant_id=ar.tenant_id ORDER BY s.created_at DESC LIMIT 1) AS current_expiry
               FROM activation_requests ar
               LEFT JOIN tenants t ON t.id = ar.tenant_id
              WHERE ar.status = ?
              ORDER BY ar.created_at ASC LIMIT 200");
        $q->execute([strtoupper($status)]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Manzoori — licence barhta hai, trial ka nishan hat jata hai.
     * Yehi wo click hai jis ka 12 ghante ka waada hai.
     */
    public static function approve(string $reqId, int $months, string $actor, string $note = ''): array
    {
        $p = DB::pdo();
        $q = $p->prepare("SELECT * FROM activation_requests WHERE id=? AND status='PENDING' LIMIT 1");
        $q->execute([$reqId]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new \RuntimeException('That request is not pending any more.');

        $months = max(1, min(60, $months ?: (int)$r['months']));
        $tid = (string)$r['tenant_id'];

        /* Mojooda expiry se AAGE barhao — customer ne jo din trial mein
           istemal nahi kiye, wo zaya na hon. Guzar chuki ho to aaj se. */
        $cq = $p->prepare("SELECT expiry_date FROM tenant_subscriptions
                            WHERE tenant_id=? ORDER BY created_at DESC LIMIT 1");
        $cq->execute([$tid]);
        $cur = (string)($cq->fetchColumn() ?: '');
        $base = ($cur !== '' && $cur >= date('Y-m-d')) ? $cur : date('Y-m-d');
        $exp = date('Y-m-d', strtotime($base . ' +' . $months . ' month'));

        $sub = $p->prepare("SELECT id FROM tenant_subscriptions WHERE tenant_id=? ORDER BY created_at DESC LIMIT 1");
        $sub->execute([$tid]);
        if ($sid = $sub->fetchColumn()) {
            $p->prepare("UPDATE tenant_subscriptions SET expiry_date=?, status='ACTIVE', updated_at=NOW(6) WHERE id=?")
              ->execute([$exp, $sid]);
        } else {
            $sid = uuid();
            $p->prepare("INSERT INTO tenant_subscriptions(id,tenant_id,status,amount,start_date,expiry_date)
                         VALUES(?,?,'ACTIVE',?,CURDATE(),?)")->execute([$sid, $tid, (float)$r['amount'], $exp]);
        }

        try {
            $p->prepare("INSERT INTO subscription_payments(id,tenant_id,subscription_id,amount,method,reference,note,created_by)
                         VALUES(?,?,?,?,?,?,?,?)")
              ->execute([uuid(), $tid, $sid, (float)$r['amount'], (string)$r['method'],
                         (string)$r['txn_reference'], 'Self-service activation', null]);
        } catch (\Throwable $e) {}

        /* Ab yeh asli customer hai — trial aur demo ka nishan hata do,
           warna 5 din baad us ka apna data saaf ho jayega. */
        $p->prepare("UPDATE tenants SET status='ACTIVE', is_trial=0, is_demo=0 WHERE id=?")->execute([$tid]);

        $p->prepare("UPDATE activation_requests SET status='APPROVED', reviewed_by=?, reviewed_at=NOW(6),
                         review_note=?, months=? WHERE id=?")
          ->execute([$actor, $note ?: null, $months, $reqId]);

        AdminData::audit($actor, $tid, 'ACTIVATION_APPROVED',
            $r['business_name'] . ' — ' . $months . ' month(s) till ' . $exp);

        return ['expiry' => $exp, 'months' => $months,
                'message' => $r['business_name'] . ' activated until ' . $exp . '.'];
    }

    public static function reject(string $reqId, string $actor, string $note): array
    {
        if (trim($note) === '') {
            throw new \RuntimeException('Please write why, so the customer knows what to fix.');
        }
        $p = DB::pdo();
        $q = $p->prepare("SELECT business_name,tenant_id FROM activation_requests WHERE id=? AND status='PENDING'");
        $q->execute([$reqId]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new \RuntimeException('That request is not pending any more.');

        $p->prepare("UPDATE activation_requests SET status='REJECTED', reviewed_by=?, reviewed_at=NOW(6),
                         review_note=? WHERE id=?")->execute([$actor, $note, $reqId]);
        AdminData::audit($actor, (string)$r['tenant_id'], 'ACTIVATION_REJECTED',
            $r['business_name'] . ' — ' . $note);
        return ['message' => 'Request rejected. The customer will see your note.'];
    }
}

// build: V86 build 2026-09-01
