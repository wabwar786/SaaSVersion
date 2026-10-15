============================================================
 V154 — RETAIL MUKAMMAL
 Wabwar Software House
============================================================

AAP NE KAHA: "Retail ko complete kro, restaurant per koi farq nahi
parna chaiye."

V153 ke audit mein 16 module adhoore ya khokhle nikle thay. Ab:

    MUKAMMAL   22  ->  41
    ADHOORE    16  ->   1   (sirf WhatsApp templates)
    HATAYE      —  ->   1   (Stock Transfer, aap ke kehne par)

Aur restaurant: 43 screens, 0 farq. Saboot neeche Part C mein.


------------------------------------------------------------
 1) ASAL MASLA: STOCK KA KOI DARWAZA HI NAHI THA
------------------------------------------------------------
V153 tak supermarket ka stock SIRF GHAT sakta tha. POS bechta tha aur
`stock_qty` kam ho jata tha — magar maal AANE ka koi rasta nahi tha.
GRN, purchase return, stock count, returns aur wastage: paanchon
screens `ui_records` ke JSON blob mein row daal kar "Saved" keh deti
thin aur stock ko haath tak nahi lagati thin.

Agar main har screen ko apna `UPDATE stock_qty` likhne deta, to WAHI
hisaab CHHE JAGAH likha jata — aur chhe mein se ek kabhi na kabhi alag
hoti. Bilkul wahi shakal jo `MODULE_OF` aur `barcode` par mili thi.

Is liye pehle EK DARWAZA bana, phir uske upar module:

    RetailStock  ->  `rtl_products.stock_qty` ka akela likhne wala
                     har tabdeeli `rtl_stock_moves` par nishan chhorti hai

    RetailDocs   ->  GRN, Purchase Return, Returns, Stock Count,
                     Purchase Order, Wastage — EK posting engine

    DRAFT  ->  POSTED      (yahan stock hilta hai)
      |
      +-->  CANCELLED      (sirf DRAFT se)

DRAFT kuch nahi karta. Aadha bhara hua form, ghalat qty, counter par
rush — in mein se koi stock kharab nahi kar sakta.

POST HO CHUKA DOCUMENT WAPAS NAHI HOTA — jaan boojh kar. Us waqt tak
stock hil chuka, cost badal chuki aur supplier ka khata chal chuka hota
hai. "Undo" aisa hisaab banata hai jis par kabhi bharosa na ho. Ghalti
ho to ULTA document post karein — dukan ka register bhi yehi karta hai,
aur dono entries nazar aati hain.


------------------------------------------------------------
 2) TEEN BUG JO AUDIT MEIN MILE — TEENO THEEK
------------------------------------------------------------

BUG 1 — PRODUCT KA BARCODE KABHI SAVE HI NAHI HOTA THA
  UI `barcode` ko mita kar `barcodes[]` bhejta tha; server sirf
  `barcode` dekhta tha. Yani screen se banaya hua HAR product counter
  par scan hi nahi hota tha. Form "Product added" kehta aur kahin koi
  error nazar na aata.

  Ab server DONO shakalein qubool karta hai. Sirf UI theek karna kaafi
  nahi tha: pehle se lage hue offline nodes par PURANA UI chal raha
  hoga aur wo `barcodes[]` hi bhejega — un ke liye bhi chalna chahiye
  bina UPDATE.bat ke intezar ke.

BUG 2 — CHHE MODULE KI LIST SCREEN PAR HAMESHA KHALI
  `retail_api.js` mein ek HAATH SE LIKHI saat naamon ki fehrist thi.
  Jo storeKey us mein nahi tha — suppliers, expenses, staff, printers,
  branches, promotions — us ka `S.get()` KHALI ARRAY wapas karta tha,
  chahe server par kitni bhi rows hon. Likhna alag raaste se jata tha,
  is liye SAVE CHALTA THA AUR LIST NAHI.

  Ab naqsha `MODULE_CONFIGS` se KHUD banta hai — ek fehrist, ek jagah.
  Naya module add karne par ab wahan kuch likhna hi nahi parta.

