============================================================
 V186 — CLOSING KI PARCHI: BARA FONT, HASHIYA KHATAM,
        AUR NAYA SAAF FONT
 Wabwar Software House                     build 2026-11-11
============================================================

CHAAR FILES (ek nayi). Koi UI file nahi badli, koi migration
nahi, database nahi badla, POS ki screen nahi badli.

AAP NE KAHA:
"Closing sheet main font size increase kr do aur margin khatm
 kr do, kyunki closing sheet per 2 reports nikalti hain aik per
 bhee readable nahi hai."
Aur phir: "I need to change font style in reports."


############################################################
 1) FONT BARA, HASHIYA KHATAM
############################################################

    PEHLE :  font 9,  hashiya 12pt dono taraf  ->  37 harf
    AB    :  font 11, hashiya  4pt dono taraf  ->  33 harf

80mm ke kagaz mein se 8.5mm sirf khali hashiya mein ja raha tha.
Ab wo matn ko mil gaya hai.

Har doosra size bhi isi nisbat se bara hua hai — business ka
naam, headings, tareekh — warna sirf chand lakeerein barhtin aur
parchi be-tarteeb lagti.

CUSTOMER KE BILL PAR KOI FARQ NAHI. Wo ab bhi font 9, hashiya
12pt aur 37 harf par chhapta hai. Chala kar dekha: closing ki
parchi banane ke BAAD bana bill bhi purane paimane par hi
chhapta hai.


############################################################
 2) PARCHI KA FONT AB PDF KE ANDAR
############################################################

YEH ISI KAAM MEIN PAKRA GAYA:

Parchi `Courier` mein chhapti thi, aur Courier PDF ke ANDAR
shamil hi nahi hota. Har PC ka reader apni Courier dikhata hai
aur har thermal printer apni — isi liye shakal har jagah alag
aur patli-dhundli aati thi.

AB: `Liberation Mono` PDF ke andar hi chala jata hai. Har PC,
har printer par bilkul ek jaisi, aur harf mote aur saaf. Thermal
printer par yehi sab se zyada farq daalta hai.

QATAREIN NAHI TOOTEEN — AUR YEH ITTEFAQ NAHI:
Liberation Mono ki chaurai Courier ke barabar hai: 600/1000 per
harf, yani wahi `Pdf::CW = 0.6` jo code pehle se istemal karta
hai. Isi wajah se yeh font chuna gaya. Raqamon ki seedhi qatar
jaisi thi waisi hi rehti hai; sirf harf ki shakal badli hai.

FONT PHP FILE KE ANDAR KYUN HAI:
Offline package SIRF `.php` files ko seal karta hai —
`build_offline_bundle.php` mein saaf likha hai:

    if (substr($abs, -4) !== '.php') continue;

Ek `.ttf` file rakhte to wo customer ke PC tak pohonchti hi NAHI
aur parchi bina font ke chhapti. Is liye font ke bytes `src\
Services\ReceiptFont.php` ke andar base64 mein hain.

HAJM: sirf wohi 95 harf rakhe gaye jo parchi par chhapte hain
(space se `~` tak). Poori font file 310 KB ki thi; ab har font
8.5 KB. Poori parchi ki PDF 21 KB banti hai.

LICENSE: Liberation Mono — SIL Open Font License 1.1. Tijarti
software mein shamil karne ki ijazat deta hai.


############################################################
 3) LAMBA NAAM — RAQAM AGLI LINE PAR CHALI JATI THI
############################################################

Bara font lagate hi yeh samne aaya: ek line mein kam harf aate
hain, aur purana code lambe item ka naam TOR deta tha:

        1 x CHICKEN TIKKA BOTI MASALA
                                  420     <- akeli line par

Parchi tooti hui lagti thi aur raqamon ki qatar bigar jati thi.

AB naam kaat diya jata hai aur raqam hamesha usi line par,
seedhi qatar mein:

        1 x CHICKEN TIKKA BOTI MAS. 420


############################################################
 CHALA KAR DEKHA — SAB PASS
