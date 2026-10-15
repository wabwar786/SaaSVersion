<?php
namespace Aio\Services;

use Aio\DB;
use PDO;

/**
 * RetailStock — `rtl_products.stock_qty` ka EK HI DARWAZA.   (V154)
 *
 * KYUN BANI
 * V153 tak stock ko sirf ek jagah chhua jata tha: POS ka ek kachcha
 *
 *     UPDATE rtl_products SET stock_qty = stock_qty - ? ...
 *
 * Jab tak sirf POS tha, yeh kaafi tha. Magar ab GRN, return, purchase
 * return, stock count aur wastage — paanch aur jagah se stock hilega.
 * Agar har ek apna `UPDATE` likhta to WAHI hisaab CHHE JAGAH likha
 * jata, aur chhe mein se ek kabhi na kabhi alag hota. Bilkul wahi
 * shakal jo `MODULE_OF` aur `barcode` par mili thi.
 *
 * Is liye: stock_qty ko SIRF yeh class likhti hai. Baqi sab yahan se
 * guzarte hain.
 *
 * HAR HARKAT KA RECORD
 * Har tabdeeli `rtl_stock_moves` par ek line chhorti hai: kitna, kyun,
 * kis document se, us waqt cost kya thi, aur baad mein balance kya
 * bana. Is se teen cheezein milti hain jo pehle mumkin hi nahi thin:
 *
 *   1. STOCK CARD — "yeh 40 se 37 kyun hua" ka jawab
 *   2. chori aur ghalti dono nazar aati hain (kis user ne, kab)
 *   3. asli Stock Movement report
 *
 * `balance_after` jaan boojh kar mehfooz hota hai. Usay har dafa
 * ginna par sakta tha, magar ek ginti ki ghalti poori tareekh ko
 * jhoota kar deti. Jo us waqt tha, wahi likha hua hai.
 *
 * STOCK ZERO SE NEECHE JA SAKTA HAI — jaan boojh kar. Counter par
 * customer khara ho aur system "stock khatam" keh kar bill rok de, yeh
 * us ghalti se bara masla hai. Minus stock reports mein saaf nazar
 * aata hai aur theek ho jata hai.
 *
 * RESTAURANT KA IS SE KOI TAALLUQ NAHI. Restaurant ka stock
 * `inventory_items` / `stock_transactions` par chalta hai aur
 * `InventoryService` us ka maalik hai. Yeh class un mein se kisi ko
 * chhuti bhi nahi.
 */
final class RetailStock
{
    /* Kyun hila — report aur stock card isi par chalte hain. */
    public const SALE       = 'SALE';        // POS bill
    public const SALE_RETURN= 'SALE_RETURN'; // bill wapas
    public const GRN        = 'GRN';         // maal aaya
    public const PURCH_RET  = 'PURCH_RET';   // supplier ko wapas
    public const COUNT      = 'COUNT';       // ginti ka farq
    public const WASTAGE    = 'WASTAGE';     // kharab / expired
    public const OPENING    = 'OPENING';     // pehli dafa stock daala
    public const MANUAL     = 'MANUAL';      // haath se theek kiya

