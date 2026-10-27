============================================================
 V172 — COMPLETE PAYMENT PAR FOREIGN KEY ERROR
 Wabwar Software House                     build 2026-10-28
============================================================

V172 MEIN V171, V170, V169 AUR V168 KA SAB KUCH SHAMIL HAI.

Yeh build aap ke us error ka jawab hai jo Complete Payment dabate hi
aaya:

    SQLSTATE[23000]: Integrity constraint violation: 1452
    Cannot add or update a child row: a foreign key constraint fails
    (`railway`.`stock_transaction_lines`,
     CONSTRAINT `fk_stl_item` FOREIGN KEY (`inventory_item_id`)
     REFERENCES `inventory_items` (`id`))

AUR BILL BAND HI NAHI HOTA THA. Customer counter par khara rehta
tha, cashier dobara dobara daba kar dekhta aur har dafa wohi laal
lakeer.


############################################################
 ASAL SABAB
############################################################

Bill band karte waqt software stock bhi kaat-ta hai. Us ke liye wo
dekhta hai ke is khane ki RECIPE mein kaun kaun sa saman lagta hai,
aur har saman ki ek line `stock_transaction_lines` mein likhta hai.

Is bill ke kisi khane ki recipe mein aisa saman laga hua tha jo us
database mein MOJOOD HI NAHI THA. Database ne wo line lene se mana
kar diya, aur mana karte hi POORA kaam ulat gaya — stock bhi nahi
laga AUR bill bhi band nahi hua.

YEH SOORAT BANTI KAISE HAI:
Ek hi database ke andar yeh ban hi nahi sakti — `recipe_ingredients`
par foreign key lagi hui hai. Yeh CLOUD par banti hai, SYNC ke
raaste: bachcha row (recipe ki line) cloud tak pohanch jaye aur us
ka baap (saman ki row) kisi wajah se chhoot jaye. Yehi wajah hai ke
yeh error `railway` database par aaya, aap ke offline PC par nahi.


############################################################
 AB KYA HOTA HAI
############################################################

FAISLA SAAF HAI: stock ka hisaab ahem hai, magar GAHAK KI ADAYEGI
zyada ahem hai.

Ab aisi tooti hui line chhant di jati hai, bill band ho jata hai,
aur us ka zikr Error Log mein likh diya jata hai:

    stock/missing-item: Bill 0003: stock not deducted for 1
    ingredient(s) - the inventory item no longer exists. Check the
    recipe for the items on this bill. IDs: dead0000-...

Pehle ulta tha: stock bhi nahi lagta tha AUR bill bhi nahi banta
tha. Ab sirf us ek saman ka stock nahi katega — aur wo bhi aap ko
nazar aa jayega.

YEH MARHAM HAI, ILAAJ NAHI. Asal kharabi data mein hai. Is liye ek
aur cheez bhi lagai hai:


HAR DEPLOY PAR KHUD BATA DEGA KAUN SI RECIPE TOOTI HAI
    Nayi script `check_stock_links.php` har deploy par chalti hai
    aur kuch badalti nahi — sirf naam le kar batati hai:

        ! recipe "Chicken Boti" -> inventory item dead0000-... (gayab)
        STOCK_LINKS_BROKEN count=1 - in menu items ka stock NAHI
        katega. Recipe kholein aur ingredient dobara chunein.

    Sab theek ho to sirf yeh:

        STOCK_LINKS_OK

    Jo naam yahan aaye, us menu item ki recipe kholein aur us ka
    ingredient dobara chun kar Save kar dein. Us ke baad us ka stock
    phir se katne lagega.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
TEEN FILES BADLIN (README ke ilawa):

    VERSION
    src\Services\PosService.php
    scripts\migration_order.php

EK NAYI FILE:
    scripts\check_stock_links.php

KOI MIGRATION NAHI — database mein koi tabdeeli nahi.
Sale Point ki screen bhi nahi badli.


------------------------------------------------------------
 TESTING
