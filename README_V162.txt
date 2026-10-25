============================================================
 V162 — CLOSING PARCHI PARHNE KE QABIL + STOCK ADJUST / SIFAR
 Wabwar Software House                     build 2026-10-24
============================================================

Do kaam: roz raat wali closing parchi theek ki gayi, aur inventory ko
sahi number par lane (ya sab sifar karne) ka raasta bana. Baqi kisi
cheez ko haath nahi lagaya: POS, bill, KOT, FBR, reports, owner app —
sab V161 jaise hain.


############################################################
 ***  SAB SE AHEM — CONFIG\LOCAL.PHP KO MAT BADLEIN  ***
############################################################

Zip mein `config\local.php` bhi hai, MAGAR dukan ke computer par jo
file pari hai us mein us dukan ka apna database password, tenant id
aur sync token hai. Us ko REPLACE kar dein to node cloud se kat jayega.

KARNA YEH HAI — purani file khol kar, `push_tables` ki fehrist mein
yeh teen naam HAATH SE barha dein (jahan `stock_adjustments` likha hai
us ke saath):

        'stock_transactions', 'stock_transaction_lines', 'stock_balances',

Bas. Baqi file waisi hi rehne dein.

Yeh kyun zaroori hai: yeh teen tables sync ki push fehrist mein THIN
HI NAHI. Is liye dukan ke computer par stock badalne se (ginti,
adjustment, sab sifar) online par kuch nazar hi nahi aata tha. Naye
sealed package apni config khud banate hain aur un mein yeh pehle se
hain — masla sirf un nodes par tha jo isi file par chalte hain.


------------------------------------------------------------
 1 — DAILY CLOSING KI PARCHI
------------------------------------------------------------
Paanch cheezein theek ki gayin:

a) LAMBA NAAM AB KATTA NAHI
   Pehle parchi naam kaat kar ek nuqta laga deti thi:
       Cashi. Muhammad Abdul Rehman Siddiqui
       17 x Garlic Naan with Extra . 1,163
   Aur yeh batati bhi nahi thi ke kuch kata hai. Ab naam agli line par
   chala jata hai aur raqam aakhri line par — kuch gum nahi hota.
   (80mm roll par ek line mein sirf 37 harf ate hain.)

b) STOCK KA PAIMANA AB LIKHA AATA HAI
   Pehle: "opening 2399.5  sold 0  left 2399.5" — 2399.5 kya? gram?
   kilo? Aur teen number ek line par thunse hue.
   Ab har cheez ki teen alag line, paimane ke sath:
       Mozzarella Cheese
         opening                     2.4 kg
         sold                          0 kg
         left                        2.4 kg
   Paimana poore item ke sab se bare number se chunta hai, har number
   ka apna nahi — warna "2.4 kg" ke neeche "5 g" chhap jata aur dono
   ka moqabla na hota. (Wahi usool jo owner app mein hai.)

c) DARAZ KA ASAL HISAAB
   Shift khuli ho to `expected_cash` / `actual_cash` abhi likhe hi
   nahi gaye hote (wo band karte waqt bharte hain). Pehle wahi sifar
   chhap jate thay aur neeche BALANCED — jabke din bhar ki sale ho
   chuki hoti. Ab:
       Opening float              5,000
       Cash sales                 9,540
       Less cash expenses          -300
       Expected in till          14,240
       ---------------------------------
             Shift is still open
          Cash is counted at closing
   Band shift par pehle ki tarah Counted aur CASH OVER / CASH SHORT.

d) UNWAN AB MATN KE BARABAR
   "SALES BY CATEGORY", "CASH IN TILL", "TRACKED STOCK" pehle size 8
   par chhapte thay jabke matn size 9 par. Thermal roll par chhote
   harf sab se pehle dhundle hote hain. Ab barabar, aur bayen taraf —
   aankh seedhi neeche chalti hai.

e) SAR-NAMA PAR BUSINESS DATE
   "DAILY CLOSING" ke neeche us din ki tareekh. Pehle sirf "Opened"
   ke sath waqt likha tha.

PURANI BAND SHIFTS: jin ka snapshot V162 se pehle ka hai un par
"Cash sales" ki line chhapti hi nahi — purani report jaisi thi waisi
hi rehti hai, badalti nahi.


------------------------------------------------------------
 2 — ADJUST STOCK / SAB SIFAR  (naya)
