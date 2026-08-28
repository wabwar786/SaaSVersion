<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * WhatsApp — shift closing ka khulasa malik ko.
 *
 * DO USOOL (dono ahem):
 *
 * 1. CLOSING KABHI NA RUKE. Message qatar (queue) mein jata hai, wahin
 *    se bheja jata hai. WhatsApp band ho, net na ho, API ka key ghalat
 *    ho — shift phir bhi band hoti hai aur report chhapti hai. Cashier
 *    ko counter par rok dena hal nahi hai.
 *
 * 2. EK CLOSING = EK MESSAGE. `uq_wa_ref (kind, reference_id)` unique
 *    hai, is liye ek hi shift ka message dobara qatar mein nahi ja
 *    sakta — chahe closing ka amal kisi wajah se dobara chale.
 */
final class WhatsApp
{
    public static function settings(): array
    {
        $out = ['enabled' => 'No', 'owner_number' => '', 'provider' => 'NONE',
                'api_url' => '', 'api_token' => '', 'instance_id' => ''];
        try {
            $q = DB::pdo()->prepare("SELECT setting_key AS k, value_json AS v FROM site_settings
                                      WHERE tenant_id=? AND site_id=? AND setting_group='whatsapp'");
            $q->execute([tenant_id(), site_id()]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = (string)$r['k'];
                if (!array_key_exists($k, $out)) continue;
                $v = json_decode((string)$r['v'], true);
                $out[$k] = is_scalar($v) ? (string)$v : '';
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    public static function saveSettings(array $d): void
    {
        Scope::requireManagement('changing WhatsApp settings');
        $p = DB::pdo();
        foreach (self::settings() as $k => $_) {
            if (!array_key_exists('wa_'.$k, $d)) continue;
            $v = trim((string)$d['wa_'.$k]);
            if ($k === 'owner_number' && $v !== '') {
                $v = preg_replace('/[^0-9+]/', '', $v);
                if (strlen($v) < 10) throw new \RuntimeException('That WhatsApp number does not look complete.');
            }
            $json = json_encode($v, JSON_UNESCAPED_UNICODE);
            $q = $p->prepare("SELECT id FROM site_settings
                               WHERE site_id=? AND setting_group='whatsapp' AND setting_key=? LIMIT 1");
            $q->execute([site_id(), $k]);
            if ($id = $q->fetchColumn()) {
                $p->prepare("UPDATE site_settings SET value_json=?, updated_at=NOW(6) WHERE id=?")->execute([$json, $id]);
            } else {
                $p->prepare("INSERT INTO site_settings(id,tenant_id,site_id,setting_group,setting_key,value_json,is_secret)
                             VALUES(?,?,?,'whatsapp',?,?,?)")
                  ->execute([uuid(), tenant_id(), site_id(), $k, $json, $k === 'api_token' ? 1 : 0]);
            }
        }
        Audit::log('SETTINGS_CHANGE', 'whatsapp', ['desc' => 'WhatsApp closing settings updated']);
    }

    /** Closing ka message banao aur qatar mein daalo. */
    public static function queueShiftClosing(string $shiftId, array $snap): void
    {
        $cfg = self::settings();
        if (($cfg['enabled'] ?? 'No') !== 'Yes' || trim((string)$cfg['owner_number']) === '') return;

        $msg = self::buildMessage($snap);
        try {
            /* INSERT IGNORE + unique key = ek closing ka ek hi message,
               chahe yeh function dobara chal jaye. */
            DB::pdo()->prepare(
                "INSERT IGNORE INTO whatsapp_queue
                   (id,tenant_id,site_id,kind,reference_id,recipient,message,status,attempts,created_at)
                 VALUES (?,?,?,'SHIFT_CLOSING',?,?,?,'PENDING',0,NOW(6))"
            )->execute([uuid(), tenant_id(), site_id(), $shiftId,
                        (string)$cfg['owner_number'], $msg]);
        } catch (\Throwable $e) { /* closing kabhi na ruke */ }

        /* Foran bhejne ki koshish — na jaye to qatar mein para rahega. */
        try { self::flush(3); } catch (\Throwable $e) {}
    }

    public static function buildMessage(array $s): string
    {
        $m = [];
        $m[] = '*SHIFT CLOSING*';
        $m[] = '';
        $m[] = 'Shift: ' . ($s['shift'] ?? '-');
        if (!empty($s['counter'])) $m[] = 'Counter: ' . $s['counter'];
        $m[] = 'Cashier: ' . ($s['cashier'] ?? '-');
        $m[] = 'Opened: ' . ($s['opened'] ?? '-');
        $m[] = 'Closed: ' . ($s['closed'] ?? '-');
        $m[] = '';
        $m[] = 'Invoices: ' . (int)($s['bills'] ?? 0);
        $m[] = 'Gross sales: ' . self::n($s['subtotal'] ?? 0);
        if ((float)($s['discount'] ?? 0) > 0) $m[] = 'Discount: -' . self::n($s['discount']);
        if ((float)($s['tax'] ?? 0) > 0)      $m[] = 'Sales tax: ' . self::n($s['tax']);
        $m[] = '*Total sales: ' . self::n($s['total'] ?? 0) . '*';

        if (!empty($s['payments'])) {
            $m[] = '';
            foreach ($s['payments'] as $p) $m[] = $p['method'] . ': ' . self::n($p['amount']);
        }

        $m[] = '';
        $m[] = 'Opening cash: ' . self::n($s['opening'] ?? 0);
        if ((float)($s['expenses'] ?? 0) > 0) $m[] = 'Cash expenses: -' . self::n($s['expenses']);
        $m[] = 'Expected: ' . self::n($s['expected'] ?? 0);
        $m[] = 'Counted: ' . self::n($s['counted'] ?? 0);
        $v = (float)($s['variance'] ?? 0);
        $m[] = '*' . ($v == 0 ? 'Balanced' : ($v > 0 ? 'Cash over: +' : 'Cash short: ')) . ($v == 0 ? '' : self::n($v)) . '*';

        /* Tracked inventory — sirf wo items jin par tracking on hai.
           Poori inventory bhejna malik ke liye be-kaar shor hai. */
        if (!empty($s['tracked'])) {
            $m[] = '';
            $m[] = '*Tracked Inventory*';
            foreach ($s['tracked'] as $t) {
                $m[] = $t['name'] . ' — sold ' . self::n($t['sold']) . ', left ' . self::n($t['remaining']);
            }
        }
        return implode("\n", $m);
    }

    private static function n($v): string { return number_format((float)$v, 0); }

    /** Qatar khali karo. Har nakami wajah ke saath record hoti hai. */
    public static function flush(int $max = 20): array
    {
        $cfg = self::settings();
        $sent = 0; $failed = 0;
        try {
            $q = DB::pdo()->prepare(
                "SELECT id, recipient, message, attempts FROM whatsapp_queue
                  WHERE tenant_id=? AND site_id=? AND status IN ('PENDING','RETRY')
                    AND attempts < 5
                  ORDER BY created_at LIMIT $max");
            $q->execute([tenant_id(), site_id()]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { return ['sent' => 0, 'failed' => 0]; }

        foreach ($rows as $r) {
            $res = self::send($cfg, (string)$r['recipient'], (string)$r['message']);
            try {
                DB::pdo()->prepare(
                    "UPDATE whatsapp_queue SET status=?, attempts=attempts+1,
                            api_response=?, sent_at=?, updated_at=NOW(6) WHERE id=?"
                )->execute([$res['ok'] ? 'SENT' : 'RETRY',
                            substr((string)$res['body'], 0, 1000),
                            $res['ok'] ? date('Y-m-d H:i:s') : null, $r['id']]);
            } catch (\Throwable $e) {}
            $res['ok'] ? $sent++ : $failed++;
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /** Provider adapter. Naya provider yahan add hota hai, baqi kahin nahi. */
    private static function send(array $cfg, string $to, string $text): array
    {
        $prov = strtoupper((string)($cfg['provider'] ?? 'NONE'));
        if ($prov === 'NONE' || trim((string)$cfg['api_url']) === '') {
            return ['ok' => false, 'body' => 'No WhatsApp provider configured'];
        }
        $url = (string)$cfg['api_url'];
        $payload = [
            'number'   => $to,
            'phone'    => $to,
            'to'       => $to,
            'message'  => $text,
            'body'     => $text,
            'text'     => $text,
            'token'    => (string)$cfg['api_token'],
            'instance_id' => (string)$cfg['instance_id'],
        ];
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n"
                      . ((string)$cfg['api_token'] !== '' ? ("Authorization: Bearer ".$cfg['api_token']."\r\n") : ''),
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => 12,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return ['ok' => false, 'body' => 'Could not reach the WhatsApp service'];
        $code = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
        }
        return ['ok' => $code >= 200 && $code < 300, 'body' => (string)$raw];
    }

    public static function queue(int $limit = 100): array
    {
        try {
            $q = DB::pdo()->prepare(
                "SELECT wq.id, wq.kind, wq.recipient, wq.status, wq.attempts,
                        wq.api_response, wq.sent_at, wq.created_at,
                        COALESCE(cs.shift_no,'') AS shift_no
                   FROM whatsapp_queue wq
                   LEFT JOIN cashier_shifts cs ON cs.id = wq.reference_id
                  WHERE wq.tenant_id=? AND wq.site_id=?
                  ORDER BY wq.created_at DESC LIMIT $limit");
            $q->execute([tenant_id(), site_id()]);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { return []; }
    }

    public static function retry(string $id): array
    {
        Scope::requireManagement('resending WhatsApp messages');
        try {
            DB::pdo()->prepare("UPDATE whatsapp_queue SET status='RETRY', attempts=0, updated_at=NOW(6)
                                 WHERE id=? AND tenant_id=? AND site_id=?")
              ->execute([$id, tenant_id(), site_id()]);
        } catch (\Throwable $e) {}
        return self::flush(5);
    }
}

// build: V77 build 2026-08-28
