============================================================
 V181 — NUMBER SEEDHE SADE, SERVICE CHARGE BARQARAR,
        CLOSING PAR DO ALAG PARCHIYAN
 Wabwar Software House                     build 2026-11-04
============================================================

Chaar cheezein jo aap ne kahin — aur bas wohi chaar.

    1) Bill number screen par 1, 2, 3
    2) Account number screen par 1, 2, 3
    3) Service charge hold kar ke dobara kholne par barqarar
    4) Closing par SALE REPORT aur CLOSING SHEET alag alag


############################################################
 1 & 2) NUMBER SIRF SCREEN PAR BADLE — DATABASE WAISA HI
############################################################

Aap ne kaha: "db main wesy he chalny do, user ko na feel ho k db
main kuch aur ja raha hai." Bilkul wohi kiya gaya hai.

KYUN DATABASE NAHI BADLA

    orders         par  UNIQUE (site_id, business_date, bill_no)
    cashier_shifts par  UNIQUE (site_id, shift_no)

Bill number `L206-0007` hai aur `L206-` us offline PC ka NODE CODE
hai. Yeh prefix isi liye laga tha ke do offline PC ek hi din bill
"7" na bana dein — warna cloud par sync ke waqt ek bill doosre ko
mita deta. Account `S-261104-7C9F` par bhi yehi baat hai.

Is liye database mein number jaisa hai waisa hi rehta hai, sync
waisa hi chalta hai, FBR waisa hi, purane bill ka reprint waisa hi.
Sirf SCREEN par wo hissa chhapta hai jo cashier aur gahak ke kaam
ka hai.

    L206-0007  ->  7
    0001       ->  1
    L41-0012   ->  12

EHTIYAT JO BARTI GAYI (yeh ahem hai):
POS server ko HAMESHA asli number bhejta hai. Agar kata hua number
("7") server ko chala jata to `pos_bill_guard()` us par dobara
prefix lagata — "L206-7" — aur wo ek BILKUL NAYA bill ban jata.
Is liye: dikhane mein chhota number, bhejne mein poora.

JO NUMBER IS SAANCHE MEIN NA HO, WO WAISA HI RAHE GA:
    ON-123456-42   (customer app ka bill)  ->  waisa hi
    APP-1                                   ->  waisa hi
Sirf wohi number kata jata hai jo POS ne khud banaya ho (chaar ya
zyada hindsay).

KAHAN KAHAN BADLA
    POS ki patti ("Bill #7", "Shift 1")
    Duplicate Bill ka popup aur us ki search
    Hold Bills ki fehrist
    Payment ka dialog, New Bill ka dialog, Delivery Charge ka dialog
    Gahak ka chhapa hua bill
    Shift ki closing parchi
    Dashboard ka account
    Shift Management aur Closing History
    Account wise sale report
    Har report jis mein bill number ka khana hai (CSV aur PDF samet)

ACCOUNT KA NUMBER — EK NAYA KHANA
`S-261104-7C9F` ke andar koi number hai hi nahi, is liye
`cashier_shifts` mein ek naya khana bana: `shift_seq`.

    >> MIGRATION PURANI SHIFTS KO BHI NUMBER DE DETI HAI,
       `opened_at` KI TARTEEB SE. Is liye ginti beech se shuru
       nahi hoti aur purana data khali nahi rehta.

Chala kar dekha — 9 purani shifts, sab theek tarteeb mein:
    1: 31 Oct 11:00   2: 01 Nov 09:00   3: 01 Nov 09:47
    4: 02 Nov 10:00   5: 02 Nov 11:00   6: 03 Nov 18:00
    7: 04 Nov 10:00   8: 04 Nov 15:52   9: 04 Nov 15:57

Naya account khulte hi agla number khud le leta hai. Kisi wajah se
number na mile to purana poora naam dikh jata hai — khana khali
nahi rehta.


############################################################
 3) SERVICE CHARGE AB HOLD/RESUME PAR BARQARAR
############################################################

YEH ASAL BUG THA, AUR MIL GAYA.

Server PEHLE SE service charge bhej raha tha:

    'discount' => ..., 'service' => $o['service_charge'], 'delivery' => ...

Magar POS ka resume wala hissa sirf `disc` aur `dlv` wapis lagata
tha — `svc` ko haath hi nahi lagata tha. Nateeja: service charge
laga kar bill hold karo aur dobara kholo to charge GAYAB, aur bill
us raqam se kam ban jata.

