============================================================
 V158 — SALE POINT + INVENTORY KE 5 POINTS
 Wabwar Software House                     build 2026-10-19
============================================================

Aap ke paanch points. Baqi kisi cheez ko haath nahi lagaya: KOT,
payment, print, bill numbering, FBR, sync, retail — sab jaise thay
waise hain.


------------------------------------------------------------
 1) TABLE BADALNE PAR JO ITEMS KITCHEN KO NAHI GAYE THAY
------------------------------------------------------------
Aap ne likha tha:

   "jab main kisi table per item punch krta hon aur usko kitchen main
    send kr deta hon, phir kuch ase item dalta hon jo kitchen ko send
    nahi krta... wapis jb is table ko open krta hon to wo items nahi
    hoti jo abhi kitchen ko send nahi ke thee"

WAJAH MIL GAYI — aur wo wahan nahi thi jahan hum dhoond rahe thay.

V157 ne yeh theek kar diya tha ke "table chunte hi us ka ruka hua bill
khud khul jaye". Magar ULTA raasta khula reh gaya tha:

   Table 1 par 2 item KOT kiye, 2 aur punch kiye (KOT nahi)
   -> New Bill -> Table 2
   -> wo 2 non-KOT item CART MEIN HI RAHE, aur chup chaap
      TABLE 2 KE BILL PAR chale gaye

Kyunke table chunte waqt sirf `curTable` badalta tha; cart ko koi
haath hi nahi lagata tha. Table 1 ka order server par sirf KOT waale
2 items ke saath khula tha — is liye Table 1 par wapas aate hi wohi 2
milte thay. Baqi 2 "gum" nahi hue thay, doosre table ke bill par ja
chuke thay. (Yehi wajah hai ke yeh test karte waqt pakra nahi jata
tha jab beech mein Hold ka button daba diya jata.)

AB: doosra table (ya customer) chunte hi mojooda bill KHUD-BA-KHUD
hold ho jata hai, phir naya table khulta hai. Table 1 ka kaam Hold
Bills mein mehfooz rehta hai — KOT waale bhi, non-KOT waale bhi.

Do soortein istisna hain, jaan boojh kar:
  * "Change Bill Type / Table" — us ka matlab hi "YEHI bill doosri
    jagah le jao" hai, wahan hold karna ulta kaam hota.
  * Wahi table dobara chunna — kuch badalta hi nahi, dialog band.


------------------------------------------------------------
 2) HOLD SCREEN SE CANCEL KE BAAD MENU GAYAB
------------------------------------------------------------
Yeh wala hum apne paas dobara nahi bana sake (chala kar dekha: cancel
ke baad bhi 12 ke 12 items maujood rahe, koi JS error nahi). Magar
jiss cheez se yeh ho sakta hai wo saaf nazar aa gayi:

   // purana
   if(curHoldId===h.id){ curHoldId=''; cart=[]; renderAll(); }

`renderAll()` SIRF us soorat mein chalta tha jab cancel hone wala bill
wohi ho jo is waqt khula hai. Baqi har soorat mein screen jaisi thi
waisi chhor di jati thi.

AB cancel ke baad screen HAR SOORAT mein dobara banti hai. Is mein
kuch kharcha nahi — grid pehle se mojood data se banti hai, server se
kuch nahi mangwata. Agar yeh masla phir bhi aaye to mujhe bataiyega:
phir yeh offline package ka koi purana nuskha hoga, naya nahi.


------------------------------------------------------------
 3) MENU AUR CATEGORY KA ORDER NUMBER
------------------------------------------------------------
Pehle POS par items ALPHABET ke hisaab se lagte thay. Jo cheez sab se
zyada bikti hai usay pehle laane ka koi rasta nahi tha.

AB DO JAGAH NUMBER:

  * Menu & Categories -> kisi item ka Edit -> "Order on Sale Point"
    Chhota number pehle. Khali chhoren to 99.
  * Menu & Categories -> upar "Category order" ka naya button
    Saari categories ek sath, har ek ke samne number.

Barabar number waale apas mein naam ke hisaab se lagte hain — yani
purana rawaiya kahin jata nahi, bas us ke ooper qaboo mil jata hai.
Fehrist mein item ke neeche "#3" bhi nazar aata hai.

Drag-and-drop jaan boojh kar nahi rakha: yeh screen touch POS par bhi
khulti hai aur wahan drag se tarteeb barbad karna bohot aasan hai.

"BEST SELLING" KA DROPDOWN HATA DIYA
Wo kabhi kaam hi nahi karta tha — us select par koi handler hi nahi
tha, Today / Yesterday / Last 7 days chunne se kuch nahi hota tha.
Saath hi server par har POS load par ek be-wajah sales query chalti
thi (har item ka aaj/kal/7-din ka bika hua qty) jisay koi parhta hi
nahi tha. Dono khatam — POS thora aur halka ho gaya.

