============================================================
 V155 — SALE POINT FREE AUR FAST
 Wabwar Software House                     build 2026-10-15
============================================================

AAP NE KAHA:
  "FBR activated nahi hai to <1 second mein bill nikalna chaiye.
   FBR wale par 1-2 second theek hai. Aur sync sale point se nahi,
   background service se. Sale point ko har lihaz se free rakhna hai."

9 kaam ki fehrist — poori, isi tarteeb mein.


------------------------------------------------------------
 KYA BADLA (9)
------------------------------------------------------------
 1. Bill ke baad sync SALE POINT SE NIKAL DI GAYI. Ab sirf ek
    nishani file: storage\tmp\sync_now.flag          public\api.php
 2. Background loop us nishani ko HAR SECOND dekhta hai —
    bill phir bhi ~1 second mein cloud par      scripts\sync_loop.php
 3. PARCHI pehle, server ka baqi kaam baad mein
                                       approved_ui\restaurant_pos.html
 4. FBR ka timeout 3s -> 2s             src\Services\FiscalService.php
 5. QR orders ka 20-second wala chakkar sync -> ASYNC
    (pehle har 20 second POS jam hota tha)
                                       approved_ui\restaurant_pos.html
 6. Retail bill par bhi wahi nishani                public\api.php
 7. Offline data folder ab software ke folder ka qaidi nahi:
    %PROGRAMDATA%\Wabwar\SmartPOS\data         tools\resolve_mariadb.ps1
 8. Khali datadir par KHAMOSHI SE naya database nahi banta —
    ruk kar purani jagah batata hai            tools\resolve_mariadb.ps1
 9. Server chalne se PEHLE tables ka pehra; na hon to setup khud
    chal jata hai             tools\start_offline.ps1 (+ nayi script)


------------------------------------------------------------
 NAAP (guess nahi — chala kar dekha)
------------------------------------------------------------
Test: offline node (php -S), cloud ka jawab 2 second.

                                        V154        V155
  pos-finalize ka jawab                10.50 s     0.03 s
  USI waqt agli request (parchi)         8.22 s     0.02 s

  FBR ON, service theek chal rahi         —         0.10 s   (SENT)
  FBR ON, service latak gayi              —         2.03 s   (pehla bill)
  FBR ON, uske baad ke bill               —         0.03 s   (breaker)
  Retail bill (rpos-sale)                 —         0.02 s

  Bill cloud tak pohanchne ka waqt                  0.51 s

WAJAH JO PAKRI GAYI: `php -S` EK WAQT MEIN EK request leta hai.
`sync_nudge` jawab ke baad chalti thi, magar server tab tak AGLI
request nahi uthata tha. Us ke andar Sync::run() ke 5 HTTP call,
har ek ka timeout 45 second. Isi liye "some time 1 minute".

20 second ka throttle pehle lagaya gaya tha — us se latakna HAR bill
par nahi, KABHI KABHI hota tha. Counter par "kabhi kabhi" sab se
buri shakal hai, kyunke wajah kabhi samajh nahi aati.


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    Windows ka "Replace or Skip" extraction ko toot-phoot deta hai,
    is liye pehle delete, phir extract.

    VERSION
    public\api.php
    src\Services\FiscalService.php
    scripts\sync_loop.php
    approved_ui\restaurant_pos.html
    tools\resolve_mariadb.ps1
    tools\start_offline.ps1

    YEH NAYI HAI — delete karne ki zaroorat nahi:
    scripts\check_local_schema.php

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V155.zip extract karein, phir Railway par deploy.

STEP A3 — DEPLOY LOG MEIN YEH DEKHEIN (V154 jaisa hi)
        MIGRATIONS_OK
        RETAIL_OPS_OK
        MODULE_GROUPS_OK
        SETTINGS_CHECK_OK
        OFFLINE_TOOLS_OK
        COLLATION_OK
        RETAIL_WIRING_OK

STEP A4 — VERIFY (har file mein Ctrl+F)

      FILE                                   SEARCH FOR
      -------------------------------------  ------------------------
      VERSION                                V155
      public\api.php                         sync_now.flag
      scripts\sync_loop.php                  bill par foran
      src\Services\FiscalService.php         int $timeout = 2
      approved_ui\restaurant_pos.html        PARCHI SAB SE PEHLE
      scripts\check_local_schema.php         SCHEMA_EMPTY
      tools\resolve_mariadb.ps1              Wabwar\SmartPOS
      tools\start_offline.ps1                Checking database tables


============================================================
 PART B — OFFLINE PACKAGE (customer ka PC)
============================================================

Customer ke PC par UPDATE.bat chalayein (aam tareeqa). Package
khud banate hain to upar wali saat files + nayi script lazmi hain.

PEHLI DAFA CHALNE PAR KYA HOGA:

  * Jin PC par data pehle se `<folder>\data\mysql` mein hai, wahan
    KUCH NAHI BADLEGA — software wahi datadir use karta rahega.
    Chalte POS ka database khiskana sab se khatarnaak kaam hai.

  * Nayi install ab %PROGRAMDATA%\Wabwar\SmartPOS\data mein data
    rakhti hai. Faida: software kisi bhi folder mein dobara install
    ho, data wahi rehta hai.

  * Agar is PC par database pehle tha magar us jagah nahi mila, to
    setup RUK JAYEGA aur purani jagah ka poora rasta screen par
    likh dega. Khali database nahi banayega.

    Waqai nayi, khali shuruat chahiye to software ke folder mein
    khali file START_FRESH.txt bana kar setup dobara chalayein.


------------------------------------------------------------
 BROWSER MEIN CHECK (restaurant business se login)
------------------------------------------------------------
 1. POS -> item dalein -> Charge/Actions -> Complete Payment.
    Parchi FORAN nikle. Pehle kabhi 1 minute lagta tha.
 2. Hold se bill banayein, phir Complete Payment. Parchi phir bhi
    pehle nikle (hold delete uske BAAD hota hai).
 3. Dashboard -> Offline & Sync -> "Last sync" bill ke ~1 second
    baad update ho jaye, chahe aap ne kuch na dabaya ho.
 4. Net ki taar nikal dein -> bill kaatein -> parchi usi raftaar se
    nikle. Net wapas aane par bill khud cloud par chala jaye.
 5. Retail business -> POS -> bill. Wahi raftaar, wahi sync.

JO BILKUL NAHI BADLA: restaurant ke 43 screens aur retail ke 41
screens — dono sweep chalaye gaye, 0 farq, 0 JS error. Bill ka
hisaab, FBR ka payload aur parchi ka design chhua hi nahi gaya.

Madad: Wabwar Software House — +92 342 5095104

// build: V155 build 2026-10-15