Bilkul wohi ghalti jo pehle DISCOUNT ke saath thi (V157 mein theek
hui) aur DELIVERY ke saath (V171 mein). Service charge reh gaya tha.

AB: resume par charge wapas aa jata hai aur cashier usay
`Service Charge > Edit` se badal bhi sakta hai.

Chala kar dekha (asli screen, asli click):
    CHICKEN TIKKA 400, service 5%  ->  service 20, grand 420
    HOLD
    RESUME                          ->  service 20, grand 420
    Edit dabaya                     ->  dialog mein 20 likha mila

V176 PAR KOI ASAR NAHI: wahan NAYE bill par service charge sifar
karna tha (pichle bill ka charge chipak jata tha). Yeh raasta alag
hai — yeh USI bill ka apna charge wapas la raha hai.

Raqam ke tor par wapas aata hai, percent ke tor par nahi — database
mein `service_charge` ka khana hai, `service_pct` koi khana hai hi
nahi. Discount ke flat waale raaste jaisa.


############################################################
 4) CLOSING PAR DO ALAG PARCHIYAN
############################################################

Pehle sab kuch EK kagaz par tha. Ab do:

    SALE REPORT                     CLOSING SHEET
    (malik ke liye)                 (cashier ke liye)
    ---------------------------     ---------------------------
    Account, counter, cashier       Account, counter, cashier
    SALES BY CATEGORY (har item)    TOTAL SALES (ek line)
    Bills, subtotal, discount,      Opening float
      service, tax, delivery        Cash sales
    TOTAL SALES                     Less cash expenses
    HOW IT WAS PAID                 Expected in till
    TRACKED STOCK                   Counted / BALANCED / OVER / SHORT
    Dastkhat                        Dastkhat

Shift close karte hi DONO khud chhap jate hain. Shift Report ke
dialog par ab do button hain: "Print sale report" aur
"Print closing sheet".

PURANI POORI PARCHI KHATAM NAHI HUI. PDF ko `part` na bheja jaye to
bilkul wohi purana kagaz banta hai — is liye Shift Management aur
Closing History ka reprint jaisa tha waisa hi chalta hai.

EK PURANA BUG BHI THEEK HO GAYA: shift close par parchi DO BAAR
khul rahi thi (close wala hissa aur report wala dialog, dono apni
apni window khol rahe thay). Ab chhapne ka kaam sirf ek jagah se
hota hai.


############################################################
 CHALA KAR DEKHA — 159 TEST, SAB PASS
############################################################

NAYE (V181) — 32 test:
    bill number ka saancha (11 soortein, ON-/APP- wale bhi)   11
    account number ka saancha                                  3
    har screen par account ka number (dashboard, account wise,
      shift list, closing parchi) + migration ki tarteeb        6
    closing ki do parchiyan (unwan, kaun sa hissa kahan)       12

ASLI SCREEN PAR (9 test):
    POS ki patti par "Bill #16" aur "Shift 1"
    service charge 5% -> hold -> resume -> charge barqarar
    hold list par bhi number hindsay mein
    Edit se charge badla ja sakta hai
    koi JS error nahi

PDF (asli HTTP se nikale):
    part na bheja   ->  DAILY CLOSING   (purani poori parchi)
    part=sale       ->  SALE REPORT     (category wise + adayegi)
    part=cash       ->  CLOSING SHEET   (daraz + ginti + dastkhat)

PURANE (dobara chalaye, sab pass):
    V179 ke poore test                                        45
    saari 34 reports                                      34 / 0 error
    V180 (net ka total, column ki tarteeb)                     6
    POS se reports tak ka audit                               16
    dashboard ki asli screen                                  11
    POS par asli bill (item -> Charge -> Complete Payment)     6

KUCH TEST PEHLI BAAR FAIL HUAY — WAJAH MERI THI:
    Audit ke liye maine test ka saara purana data mita diya tha,
    aur un mein number hard-coded thay. Fixture dobara banaya aur
    test ko tay shuda number ki jagah USOOL par rakha (har line par
    sirf hindsay, ginti opened_at ki tarteeb se). Phir sab pass.

