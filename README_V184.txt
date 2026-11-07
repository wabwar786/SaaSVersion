============================================================
 V184 — REPORT KI TARTEEB QUDRATI, AUR SAB KUCH ENGLISH
 Wabwar Software House                     build 2026-11-07
============================================================

CHAAR FILES. Koi number ya hisaab nahi badla, database nahi
badla, koi migration nahi, POS ki screen nahi badli.


############################################################
 1) COLUMN AB QUDRATI TARTEEB MEIN
############################################################

AAP NE KAHA:
"Is ka sequence theek nahi hai. Normally aisa hota:
 sale 1000, service charges 50, discount 30, tax 20,
 gross 1040."

Bilkul theek. V180 mein nateeja (Sale/Net) apne hisson se
PEHLE aa gaya tha:

    PEHLE :  Bills | Sale | Kharche | Net | Subtotal |
             Discount | Service | Delivery | Tax

    AB    :  Bills | Subtotal | Discount | Service |
             Delivery | Tax | Total | Expenses | Net

    Subtotal - Discount + Service + Delivery + Tax = Total
    Total - Expenses = Net

Aap ki misaal is par:   1,000 - 30 + 50 + 20 = 1,040
Aap ke asal data par:   64,880 - 2,274 + 771.21 + 0 = 63,377.21

`Sale` ka LABEL ab "Total" hai. Key wahi `sale` hai, is liye
kuch nahi tootta — magar screen par ab wohi lafz jo Sales
summary par hai, to dono reports milana aasan.


>>> V180 WALA MASLA WAPAS NA AA JAYE <<<

V180 ki asal shikayat thi "account wise report main total nahi
aa rhi hai". Wajah yeh thi ke `reports.html` upar ke chaar tile
PEHLE CHAAR numeric column se banata hai. Qudrati tarteeb mein
wo tile Bills | Subtotal | Discount | Service ban jate — aur
Total ka tile phir ghayab.

HAL: column par ab ek IKHTIYARI `kpi` nishan lag sakta hai.
`reports.html` pehle nishan wale column dekhta hai; jis report
mein nishan NA ho, wahan bilkul pehle jaisa (pehle chaar
numeric). Yani BAQI 33 REPORTS PAR KOI FARQ NAHI.

    Tile ab : Bills | Total | Expenses | Net
    Chart ab: billon ka nahi, paise ka

(Chala kar dekha: Sales summary, Sales by item, Payment
 methods aur Current stock ke tile bilkul pehle jaise hain.)


############################################################
 2) TAX — EK ASAL KHARABI JO ISI KAAM MEIN PAKRI GAYI
############################################################

Tarteeb theek karte waqt imtihaan ne khud dikha diya ke kuch
lineon par hisaab milta hi nahi. Wajah:

JIS BUSINESS MEIN QEEMAT KE ANDAR TAX HOTA HAI (tax mode
INCLUSIVE), WAHAN TAX JAMA NAHI HOTA — wo pehle se subtotal ke
andar hai:

    subtotal 3,400 · tax 468.97 · grand_total 3,400
    (yani 2,931.03 + 16% = 3,400)

Agar column par sirf "Tax" likha rehta to aap jama kar ke
3,868.97 nikalte aur report jhooti lagti.

AB:
    • tax qeemat ke ANDAR  ->  column ka naam "Tax (incl.)"
      aur note: "Subtotal - Discount + Service + Delivery =
      Total. Tax is already inside your prices ... NOT added
      again."
    • tax BAHAR se lagta   ->  column ka naam sirf "Tax" aur
      note: "Subtotal - Discount + Service + Delivery + Tax
      = Total"  (bilkul pehle jaisa)

AAP KE APNE DATA PAR: 7 November ki report par tax 0 tha, is
liye wahan farq nazar nahi aata — magar jis din tax lagega us
din zaroor aata.

EK BAAT JAAN LENI CHAHIYE: label aur note MOJOODA setting
dekhte hain, jabke purane bill us waqt ke usool se bane hote
hain. Agar kisi business ne beech mein tax mode badla ho, to
us ki purani lineon par formula poora nahi baithega. Yeh
report ki kharabi nahi — us data ki haqeeqat hai.


############################################################
 3) SAARI 34 REPORTS AB ENGLISH MEIN
############################################################

AAP NE KAHA: "Report Urdu heading aur other things nahi honi
chaiye, English main honi chaiye."

Sirf is report se nahi — poori report screen se Roman Urdu
nikal diya, warna aadhi English aadhi Urdu reh jati.

    Khula        ->  Opened
    Band         ->  Closed
    Din          ->  Days
    Kharche      ->  Expenses
    Baqaya       ->  Outstanding
    Kab          ->  Date & time
    Kis ne       ->  By user
    Di kis ne    ->  Given by
    Wajah        ->  Reason
    Nateeja      ->  Result
    FBR bheje    ->  FBR sent
    Reh gaye     ->  Not sent

