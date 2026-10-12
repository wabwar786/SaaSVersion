============================================================
 V146 — EK MENU, STOCK IN, DAY CLOSING
 Wabwar Software House
============================================================

TEEN KAAM HUE
-------------
1) POS ka "+ New Item" popup aur left menu ka "Menu & Categories"
   ab BILKUL EK HAIN. Pehle do alag program thay jo do alag jagah
   likhte thay — POS asli menu_items mein, Menu screen ui_records
   mein. Is liye POS par banaya item left menu mein nazar nahi aata
   tha, aur left menu se banaya "item" kabhi bik hi nahi sakta tha.

2) STOCK IN — item, qty, rate. Bas. Pehle stock sirf do tarah barh
   sakta tha: item banate waqt "opening stock", ya poora purchase
   order + GRN. Rozana ke liye dono bohot lambe thay.

3) DAY CLOSING (STOCK) — nayi screen:
       opening + received - sold - wastage = hona chahiye
       ginti - hona chahiye               = farq

   Saath mein SUPER_ADMIN_HANDBOOK.html bhi update ho gayi.


DONO TARAH KE RESTAURANT — AB DONO CHALTE HAIN
----------------------------------------------
Naye item popup mein teen option hain:

  No stock      kuch nahi katega
                (service charge, delivery fee)

  The same item jo bika WOHI stock se minus, har sale par itni units
                (Coke ki tin, paani ki bottle, cigarette)
                -> yahan se NAYA inventory item bhi bana sakte hain:
                   naam, unit, opening stock, cost — sab ek hi jagah

  By recipe     har ingredient alag alag minus, yield ke hisaab se
                aur waste % jorh kar
                (biryani -> chawal, murghi, masala)

Ek he business mein dono mil sakte hain — har item ka apna hisaab.


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    VERSION
    WHATS_UPDATED.md
    approved_ui\menu_management.html
    approved_ui\restaurant_pos.html
    public\api.php
    public\router.php
    public\shell.js
    approved_ui\retail\shell.js
    src\Services\PosService.php
    src\Services\InventoryService.php
    src\Services\ModuleBridge.php
    src\Services\ModuleGroups.php
    scripts\seed_industry_modules.php
    scripts\seed_platform_modules.php

    YE NAYI HAIN — delete karne ki zaroorat nahi:
    public\item_dialog.js
    approved_ui\stock_day_close.html
    approved_ui\retail\stock_day_close.html
    src\Services\MenuItemService.php
    src\Services\StockDayService.php
    docs\SUPER_ADMIN_HANDBOOK.html

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V146.zip extract karein, phir Railway par deploy.

STEP A3 — DEPLOY LOG (Railway dashboard -> Deploy log)
    Ye aana chahiye:

        INDUSTRY_MODULES_READY inserted=1        <- naya "dayclose"
        MIGRATIONS_DONE stage=boot ok=30 skipped=0 failed=0
        MIGRATIONS_OK
        MODULE_GROUPS_OK
        OFFLINE_TOOLS_OK

    "inserted=1" ka matlab naya module catalog mein chala gaya.

STEP A4 — VERIFY (har file ke liye search string)
    Notepad mein kholein, Ctrl+F:

      FILE                              SEARCH FOR (Ctrl+F)
      --------------------------------  --------------------------
      VERSION                           V146
      public\item_dialog.js             window.ItemDialog
      src\Services\MenuItemService.php  DIRECT_INVENTORY
      src\Services\StockDayService.php  opening + received
      src\Services\InventoryService.php defaultLocationId
      approved_ui\menu_management.html  menu-items
      approved_ui\stock_day_close.html  stock-day-close
      approved_ui\restaurant_pos.html   newOrEditItem
      public\shell.js                   stock_day_close.html
      public\router.php                 item_dialog.js

STEP A5 — BROWSER MEIN CHECK
    Business mein login karein:
      - Left menu -> Menu & Categories: asli items nazar aayen, har
        item ke saamne "No stock" / "Same item" / "Recipe" ka tag
      - "+ Add item" -> teen option nazar aayen
      - Left menu -> Day Closing (Stock): aaj ki tareekh ka hisaab
      - Sale Point -> F10 (New item): wohi popup khule


============================================================
 PART B — OFFLINE CUSTOMERS
============================================================
Kuch bhejne ki zaroorat NAHI. Deploy ke baad customer sirf

    UPDATE.bat

chalaye — naya code aur naya "dayclose" module khud aa jayega.
(Agar us ke paas abhi tak V145 ka patch nahi gaya to pehle
UPDATE_PATCH_V145.zip bhejein, ek dafa.)


============================================================
 ISTEMAL KA TARIQA — CUSTOMER KO YE BATAYEIN
============================================================

NAYA ITEM BANANA (kahin se bhi — POS ya left menu)
  1. "+ Add item" ya Sale Point par F10
  2. Naam, price, category
  3. "When this item is sold, take stock off..." mein se chunein:
     - No stock
     - The same item  -> inventory item chunein, ya "Create it with
                         this item" se nayi bana lein
     - By recipe      -> yield + ingredients (qty aur waste %)
  4. Save

ROZANA KA KAAM
  Subah/jab maal aaye : Day Closing -> + Stock In
  Din bhar            : POS se sale — stock khud katta rehta hai
  Raat ko             : Day Closing -> har item ki ginti daalein ->
                        "Close the day"

  Jis item ki ginti na karen, us ka khana KHALI chhor dein — wo item
  bilkul nahi chhua jayega.


============================================================
 AHEM — EK PURANA MASLA JO THEEK HO GAYA
============================================================
Char jagah se "default stock location" chuni jati thi aur TEEN alag
tareeqe thay. Jahan do location hon (Kitchen aur Main Store — demo
mein dono bante hain), wahan opening stock ek jagah jata, sale doosri
jagah se katti, aur Stock In teesri jagah barhata. Koi error nahi
aata tha — bas stock do khaton mein bat jata aur number kabhi sahi
nahi hota.

Ab sab ek hi qanoon par hain (InventoryService::defaultLocationId).

Agar kisi purane business ka stock do jagah bat chuka hai, to ek dafa
Day Closing se ginti daal kar "Close the day" kar dein — number
durust ho jayega.


============================================================
 MASLA AAYE TO
============================================================
Business ke andar: Left menu -> Day Closing (Stock) -> tareekh chunein.
Opening, received, sold, wastage — sab ek line par. Pata chal jayega
number kahan gaya.

Super Admin console se:  info <slug>

------------------------------------------------------------
 Wabwar Software House   www.wabwar.pk   0342-5095104
------------------------------------------------------------
