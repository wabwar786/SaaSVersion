<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * FiscalService — FBR / provincial digital invoicing.
 *
 * DO USOOL (dono ahem hain):
 *
 * 1. SIRF OFFLINE. FBR ka fiscal service usi computer par hota hai jahan
 *    POS chal raha hai (localhost). Cloud (Railway) se us tak pohancha hi
 *    nahi ja sakta. Is liye cloud par yeh sab band rehta hai.
 *
 * 2. BILL KABHI NAHI RUKTA. Customer counter par khara hai. FBR service
 *    band ho, net na ho, kuch bhi ho — bill chhapega. Bas us par
 *    "FBR: PENDING" likha aayega, entry queue mein jayegi, aur retry hoga.
 *    Jo cheez NahI hogi wo hai khamosh nakami: pending ka pata usi waqt
 *    chalega, mahine ke aakhir mein nahi.
 *
 * Tax ka hisaab hamara apna hai (customer ki hidayat par):
 *   - har line ka tax alag nikaal kar jama
 *   - header usi jama se banta hai, alag se dobara nahi ginta
 *   Isi se wo mismatch khatam hota hai jo per-line rounding se paida
 *   hota hai aur jis par FBR invoice reject kar deta hai.
 */
final class FiscalService
{
    public const PROVIDERS = ['NONE', 'FBR', 'KPRA'];

    public static function defaults(): array
    {
        return [
            'provider'      => 'NONE',
            'service_url'   => 'http://localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel',
            'pos_id'        => '',
            'ntn'           => '',
            'access_key'    => '',
            'price_incl_tax'=> 'No',
            'default_pct'   => '98016000',
            'pos_fee'       => 'Off',
        ];
    }

    /** Cloud par fiscal kabhi chalu nahi hota. */
    public static function availableHere(): bool
    {
        return (string)cfg('app.role') !== 'cloud';
    }