------------------------------------------------------------
      Inventory  ->  "Adjust Stock"  (header mein, Stock In ke saath)

SIRF IS PROPERTY KA ADMIN. Manager ko bhi nahi — yeh ek hi baar mein
poori inventory badal sakta hai. Cashier ya manager login kare to
button nazar hi nahi aata, aur agar koi seedha API chalaye to server
403 deta hai (sirf button chhupana kaafi nahi hota).

KAISE CHALTA HAI
  • Har item ke saamne "On hand" likha hai aur ek khana "New quantity"
  • Jo ginti aap ne ki hai wohi number likh dein
  • "Set all to zero" — sab khane sifar kar deta hai (handover, saal
    ke aakhir, ya nayi shuruaat ke liye)
  • "Undo changes" — sab wapas
  • Naam se dhoondne ka khana
  • Reason likhein (stock history mein yehi nazar aayega)
  • Save se pehle saaf poochta hai: kitne item badlen ge, kitne sifar

YEH SEEDHA NUMBER NAHI BADALTA. Har adjustment ASAL stock transaction
banati hai (type ADJUSTMENT), bilkul Stock In ki tarah. Is liye:
  - inventory report ka Opening / Received / Used / Closing mel khata
    rehta hai
  - stock history mein pata chalta hai kis ne, kab aur KYUN badla
  - aur sync khud ba khud upar le jati hai (agla hissa)

HAR LOCATION THEEK HOTI HAI. Stock ek se ziada jagah para ho sakta hai
(Kitchen + Store). Sirf default location theek karne se kul number
wahi ka wahi reh jata, is liye: aap ka diya hua number default
location par, baqi sab sifar.

NOTE — purane "Wastage / Adjustment" aur "Physical Stock Count" wale
safhe stock ko HAATH NAHI LAGATE. Wo sirf fehrist rakhte hain (item ka
naam bhi wahan khali likha jata hai, asal inventory item se juRa hua
nahi). Yani ab tak stock ko kisi khaas number par lane ka koi raasta
tha hi nahi — yeh pehla hai.


------------------------------------------------------------
 3 — OFFLINE SE ONLINE, KHUD BA KHUD
------------------------------------------------------------
Dukan ke computer par stock sifar karein — agle sync par online bhi
sifar ho jata hai. Alag se kuch karne ki zaroorat nahi.

OFFLINE KA LIKHA HUA AAKHRI FAISLA HAI:
  • Node se jo upar jata hai, cloud use bina sawal qabool karta hai
  • Cloud se neeche aane wali PURANI row ko node qabool NAHI karta
    (us ki apni row nayi ho to)
  • Yeh teen tables sirf PUSH mein hain, PULL mein jaan boojh kar
    nahi — taake cloud ka purana number dukan ke asal stock ko na
    mitaye

Chala kar dekha gaya (do alag database, asal sync worker se):
  node 126,264  ->  node par sab sifar  ->  ek sync pass  ->
     push stock_transactions        1 row
     push stock_transaction_lines  10 rows
     push stock_balances           10 rows
  natija: node 0, cloud 0, aur adjustment ki entry (10 lines samet)
  cloud par bhi mojood — is liye cloud ki reports bhi theek rehti hain.

Ulta imtihan bhi: cloud par jaan boojh kar 9,990 ka purana number
thonsa gaya -> node ne 10 ki 10 rows chhor din (skipped), apna sifar
qayam rakha.


------------------------------------------------------------
 TESTING (chala kar, guess nahi)
------------------------------------------------------------
  CLOSING PARCHI
    Har line 37 harf ki hadd ke andar (khuli aur band, dono shift) PASS
    Lamba item naam -> kata nahi, agli line par gaya            PASS
    Lamba cashier naam -> kata nahi                             PASS
    Tracked stock: 2399.5 g -> "2.4 kg", teen alag line         PASS
    Khuli shift -> Expected in till asal hisaab, BALANCED nahi  PASS
    Band shift -> Counted + CASH OVER/SHORT pehle jaisa         PASS
    Purana snapshot -> "Cash sales" line chhapti hi nahi        PASS

  ADJUST STOCK (browser mein)
    Admin ko button nazar aata hai                              PASS
    Dialog khulta hai, 10 item bharte hain                      PASS
    Search chalta hai (10 -> 1)                                 PASS
    Ek item ko exact number par -> 1 line ki ADJUSTMENT bani    PASS
    "Set all to zero" -> 10 item, 10 line ki ADJUSTMENT bani    PASS
    "Undo changes" sab wapas le aata hai                        PASS
    Koi JS error nahi                                           PASS
    Cashier ko button nazar NAHI aata                           PASS
    Cashier ka seedha API call -> HTTP 403                      PASS

  SYNC (do alag database, asal sync_worker.php se)
    node par sifar -> ek pass -> cloud par bhi sifar            PASS
    adjustment transaction + 10 lines cloud par pohanchin       PASS
    cloud ka purana number node ne qabool nahi kiya (10 skipped) PASS

  run_migrations --stage=all -> ok=42 skipped=0 failed=0
  Restaurant 56 screens -> 0 nayi kharabi, 0 JS error
  Retail      41 screens -> 0 kharabi, 0 JS error


