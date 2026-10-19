============================================================
 V157 — SALE POINT KE 4 CRITICAL ISSUES
 Wabwar Software House                     build 2026-10-19
============================================================

Aap ke chaar points, chaaron theek. Baqi kisi cheez ko haath nahi
lagaya: KOT, payment, print, bill numbering, FBR, retail — sab jaise
thay waise hain.


------------------------------------------------------------
 1) TABLE CHUNTE HI US KA RUKA HUA BILL KHUD KHULE
------------------------------------------------------------
PURANA:
    curTable = this.dataset.t;  done();      // sirf label badla

Table 1 ka 10-item wala bill hold karne ke baad, New Bill se dobara
Table 1 chunne par KHALI bill khulta tha. Bill `holds` mein mojood
hota tha — magar table chunte waqt usay dekha hi nahi jata tha.

AB: table chunte hi us table ka ruka hua bill khud khul jata hai.
Koi sawal nahi, seedha. Takeaway / Home Delivery mein table hota hi
nahi, is liye wahan CUSTOMER se milaya jata hai (naam se nahi, ID se
— do customers ka naam ek jaisa ho sakta hai).

"Shift / Change Table" jaan boojh kar waisa hi hai — us ka matlab hai
"yehi bill doosre table par le jao".


------------------------------------------------------------
 2) CURRENT STOCK KI REPORT
------------------------------------------------------------
Reports -> Inventory mein 7 reports thin, magar koi bhi "abhi kitna
stock hai" nahi batati thi:
    Low stock          -> sirf jin ka reorder level set ho AUR wo us
                          se neeche gir chuke hon
    Tracked inventory  -> sirf "tracked" mark kiye hue items
    Stock movement     -> aane jane ka hisaab, mojooda halat nahi

NAYI REPORT — "Current stock":

    Item · Category · Unit · On hand · Avg cost · Stock value
                                          TOTAL: PKR ______

Maliyat `avg_cost_per_stock_unit` se nikalti hai — wohi weighted
average jo Stock In ke waqt banti hai. Jin items ki cost kabhi daali
hi nahi gayi, un ki value 0 aayegi (aur yehi sach hai).

Ek chhoti cheez saath mein: report ke KPI tiles ab SIRF us column ke
bante hain jis ka total waqai bheja gaya ho. Pehle har numeric column
ka tile ban jata tha chahe us ka total hota hi na ho — aur us par "0"
chhap jata tha. "Current stock" par yeh saaf ghalat lagta: ON HAND 0
aur AVG COST 0, jabke neeche table bhara hua hai. (Aur yeh dono jamaa
karna be-maani bhi hai: PCS, G aur ML ko jorna kis kaam ka?) Purani
reports apne har numeric column ka total bhejti hain, is liye un par
koi farq nahi parta — chala kar dekh liya.

Yeh report DATE RANGE par nahi chalti — stock abhi ka hota hai. Screen
par note bhi likha aata hai, warna tareekh badal kar hairani hoti hai
ke number kyun nahi badla.


------------------------------------------------------------
 3) DISCOUNT HOLD/UNHOLD PAR BARQARAR
------------------------------------------------------------
Save side theek tha. Wapas lane par DO jagah toota hua tha:

    // OpsService::holdBillItems() — query mein PEHLE SE parhe ja rahe thay:
    SELECT o.tax_amount, o.discount_amount ...
    // magar return array mein in ka naam-o-nishan nahi tha

    // POS ka resume — `disc` ko haath hi nahi lagata tha

Dono theek. Aur ek naya column:

    orders.discount_pct    (migrate_discounts.php)

Kyun: `orders` mein sirf NATIJA (`discount_amount`) mehfooz tha. 10%
wala discount unhold par usi raqam ka FLAT discount ban jata — aur
us ke baad items badal jayen to wo raqam GHALAT ho jati (10% ka matlab
hi yeh hai ke wo bill ke sath badle). Ab percent bhi row par likha
jata hai, to "10%" hi wapas aata hai.

Discount ab cashier ke khud hatane tak laga rehta hai.


------------------------------------------------------------
 4) CUSTOMER KA TAY-SHUDA DISCOUNT %
------------------------------------------------------------
Pehle is ke liye jagah hi nahi thi — `customers` table mein koi
discount column nahi tha. Har bill par haath se lagana parta tha aur
bhool jana aam baat thi.

AB:
  * Nayi column `customers.discount_pct` (0-100 par mehdood, server
    par — screen par bharosa nahi kiya ja sakta)
  * Customers screen par Add aur Edit dono mein "Discount %" ka field,
    aur fehrist mein bhi column (ek nazar mein pata chale kis ko kitna)
  * POS ke "New Customer" dialog mein bhi wahi field
  * Customer chunte hi discount KHUD lag jata hai — aur cashier usay
    badal bhi sakta hai, hata bhi sakta hai