------------------------------------------------------------
MASLA PEHLE REPRODUCE KIYA:
    Aap wali soorat banai — ek menu item ki recipe mein aisa saman
    jo mojood nahi. Phir wo item bech kar Complete Payment:

      pos-finalize ka jawab:
        {"ok":false,"message":"SQLSTATE[23000] ... 1452 ...
         CONSTRAINT `fk_stl_item` ..."}
      bill ka status: band hi nahi hua                    <-- wahi masla

FIX KE BAAD WAHI TEST:
      pos-finalize ka jawab: {"ok":true, ...}
      bill ka status: CLOSED                              OK
      Error Log mein: stock/missing-item: Bill 0001 ...   OK

SAB SE AHEM PEHRA — SAHI STOCK AB BHEE THEEK KATTA HAI:
    Ek ASLI saman (Cooking Oil) ki recipe banai, 1 khane par 2 adad.
    Phir 3 khane bech kar pay kiya:
      stock ki line likhi gayi                            OK
      3 x 2 = 6 adad kata                                 OK
      stock balance bhee 6 se ghata                       OK
    Yani fix ne sirf TOOTI hui line chhanti hai, sahi ko haath nahi
    lagaya.

NAYA PEHRA — DONO SOORAT CHALA KAR DEKHI:
    saaf data par        -> STOCK_LINKS_OK
    tooti hui wiring par -> ! recipe "Chicken Boti" -> ... (gayab)
                            STOCK_LINKS_BROKEN count=1

POORI REGRESSION — 122 TEST, SAB PASS, KOI FAIL NAHI:
    delivery charge (V171) 19 · billing audit (V168) 21 ·
    paison ka hisaab 10 · kinare 8 · do din ka bill 8 ·
    hold/resume 7 · note wali lines 4 · KOT+bill band 5 ·
    duplicate naam 3 · stock theek katta hai 4 ·
    tooti wiring par bill bane 1 · bulk delete (V166) 28

JO MAIN NAHI KAR SAKA:
    Aap ke `railway` database ko khud dekhna — wo mere paas nahi
    hai. Maine wohi soorat apne yahan bana kar error ba-ainahi
    reproduce kiya aur fix us par chala kar dekha. Deploy ke baad
    log mein `STOCK_LINKS_` wali lakeer aap ko asal naam bata
    degi.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B PEHLE — SERVER (Railway)

    YEH ERROR CLOUD PAR AAYA HAI, IS LIYE PEHLE YEHI.

    Deploy ke baad log mein neeche ki taraf yeh dhoondein:

        STOCK_LINKS_OK
    ya
        ! recipe "<khane ka naam>" -> inventory item <id> (gayab)
        STOCK_LINKS_BROKEN count=N

    Agar doosri wali aaye to un khanon ki recipe kholein (Menu ->
    item -> Recipe), ingredient dobara chunein aur Save karein.

    Deploy ke foran baad wohi bill dobara charge kar ke dekh lein —
    ab band ho jana chahiye.


PART A — OFFLINE PC

    Yeh error offline PC par nahi aata (wahan sab rows ek hi jagah
    banti hain). Phir bhi files dono taraf ek jaisi rakhni hain.

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    src\Services\PosService.php
    scripts\migration_order.php

    (scripts\check_stock_links.php NAYI hai — delete karne ki
     zaroorat nahi.)

    Agar V171 abhi nahi lagaya to us ki baqi files bhi delete
    karein — fehrist README_V171.txt mein hai.

STEP A2 — EXTRACT
    SaaSVersion_V172.zip extract karein, phir UPDATE.bat

STEP A3 — VERIFY (Ctrl+F)

      FILE                               SEARCH FOR
      ---------------------------------  --------------------------
      VERSION                            V172
      src\Services\PosService.php        liveMoves
      src\Services\PosService.php        TOOTI HUI STOCK WIRING
      scripts\migration_order.php        check_stock_links
      scripts\check_stock_links.php      STOCK_LINKS_OK

Madad: Wabwar Software House — +92 342 5095104

// build: V172 build 2026-10-28