EK GHALTI JO MUJHSE V179 MEIN HUI — AAP KO BATANA ZAROORI HAI:
    V179 mein maine `dashboard.html` mein aakhri chhoti tabdeeli
    (khali ghante par halki lakeer) hash dobara banane ke BAAD ki
    thi. Is liye us package ki `APPROVED_UI_SHA256.json` mein
    dashboard.html ka hash PURANA reh gaya tha.
    ASAR: koi nahi — poore code mein koi cheez is file ko parhti hi
    nahi, yeh sirf ek fehrist hai. Is package mein dono hash theek
    kar diye gaye hain.

JO MAIN NAHI KAR SAKA:
    Aap ke apne customer ke data par chala kar dekhna.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
NAU FILES (README aur screenshot ke ilawa):

    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\restaurant_pos.html
    src\helpers.php                      (naye helper: bill_show, acct_show)
    src\Services\PageData.php
    src\Services\OpsService.php
    src\Services\ReportService.php
    src\Services\BillTemplate.php
    public\api.php
    scripts\migrate_shift_seq.php        (NAYI FILE)
    scripts\migration_order.php

>> IS BAAR EK MIGRATION HAI (`cashier_shifts.shift_seq`).
   Ek naya khana jurta hai; kisi purani row ki koi qadar nahi
   badalti. Script idempotent hai — jitni dafa chale, ek hi natija.
   Na chale to bhi kaam rukta nahi: screen par purana poora naam
   dikhta rahe ga.

POS ka billing raasta (PosService) nahi badla. Dashboard, Sales
summary aur baqi 33 reports ka hisaab nahi badla — sirf number
dikhne ka tareeqa badla.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    Files chadha dein. Migration KHUD CHAL JATI HAI (deploy ke baad
    background stage mein). Deploy log mein yeh line dhoondein:

        SHIFT_SEQ_OK added=1 numbered=<kitni purani shifts>

PART A — OFFLINE PC
    `approved_ui\`, `src\`, `public\` aur `scripts\` ki files sealed
    package ke andar hoti hain. Railway par chadhate hi cloud ka
    package naya ho jata hai, phir us PC par wohi purana tareeqa:

        GET_UPDATE.bat   phir   INSTALL_UPDATE.bat

    `INSTALL_UPDATE.bat` khud `run_migrations.php` chalata hai, is
    liye `shift_seq` wahan bhi khud lag jata hai.

    CLOUD PEHLE, OFFLINE BAAD MEIN — yeh tarteeb zaroori hai. Sync
    naya khana sirf tab bhejta hai jab DONO taraf mojood ho.

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\restaurant_pos.html
    src\helpers.php
    src\Services\PageData.php
    src\Services\OpsService.php
    src\Services\ReportService.php
    src\Services\BillTemplate.php
    public\api.php
    scripts\migration_order.php

STEP A2 — EXTRACT
    SaaSVersion_V181.zip extract karein
    (`scripts\migrate_shift_seq.php` NAYI file hai — delete karne ki
     zarurat nahi, wo pehle se hai hi nahi.)

STEP A3 — VERIFY (Ctrl+F)

      FILE                               SEARCH FOR
      ---------------------------------  ------------------------------
      VERSION                            V181
      src\helpers.php                    SCREEN PAR NUMBER SEEDHA SADA
      approved_ui\restaurant_pos.html    BILL NUMBER SCREEN PAR SEEDHA
      approved_ui\restaurant_pos.html    SERVICE CHARGE BHI WAPAS
      src\Services\BillTemplate.php      CLOSING PAR DO ALAG PARCHIYAN
      scripts\migrate_shift_seq.php      ACCOUNT KA SEEDHA SADA NUMBER

STEP A4 — CHALA KAR DEKHEIN
    1. POS kholein  -> patti par "Bill #1" aur "Shift 1" (prefix aur
       sifar nahi)
    2. Duplicate Bill aur Hold Bills -> wahan bhi seedhe number
    3. Ek bill par service charge lagayein -> HOLD karein -> Hold
       Bills se RESUME karein -> charge wahin ka wahin mojood ho
    4. Shift close karein -> DO PDF khulein:
       SALE REPORT  aur  CLOSING SHEET
    5. Reports -> Account wise sale -> ACCOUNT ka khana 1, 2, 3
    6. Shift Management -> purani shifts par bhi number

Madad: Wabwar Software House — +92 342 5095104

// build: V181 build 2026-11-04