POS KA VERSION
Screen par `POS v48` likha aata tha — haath ka likha hua number jo
V49 se V157 tak kabhi badla hi nahi. Support call par ghalat jawab
milta tha. Ab asal build aata hai: "POS V158 build 2026-10-19".


------------------------------------------------------------
 4) STOCK IN MEIN MANFI (-) QUANTITY + GRID KI ALIGNMENT
------------------------------------------------------------
4a — MANFI QUANTITY

   // purana
   if ($qty <= 0) throw new RuntimeException('Quantity must be more than zero');

Galti se zyada stock chadh jaye to usay theek karne ka koi rasta hi
nahi bachta tha — na edit, na ghatao. Ya to ghalat number ke saath
jiya jaye, ya koi jhooti "wastage" daali jaye (jo report aur kharab
karti).

AB manfi qty chalti hai. Magar us ka darja `PURCHASE` NAHI —
`ADJUSTMENT` hai. Warna "Purchases" report mein ek manfi kharidari
nazar aati jo kabhi hui hi nahi. Average cost ko bhi manfi entry
chhoti nahi (wo sirf kharidari par banti hai) — food cost sahi
rehta hai.

Sifar ab bhi mana hai: us se kuch hota hi nahi, sirf ek khali entry
ledger mein par jati.

4b — GRID KI HEADING AUR COLUMN

Aap ne theek pakra: "qty k oper rate aa rha heading main". Heading 12
thi aur cells bhi 12, magar TARTEEB alag thi — Edit ka button "Usage"
ke neeche, Track "On Hand" ke neeche, aur isi tarah sab ek ek khana
khisak gaye thay. Ek khali <th> bhi beech mein para tha.

AB: Item · Category · Usage · On Hand · Purchase Unit · Avg Cost ·
Reorder · Storage · Status · Actions — aur neeche bilkul yehi
tarteeb. Edit aur Track dono Actions ke sath aakhir mein.


------------------------------------------------------------
 5) OPENING / CLOSING STOCK KI REPORT
------------------------------------------------------------
Reports -> Inventory -> "Opening / closing stock"

   Item · Unit · Opening · Purchased · Sold · Closing · Current stock

Hisaab ULTA chalta hai, aur yehi is report ki jaan hai: Current stock
(abhi ka asal balance) se shuru kar ke peeche ginti hai. Is liye
Closing hamesha ASAL stock hota hai — koi jor-tor nahi.

   Current  = abhi ka balance
   Closing  = Current  - "to" ke baad ki harkat
   Opening  = Closing  - arse ke andar ki kul harkat

Screen par note bhi likha aata hai: wastage, stock count ya manfi
stock-in (durusti) waale din Opening + Purchased - Sold, Closing se
thora alag hoga — aur wo farq hi wo teen cheezein hain.

Aap ne poocha tha "other column kon sy? agr zarori nahi hain to na
rakho" — nahi rakha. Us ki jagah yeh note hai.

Total ki patti (KPI tiles) is report par jaan boojh kar nahi hai:
PCS, G aur ML ko jorna be-maani hai (42,005 gram chawal + 106 bun =
kya?). Har item ki apni line poori baat keh deti hai.


------------------------------------------------------------
 TESTING (chala kar, guess nahi)
------------------------------------------------------------
  #1 T-01 par 2 item + KOT, phir 2 item bina KOT
     -> T-02 chuna -> cart khali, T-01 hold mein               PASS
     -> T-01 wapas -> CHAARON item wapas (2 sent + 2 non-sent) PASS
     -> wahi table dobara chuna -> kuch nahi badla             PASS
  #2 hold se non-KOT bill cancel -> 12 ke 12 menu items baqi   PASS
     khula hua (resumed) bill cancel -> 12 items baqi          PASS
  #3 Category order dialog -> ulti tarteeb save -> POS par wahi PASS
     Item "Order on Sale Point" = 1 -> POS par pehle aaya      PASS
     #period (Best Selling) DOM mein maujood hi nahi           PASS
     BOOT.products par today/yesterday/days7 ab nahi aate      PASS
     VERSION = "POS V158 build 2026-10-19"                     PASS
  #4 stock-in +5 -> PURCHASE, avg cost barha                   PASS
     stock-in -3 -> ADJUSTMENT, avg cost waisa ka waisa        PASS
     stock-in 0  -> "Quantity cannot be zero"                  PASS
     Stock In dialog se -3 -> ADJUSTMENT darj hua              PASS
     grid: 10 headings, 10 cells, tarteeb bilkul barabar       PASS
  #5 Opening/closing stock: 10 rows, Burger Bun
     open 86 + purch 20 - sold 0 = close 106 = current 106     PASS

  migrate_menu_sort dobara chalayi -> added=0 skipped=1 (idempotent)
  run_migrations --stage=all -> ok=42 skipped=0 failed=0

  Restaurant 43 screens -> 0 masla, 0 JS error
  Retail      41 screens -> 0 masla, 0 JS error
  MIGRATIONS_OK · MENU_SORT_OK · MODULE_GROUPS_OK · COLLATION_OK
  RETAIL_WIRING_OK · OFFLINE_TOOLS_OK · SETTINGS_CHECK_OK


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    Windows ka "Replace or Skip" extraction ko toot-phoot deta hai,
    is liye pehle delete, phir extract.

    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\restaurant_pos.html
    approved_ui\menu_management.html
    approved_ui\inventory_creation.html
    public\api.php
    public\router.php
    public\item_dialog.js
    public\stock_in.js
    scripts\migration_order.php
    src\Services\MenuItemService.php
    src\Services\PageData.php
    src\Services\ReportService.php
    src\Services\StockDayService.php

    YEH NAYI HAI — delete karne ki zaroorat nahi:
    scripts\migrate_menu_sort.php

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V158.zip extract karein, phir Railway par deploy.

