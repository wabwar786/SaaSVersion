============================================================
 V179 — DASHBOARD, REPORTS AUR MOBILE APP: AB EK HI NUMBER
 Wabwar Software House                     build 2026-11-04
============================================================

POS KI SCREEN KO HAATH NAHI LAGAYA GAYA.
`approved_ui\restaurant_pos.html` bilkul waisa hi hai. 70 UI files
mein se SIRF EK ka hash badla — `dashboard.html`. Baqi 69 ke hash
milaa kar dekhe, sab waise ke waise.

Database nahi badla. Koi migration nahi.


############################################################
 MASLA KYA THA — EK HI WAQT, TEEN ALAG JAWAB
############################################################

Aap ki bheji hui teen screenshots par chala kar dekha. Wajah yeh
nikli ke "aaj ki sale" teen jagah TEEN ALAG sawal se nikaali ja
rahi thi:

    Kahan                        Sawal                      Shart
    ---------------------------  -------------------------  -----------------
    Dashboard "net sales today"  bill kis din KHULA          business_date
    Sales summary (+33 reports)  bill kis din BAND hua       DATE(closed_at)
    Dashboard ka cash/card       paisa kis din AAYA          payments.paid_at
    Account wise sale            kis account ka, kab band    shift_id + band

Yahan laboratory mein aap ka maamla HOOBAHOO dobara banaya — do bill
jo raat 8 baje khule aur raat 12:40 / 1:10 par band huay:

    M-A1   khula 03 Nov   band 04 Nov      342.65
    M-A2   khula 03 Nov   band 04 Nov      342.65
    M-B1..B4  khula 04 Nov  band 04 Nov   1836.75 x 4

    DASHBOARD      : 4 bill   7,347.00     <- do bill giraa diye
    SALES SUMMARY  : 6 bill   8,032.30
    ACCOUNT WISE   : 6 bill   8,032.30

    farq = 685.30    <- aap ke yahan bhi 8,032.30 - 7,347 = 685.30

`business_date` bill KHULNE ke waqt bharta hai. Yani wo "business
day" nahi, bas khulne ki calendar tareekh hai — raat 12 ke baad
khula bill agle din ka ho jata hai chahe till wohi chal raha ho.


DASHBOARD KHUD APNE AAP SE ULAT THA
Yeh sab se saaf daleel hai. Usi screen par, usi waqt:

    "net sales today"   (business_date) = 7,347.00
    cash + card + raast (paid_at)       = 8,032.30     <- 685.30 zyada

Yani dashboard ka PAISA wala hissa pehle se reports ke barabar tha.
Sirf SALE wala hissa alag usool par chal raha tha. Is liye dashboard
ko reports par laaya gaya hai, reports ko dashboard par nahi — warna
34 reports chherni partin.

DASHBOARD PEHLE JAISA LIVE HI HAI. Har baar nikaal kar hisaab hota
hai, koi cache nahi. OPEN (bina adayegi wala) bill ab bhi sale mein
nahi ginta, bilkul pehle ki tarah.


############################################################
 AB KYA HOGA
############################################################

1) TEENO JAGAH EK NUMBER
   Dashboard = Sales summary ka aaj ka row = Account wise ka aaj ka
   total. Raat 12 ke baad band hone wala bill ab kahin nahi girta.

2) ACCOUNT KHULTE HI REPORT MEIN AA JATA HAI
   Aap ne bilkul theek pakra: aap ke screenshot mein S-261104-7C9F
   kahin nazar nahi aa raha tha. Wajah V178 ki yeh lakeer thi:

       HAVING bills > 0 OR expenses > 0

   Ab chaar mein se koi ek baat kaafi hai:
       - is arse mein us account ka koi bill ho, YA
       - is arse mein us ka koi kharcha ho, YA
       - account ISI ARSE mein khula ho (chahe ek bill bhi na bana ho), YA
       - account AB TAK CHAL RAHA ho

   Pehla bill banate hi us line par sale aani shuru ho jati hai.

   EK FAIDA MUFT MEIN: jo till koi band karna BHOOL gaya ho, wo har
   report par 0 bill ke saath nazar aata rahe ga.

   TOTAL PAR KOI ASAR NAHI — nayi lineen sirf sifar laati hain. V178
   ka kaam (dono reports ka total ek) waisa hi qayam hai; chhay arson
   par chala kar tasdeeq ki gayi.

