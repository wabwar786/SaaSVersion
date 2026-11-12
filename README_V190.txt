============================================================
 V190 — JO KAGAZ CUSTOMER KO JATA HAI, WO AB DATABASE
        MEIN BHI HAI
 Wabwar Software House                     build 2026-11-12
============================================================

>>> YEH ZIP V186, V187, V188 AUR V189 KI JAGAH LAGAYEIN <<<

Un sab ka kaam is mein shamil hai:
    V186 — closing parchi ka font PDF ke andar
    V187 — reprint par DUPLICATE, invoice detail ki tarteeb
    V188 — lambi parchi kai safhon mein
    V189 — bill band account par nahi, parchi ka font chhota,
           Duplicate mein tareekh ka option

GYARAH FILES. Koi migration nahi, database nahi badla.


############################################################
 MASLA KYA THA
############################################################

Jaanch kar dekha ke har qadam par database mein kya bachta hai:

    1. item cart mein              ->  DB mein kuch nahi
    2. service 300 + discount 50   ->  DB mein kuch nahi
    3. Preview / Print ke baad     ->  DB mein kuch nahi
    4. Send to Kitchen ke baad     ->  svc 0 · disc 0 · TOTAL 0
    5. Hold / + New Bill ke baad   ->  svc 300 · disc 50 · 1,950

Do baatein is mein:

(a) Jo kagaz chhap kar customer ke haath mein jata tha, us ki
    raqam us waqt system mein kahin mehfooz nahi hoti thi. Us
    darmiyan software band ho jaye, bijli jaye, ya cashier bill
    chhor kar aage nikal jaye — wo bill kahin nahi hota.

(b) Send to Kitchen par bill ban to jata tha, magar SIRF items
    ke saath. Service charge, discount, delivery aur total sab
    sifar. Yani kitchen bhejne ke baad chhora gaya bill DB mein
    "0 ka" khara rehta tha. Hold Bills mein "PKR 0" wala purana
    masla bhi yehi tha.


############################################################
 1) KOT PAR BHI POORA HISAAB
############################################################

`sendKot()` raqam ko haath hi nahi lagata tha. Ab wo wahi hisaab
likhta hai jo hold likhta hai.

Magar do jagah alag alag hisaab likhne ka natija hum V168 mein
dekh chuke hain (hold aur finalize ka total alag ho gaya tha).
Is liye hisaab ab EK HI JAGAH hai:

    private static function writeTotals($p, $order, $d, $hold)
        subtotal · tax · discount · service · delivery · grand

    hold()     -> writeTotals(..., 1)   // bill ruka hua
    sendKot()  -> writeTotals(..., 0)   // bill chal raha hai

Ab in dono ka hisaab aage chal kar alag ho hi nahi sakta.


############################################################
 2) PARCHI SE PEHLE BILL MEHFOOZ
############################################################

"Preview / Print" aur WhatsApp — dono ab parchi nikalne se
PEHLE bill mehfooz karte hain. Wahi `pos-hold` jo "+ New Bill"
par chalta hai.

    #cmPrev.onclick = function(){ if(saveNow()) slipPreview(...) }

JO NAHI BADLA:
    • Bill KHULA hi rehta hai — kitchen ko kuch nahi jata
    • Cart saaf nahi hota — cashier wahin se kaam jari rakhta hai
    • Parchi ki shakl bilkul wahi

`curHoldId` yaad rakha jata hai, to dobara print karne par WOHI
order update hota hai — naya bill nahi banta.

Agar mehfooz na ho saka (server tak baat na pohnche) to parchi
nikalti hi nahi aur cashier ko saaf paigham milta hai. Yeh jaan
boojh kar hai: aisa kagaz dena jo system mein na ho, usi masle
ki jar hai.


############################################################
 EK CHEEZ BARHI HAI
############################################################

Har print par ab ek server request jati hai. Local network par
yeh milliseconds ka kaam hai, magar batana zaroori tha.


############################################################
 CHALA KAR DEKHA — SAB PASS
############################################################

AB HAR QADAM PAR:
    1. item cart mein              ->  DB mein kuch nahi
    2. service 300 + discount 50   ->  DB mein kuch nahi
    3. Preview / Print ke baad     ->  svc 300 · disc 50 · 1,950
    4. Send to Kitchen ke baad     ->  svc 300 · disc 50 · 1,950
    5. Hold / + New Bill ke baad   ->  svc 300 · disc 50 · 1,950