    /**
     * Stock hilao aur uska record chhoro.
     *
     * @param float $delta  plus = aaya, minus = gaya
     * @return float naya balance
     */
    public static function move(PDO $pdo, string $productId, float $delta, string $reason,
                                array $ref = []): float
    {
        if ($productId === '') throw new \RuntimeException('Stock move needs a product');
        if (\abs($delta) < 0.000001) return self::balance($pdo, $productId);

        $tid = tenant_id();

        /* FOR UPDATE: do counter ek hi product ek saath bech sakte hain.
           Is ke baghair dono purana balance parh kar dono ek hi naya
           balance likh dete — ek bill ka stock chup chaap gum ho jata. */
        $q = $pdo->prepare("SELECT stock_qty, cost_price FROM rtl_products
                             WHERE id=? AND tenant_id=? FOR UPDATE");
        $q->execute([$productId, $tid]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Product not found for stock move');

        $after = (float)$row['stock_qty'] + $delta;

        $pdo->prepare("UPDATE rtl_products SET stock_qty=?, updated_at=NOW(6) WHERE id=? AND tenant_id=?")
            ->execute([$after, $productId, $tid]);

        $cost = \array_key_exists('cost', $ref) ? (float)$ref['cost'] : (float)$row['cost_price'];
        $user = \function_exists('current_user') ? (current_user()['id'] ?? null) : null;

        $pdo->prepare(
            "INSERT INTO rtl_stock_moves(id,tenant_id,site_id,product_id,qty_delta,reason,
                 ref_table,ref_id,ref_no,cost_at_move,balance_after,note,moved_by,moved_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(6))")
            ->execute([\uuid(), $tid, site_id(), $productId, $delta, $reason,
                       (string)($ref['table'] ?? ''), ($ref['id'] ?? null),
                       (string)($ref['no'] ?? ''), $cost, $after,
                       (string)($ref['note'] ?? ''), $user]);

        return $after;
    }

    public static function balance(PDO $pdo, string $productId): float
    {
        $q = $pdo->prepare("SELECT stock_qty FROM rtl_products WHERE id=? AND tenant_id=?");
        $q->execute([$productId, tenant_id()]);
        return (float)$q->fetchColumn();
    }

    /**
     * GRN par naya cost — WEIGHTED AVERAGE.
     *
     * Last-purchase-price seedha likh dena aasan tha, magar us se ek
     * mehengi khareed poore purane stock ki qeemat badal deti hai aur
     * margin ki report jhooti ho jati hai. Weighted average sirf utna
     * hi hilta hai jitna naya maal poore stock mein hissa rakhta hai.
     *
     *     naya cost = (purana qty x purana cost + aaya qty x aaya cost)
     *                 / (purana qty + aaya qty)
     *
     * Purana stock minus ya zero ho to average ka koi matlab nahi —
     * us soorat mein nayi qeemat hi cost hai.
     */
    public static function applyAverageCost(PDO $pdo, string $productId,
                                            float $inQty, float $inCost): float
    {
        if ($inQty <= 0 || $inCost < 0) return 0.0;
        $tid = tenant_id();
        $q = $pdo->prepare("SELECT stock_qty, cost_price FROM rtl_products
                             WHERE id=? AND tenant_id=? FOR UPDATE");
        $q->execute([$productId, $tid]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 0.0;

        $oldQty  = (float)$row['stock_qty'];
        $oldCost = (float)$row['cost_price'];

        $newCost = ($oldQty > 0)
            ? (($oldQty * $oldCost) + ($inQty * $inCost)) / ($oldQty + $inQty)
            : $inCost;
        $newCost = \round($newCost, 4);

        $pdo->prepare("UPDATE rtl_products SET cost_price=?, updated_at=NOW(6) WHERE id=? AND tenant_id=?")
            ->execute([$newCost, $productId, $tid]);
        return $newCost;
    }

    /**
     * Ek product ka stock card.
     * `balance_after` wahi likha hua hai jo us waqt tha — dobara ginti
     * nahi hoti.
     */
    public static function card(string $productId, int $limit = 200): array
    {
        $q = DB::pdo()->prepare(
            "SELECT m.*, p.name AS product_name
               FROM rtl_stock_moves m
               JOIN rtl_products p ON p.id = m.product_id
              WHERE m.tenant_id=? AND m.product_id=? AND m.deleted_at IS NULL
              ORDER BY m.moved_at DESC, m.created_at DESC
              LIMIT ".\max(1, \min(1000, $limit)));
        $q->execute([tenant_id(), $productId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Sab harkatein ek arse ki — Stock Movement report. */
    public static function moves(string $from, string $to, string $reason = '', int $limit = 500): array
    {
        $sql = "SELECT m.moved_at, m.reason, m.qty_delta, m.balance_after, m.cost_at_move,
                       m.ref_no, m.note, p.name AS product_name, p.sku,
                       COALESCE(u.full_name,'') AS user_name
                  FROM rtl_stock_moves m
                  JOIN rtl_products p ON p.id = m.product_id
             LEFT JOIN users u ON u.id = m.moved_by
                 WHERE m.tenant_id=? AND m.deleted_at IS NULL
                   AND DATE(m.moved_at) BETWEEN ? AND ?";
        $arg = [tenant_id(), $from, $to];
        if ($reason !== '') { $sql .= " AND m.reason=?"; $arg[] = \strtoupper($reason); }
        $sql .= " ORDER BY m.moved_at DESC LIMIT ".\max(1, \min(2000, $limit));
        $q = DB::pdo()->prepare($sql);
        $q->execute($arg);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}

// build: V154 build 2026-10-13