    public static function settings(): array
    {
        $out = self::defaults();
        try {
            $q = DB::pdo()->prepare("SELECT setting_key AS k, value_json AS v FROM site_settings
                                      WHERE tenant_id=? AND site_id=? AND setting_group='fiscal'");
            $q->execute([tenant_id(), site_id()]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = (string)$r['k'];
                if (!array_key_exists($k, $out)) continue;
                $v = json_decode((string)$r['v'], true);
                $out[$k] = is_scalar($v) ? (string)$v : '';
            }
        } catch (\Throwable $e) {}
        $out['enabled']         = self::availableHere() && $out['provider'] !== 'NONE';
        $out['available_here']  = self::availableHere();
        return $out;
    }

    /* ==================== tax ka hisaab ==================== */

    /**
     * Har line ka sale / tax / total. Rounding sirf 2 decimal par, aakhir mein.
     * @return array{lines:array,totals:array}
     */
    public static function compute(array $items, float $rate, bool $priceIncludesTax): array
    {
        $lines = [];
        $sSale = 0.0; $sTax = 0.0; $sTotal = 0.0; $sQty = 0.0;

        foreach ($items as $it) {
            $qty  = (float)($it['qty'] ?? 0);
            $disc = (float)($it['discount'] ?? 0);
            $gross = (float)($it['line_total'] ?? ((float)($it['unit_price'] ?? 0) * $qty));
            $gross = max(0.0, $gross - $disc);

            if ($priceIncludesTax) {
                $total = $gross;
                $sale  = $rate > 0 ? ($total * 100.0 / (100.0 + $rate)) : $total;
                $tax   = $total - $sale;
            } else {
                $sale  = $gross;
                $tax   = $sale * $rate / 100.0;
                $total = $sale + $tax;
            }

            $lines[] = [
                'item_id'   => (string)($it['item_id'] ?? ''),
                'name'      => (string)($it['name'] ?? ''),
                'pct'       => (string)($it['pct'] ?? ''),
                'qty'       => round($qty, 3),
                'rate'      => round($rate, 2),
                'sale'      => round($sale, 2),
                'tax'       => round($tax, 2),
                'total'     => round($total, 2),
                'discount'  => round($disc, 2),
            ];
            $sSale += $sale; $sTax += $tax; $sTotal += $total; $sQty += $qty;
        }

        /* Header lines ke JAMA se banta hai — alag se dobara nahi ginta. */
        return ['lines' => $lines, 'totals' => [
            'sale'  => round($sSale, 2),
            'tax'   => round($sTax, 2),
            'total' => round($sTotal, 2),
            'qty'   => round($sQty, 3),
        ]];
    }

    /* ==================== submit ==================== */

    /**
     * Bill close hone ke BAAD chalta hai. Kabhi exception nahi phenkta —
     * bill ka rasta rokna is ka kaam nahi.
     * @return array{status:string,invoice_no:string,message:string}
     */
    public static function submit(string $orderId): array
    {
        $none = ['status' => 'NONE', 'invoice_no' => '', 'message' => ''];
        if (!self::availableHere()) return $none;

        $cfg = self::settings();
        if (($cfg['provider'] ?? 'NONE') === 'NONE') return $none;

        try {
            $p = DB::pdo();
            $oq = $p->prepare("SELECT o.*, c.full_name cust, c.phone cphone
                                 FROM orders o LEFT JOIN customers c ON c.id=o.customer_id
                                WHERE o.id=? AND o.site_id=? LIMIT 1");
            $oq->execute([$orderId, site_id()]);
            $o = $oq->fetch(PDO::FETCH_ASSOC);
            if (!$o) return ['status' => 'FAILED', 'invoice_no' => '', 'message' => 'Bill not found'];

            $iq = $p->prepare("SELECT oi.qty, oi.unit_price, oi.line_total, oi.menu_item_id,
                                      COALESCE(oi.item_name_snapshot, mi.name) nm, mi.pct_code
                                 FROM order_items oi
                                 LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
                                WHERE oi.order_id=?");
            $iq->execute([$orderId]);
            $rows = $iq->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return ['status' => 'FAILED', 'invoice_no' => '', 'message' => 'The bill has no items'];

            $rate = self::rateFor($orderId);
            $items = [];
            foreach ($rows as $r) {
                $items[] = [
                    'item_id'    => (string)($r['menu_item_id'] ?? ''),
                    'name'       => (string)$r['nm'],
                    'pct'        => (string)($r['pct_code'] ?: $cfg['default_pct']),
                    'qty'        => (float)$r['qty'],
                    'unit_price' => (float)$r['unit_price'],
                    'line_total' => (float)$r['line_total'],
                ];
            }
            $calc = self::compute($items, $rate, ($cfg['price_incl_tax'] ?? 'No') === 'Yes');

            $res = ($cfg['provider'] === 'KPRA')
                 ? self::sendKpra($cfg, $o, $calc)
                 : self::sendFbr($cfg, $o, $calc);

            self::record($orderId, (string)$o['bill_no'], $cfg, $res, $calc);
            return $res;

        } catch (\Throwable $e) {
            $msg = substr($e->getMessage(), 0, 200);
            try { self::record($orderId, '', $cfg ?? [], ['status'=>'FAILED','invoice_no'=>'','message'=>$msg], null); }
            catch (\Throwable $e2) {}
            return ['status' => 'FAILED', 'invoice_no' => '', 'message' => $msg];
        }
    }

    /** Payment mode ke hisaab se rate — wahi tax_cash / tax_card jo POS use karta hai. */
    private static function rateFor(string $orderId): float
    {
        $t = SettingsService::taxes();
        $mode = 'CASH';
        try {
            $q = DB::pdo()->prepare("SELECT pm.method_type FROM payments pay
                                      JOIN payment_methods pm ON pm.id = pay.payment_method_id
                                     WHERE pay.order_id=? ORDER BY pay.paid_at DESC LIMIT 1");
            $q->execute([$orderId]);
            $mode = strtoupper((string)($q->fetchColumn() ?: 'CASH'));
        } catch (\Throwable $e) {}
        return in_array($mode, ['CARD', 'WALLET', 'BANK'], true)
             ? (float)$t['tax_card'] : (float)$t['tax_cash'];
    }

    private static function payModeCode(string $orderId): int
    {
        try {
            $q = DB::pdo()->prepare("SELECT pm.method_type FROM payments pay
                                      JOIN payment_methods pm ON pm.id = pay.payment_method_id
                                     WHERE pay.order_id=? ORDER BY pay.paid_at DESC LIMIT 1");
            $q->execute([$orderId]);
            $m = strtoupper((string)($q->fetchColumn() ?: 'CASH'));
            return $m === 'CARD' ? 2 : 1;   // FBR: 1 = cash, 2 = card
        } catch (\Throwable $e) { return 1; }
    }

    /* ---------------- FBR ---------------- */

    private static function sendFbr(array $cfg, array $o, array $calc): array
    {
        $items = [];
        foreach ($calc['lines'] as $l) {
            /* Yeh field names FBR ke hain — inhen badla nahi ja sakta. */
            $items[] = [
                'ItemCode'    => $l['item_id'],
                'ItemName'    => $l['name'],
                'Quantity'    => $l['qty'],
                'PCTCode'     => $l['pct'] ?: (string)$cfg['default_pct'],
                'TaxRate'     => $l['rate'],
                'SaleValue'   => $l['sale'],
                'TotalAmount' => $l['total'],
                'TaxCharged'  => $l['tax'],
                'Discount'    => $l['discount'],
                'FurtherTax'  => 0,
                'InvoiceType' => 1,
            ];
        }
        $payload = [
            'POSID'            => (int)$cfg['pos_id'],
            'USIN'             => (string)$o['bill_no'],
            'DateTime'         => date('Y-m-d H:i:s', strtotime((string)($o['closed_at'] ?: $o['created_at']))),
            'BuyerNTN'         => '',
            'BuyerCNIC'        => '',
            'BuyerName'        => (string)($o['cust'] ?? ''),
            'BuyerPhoneNumber' => (string)($o['cphone'] ?? ''),
            'TotalBillAmount'  => $calc['totals']['total'],
            'TotalQuantity'    => $calc['totals']['qty'],
            'TotalSaleValue'   => $calc['totals']['sale'],
            'TotalTaxCharged'  => $calc['totals']['tax'],
            'Discount'         => (float)($o['discount_amount'] ?? 0),
            'FurtherTax'       => 0,
            'PaymentMode'      => self::payModeCode((string)$o['id']),
            'InvoiceType'      => 1,
            'Items'            => $items,
        ];

        $r = self::post((string)$cfg['service_url'], $payload);
        if (!$r['ok']) return ['status' => 'PENDING', 'invoice_no' => '', 'message' => $r['error']];

        /* Asli JSON parse. Fixed byte offsets / substring hacks NahI —
           format zara sa badle to galat number ya crash. */
        $j = json_decode($r['body'], true);
        $no = '';
        if (is_array($j)) {
            foreach (['InvoiceNumber', 'FBRInvoiceNumber', 'invoiceNumber', 'Invoice_Number'] as $k) {
                if (!empty($j[$k])) { $no = (string)$j[$k]; break; }
            }
        }
        if ($no === '') {
            return ['status' => 'PENDING', 'invoice_no' => '',
                    'message' => 'FBR did not return an invoice number. Reply: '.substr($r['body'], 0, 160)];
        }
        return ['status' => 'SENT', 'invoice_no' => $no, 'message' => ''];
    }

    /* ---------------- KPRA ---------------- */

    private static function sendKpra(array $cfg, array $o, array $calc): array
    {
        $url = 'http://kpra.gov.pk/rims/integration/?'.http_build_query([
            'ntn'        => (string)$cfg['ntn'],
            'key'        => (string)$cfg['access_key'],
            'invoice_no' => (string)$o['bill_no'],
            'amount'     => $calc['totals']['sale'],
            'sts'        => $calc['totals']['tax'],
            'date'       => date('Y-m-d H:i:s', strtotime((string)($o['closed_at'] ?: $o['created_at']))),
        ]);
        $r = self::get($url);
        if (!$r['ok']) return ['status' => 'PENDING', 'invoice_no' => '', 'message' => $r['error']];
        /* KPRA invoice number wapas nahi karta — sirf submission. */
        return ['status' => 'SENT', 'invoice_no' => (string)$o['bill_no'], 'message' => ''];
    }

    /* ---------------- HTTP ---------------- */

    public static function post(string $url, array $payload, int $timeout = 8): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content'       => $body,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return ['ok' => false, 'body' => '',
                    'error' => 'Could not reach the fiscal service ('.$url.'). Is it running on this computer?'];
        }
        $code = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
        }
        if ($code >= 400) {
            return ['ok' => false, 'body' => $raw, 'error' => 'The fiscal service returned HTTP '.$code.': '.substr($raw, 0, 160)];
        }
        return ['ok' => true, 'body' => (string)$raw, 'error' => ''];
    }

    public static function get(string $url, int $timeout = 8): array
    {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return ['ok' => false, 'body' => '', 'error' => 'Could not reach the service.'];
        return ['ok' => true, 'body' => (string)$raw, 'error' => ''];
    }

    /** Settings page ka "Test connection". */
    public static function test(): array
    {
        if (!self::availableHere()) {
            return ['ok' => false, 'message' => 'FBR works only in the offline version (fiscal service localhost par hota hai).'];
        }
        $cfg = self::settings();
        if ($cfg['provider'] === 'NONE') return ['ok' => false, 'message' => 'Select a provider first.'];
        if ($cfg['provider'] === 'KPRA') {
            if (!$cfg['ntn'] || !$cfg['access_key']) return ['ok' => false, 'message' => 'KPRA requires both an NTN and an access key.'];
            return ['ok' => true, 'message' => 'KPRA settings look complete (they will be verified on the first bill).'];
        }
        if (!$cfg['service_url']) return ['ok' => false, 'message' => 'Enter the fiscal service URL.'];
        if (!$cfg['pos_id'])      return ['ok' => false, 'message' => 'Enter the POS ID.'];

        $r = self::post((string)$cfg['service_url'], ['POSID' => (int)$cfg['pos_id'], 'USIN' => 'TEST', 'Items' => []], 5);
        if (!$r['ok']) return ['ok' => false, 'message' => $r['error']];
        return ['ok' => true, 'message' => 'The fiscal service responded. Reply: '.substr($r['body'], 0, 120)];
    }

    /* ---------------- log + order ---------------- */

    private static function record(string $orderId, string $billNo, array $cfg, array $res, ?array $calc): void
    {
        $p = DB::pdo();
        try {
            $p->prepare("UPDATE orders SET fiscal_invoice_no=?, fiscal_status=?, updated_at=NOW(6) WHERE id=?")
              ->execute([$res['invoice_no'] ?: null, $res['status'], $orderId]);
        } catch (\Throwable $e) {}
        try {
            $p->prepare("INSERT INTO fiscal_invoices
                          (id,tenant_id,site_id,order_id,bill_no,provider,invoice_no,status,message,
                           sale_value,tax_amount,total_amount,attempts,created_at,updated_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(6),NOW(6))
                         ON DUPLICATE KEY UPDATE
                           invoice_no=VALUES(invoice_no), status=VALUES(status), message=VALUES(message),
                           attempts=attempts+1, updated_at=NOW(6)")
              ->execute([uuid(), tenant_id(), site_id(), $orderId, $billNo,
                         (string)($cfg['provider'] ?? ''), $res['invoice_no'] ?: null,
                         $res['status'], substr((string)$res['message'], 0, 300),
                         $calc['totals']['sale'] ?? 0, $calc['totals']['tax'] ?? 0, $calc['totals']['total'] ?? 0]);
        } catch (\Throwable $e) {}
    }

    /** Pending bills — dashboard/Settings par ginti, aur retry ke liye. */
    public static function pending(int $limit = 50): array
    {
        try {
            $q = DB::pdo()->prepare("SELECT order_id, bill_no, status, message, attempts, updated_at
                                       FROM fiscal_invoices
                                      WHERE tenant_id=? AND site_id=? AND status IN ('PENDING','FAILED')
                                      ORDER BY updated_at DESC LIMIT $limit");
            $q->execute([tenant_id(), site_id()]);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { return []; }
    }

    public static function retryPending(int $max = 20): array
    {
        $done = 0; $still = 0;
        foreach (self::pending($max) as $row) {
            $r = self::submit((string)$row['order_id']);
            if ($r['status'] === 'SENT') $done++; else $still++;
        }
        return ['sent' => $done, 'pending' => $still];
    }
}

// build: V64 build 2026-08-27
