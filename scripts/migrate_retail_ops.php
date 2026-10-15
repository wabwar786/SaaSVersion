<?php
/**
 * migrate_retail_ops.php — RETAIL KA STOCK AUR DOCUMENTS.   (V154)
 *
 * V153 tak supermarket ka stock SIRF GHAT sakta tha. POS bechta tha aur
 * `stock_qty` kam ho jata tha — magar maal AANE ka koi rasta nahi tha.
 * GRN, purchase return, stock count, returns aur wastage: paanchon
 * screens `ui_records` ke JSON blob mein row daal kar "Saved" keh deti
 * thin aur stock ko haath tak nahi lagati thin.
 *
 * Yeh migration un paanchon ke neeche asli zameen daalti hai:
 *
 *   rtl_stock_moves       har tabdeeli ka record (kitna, kyun, kis se)
 *   rtl_docs              document ka header (GRN / PRET / CNT / RET / PO)
 *   rtl_doc_lines         us ki lines
 *   rtl_supplier_ledger   supplier ka khata (GRN se payable)
 *
 * EK DOCUMENT TABLE KYUN, PAANCH NAHI
 * In paanchon ki shakal bilkul ek hai: header (number, tareekh, party,
 * total, halat) + lines (product, qty, rate). Paanch alag table banane
 * ka matlab tha paanch jagah wahi posting ka hisaab likhna — aur paanch
 * mein se ek kabhi na kabhi alag ho jati. `doc_type` ek column hai,
 * posting ka engine ek jagah.
 *
 * RESTAURANT KO KUCH NAHI HOTA. Yeh saari tables nayi hain aur `rtl_`
 * se shuru hoti hain. Restaurant ka stock `inventory_items` /
 * `stock_transactions` par hai — un mein se kisi ko yeh script chhuti
 * bhi nahi. Idempotent hai: dobara chalao to kuch nahi badalta.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

/* Wahi sync columns jo baqi `rtl_*` tables par hain — warna yeh tables
   offline node tak kabhi na pohanchtin. */
$SYNC = "created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
         updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
         deleted_at DATETIME(6) NULL,
         row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
         origin_node_id CHAR(36) NULL";