BUG 3 — WASTAGE RETAIL KA THA HI NAHI
  `ModuleBridge` ka wastage restaurant ke `inventory_items` mein item
  dhoondhta tha; supermarket ke product `rtl_products` mein hote hain.
  Har koshish par "Inventory item not found".

  Ab wastage bhi ek document hai (`doc_type = WST`) aur usi posting
  engine se guzarta hai. ModuleBridge ko CHHUA TAK NAHI GAYA —
  restaurant ka wastage jaisa tha waisa hi chal raha hai.


------------------------------------------------------------
 3) EK AUR BUG JO BANATE WAQT MILA
------------------------------------------------------------
Kuch file ke naam dono verticals mein ek jaise hain: `stock_count.html`,
`purchase_orders.html`, `stock_transfer.html`, `accounting.html`.
Router ki ek fehrist sirf NAAM dekhti thi, vertical nahi — is liye
supermarket ke Stock Count aur Purchase Orders par bhi RESTAURANT ka
`ops_db.js` inject ho jata tha. Wo script apna content `.content` par
likh deti hai aur baad mein chalti hai, is liye retail ka poora safha
chup chaap mit jata tha: screen par "Nothing to show yet" reh jata aur
kahin koi error nahi aata.

Ab wo injection sirf restaurant ke liye hai. Restaurant ke liye kuch
nahi badla — wahi screens, wahi script.


------------------------------------------------------------
 4) MODULE DAR MODULE — AB KYA HOTA HAI
------------------------------------------------------------
  GRN (Goods Receipt)   post karte hi: stock BARHTA hai, cost ka
                        WEIGHTED AVERAGE naye bhao se theek hota hai,
                        supplier ka payable barhta hai, aur
                        batch/expiry ki line bhi ban jati hai
  Purchase Return       stock ghatta hai, supplier ka payable kam
  Returns / Refund      asal bill se juri, stock WAPAS, udhaar halka
  Physical Stock Count  sirf "shelf par kitna mila" likhein — system ka
                        number khud aata hai, farq khud nikalta hai,
                        aur adjustment post ho jata hai
  Purchase Orders       stock nahi hilta (wo sirf waada hai) — maal GRN
                        par aata hai
  Damage / Write-off    stock ghatta hai, Stock Movement par WASTAGE
  Purchasing            ab apna data NAHI rakhti — GRN + Purchase Return
                        ka register hai, aur har supplier ka baqaya.
                        Supplier ko payment bhi yahin se.
  Shift Closing History blob chhor kar asli `cashier_shifts` par —
                        opening, expected, counted aur VARIANCE
  Suppliers / Expenses  server par pehle se asli thin; ab screen par
  Staff / Printers      bhi nazar aati hain (Bug 2)
  Branches / Promotions

  DO NAYI REPORTS
  Stock movement        har tabdeeli: kab, kya, kitna, kis document se,
                        kis ne ki, aur baad ka balance
  Stock card (by item)  ek item ki poori tareekh

  HATAYA GAYA
  Stock Transfer        aap ke kehne par. Retail mein har branch apna
                        hisaab khud rakhti hai. Menu se bhi nikal gaya
                        taake khokhla screen na rahe. Restaurant ka
                        transfer jaisa tha waisa hi hai.

  JAAN BOOJH KAR WAISE HI
  WhatsApp templates    sirf templates hain — `ui_records` kaafi hai
  Barcode & Shelf Labels / Tax (FBR)   offline node par chalte hain


------------------------------------------------------------
 5) EK AUR PEHRA
------------------------------------------------------------
V153 ke teeno bug ek hi shakal ke thay: ek fehrist DO jagah likhi hui,
dono chup chaap alag ho gayin. Screen khulti thi, "Saved" aata tha,
peeche kuch nahi hota tha — aur koi error kahin nazar nahi aata tha.

`scripts/check_retail_wiring.php` har deploy par wahi milan karti hai:
har screen ka config mojood hai, har document type server ko maloom
hai, aur koi module chup chaap blob par to nahi reh gaya.

    RETAIL_WIRING_OK configs=21 docs=6 screens=41 real=9+7+6 blob=1

