============================================================
 V187 — DOBARA CHHAPI PARCHI PAR "DUPLICATE", AUR
        ORDER / INVOICE DETAIL KI TARTEEB DURUST
 Wabwar Software House                     build 2026-11-11
============================================================

>>> YEH ZIP V186 KI JAGAH LAGAYEIN <<<

V186 ka saara kaam (closing parchi ka bara font, hashiya khatam,
naya saaf font jo PDF ke andar jata hai) IS MEIN SHAMIL HAI.
V186 alag se lagane ki zarurat nahi.

AATH FILES. Koi UI file nahi badli, koi migration nahi,
database nahi badla, POS ki screen nahi badli.


############################################################
 1) DOBARA CHHAPI PARCHI PAR "DUPLICATE"
############################################################

AAP NE KAHA: "Duplicate print option of opening and closing
sheet" — aur puchne par: sirf DUPLICATE ka nishan.

MASLA: Shift Management aur Closing History ke "Print report"
se nikalne wali parchi pehli nahi, DOOSRI hoti hai — magar
dekhne mein bilkul asli jaisi thi. Do kagaz saath rakh kar yeh
batana mumkin hi nahi tha ke kaun sa asli hai. Daraz ke hisaab
mein yeh khatarnak baat hai.

AB: us par kaale band mein safed "DUPLICATE" chhapta hai, bilkul
upar — business ke naam ke theek neeche, parchi ke unwan se
pehle. Door se nazar aa jata hai.

ASLI PARCHI PAR NISHAN NAHI LAGTA:
Shift band hote hi jo kagaz KHUD nikalta hai wohi asli hai. Farq
yahan se aata hai ke `shiftmgr-report-pdf` ko `dup=1` bheja gaya
ya nahi:

    dup=1 jata hai      ->  Shift Management ka "Print report"
                            Closing History ka print
    dup=1 NAHI jata     ->  cashier ne apni shift band ki
                            admin ne doosre ka till band kiya

Dono asli raaste jaise thay waise hi hain.

Nishan SALE REPORT aur CLOSING SHEET dono par lagta hai.


############################################################
 2) ORDER / INVOICE DETAIL — TARTEEB DURUST
############################################################

Aap ne kaha "isko proper order main kr do". Report dekhi to do
kharabiyan nikleen.

(a) RAQAM KA HISAAB NAZAR HI NAHI AATA THA

    Column yeh thay:
        Subtotal | Discount | Tax | Total

    Service charge aur delivery ka koi column NAHI tha. Is liye
    line jorne par milti hi nahi thi:

        1,000 - 30 + 20 = 990      magar Total 1,040 likha hota

    Farq service charge (50) ka tha — jo report mein kahin
    dikhta hi nahi tha. Dekhne wale ko lagta ke hisaab ghalat
    hai.

    AB wohi qudrati tarteeb jo V184 mein account wise par lagi:

        Subtotal | Discount | Service | Delivery | Tax | Total

        Subtotal - Discount + Service + Delivery + Tax = Total

    Aur jahan tax qeemat ke ANDAR hota hai, wahan column ka naam
    "Tax (incl.)" aata hai aur wo jama nahi hota — wahi usool jo
    V184 mein tay hua tha.

(b) LINEIN ULTI AUR GHALAT TARTEEB MEIN

        ORDER BY d DESC, o.bill_no DESC

    `bill_no` matn hai, number nahi. Jin billon par node ka
    prefix hota hai (`L206-0009`) wo alag jagah chale jate thay,
    aur online bill (`ON-...`) beech mein ghus jate thay.

    AB: PEHLE TAREEKH, PHIR BILL KA NUMBER — dono barhti hui
    tarteeb mein, jaise khaata parha jata hai. Yeh wahi usool
    hai jo V182 mein hold aur duplicate ki fehrist par laga tha.


############################################################
 CHALA KAR DEKHA — SAB PASS
############################################################