$ENG = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$tables = [

/* ---------------------------------------------------------------
   STOCK KI HAR HARKAT.

   `balance_after` jaan boojh kar yahan mehfooz hota hai. Usay har dafa
   ginna par sakta tha, magar ek ginti ki ghalti poori tareekh ko jhoota
   kar deti. Jo us waqt tha, wahi likha rehta hai.
   --------------------------------------------------------------- */
'rtl_stock_moves' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  product_id CHAR(36) NOT NULL,
  qty_delta DECIMAL(18,3) NOT NULL DEFAULT 0,
  reason VARCHAR(20) NOT NULL,
  ref_table VARCHAR(40) NULL,
  ref_id CHAR(36) NULL,
  ref_no VARCHAR(60) NULL,
  cost_at_move DECIMAL(14,4) NOT NULL DEFAULT 0,
  balance_after DECIMAL(18,3) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  moved_by CHAR(36) NULL,
  moved_at DATETIME(6) NOT NULL,
  $SYNC,
  KEY ix_rsm_prod (tenant_id, product_id, moved_at),
  KEY ix_rsm_when (tenant_id, moved_at),
  KEY ix_rsm_ref (ref_table, ref_id)",

/* ---------------------------------------------------------------
   DOCUMENT KA HEADER.

   status: DRAFT -> POSTED -> CANCELLED
   POSTING hi wo lamha hai jab stock hilta hai. Draft kuch nahi karta,
   is liye adhoori entry stock kharab nahi karti.
   --------------------------------------------------------------- */
'rtl_docs' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  doc_type VARCHAR(10) NOT NULL,
  doc_no VARCHAR(40) NOT NULL,
  doc_date DATE NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'DRAFT',
  supplier_id CHAR(36) NULL,
  supplier_name VARCHAR(180) NULL,
  customer_id CHAR(36) NULL,
  source_sale_id CHAR(36) NULL,
  source_doc_id CHAR(36) NULL,
  ref_no VARCHAR(60) NULL,
  line_count INT NOT NULL DEFAULT 0,
  subtotal DECIMAL(14,4) NOT NULL DEFAULT 0,
  discount DECIMAL(14,4) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(14,4) NOT NULL DEFAULT 0,
  total DECIMAL(14,4) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(14,4) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  posted_at DATETIME(6) NULL,
  posted_by CHAR(36) NULL,
  created_by CHAR(36) NULL,
  $SYNC,
  UNIQUE KEY uq_rdoc_no (tenant_id, doc_type, doc_no),
  KEY ix_rdoc_type (tenant_id, doc_type, status, doc_date),
  KEY ix_rdoc_sale (source_sale_id)",

'rtl_doc_lines' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  doc_id CHAR(36) NOT NULL,
  product_id CHAR(36) NOT NULL,
  product_name VARCHAR(200) NULL,
  unit_code VARCHAR(20) NULL,
  qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  counted_qty DECIMAL(18,3) NULL,
  system_qty DECIMAL(18,3) NULL,
  unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  unit_price DECIMAL(14,4) NOT NULL DEFAULT 0,
  discount DECIMAL(14,4) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
  line_total DECIMAL(14,4) NOT NULL DEFAULT 0,
  batch_no VARCHAR(80) NULL,
  expiry_date DATE NULL,
  reason VARCHAR(60) NULL,
  $SYNC,
  KEY ix_rdl_doc (doc_id),
  KEY ix_rdl_prod (tenant_id, product_id)",

/* ---------------------------------------------------------------
   SUPPLIER KA KHATA — bilkul wahi shakal jo `rtl_customer_ledger` ki
   hai. Sirf balance rakhna kaafi nahi hota: supplier poochta hai
   "kis GRN ka?" aur us ka jawab line-by-line hona chahiye.
   --------------------------------------------------------------- */
'rtl_supplier_ledger' => "
  id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  site_id CHAR(36) NULL,
  supplier_id CHAR(36) NOT NULL,
  entry_type VARCHAR(20) NOT NULL,
  ref_table VARCHAR(60) NULL,
  ref_id CHAR(36) NULL,
  ref_no VARCHAR(60) NULL,
  debit DECIMAL(14,4) NOT NULL DEFAULT 0,
  credit DECIMAL(14,4) NOT NULL DEFAULT 0,
  balance_after DECIMAL(14,4) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  entry_at DATETIME(6) NOT NULL,
  $SYNC,
  KEY ix_rsl_supp (supplier_id, entry_at)",
];

$made = []; $already = 0;
foreach ($tables as $name => $cols) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$name]);
    if ((int)$q->fetchColumn()) { $already++; continue; }
    try {
        $pdo->exec("CREATE TABLE `$name` ($cols) $ENG");
        $made[] = $name;
    } catch (\Throwable $e) {
        echo "  skip $name: ".substr($e->getMessage(), 0, 140)."\n";
    }
}

/* ============================================================
   `suppliers` TABLE KO HAATH NAHI LAGAYA GAYA — JAAN BOOJH KAR.

   Supplier ka balance wahan ek column bana kar rakhna aasan tha. Magar
   `suppliers` restaurant ke saath MUSHTARAK table hai, aur is kaam ki
   ek hi shart thi: restaurant bilkul na hile. Ek column barhana sab se
   halki tabdeeli hoti, phir bhi tabdeeli hoti.

   Is liye balance kahin likha hi nahi jata — `rtl_supplier_ledger` se
   gin liya jata hai (credit - debit). Ledger par index laga hua hai aur
   supermarket ke hajm par yeh sasta hai. Faida yeh bhi hai ke balance
   aur us ki tafseel kabhi alag nahi ho sakte: dono ek hi jagah se
   aate hain.
   ============================================================ */

/* Yeh column retail ki apni table par hai — GRN ka supplier yaad rakhne
   ke liye. */
$addCol = function (string $table, string $col, string $def) use ($pdo): bool {
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                             WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
        $q->execute([$table, $col]);
        if ((int)$q->fetchColumn()) return false;
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        return true;
    } catch (\Throwable $e) { return false; }
};
$cols = [];
if ($addCol('rtl_products', 'last_supplier_id', 'CHAR(36) NULL')) $cols[] = 'rtl_products.last_supplier_id';

echo "RETAIL_OPS_OK created=".(count($made) ? implode(',', $made) : 'none')
   . " already=$already"
   . (count($cols) ? " columns_added=".implode(',', $cols) : '') . "\n";

// build: V154 build 2026-10-13