NAYE (V190):                                              12
    - KOT ke baad service charge, discount, total — sab asli
    - total ka hisaab theek (base - 50 + 300)
    - KOT par hold_status 0, hold par 1
    - dono raaste ek hi jagah se hisaab lete hain
    - dobara print par wohi order, naya nahi banta
    - bina charges wale bill ka total bhi asli (PKR 0 nahi)

PURANE (dobara chalaye, sab pass):
    V189 · V188 · V187 · V186              14 · 18 · 14 · 20
    V185 ke test                                          27
    V184 · tax wala business                          32 · 6
    V183 · account band karne ke guards            4 · 7 + 3
    V181 · V179 ke test                             32 · 43
    POS se reports tak ka audit                           12
    asli POS par duplicate + tareekh                       7
    saari 34 reports                           34 / 0 error
    70 UI file ke hash                            0 mismatch

JO MAIN NAHI KAR SAKA:
    Aap ke apne customer ke data aur printer par chala kar
    dekhna.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
GYARAH FILES (README aur screenshot ke ilawa):

    VERSION
    src\Services\PosService.php      (writeTotals + V189 shift)
    src\Services\PageData.php        (V189 — tareekh wale bill)
    src\Services\ReceiptFont.php     (V186 — font ke bytes)
    src\Services\Pdf.php             (V188 safhe, V189 khali jagah)
    src\Services\BillTemplate.php    (V186 size, V187 DUPLICATE)
    src\Services\ReportService.php   (V187 — invoice detail tarteeb)
    public\api.php                   (V189 — pos-bills-on, parchi)
    public\ops_db.js                 (V187 — reprint par dup=1)
    public\router.php                (ops_db.js ka ?b= number)
    approved_ui\restaurant_pos.html  (saveNow + syncShift + tareekh)
    APPROVED_UI_SHA256.json          (us ka naya hash)

DATABASE NAHI BADLA. KOI MIGRATION NAHI.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    Sab files chadha dein. Koi migration nahi.

PART A — OFFLINE PC
    Railway par chadhate hi cloud ka package naya ho jata hai,
    phir us PC par:
        GET_UPDATE.bat   phir   INSTALL_UPDATE.bat

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    src\Services\PosService.php
    src\Services\PageData.php
    src\Services\Pdf.php
    src\Services\BillTemplate.php
    src\Services\ReportService.php
    public\api.php
    public\ops_db.js
    public\router.php
    approved_ui\restaurant_pos.html
    APPROVED_UI_SHA256.json
    (ReceiptFont.php nayi hai — delete karne ki zarurat nahi)

STEP A2 — EXTRACT
    SaaSVersion_V190.zip extract karein

STEP A3 — VERIFY (Ctrl+F)

      FILE                            SEARCH FOR
      ------------------------------  ------------------------------
      VERSION                         V190
      src\Services\PosService.php     BILL KA HISAAB LIKHNE KI EK HI
      src\Services\PosService.php     KOT par bhi poora hisaab likho
      src\Services\PosService.php     BAND HO CHUKE ACCOUNT PAR BILL
      src\Services\PageData.php       kisi bhi ek din ke BAND bill
      src\Services\Pdf.php            HAR SAFHA APNE MATN JITNA LAMBA
      public\api.php                  DUPLICATE BILL MEIN TAREEKH KA
      approved_ui\restaurant_pos.html PARCHI SE PEHLE BILL MEHFOOZ
      approved_ui\restaurant_pos.html dupDate
      APPROVED_UI_SHA256.json         0a76ea3920b6f492

STEP A4 — CHALA KAR DEKHEIN
    1. Ek bill banayein, service charge aur discount lagayein,
       phir Charge / Actions -> Preview / Print
       -> parchi nikalni chahiye
       -> ab Hold Bills kholein: wo bill wahan hona chahiye,
          POORI raqam ke saath (PKR 0 nahi)
    2. Dobara print karein -> naya bill number NAHI banna chahiye
    3. Send to Kitchen bhej kar Hold Bills dekhein
       -> raqam poori honi chahiye, sifar nahi
    4. Duplicate Bill mein tareekh badal kar kal ke bill dekhein
    5. Koi shift band kar ke parchi dekh lein
       -> matn thora chhota, aakhir mein khali kagaz nahi

Madad: Wabwar Software House — +92 342 5095104

// build: V190 build 2026-11-12