3) "GROSS PROFIT" AB JHOOT NAHI BOLTA
   Screen par hisaab `sale - food cost - kharcha` hai. Aap ke yahan
   kisi menu item par recipe ya inventory ka link laga hi nahi, is
   liye food cost hamesha 0 rehta hai — aur "Gross profit" POORI SALE
   ke barabar aa jata tha. Aap ke screenshot mein bilkul yehi tha:
   net sale 7,347 aur gross profit bhi 7,347.

   Ab lagat ka data na ho to tile par number ki jagah "—" aata hai
   aur neeche likha hota hai:
       "Food cost set nahi — recipe/inventory link lagayein"

   Jis din aap menu items par recipe ya inventory link laga denge,
   us din se asal munafa khud ba khud aane lagega.

4) GHANTON KA CHART POORE DIN KA
   Pehle chart sirf subah 10 se shaam 6 tak dikhata tha. Aap ka
   restaurant raat 2 baje band hota hai — shaam 6 ke baad ki saari
   sale chart par nazar hi nahi aati thi, aur chart ka jama upar wale
   net sale se kabhi nahi milta tha.
   Ab 00 se 23 tak poore chaubees ghante. Jis ghante mein kuch bika
   hi nahi us par sirf halki si lakeer hai, bar nahi.

5) KHARCHE KI SHART BARABAR
   Dashboard sirf APPROVED kharche ginta tha aur MITAYA HUA kharcha
   bhi gin leta tha. Shift ki closing parchi aur Account wise report
   dono `REJECTED ke ilawa sab, mitaye huay nahi` par chalti hain.
   Ab teenon ek hi shart par hain.

6) ONLINE WALA TILE
   Us par `order_status` ki shart hai hi nahi thi — OPEN aur VOID
   bill bhi "online sale" mein gin jate thay. Ab sirf band huay bill.

7) MALIK KA MOBILE APP BHI WOHI NUMBER
   Poora mobile app `business_date` par chal raha tha. Yani malik
   mobile par ek total dekhta aur counter par khari report doosra.
   Ab us ki har sale wali query bhi band hone ke din par hai — aaj
   ka bhi aur 30 din ka bhi.


############################################################
 CHALA KAR DEKHA — 96 TEST, SAB PASS
############################################################

NAYE (45 test):
    dashboard = sales summary = account wise (aaj)            5
    dashboard khud apne andar barabar
      (sale = cash+card = mode mix = chart ka jama)           5
    raat 12 ke baad band hone wale bill kahin na girein       2
    OPEN aur VOID bill sale mein na aayein                    3
    abhi khula account (0 bill) report mein aa gaya           5
      - us par "chal raha hai" likha
      - pehla bill banate hi us par sale aa gayi
      - bhoola hua khula till bhi nazar aaya
    account wise = sales summary (6 alag arse)               12
    date wise report ke purane number waise ke waise          6
    owner mobile app: aaj aur 30 din report ke barabar        4
    "(bina account)" wala bill apni line mein                 3

SAARI REPORTS (34): har report chala kar dekhi — KOI ERROR NAHI.

ASLI SCREEN PAR (11 test):
    hero aur Net sales tile par wohi number jo report par hai
    Gross profit par "—" aur neeche wajah
    chart par 24 khaane, labels 00..23
    reports ki fehrist mein "Account wise sale" mojood
    POS khula — koi JS error nahi, koi dialog khud nahi khula

POS KA POORA RAASTA (6 test) — ASLI CLICK SE:
    + New Bill -> Takeaway -> item 2 baar -> Charge / Actions
    -> Complete Payment  =>  PKR 3,400 ka bill ban gaya
    Phir wohi bill: dashboard 11,432.30, sales summary 11,432.30,
    account wise 11,432.30 — teeno barabar.