Is pehre ke baghair agla module phir isi tarah adhoora chala jata, aur
pata tab chalta jab kisi customer ka supplier ghayab hota.


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    Windows ka "Replace or Skip" extraction ko toot-phoot deta hai,
    is liye pehle delete, phir extract.

    VERSION
    config\local.php
    public\api.php
    public\router.php
    scripts\migration_order.php
    scripts\seed_industry_modules.php
    src\ModuleGroups.php                 (src\Services\ModuleGroups.php)
    src\Services\RetailCatalog.php
    src\Services\RetailPos.php
    src\Services\RetailReportService.php
    approved_ui\retail\module_config.js
    approved_ui\retail\retail_api.js
    approved_ui\retail\shell.js
    approved_ui\retail\grn.html
    approved_ui\retail\purchase_return.html
    approved_ui\retail\purchase_orders.html
    approved_ui\retail\returns.html
    approved_ui\retail\stock_count.html
    approved_ui\retail\wastage.html
    approved_ui\retail\closing.html
    approved_ui\retail\purchasing.html

    YEH FILE DELETE KAREIN AUR WAPAS NAHI AAYEGI:
    approved_ui\retail\stock_transfer.html

    YEH NAYI HAIN — delete karne ki zaroorat nahi:
    src\Services\RetailStock.php
    src\Services\RetailDocs.php
    scripts\migrate_retail_ops.php
    scripts\check_retail_wiring.php
    approved_ui\retail\doc.js
    approved_ui\retail\doc_config.js

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V154.zip extract karein, phir Railway par deploy.

STEP A3 — DEPLOY LOG MEIN YEH DEKHEIN
        MIGRATIONS_OK
        RETAIL_OPS_OK               <- nayi (created=rtl_stock_moves,...)
        MODULE_GROUPS_OK
        SETTINGS_CHECK_OK
        OFFLINE_TOOLS_OK
        COLLATION_OK
        RETAIL_WIRING_OK            <- nayi

STEP A4 — VERIFY (har file ke liye search string — Ctrl+F)

      FILE                                   SEARCH FOR
      -------------------------------------  ------------------------
      VERSION                                V154
      src\Services\RetailStock.php           class RetailStock
      src\Services\RetailDocs.php            applyAverageCost
      scripts\migrate_retail_ops.php         RETAIL_OPS_OK
      scripts\check_retail_wiring.php        RETAIL_WIRING_OK
      approved_ui\retail\doc.js              RetailDoc
      approved_ui\retail\doc_config.js       DOC_CONFIGS
      approved_ui\retail\retail_api.js       moduleOf
      src\Services\RetailCatalog.php         barcodes
      src\Services\RetailPos.php             RetailStock::move
      public\api.php                         rdoc-post
      public\router.php                      approved_ui/retail' &&
      src\Services\RetailReportService.php   stock_moves

STEP A5 — BROWSER MEIN CHECK (retail business se login)
    1. Product Catalog -> naya product barcode ke saath banayein ->
       POS par wahi barcode scan karein. Pehle yeh kabhi nahi chalta tha.
    2. Suppliers -> ek supplier banayein -> reload -> list mein nazar
       aana chahiye.
    3. Goods Receipt -> + New receipt -> supplier chunein, item search
       karein, qty aur cost dalein, Add -> Receive.
       Stock on Hand par stock barha hua hoga aur cost average ho chuki
       hogi.
    4. Purchasing -> us GRN ka register aur supplier ka payable.
    5. Reports -> Stock movement -> har harkat ek line par.
    6. Sidebar mein Stock Transfer ab nahi hona chahiye.


============================================================
 PART B — OFFLINE CUSTOMERS
============================================================
Customer sirf UPDATE.bat chalaye.

Nayi tables (`rtl_stock_moves`, `rtl_docs`, `rtl_doc_lines`,
`rtl_supplier_ledger`) sync ki PUSH list mein hain — branch par bani
hui har harkat cloud tak jati hai. Pull mein jaan boojh kar NAHI: yeh
branch ka apna hisaab hai, aur aap ne kaha tha ke har branch individual
rahegi.


============================================================
 PART C — RESTAURANT KO KYA HUA: KUCH NAHI
