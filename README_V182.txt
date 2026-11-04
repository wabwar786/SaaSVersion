============================================================
 V182 — HOLD BILLS AUR DUPLICATE BILL: SIRF KHULE ACCOUNT KE,
        AUR NUMBER TARTEEB SE
 Wabwar Software House                     build 2026-11-04
============================================================

>>> PEHLE EK ZAROORI BAAT <<<

Jo zip maine 19:25 par bheji thi (V181), us mein Hold Bills wali
tabdeeli SHAMIL NAHI THI. `src\Services\OpsService.php` is project
folder mein 19:34 par badli — meri is guftagu ke kisi qadam se nahi.
Us tabdeeli par "V182" likha hua tha aur wo bilkul wohi kaam kar
rahi thi jo aap ne maanga.

Maine usay HATAYA NAHI. Pehle chala kar tasdeeq ki ke wo theek kaam
karti hai (natija neeche), phir us ke oopar baqi kaam kiya.

    >> IS LIYE: V181 ki zip ke bajaye YEH (V182) lagayein.
       Is mein V181 ka saara kaam bhi shamil hai.

Agar wo tabdeeli aap ne (ya kisi aur ne) ki thi to bata dein — main
aage se us jagah ko aise hi sambhal ke chaloon ga.


############################################################
 1) HOLD BILLS — SIRF USI ACCOUNT KE BILL
############################################################

AAP NE PAKRA: Hold Bills mein 22 OCTOBER ka bill baitha tha, aur
doosre cashier (Mueed Abbasi) ke account ka bill bhi — jabke counter
par Mubeen ka account khula tha.

WAJAH: yahan shart sirf itni thi —

        WHERE site_id=? AND order_status='OPEN'

Na account ki shart, na tareekh ki. Yani site ka HAR khula bill,
hamesha ke liye.

YEH SIRF BADSOORTI NAHI THI. Aisa purana bill koi bhi Resume kar ke
apne account mein band kar sakta tha, aur `touchOrder()` us par NAYA
`shift_id` likh deta hai. Nateeja: 22 October ka bill AAJ ke account
ki sale ban jata, aur doosre cashier ka bill is cashier ke khaate
mein chala jata. Bilkul wohi "sale mix" jis se bachna sab se ahem
hai.

AB: wohi usool jo Duplicate Bill par pehle se laga hua tha — is waqt
KHULE account ke bill, aur wo bina-account wale bill jo yeh account
khulne ke BAAD bane (tablet/QR ka order, jin par account likha hi
nahi jata).

TAREEKH KI KOI SHART JAAN BOOJH KAR NAHI RAKHI: ek till subah 11
baje khul kar agli raat 2 baje band hota hai. Us ka raat 12 ke baad
ka hold BHI USI account ka hai. Account ki shart khud hi tareekh ka
kaam kar deti hai.

ACCOUNT KHULA HI NA HO -> khali fehrist. POS waise bhi bina account
ke bill banane nahi deta.

JO PURANE KHULE BILL REH GAYE HAIN WO GUM NAHI HOTE:
Operations > Orders Management par nazar aate hain aur wahin se
manager password ke saath Void ho sakte hain. Us screen ko haath
nahi lagaya gaya.

CHALA KAR DEKHA:
    isi account ka bill         ->  dikha        PASS
    22 October ka bill          ->  nahi dikha   PASS
    doosre account ka bill      ->  nahi dikha   PASS


############################################################
 2) DUPLICATE BILL — ACCOUNT KI SHART PEHLE SE THEEK THI,
    MAGAR DO ASLI KHARABIYAN NIKLIN
############################################################

Duplicate Bill par account ki shart V159 se laga hui thi, aur chala
kar dekha to wo theek kaam kar rahi hai (22 October ka aur doosre
account ka bill dono nahi aate). Magar isi raaste par do aur
kharabiyan mil gayin:

(a) HAR POS LOAD PAR PHP WARNING
    `Undefined array key "opened_at"` — PageData line 240.
    Account ki query sirf `id, shift_no` uthati thi, magar neeche
    `$shift['opened_at']` istemal hota tha.

    Is ka ASAR sirf warning nahi tha: shart `closed_at >= NULL`
    kabhi sach nahi hoti, is liye wo bill jin par account likha hi
    nahi gaya (tablet / QR / customer app ka order) Duplicate Bill
    ki fehrist mein KABHI nazar nahi aate thay — halanke shift ki
    closing parchi un ko ginti hai.

    Ab `opened_at` bhi uthaya jata hai. Warning gayab, aur aise bill
    ab fehrist mein aate hain.

(b) PREFIX WALE BILL GHALAT JAGAH PAR
    V177 mein tarteeb ka number aise banta tha:
        (int) preg_replace('/\D/','', 'L206-0009')   ->  2060009
    Yani prefix ke hindsay bhi gin liye jate thay aur wo bill
    fehrist mein bilkul aakhir mein chala jata tha.

    Ab sirf aakhri '-' ke baad wale hindsay: `L206-0009` -> 9.
    Jin customers ke bill par prefix nahi, un par koi farq nahi.