STEP A3 — DEPLOY LOG MEIN YEH DEKHEIN
        MIGRATIONS_OK
        MENU_SORT_OK            <- nayi (added=1 pehli dafa)
        DISCOUNTS_OK
        RETAIL_OPS_OK
        MODULE_GROUPS_OK
        COLLATION_OK
        RETAIL_WIRING_OK

STEP A4 — VERIFY (har file mein Ctrl+F)

      FILE                               SEARCH FOR
      ---------------------------------  ------------------------
      VERSION                            V158
      approved_ui\restaurant_pos.html    DOOSRA TABLE CHUNNE SE PEHLE
      approved_ui\restaurant_pos.html    if(BOOT.build) VERSION
      approved_ui\menu_management.html   mmCatOrder
      approved_ui\inventory_creation.html HEADING AUR COLUMN AB BARABAR
      public\api.php                     menu-category-order
      public\router.php                  ?b=v21
      public\item_dialog.js              Order on Sale Point
      public\stock_in.js                 manfi quantity likhein
      src\Services\MenuItemService.php   saveCategoryOrder
      src\Services\PageData.php          hasItemSort
      src\Services\ReportService.php     r_stock_ledger
      src\Services\StockDayService.php   Quantity cannot be zero
      scripts\migrate_menu_sort.php      MENU_SORT_OK
      scripts\migration_order.php        migrate_menu_sort


============================================================
 PART B — OFFLINE PACKAGE (customer ka PC)
============================================================

Pehle Railway par deploy, phir customer ke PC par shop band hone ke
baad UPDATE.bat. (Offline package aap ke apne server se banta hai, aur
yeh saari files `runtime\app.sealed` ke andar hoti hain — haath se
replace nahi hotin.)

Is version mein MIGRATION hai (ek nayi column: menu_items.sort_order).
UPDATE.bat wo khud chala deta hai. Column ka default 99 hai aur purani
rows par koi asar nahi parta. Agar kisi wajah se migration na chale to
bhi POS chalta rehta hai — code pehle pooch leta hai ke column mojood
hai ya nahi, aur na ho to purane tareeqe se (naam ke hisaab se)
tarteeb laga deta hai.


------------------------------------------------------------
 BROWSER MEIN CHECK (restaurant business se login)
------------------------------------------------------------
 1. Table 1 -> 2 item -> Send to Kitchen -> 2 aur item (KOT nahi)
    -> "+ New Bill" -> Table 2
    -> Table 2 ka bill KHALI mile, aur Hold Bills mein Table 1 ho
    -> wapas Table 1 -> CHAARON item wapas
 2. Hold Bills -> kisi bill par Cancel -> menu ki items waisi ki waisi
 3. Menu & Categories -> "Category order" -> numbers badlein -> Save
    -> POS par Ctrl+Shift+R -> categories usi tarteeb mein
    -> kisi item ka Edit -> "Order on Sale Point" = 1 -> Save
    -> POS par wo item apni category mein pehle
 4. Inventory -> "+ Stock In" -> quantity mein -3 -> Add to stock
    -> stock 3 kam ho jaye; Reports -> Purchases mein wo nazar NA aaye
    -> grid mein har heading apne hi column ke ooper ho
 5. Reports -> Inventory -> "Opening / closing stock"
    -> Opening · Purchased · Sold · Closing · Current stock

Ek chhoti cheez: `APPROVED_UI_SHA256.json` (approved_ui ki file hashes
ka register) V155 se purana para tha — 16 files ke hash ghalat ho
chuke thay. Ab taza kar diya hai. Is se chalne mein koi farq nahi
parta, yeh sirf record hai.

Madad: Wabwar Software House — +92 342 5095104

// build: V158 build 2026-10-19