============================================================
Yeh sirf dawa nahi hai. Build se PEHLE restaurant ka poora record liya
gaya tha, aur baad mein wahi dobara:

  SCREENS      43 screens pehle: sab HTTP 200, koi redirect nahi, koi
               JavaScript error nahi
               43 screens baad mein: BILKUL WAHI — status aur redirect
               mein 0 farq, errors 0

  ASLI BILL    Build se pehle restaurant POS se asli bill kaata
               (bill 0002, PKR 1,300). Build ke baad wahi bill dobara
               kaata (bill 0003) — wahi jawab, wahi shakal, order_items
               aur payments dono darj.

  DATABASE     menu, inventory, customers, suppliers, shifts,
               dining_tables, ui_records — sab ginti bilkul wahi. Sirf
               wo bill barha jo maine khud kaata.

  FILES        25 files badlin. Un mein se 21 ya to `approved_ui/retail/`
               mein hain ya retail ki apni services (RetailStock,
               RetailDocs, RetailCatalog, RetailPos, RetailReportService).

               Teen mushtarak files badlin aur teenon ki tabdeeli
               retail se bandhi hui hai:
                 public\api.php      — nayi actions, HAR EK
                                       `needRetail()` se gated
                 public\router.php   — retail ka module map, retail ka
                                       cache-bust, aur ops_db.js ab
                                       sirf NON-retail par
                 config\local.php    — nayi rtl_ tables sync list mein

               `seed_industry_modules.php` aur `ModuleGroups.php` mein
               `transfer` COMMON se RESTAURANT mein chala gaya — yani
               restaurant ke paas pehle ki tarah hai, retail se hat gaya.

  CHHUA TAK NAHI GAYA:
               ModuleBridge · PurchaseService · InventoryService ·
               PosService · inventory_items · stock_transactions ·
               aur koi bhi `approved_ui/*.html` restaurant page

  EK GHALTI JO IS CHECK NE PAKRI:
               Transfer hatate waqt wo galti se restaurant ke module map
               se bhi nikal gaya tha. Screen sweep ne nahi pakra (test
               business ke paas saare module khule hue thay) — file ka
               line-by-line milan ne pakra. Theek kar diya gaya aur
               dobara check kiya.


============================================================
 TESTING JO KI GAYI
============================================================
  - Taza database, poora schema + 33 migrations
  - Asli RETAIL business, asli RESTAURANT business, dono ek saath
  - Barcode: `barcodes[]` wale tareeqe se product bana kar scan kiya —
    ab milta hai (pehle "Not found")
  - Server par 1 row daal kar screen ki rows gini: suppliers, expenses,
    staff, branches — chaaron ab 1 (pehle 0)
  - Poora stock ka chakkar, har qadam par stock gina:
        21 -> sale 4      -> 17 ... (POS)
        21 -> GRN 20@120  -> 41   cost 100 -> 109.7561 (weighted avg)
        41 -> PRET 5      -> 36
        36 -> RET 2       -> 38
        38 -> CNT 30      -> 30   (farq -8 adjustment)
        30 -> PO 50       -> 30   (order se stock nahi hilta)
        30 -> wastage 3   -> 27
  - GRN dobara post kiya -> stock nahi badla (idempotent)
  - DRAFT save kiya -> stock bilkul nahi hila (sirf POSTED par hilta hai)
  - Supplier ledger: GRN par payable 2,400 bana; batch B-01 exp
    2027-03-31 bhi ban gaya
  - Poora UI ka chakkar browser mein: search -> item add -> post ->
    stock 27 se 34
  - Reports: stock_moves aur stock_card dono chale, 7 harkatein sahi
    tarteeb aur sahi balance ke saath
  - 43 retail screens: sab HTTP 200, koi error nahi
  - 43 restaurant screens: baseline se 0 farq
  - check_retail_wiring: RETAIL_WIRING_OK (blob 9 se 1 reh gaya)
  - check_module_groups: MODULE_GROUPS_OK (is ne transfer wali
    adhoori safai pakri thi)

------------------------------------------------------------
 Wabwar Software House   www.wabwar.pk   0342-5095104
 V154 build 2026-10-13
------------------------------------------------------------
