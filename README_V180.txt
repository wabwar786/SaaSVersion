============================================================
 V180 — ACCOUNT WISE REPORT PAR TOTAL AB SAAMNE
 Wabwar Software House                     build 2026-11-04
============================================================

SIRF EK FILE BADLI:  src\Services\ReportService.php
SIRF EK REPORT BADLI: "Account wise sale"

Koi UI file nahi badli — is liye APPROVED_UI_SHA256.json bhi NAHI
badla. POS ki screen, dashboard, owner mobile app, aur baqi 33
reports ko haath nahi lagaya. Database nahi badla. Koi migration
nahi.


############################################################
 MASLA KYA THA
############################################################

Aap ne kaha: "account wise report main total nahi aa rhi hai".

1366 ki normal laptop screen par report chala kar dekha:

    UPAR WALE TILES : Bills | Subtotal | Discount | Service
                      ^^^ SALE ka tile tha hi nahi

    BINA SCROLL YAHAN TAK : Account | Counter | Cashier | Khula |
                            Band | Din | Bills | Subtotal | Discount

    "Sale" nazar aata hai?  NAHI

Sale TERHVAN column tha — screen se bahar. Aur jo "Total" ki lakeer
neeche banti hai, us ke number bhi usi scroll ke peechay thay. Is
liye malik ko report par apna asal total milta hi nahi tha.

WAJAH:
`reports.html` upar ke chaar tile banane ke liye PEHLE CHAAR numeric
column uthata hai (`nums.slice(0,4)`). Account wise report mein Sale
SAATWAN numeric column tha, is liye us ka tile kabhi banta hi nahi
tha.

Sales summary par yeh masla isi liye nahi aata ke us ke sirf 8
column hain aur Total screen par aa jata hai. Account wise ke 15
column hain — yeh sab se chaurhi report hai.


############################################################
 AB KYA HOGA
############################################################

1) COLUMNS KI TARTEEB BADLI — AHEM NUMBER PEHLE

    PEHLE:  Din | Bills | Subtotal | Discount | Service |
            Delivery | Tax | Sale | Kharche | Net

    AB:     Din | Bills | Sale | Kharche | Net |
            Subtotal | Discount | Service | Delivery | Tax

   Natija — upar ke chaar tile ab yeh hain:

        BILLS        SALE         KHARCHE      NET

   Aur Sale ka column bina scroll nazar aata hai. Tafseel
   (Subtotal / Discount / Service / Delivery / Tax) dayein taraf
   chali gayi — scroll karne par wahin mil jati hai.

   KOI NUMBER NAHI BADLA. Sirf tarteeb badli hai.

   `reports.html` ko JAAN BOOJH KAR haath nahi lagaya — us ka
   `slice(0,4)` badalne se saari 34 reports ke tile badal jate.
   Sirf isi report ki columns ki tarteeb badli gayi hai.

2) EK PAISY KA FARQ THEEK

   Aap ke total par Sale 283,630.11 tha magar Net 283,630.12 —
   ek paisa zyada.

   Wajah: har line ka `net` alag alag round hota tha aur phir wo
   GOL KIYE HUAY number jama kiye jate thay. 192 billon par paisa
   khisak jata hai.

   Ab Net ka total jama kiye huay Sale mein se jama kiye huay
   Kharche nikaal kar banta hai — hamesha theek.


############################################################
 CHALA KAR DEKHA
############################################################

NAYE:
    1366 screen par upar ke tile   ->  BILLS | SALE | KHARCHE | NET
    "Sale" bina scroll nazar aaya  ->  HAAN
    Net ka total = Sale ka total - Kharche ka total (3 arse)  ->  theek

PURANE (dobara chalaye, sab pass):
    V179 ke poore test                                45 / 45
    saari 34 reports chala kar                        koi error nahi
    dashboard ki asli screen                          11 / 11
    POS par asli bill (item -> Charge -> Complete Payment)   6 / 6

JO MAIN NAHI KAR SAKA:
    Aap ke apne customer ke data par chala kar dekhna. Yahan wohi
    screen aur wohi width (1366) istemal ki, magar wo aap ka data
    nahi tha.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
DO FILES (README aur screenshot ke ilawa):

    VERSION
    src\Services\ReportService.php

APPROVED_UI_SHA256.json NAHI badla (koi UI file nahi badli).
DATABASE NAHI BADLA. KOI MIGRATION NAHI.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    Do files chadha dein. Koi migration nahi, database ko haath
    nahi lagta.

PART A — OFFLINE PC
    `src\` ki files sealed package ke andar hoti hain. Railway par
    chadhate hi cloud ka package naya ho jata hai, phir us PC par
    wohi purana tareeqa:
        GET_UPDATE.bat   phir   INSTALL_UPDATE.bat

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    src\Services\ReportService.php

STEP A2 — EXTRACT
    SaaSVersion_V180.zip extract karein

STEP A3 — VERIFY (Ctrl+F)

      FILE                               SEARCH FOR
      ---------------------------------  ------------------------------
      VERSION                            V180
      src\Services\ReportService.php     V180 — AHEM NUMBER PEHLE
      src\Services\ReportService.php     V180 — EK PAISY KA FARQ

STEP A4 — CHALA KAR DEKHEIN
    1. Reports -> Account wise sale, 1-4 November
    2. Upar chaar tile aane chahiyen:
           BILLS    SALE    KHARCHE    NET
    3. Table mein Bills ke foran baad Sale ka column — bina scroll
    4. Neeche "Total" ki lakeer par Net = Sale minus Kharche,
       ek paisa bhi farq nahi
    5. Wohi arsa Sales summary par chala kar dekhein — total barabar

Madad: Wabwar Software House — +92 342 5095104

// build: V180 build 2026-11-04