Jis customer ka discount 0 ho, us par koi chhera-chhari nahi hoti:
cashier ne pehle se jo discount laga rakha hai wo barqarar rehta hai.


------------------------------------------------------------
 TESTING (chala kar, guess nahi)
------------------------------------------------------------
  #1 Table 1 par 3 item -> hold -> New Bill -> Table 1 dobara
     -> purana bill KHUD khul gaya (3 item, wahi table)        PASS
  #3 10% discount -> hold -> unhold -> 10% barqarar            PASS
     grand total bhi bilkul wahi (1,368)                       PASS
  #4 BOOT.customers mein discount aa raha hai                  PASS
     Home Delivery -> customer chunte hi 10% khud laga         PASS
     cashier us ko badal sakta hai (10% -> 5%)                 PASS
     0% wale customer par cashier ka discount barqarar         PASS
  #2 Current stock report: 10 rows, TOTAL PKR 83,416.52        PASS

  migrate_discounts dobara chalayi -> added=0 skipped=2 (idempotent)
  run_migrations --stage=all -> ok=39 skipped=2 failed=0

  Restaurant 43 screens -> 0 masla, 0 JS error
  Retail      41 screens -> 0 masla, 0 JS error
  RETAIL_WIRING_OK · MODULE_GROUPS_OK · COLLATION_OK


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    Windows ka "Replace or Skip" extraction ko toot-phoot deta hai,
    is liye pehle delete, phir extract.

    VERSION
    approved_ui\restaurant_pos.html
    approved_ui\reports.html
    public\router.php
    public\module_config.js
    public\ui_action_modal.js
    scripts\migration_order.php
    src\Services\ModuleBridge.php
    src\Services\OpsService.php
    src\Services\PageData.php
    src\Services\PosService.php
    src\Services\ReportService.php

    YEH NAYI HAI — delete karne ki zaroorat nahi:
    scripts\migrate_discounts.php

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V157.zip extract karein, phir Railway par deploy.

STEP A3 — DEPLOY LOG MEIN YEH DEKHEIN
        MIGRATIONS_OK
        DISCOUNTS_OK            <- nayi (added=2 pehli dafa)
        RETAIL_OPS_OK
        MODULE_GROUPS_OK
        COLLATION_OK
        RETAIL_WIRING_OK

STEP A4 — VERIFY (har file mein Ctrl+F)

      FILE                              SEARCH FOR
      --------------------------------  ------------------------
      VERSION                           V157
      approved_ui\restaurant_pos.html   TABLE / CUSTOMER KA APNA BILL
      approved_ui\restaurant_pos.html   function applyCustomerDiscount
      src\Services\OpsService.php       discountPct
      src\Services\PosService.php       discount_pct=?
      src\Services\ReportService.php    r_stock_on_hand
      approved_ui\reports.html          KPI sirf us column ka
      src\Services\ModuleBridge.php     discount_pct=?
      src\Services\PageData.php         COALESCE(c.discount_pct,0)
      scripts\migrate_discounts.php     DISCOUNTS_OK
      scripts\migration_order.php       migrate_discounts
      public\module_config.js           Discount %
      public\router.php                 ?b=v20


============================================================
 PART B — OFFLINE PACKAGE (customer ka PC)
============================================================

Pehle Railway par deploy, phir customer ke PC par shop band hone ke
baad UPDATE.bat. (Offline package aap ke apne server se banta hai, aur
yeh saari files `runtime\app.sealed` ke andar hoti hain — haath se
replace nahi hotin.)

Is version mein MIGRATION hai (do nayi columns). UPDATE.bat wo khud
chala deta hai. Dono columns default 0 hain aur purani rows par koi
asar nahi parta.


------------------------------------------------------------
 BROWSER MEIN CHECK (restaurant business se login)
------------------------------------------------------------
 1. Table 1 -> 10 item -> Hold -> "+ New Bill" -> Table 1 dobara
    -> wohi 10 item khud wapas
 2. Kisi bill par 10% discount -> Hold -> dobara kholein
    -> 10% laga hua mile
 3. Customers -> kisi customer ko Edit -> Discount % = 10 -> Save
    -> POS -> Home Delivery -> wahi customer -> 10% khud lag jaye
    -> cashier chahe to badal de
 4. Reports -> Inventory -> "Current stock" -> har item,
    mojooda stock aur total maliyat

Madad: Wabwar Software House — +92 342 5095104

// build: V157 build 2026-10-19