Saath hi 3 note aur 5 report ki tafseel bhi, misaal ke taur par:
    "Opening, aaj ki kharidari, aaj ki sale, closing aur current"
 -> "Opening, purchases, sales, closing and current"

(Imtihaan har report ka har heading, note aur tafseel scan
 karta hai — ab kahin Roman Urdu nahi.)


############################################################
 CHALA KAR DEKHA — SAB PASS
############################################################

NAYE:
    V184 (tarteeb, hisaab, tile, English)                  27
      - poori tarteeb theek
      - har line ka apna hisaab theek
      - tile: Bills | Total | Expenses | Net
      - chart paise wale column par
      - nishan sirf is report par, baqi 33 par nahi
      - kahin Roman Urdu nahi
    tax BAHAR se lagne wala business                        6
      - aap ki misaal ke bill banaye: 1000-30+50+20 = 1040
      - label sirf "Tax", note mein tax jama hone wala formula
    asli screen (1366) par                                  6
    baqi 4 reports ke tile pehle jaise                      8

PURANE (dobara chalaye, sab pass):
    V183 ke test                                            4
    account band karne ke guards                        7 + 3
    V181 ke test                                           32
    V179 ke test                                           43
    V180 (net ka total, Total ka tile)                      9
    POS se reports tak ka audit                            12
    saari 34 reports                                34 / 0 error
    70 UI file ke hash                            0 mismatch *

* reports.html ka hash JAAN BOOJH KAR badla aur
  APPROVED_UI_SHA256.json mein naya likh diya gaya. Baqi 69
  files waisi ki waisi.

TEEN PURANE IMTIHAAN JAAN BOOJH KAR BADLE:
    V180 ke do yeh maangte thay ke "Sale pehle 8 column mein
    ho". Wo us waqt ka ZARIYA tha, zaroorat nahi — zaroorat yeh
    thi ke TOTAL NAZAR AAYE. Ab wo tile se poori hoti hai, is
    liye imtihaan ab wohi janchta hai.

    Teesra (account band karne wala) ab apna khula till KHUD
    banata hai. Pehle wo database mein pehle se maujood kisi
    khule till par chalta tha — aur chunke wohi imtihaan us
    till ko band kar deta hai, doosri baar chalane par ruk
    jata tha.

JO MAIN NAHI KAR SAKA:
    Aap ke apne customer ke data par chala kar dekhna.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
CHAAR FILES (README aur screenshot ke ilawa):

    VERSION
    src\Services\ReportService.php    (tarteeb, tax, English)
    approved_ui\reports.html          (tile/chart ka nishan)
    APPROVED_UI_SHA256.json           (reports.html ka naya hash)

DATABASE NAHI BADLA. KOI MIGRATION NAHI.
Koi number ya hisaab nahi badla — sirf tarteeb, naam aur note.
POS, dashboard aur baqi 33 reports nahi badlin.

NOTE: V183 ab bhi chahiye (account wise ka naya usool aur
"Close this till"). Agar V183 nahi laga to pehle wo lagayein,
ya us ki files bhi sath chadha dein.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    Chaar files chadha dein. Koi migration nahi.

PART A — OFFLINE PC
    `src\` aur `approved_ui\` ki files sealed package ke andar
    hoti hain. Railway par chadhate hi cloud ka package naya ho
    jata hai, phir us PC par:
        GET_UPDATE.bat   phir   INSTALL_UPDATE.bat

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    src\Services\ReportService.php
    approved_ui\reports.html
    APPROVED_UI_SHA256.json

STEP A2 — EXTRACT
    SaaSVersion_V184.zip extract karein

STEP A3 — VERIFY (Ctrl+F)

      FILE                            SEARCH FOR
      ------------------------------  ------------------------------
      VERSION                         V184
      src\Services\ReportService.php  COLUMN AB QUDRATI TARTEEB MEIN
      src\Services\ReportService.php  TAX KA LABEL BUSINESS KE USOOL
      approved_ui\reports.html        REPORT KHUD BATA SAKTI HAI
      APPROVED_UI_SHA256.json         299b22e065e921fb

STEP A4 — CHALA KAR DEKHEIN
    1. Reports -> Account wise sale
       -> column: Subtotal | Discount | Service | Delivery |
          Tax | Total | Expenses | Net
       -> upar ke tile: Bills | Total | Expenses | Net
       -> koi Urdu heading nahi
    2. Sales summary kholein
       -> us ke tile bilkul pehle jaise
    3. Agar aap ke yahan tax qeemat ke ANDAR hai
       -> column par "Tax (incl.)" likha hoga aur note batayega
          ke wo jama nahi hota

Madad: Wabwar Software House — +92 342 5095104

// build: V184 build 2026-11-07