------------------------------------------------------------
 JO IS UPDATE MEIN NAHI HAI
------------------------------------------------------------
  - Retail (supermarket) ka stock alag tables par hai
    (rtl_products.stock_qty). "Adjust Stock" abhi sirf restaurant
    inventory par hai. Kahein to retail ke liye bhi bana doon.
  - Retail ki apni closing parchi alag hai — us par yeh tabdeeliyan
    nahi lagin.
  - Pichhle audit ke do point abhi bhi baqi: counter ki parchi par FBR
    number, aur PENDING FBR bills ka khud retry.


============================================================
 PART A — OFFLINE PACKAGE (customer ka PC)   *** PEHLE YEH ***
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    Windows ka "Replace or Skip" extraction ko toot-phoot deta hai,
    is liye pehle delete, phir extract.

    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\inventory_creation.html
    public\api.php
    public\router.php
    src\Services\BillTemplate.php
    src\Services\InventoryService.php
    src\Services\OpsService.php

    config\local.php KO DELETE NA KAREIN — ooper wala hissa parhein,
    us mein sirf teen naam haath se barhane hain.

    KOI NAYI FILE NAHI HAI (README ke ilawa).

STEP A2 — EXTRACT + UPDATE.bat
    Koi migration nahi, is liye seedha hai.

STEP A3 — CONFIG MEIN TEEN NAAM
    Ooper wala hissa — `push_tables` mein:
        'stock_transactions', 'stock_transaction_lines', 'stock_balances',

STEP A4 — CHALA KAR DEKHEIN
    1. Admin se login -> Inventory -> "Adjust Stock" nazar aaye
    2. Ek item ka number badlein -> Save
    3. Thori der baad (ya sync chalne par) online portal par wohi
       number nazar aaye


============================================================
 PART B — SERVER (Railway)
============================================================

STEP B1 — wohi files delete karein jo PART A mein likhi hain
          (config\local.php ka cloud par koi taaluq nahi —
           cloud `config/cloud.php` chalata hai)

STEP B2 — EXTRACT + DEPLOY

STEP B3 — DEPLOY LOG MEIN YEH DEKHEIN
        MIGRATIONS_OK
        MENU_SORT_OK
        DISCOUNTS_OK
        MODULE_GROUPS_OK
        COLLATION_OK
        RETAIL_WIRING_OK

STEP B4 — VERIFY (har file mein Ctrl+F)

      FILE                                  SEARCH FOR
      ------------------------------------  ----------------------------
      VERSION                               V162
      src\Services\BillTemplate.php         private static function rows
      src\Services\BillTemplate.php         TRACKED STOCK
      src\Services\BillTemplate.php         Expected in till
      src\Services\InventoryService.php     function adjustTo
      src\Services\OpsService.php           cash_sales
      public\api.php                        case 'stock-adjust'
      public\router.php                     ?b=v25
      approved_ui\inventory_creation.html   adjustBtn
      config\local.php                      stock_transaction_lines


------------------------------------------------------------
 BROWSER MEIN CHECK
------------------------------------------------------------
 1. Admin se login -> Inventory -> "Adjust Stock" button
 2. Ek item ka number badal kar Save -> list mein naya number
 3. Inventory report -> us item ke saamne adjustment nazar aaye
 4. Cashier se login -> wohi screen -> button nazar NA aaye
 5. Shift chal rahi ho to closing report chhapein ->
    "Expected in till" asal raqam ho, sifar nahi
 6. Kisi item ko tracked karein -> closing parchi par paimane ke
    sath teen line (opening / sold / left)

Madad: Wabwar Software House — +92 342 5095104

// build: V162 build 2026-10-24