DO TEST PEHLI BAAR FAIL HUAY — WAJAH MERI THI, SOFTWARE KI NAHI:
    Mere apne test ne POS par asli bill bana diya tha, aur test mein
    number hard-coded tha (8,032.30). Ab test ASAL report se number
    uthata hai, hard-coded nahi — phir 45/45 aur 11/11 pass.

JO MAIN NAHI KAR SAKA:
    Aap ke apne customer ke data par chala kar dekhna. Yahan wohi
    soorat bana kar dekhi (raat 12 ke baad band hone wale bill, aaj
    khula khali account), magar wo aap ka data nahi tha.


############################################################
 JO ABHI BAQI HAI (aap batayein to kar doon)
############################################################

1) Dashboard ke upar sirf EK khuli shift ka naam aata hai
   (`ORDER BY opened_at DESC LIMIT 1`). Do till ek saath khule hon to
   doosra nazar nahi aata. Yeh maine jaan boojh kar NAHI chhera —
   aap ne nahi kaha tha.

2) Account wise report mein ab har arse par wo purana khula till bhi
   0 ke saath aata rahe ga jo kisi ne band nahi kiya. Agar aap yeh
   nahi chahte to batayein, main sirf "isi arse mein khula" wali
   shart rakh doonga.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
CHAR FILES (README aur screenshot ke ilawa):

    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\dashboard.html
    src\Services\PageData.php
    src\Services\ReportService.php
    src\Services\OwnerMobile.php

DATABASE NAHI BADLA. KOI MIGRATION NAHI.
POS KI SCREEN NAHI BADLI. 33 purani reports nahi badlin.
PosService / OpsService / shift ki closing parchi nahi badli.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    Chhay files chadha dein. Koi migration nahi, database ko haath
    nahi lagta.

PART A — OFFLINE PC
    `approved_ui\` aur `src\` ki files SEALED PACKAGE ke andar hoti
    hain. Sirf zip extract karne se offline PC par kuch nahi badlega.

    Railway par chadhate hi cloud ka package naya ho jata hai. Phir
    us PC par wohi purana tareeqa:
        GET_UPDATE.bat   phir   INSTALL_UPDATE.bat

    Jo customer yeh update na le, us par koi asar nahi — database
    waisa hai aur koi migration nahi.

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\dashboard.html
    src\Services\PageData.php
    src\Services\ReportService.php
    src\Services\OwnerMobile.php

STEP A2 — EXTRACT
    SaaSVersion_V179.zip extract karein

STEP A3 — VERIFY (Ctrl+F)

      FILE                               SEARCH FOR
      ---------------------------------  ------------------------------
      VERSION                            V179
      src\Services\PageData.php          DASHBOARD AUR REPORTS KA EK HI USOOL
      src\Services\PageData.php          GROSS PROFIT" JHOOT NA BOLE
      src\Services\ReportService.php     ACCOUNT KHULTE HI REPORT MEIN
      src\Services\OwnerMobile.php       YAHAN KA USOOL BHI WOHI
      approved_ui\dashboard.html         CHART POORE DIN KA

STEP A4 — CHALA KAR DEKHEIN
    1. Dashboard kholein -> "net sales today" ka number note kar lein
    2. Reports -> Sales summary, aaj se aaj
       -> bills aur total BILKUL wohi aana chahiye
    3. Reports -> Account wise sale, aaj se aaj
       -> total phir wohi
    4. Naya account (shift) kholein, abhi koi bill na banayein
       -> Account wise sale mein wo account 0 ke saath nazar aaye
    5. Us par ek bill banayein
       -> usi line par sale aani shuru ho jaye
    6. Dashboard ka ghanton wala chart
       -> 00 se 23 tak, raat ki sale bhi nazar aaye

Madad: Wabwar Software House — +92 342 5095104

// build: V179 build 2026-11-04