############################################################

NAYE (V186):                                              20
    - dono font PDF ke andar (pdffonts: emb = yes)
    - Courier ab kahin nahi
    - dono fonts ka Widths array: 95 harf, har ek 600
    - font ke bytes asli TTF hain
    - font 11 par ek line mein 33 harf
    - koi line kagaz se bahar nahi
    - lamba naam: raqam usi line par, koi raqam akeli nahi
    - customer ka bill 37 harf / font 9 par hi
    - closing ke BAAD bana bill bhi purane paimane par
    - poori PDF 21 KB

ASLI API SE (end to end):
    shiftmgr-report-pdf&part=sale    http 200, 21,131 bytes
    shiftmgr-report-pdf&part=cash    http 200, 21,303 bytes
    dono mein LiberationMono + Bold, emb = yes

PURANE (dobara chalaye, sab pass):
    V185 ke test                                          27
    V184 · tax wala business                          32 · 6
    V183 · account band karne ke guards            4 · 7 + 3
    V181 · V179 ke test                             32 · 43
    POS se reports tak ka audit                           12
    saari 34 reports                           34 / 0 error

JO MAIN NAHI KAR SAKA:
    Asli thermal printer par chhapa kar dekhna. Shakal screen
    par aur PDF mein tasdeeq shuda hai; kagaz par aap dekh lein.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
CHAAR FILES (README aur screenshot ke ilawa):

    VERSION
    src\Services\ReceiptFont.php   (NAYI — font ke bytes)
    src\Services\Pdf.php           (font PDF ke andar, hashiya/line)
    src\Services\BillTemplate.php  (bara size, naam kaatna)
    public\api.php                 (closing ki parchi: 11 / 4pt / 15pt)

DATABASE NAHI BADLA. KOI MIGRATION NAHI.
Customer ka bill, KOT parchi, POS, dashboard aur saari 34
reports nahi badlin.

ROLL KA KHARCH: bara font = zyada kagaz. Sale report ~225mm se
~257mm, closing sheet 165mm se 183mm. Har closing par do parchi
nikalti hain, yani roz taqreeban 50mm zyada roll.

AGAR AUR BARA CHAHIYE: `public\api.php` ki us ek lakeer mein
11 ki jagah 12 kar dein (aur 4.0 ko 3.0). Us par ek line mein
30 harf aayenge — item ke naam thora zyada katenge.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    Chaar files chadha dein. Koi migration nahi.

PART A — OFFLINE PC
    Railway par chadhate hi cloud ka package naya ho jata hai,
    phir us PC par:
        GET_UPDATE.bat   phir   INSTALL_UPDATE.bat

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    src\Services\Pdf.php
    src\Services\BillTemplate.php
    public\api.php
    (ReceiptFont.php nayi hai — delete karne ki zarurat nahi)

STEP A2 — EXTRACT
    SaaSVersion_V186.zip extract karein

STEP A3 — VERIFY (Ctrl+F)

      FILE                            SEARCH FOR
      ------------------------------  ------------------------------
      VERSION                         V186
      src\Services\ReceiptFont.php    Liberation Mono
      src\Services\Pdf.php            PARCHI KA FONT AB PDF KE ANDAR
      src\Services\BillTemplate.php   CLOSING KI PARCHI KA FONT BARA
      public\api.php                  closing ki parchi: font 11

STEP A4 — CHALA KAR DEKHEIN
    1. Operations -> Opening & Closing Shift -> koi shift band
       karein (ya Closing History se "Print report")
       -> do parchi: SALE REPORT aur CLOSING SHEET
       -> matn pehle se saaf bara, kinare par khali jagah
          taqreeban nahi
    2. Ek aam customer bill nikaal kar dekh lein
       -> wo bilkul pehle jaisa hona chahiye
    3. Lambe naam wale item par dekh lein
       -> raqam usi line par honi chahiye, agli par nahi

Madad: Wabwar Software House — +92 342 5095104

// build: V186 build 2026-11-11