############################################################
 3) NUMBER AB TARTEEB SE — PEHLE DIN, PHIR NUMBER
############################################################

Hold Bills par tarteeb waqt ke hisaab se ULTI thi (naya bill sab se
oopar). Ab number ki tarteeb se — jaisa Duplicate Bill par V177 se
hai.

MAGAR SIRF NUMBER PAR TARTEEB KAFI NAHI:
Bill number HAR RAAT 1 se dobara shuru hota hai (`nextBill()` sirf
`business_date = aaj` dekhta hai). Ek till subah 11 baje khul kar
agli raat 2 baje band hota hai, to USI EK account mein pehle din ke
1..50 aur agle din ke 1, 2, 3 — dono hote hain.

Sirf number par tarteeb lagate to agle din ka "1" pehle din ke "1"
ke sath ja chipakta.

AB: pehle TAREEKH, phir number. Yani bill bilkul usi tarteeb mein
jis mein bane thay. Yeh dono fehriston par laga hai.

CHALA KAR DEKHA (hold bills):
    pehle:  20, 18, 16, 15, 14, 9, 6      (waqt ke hisaab se ulti)
    ab:      6,  9, 14, 15, 16, 18, 20


############################################################
 CHALA KAR DEKHA — SAB PASS
############################################################

NAYE:
    hold bills: sirf khule account ke (teen soortein)          3
    duplicate: sirf khule account ke (teen soortein)           3
    hold bills ki tarteeb (din, phir number)                   1
    prefix wala bill sahi jagah par (L206-0009 -> 9)           1
    POS load par koi PHP warning nahi                          0 warning

PURANE (dobara chalaye, sab pass):
    V181 ke test                                              32
    V179 ke test                                              45
    saari 34 reports                                      34 / 0 error
    V180 (net ka total, column ki tarteeb)                     6
    POS se reports tak ka audit                               16
    dashboard ki asli screen                                  11
    POS par asli bill (item -> Charge -> Complete Payment)     6
    POS ki asli screen: bill/account number, service charge
      hold -> resume                                           9

EK TEST PEHLI BAAR FAIL HUA — WAJAH MERI THI:
    Mera test hold list ka PEHLA "Resume" dabata tha. Ab fehrist
    number ki tarteeb mein hai, is liye pehla bill wo nahi raha jo
    abhi hold kiya tha — test ne ghalat bill khol liya. Test ko usi
    row par lagaya jis par hamara number hai. Phir 9/9 pass.

JO MAIN NAHI KAR SAKA:
    Aap ke apne customer ke data par chala kar dekhna.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
TEEN FILES (README aur screenshot ke ilawa):

    VERSION
    src\Services\OpsService.php     (hold bills: account + tarteeb)
    src\Services\PageData.php       (warning, tarteeb, sahi number)

KOI UI FILE NAHI BADLI — is liye APPROVED_UI_SHA256.json bhi nahi
badla (70 files ke hash milaa kar dekhe, sab waise ke waise).
DATABASE NAHI BADLA. IS BAAR KOI MIGRATION NAHI.
POS ka billing raasta, dashboard, aur saari reports nahi badlin.

NOTE: V181 ki migration (`cashier_shifts.shift_seq`) ab bhi chahiye.
Agar aap ne V181 nahi lagayi, to yeh package us ke BAAD lagayein —
ya V181 ki files bhi sath chadha dein.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    Teen files chadha dein. Koi migration nahi.

PART A — OFFLINE PC
    `src\` ki files sealed package ke andar hoti hain. Railway par
    chadhate hi cloud ka package naya ho jata hai, phir us PC par:
        GET_UPDATE.bat   phir   INSTALL_UPDATE.bat

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    src\Services\OpsService.php
    src\Services\PageData.php

STEP A2 — EXTRACT
    SaaSVersion_V182.zip extract karein

STEP A3 — VERIFY (Ctrl+F)

      FILE                            SEARCH FOR
      ------------------------------  ------------------------------
      VERSION                         V182
      src\Services\OpsService.php     HOLD BILLS SIRF USI ACCOUNT
      src\Services\OpsService.php     PEHLE DIN, PHIR BILL NUMBER
      src\Services\PageData.php       `opened_at` BHI UTHAO
      src\Services\PageData.php       TARTEEB: PEHLE DIN, PHIR NUMBER

STEP A4 — CHALA KAR DEKHEIN
    1. Hold Bills kholein
       -> sirf abhi khule account ke bill
       -> koi purani tareekh ka bill nahi
       -> number barhti hui tarteeb mein: 6, 9, 14, 15...
    2. Duplicate Bill kholein
       -> wahi baat, aur number tarteeb se
    3. Jo purane khule bill pehle Hold Bills mein baithe thay, wo ab
       Operations > Orders Management par milenge — wahin se Void
       kar dein

Madad: Wabwar Software House — +92 342 5095104

// build: V182 build 2026-11-04
