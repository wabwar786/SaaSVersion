============================================================
 V188 — LAMBI PARCHI AB POORI CHHAPTI HAI
 Wabwar Software House                     build 2026-11-11
============================================================

>>> YEH ZIP V186 AUR V187 KI JAGAH LAGAYEIN <<<

Un dono ka saara kaam is mein shamil hai:
    V186 — closing parchi ka bara font, hashiya khatam, naya
           saaf font jo PDF ke andar jata hai
    V187 — dobara chhapi parchi par DUPLICATE, aur
           Order/invoice detail ki tarteeb durust

AATH FILES. Koi UI file nahi badli, koi migration nahi,
database nahi badla, POS ki screen nahi badli.


############################################################
 WAJAH PDF MEIN NAHI THI — PRINTER MEIN THI
############################################################

AAP NE KAHA: "Complete print of closing sheet... mujhe report
sirf salad/raita department tak mili, us ke baad kuch print
nahi hua."

Aap ki bheji hui PDF andar se jaanchi. Wo POORI THI:

    128 lineen, sab safhe ke andar
    sab se upar  y = 1940
    sab se neeche y =   25       (safha 1954pt ka)
    safhe se bahar lineen: 0

Aakhri lineen bhi mojood theen — `Cashier ____`,
`Manager ____`, `Printed 11 Nov 2026 19:44`. Yani software ne
sab kuch likha tha.

MASLA YEH THA: parchi EK hi safha thi, 1954pt = 689mm lamba.
Thermal driver ka apna page length hota hai — aap ke yahan
279mm. Us se aage ka hissa driver khamoshi se phenk deta hai:

    safha            1954pt  (689mm)
    driver ki hadd    791pt  (279mm)  ->  kaat  y = 1163
    SALAD/RAITA                           y = 1075

Isi liye aap ko Salad/Raita tak mila aur aage kuch nahi.


############################################################
 HAL — PARCHI AB KAI SAFHON MEIN
############################################################

Parchi 780pt (~275mm) se lambi ho to ab KAI SAFHON mein tooth
jati hai. Thermal roll par safhe ek ke baad ek bah kar nikalte
hain — kagaz par koi khala nahi aata — aur har driver par poori
chhapti hai, chahe jitni lambi ho.

    aap ki parchi : 134 lineen  ->  3 safhe (780 + 780 + 534 pt)

780 is liye chuna ke aap ke driver ki hadd 791pt (279mm) hai;
us se thora neeche rakha taake driver ke apne kinaron ki jagah
bhi bach jaye.

DOOSRE SAFHE SE AAGE upar chhota sa nishan chhapta hai:

    - 2 / 3 -

taake dekhne wale ko pata rahe ke parchi aage bhi hai aur koi
hissa gum nahi hua.

CHHOTI PARCHI JAISI THI WAISI HI: jo 780pt ke andar aa jaye wo
pehle ki tarah EK hi safha rehti hai, bilkul utni hi lambi
jitna us ka matn. Customer ka bill kabhi nahi tootta — asli API
se chala kar dekha: 1 safha, 226 x 300pt.

>>> PRINTER KI SETTING BADALNE KI ZARURAT NAHI. 279mm wali
    setting jaisi hai waisi rehne dein.


############################################################
 EK AUR KHARABI JO ISI KAAM MEIN NIKLI
############################################################

PDF ka `xref` table yeh maan kar banta tha ke object 1 se N tak
LAGATAR hain:

    $n = count($objs) + 1;
    foreach ($objs as $num => $_) ... offset likho

Jab tak ek hi safha tha, yeh sach tha. Magar kai safhe aate hi
object 11, 13, 15 ... par chale gaye aur 3-4 khali reh gaye.
Purane tareeqe se xref ki qatar khisak jati aur PDF ya to kharab
hoti ya reader us ki marammat kar ke kholta — aur kuch printer
aise PDF chupchap phenk dete hain.

Ab xref asli object numbers par banta hai, khali jagahon ke liye
"free" entry ke saath. (`pdfinfo` aur `pdftotext` dono bina
shikayat parh lete hain — imtihaan mein shamil hai.)


############################################################
 CHALA KAR DEKHA — SAB PASS
############################################################

NAYE (V188):                                              18
    - 134 lineon wali parchi -> 3 safhe (780+780+534)
    - koi safha 279mm (791pt) se lamba nahi
    - SALAD/RAITA · DESSERT · DRINKS · Cashier · Manager:
      sab PDF mein maujood (kuch gum nahi hua)
    - chhoti parchi ab bhi EK safha, 519pt
    - xref aur trailer ka /Size barabar
    - font ab bhi shamil (dono)
    - pdfinfo aur pdftotext bina shikayat parh lete hain

ASLI API SE:
    shiftmgr-report-pdf   -> http 200, 1 safha, 226 x 619pt
    bill-pdf (customer)   -> http 200, 1 safha, 226 x 300pt

PURANE (dobara chalaye, sab pass):
    V187 · V186                                      14 · 20
    V185 ke test                                          27
    V184 · tax wala business                          32 · 6
    V183 · account band karne ke guards            4 · 7 + 3
    V181 · V179 ke test                             32 · 43
    POS se reports tak ka audit                           12
    saari 34 reports                           34 / 0 error

JO MAIN NAHI KAR SAKA:
    Aap ke asli thermal printer par chhapa kar dekhna. PDF ka
    dhancha aur safhon ki lambai yahan tasdeeq shuda hai; kagaz
    par aap dekh lein.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
AATH FILES (README aur screenshot ke ilawa):

    VERSION
    src\Services\ReceiptFont.php    (V186 — NAYI, font ke bytes)
    src\Services\Pdf.php            (V188 safhe + xref, V186 font)
    src\Services\BillTemplate.php   (V186 bara font, V187 DUPLICATE)
    src\Services\ReportService.php  (V187 — invoice detail tarteeb)
    public\api.php                  (V188 safhe ki hadd, V187 dup)
    public\ops_db.js                (V187 — reprint par dup=1)
    public\router.php               (ops_db.js ka ?b= number)

DATABASE NAHI BADLA. KOI MIGRATION NAHI.


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
    SaaSVersion_V188.zip extract karein

STEP A3 — VERIFY (Ctrl+F)

      FILE                            SEARCH FOR
      ------------------------------  ------------------------------
      VERSION                         V188
      src\Services\Pdf.php            LAMBI PARCHI AB KAI SAFHON MEIN
      src\Services\Pdf.php            xref ab asli object numbers par
      src\Services\ReceiptFont.php    Liberation Mono
      src\Services\BillTemplate.php   DOBARA NIKALI GAYI PARCHI PAR
      src\Services\ReportService.php  V187 — TARTEEB DURUST
      public\api.php                  780pt (~275mm) se lambi parchi
      public\ops_db.js                dobara chhapi ja rahi hai
      public\router.php               ops_db.js?b=v187

STEP A4 — CHALA KAR DEKHEIN
    1. Wohi lambi closing sheet dobara nikalein
       -> ab POORI chhapni chahiye, aakhir mein Cashier /
          Manager ki lakeerein aur "Printed ..." tak
       -> doosre safhe se aage upar "- 2 / 3 -" likha hoga
    2. Ek aam customer bill nikaal kar dekh lein
       -> wo ek hi safhe par, bilkul pehle jaisa
    3. Printer ki setting ko haath na lagayein — 279mm theek hai

Madad: Wabwar Software House — +92 342 5095104

// build: V188 build 2026-11-11
