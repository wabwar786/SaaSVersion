============================================================
 V178 — ACCOUNT WISE AUR DATE WISE KA TOTAL AB EK
 Wabwar Software House                     build 2026-11-03
============================================================

Sirf EK file badli: src\Services\ReportService.php
Sirf EK report badli: "Account wise sale". Baqi 30+ reports ko haath
nahi lagaya gaya.


############################################################
 MASLA KYA THA
############################################################

Aap ne 1-3 November ki report chalayi aur do alag total mile:

    date wise      134 bill   198,436
    account wise   282 bill   417,986

Wajah yeh thi ke account wise report us account ka POORA hisaab deti
thi, chahe us ke bill report ke arse se bahar ke hon. Aap ke data
mein:

    S-261025-4A3A   khula 24 Oct 23:39   9 din   118 bill
    S-261031-3CC4   khula 31 Oct 00:10   2 din    46 bill

Pehla account 24 OCTOBER ko khula tha. Us ke nau din ke bill 1-3
November ki report mein aa gaye — zyada tar October ke. Is liye
dono totalon ka muqabla hi nahi ho sakta tha, aur yehi uljhan thi.


############################################################
 AB KYA HOGA
############################################################

Wahi tareekh ki shart jo Sales summary par lagti hai, ab account
wise report mein HAR BILL par bhi lagti hai.

    >> DONO REPORTS KA TOTAL AB BILKUL EK HOTA HAI.

Account ab bhi EK HI LINE hai — 10 din chalne wala account 10
lineon mein nahi tootta. Bas us line par sirf un dinon ke bill
ginay jate hain jo aap ne chune.

Jis account ka chune hue dinon mein koi kaam hi na hua ho, wo
fehrist mein nahi aata — warna khali lineon se table bhar jata.


############################################################
 CHALA KAR DEKHA — PAANCH ARSE, HAR BAAR BARABAR
############################################################

    arsa                    date wise        account wise
    ---------------------   --------------   --------------
    31 Oct - 31 Oct          2 / 8,000        2 / 8,000      OK
    01 Nov - 01 Nov          4 / 14,606.5     4 / 14,606.5   OK
    31 Oct - 01 Nov          6 / 22,606.5     6 / 22,606.5   OK
    02 Nov - 02 Nov          6 / 15,900       6 / 15,900     OK
    01 Oct - 30 Nov         14 / 41,719.5    14 / 41,719.5   OK

Bills ki ginti aur raqam, dono barabar.


############################################################
 JO BAATEIN WAISI KI WAISI HAIN
############################################################

1) DO DIN CHALNE WALA ACCOUNT EK HI LINE MEIN
   31 Oct - 01 Nov ki report:
       S-RAAT-01   khula 31 Oct 11:00   din=2   4 bill   14,000
   Raat 12 ke baad wale bill usi account mein rahe.

2) KAI TILL EK SAATH — SALE MIX NAHI HOTI
   02 Nov ki report (dono till ek hi waqt par chale):
       S-CNT-A    3 bill    9,000
       S-CNT-B    2 bill    6,000
   asal sach bilkul yehi hai. Account sirf bill par likhe hue
   account number se tay hota hai, waqt ke andaze se nahi.

3) BINA ACCOUNT WALE BILL GUM NAHI HOTE
       (bina account)   1 bill   900
   Apni alag line mein aate hain, kisi ke khaate mein nahi jate.

4) DATE WISE REPORT KO HAATH NAHI LAGAYA.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
DO FILES (README ke ilawa):

    VERSION
    src\Services\ReportService.php

POS ki koi screen nahi badli. APPROVED_UI_SHA256.json nahi badla
(koi UI file nahi badli). Database nahi badla. Koi migration nahi.
Kisi aur report ko haath nahi lagaya gaya.


------------------------------------------------------------
 TESTING — SAB PASS
------------------------------------------------------------
NAYE (V178):
    paanch arse par dono reports ka total barabar      5
    do din wala account ek line mein                   1
    do till ek saath -> mix nahi hui                   1
    bina account wale bill alag line mein              1

PURANE (dobara chalaye, sab pass):
    V176 ke faisle                                     7
    billing ka poora raasta                            7
    V177 ki screens (duplicate bill, hold bills)       4

JO MAIN NAHI KAR SAKA:
    Aap ke apne data par chala kar dekhna. Yahan maine wohi soorat
    bana kar dekhi (ek account jo arse se pehle khula ho), magar wo
    aap ka data nahi tha. Lagane ke baad 1-3 November dobara
    chalayein — dono reports par ab 134 bill aur 198,436 aana
    chahiye.


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
    SaaSVersion_V178.zip extract karein

STEP A3 — VERIFY (Ctrl+F)

      FILE                               SEARCH FOR
      ---------------------------------  ------------------------------
      VERSION                            V178
      src\Services\ReportService.php     TAREEKH KA FILTER BILLON PAR

STEP A4 — CHALA KAR DEKHEIN
    1. Reports -> Sales summary, 1-3 November -> total note kar lein
    2. Reports -> Account wise sale, wohi 1-3 November
       -> bills aur total BILKUL wohi aana chahiye
    3. Koi aisa arsa chunein jis mein ek account kai din chala ho
       -> wo account ek hi line mein, us arse ke bill ke saath

Madad: Wabwar Software House — +92 342 5095104

// build: V178 build 2026-11-03