NAYE (V187):                                              14
    - asli parchi par koi nishan nahi
    - duplicate par band maujood, aur sab se upar
    - PDF mein DUPLICATE likha; asli PDF mein nahi
    - sale wali parchi par bhi lagta hai
    - invoice detail ki poori column tarteeb
    - Service aur Delivery ab maujood
    - har line ka hisaab milta hai (0 ghalat line)
    - linein: pehle tareekh, phir bill number (0 ghalat)
    - Total ab bhi upar ke tile mein

ASLI API SE (end to end):
    shiftmgr-report-pdf ... &part=cash          -> DUPLICATE: 0
    shiftmgr-report-pdf ... &part=cash&dup=1    -> DUPLICATE: 1
    report-run&id=invoice_detail
        column : Date | Bill | Mode | Customer | Cashier |
                 Items | Subtotal | Discount | Service |
                 Delivery | Tax (incl.) | Total | FBR no.
        total  : 57,531.3 - 10 + 20 + 100 = 57,641.3

PURANE (dobara chalaye, sab pass):
    V186 (font, size, hashiya)                            20
    V185 ke test                                          27
    V184 · tax wala business                          32 · 6
    V183 · account band karne ke guards            4 · 7 + 3
    V181 · V179 ke test                             32 · 43
    POS se reports tak ka audit                           12
    saari 34 reports                           34 / 0 error

JO MAIN NAHI KAR SAKA:
    Asli thermal printer par chhapa kar dekhna.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
AATH FILES (README aur screenshot ke ilawa):

    VERSION
    src\Services\ReceiptFont.php    (V186 — NAYI, font ke bytes)
    src\Services\Pdf.php            (V186 — font PDF ke andar)
    src\Services\BillTemplate.php   (V186 bara font + V187 DUPLICATE)
    src\Services\ReportService.php  (V187 — invoice detail ki tarteeb)
    public\api.php                  (V186 parchi ka size + V187 dup)
    public\ops_db.js                (V187 — reprint par dup=1)
    public\router.php               (ops_db.js ka ?b= number)

DATABASE NAHI BADLA. KOI MIGRATION NAHI.
Customer ka bill, KOT parchi, POS, dashboard aur baqi 33
reports nahi badlin. Shift band karne wala raasta bhi waisa hi
hai — asli parchi jaisi thi waisi hi nikalti hai.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    Aath files chadha dein. Koi migration nahi.

PART A — OFFLINE PC
    Railway par chadhate hi cloud ka package naya ho jata hai,
    phir us PC par:
        GET_UPDATE.bat   phir   INSTALL_UPDATE.bat

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    src\Services\Pdf.php
    src\Services\BillTemplate.php
    src\Services\ReportService.php
    public\api.php
    public\ops_db.js
    public\router.php
    (ReceiptFont.php nayi hai — delete karne ki zarurat nahi)

STEP A2 — EXTRACT
    SaaSVersion_V187.zip extract karein

STEP A3 — VERIFY (Ctrl+F)

      FILE                            SEARCH FOR
      ------------------------------  ------------------------------
      VERSION                         V187
      src\Services\ReceiptFont.php    Liberation Mono
      src\Services\Pdf.php            PARCHI KA FONT AB PDF KE ANDAR
      src\Services\BillTemplate.php   DOBARA NIKALI GAYI PARCHI PAR
      src\Services\ReportService.php  V187 — TARTEEB DURUST
      public\api.php                  parchi par DUPLICATE ka band
      public\ops_db.js                dobara chhapi ja rahi hai
      public\router.php               ops_db.js?b=v187

STEP A4 — CHALA KAR DEKHEIN
    1. Operations -> Opening & Closing Shift -> kisi BAND shift
       par "Print report"
       -> parchi ke upar kaale band mein DUPLICATE
    2. Closing History se bhi print karein -> wahi DUPLICATE
    3. Ab ek shift band karein
       -> jo parchi KHUD nikalti hai us par DUPLICATE nahi hona
          chahiye (wo asli hai)
    4. Reports -> Order / invoice detail
       -> column: ... Subtotal | Discount | Service | Delivery |
          Tax | Total
       -> linein purani tareekh se nayi taraf, aur har din ke
          andar bill number barhti tarteeb mein

Madad: Wabwar Software House — +92 342 5095104

// build: V187 build 2026-11-11
