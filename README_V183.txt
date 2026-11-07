============================================================
 V183 — ACCOUNT WISE KA NAYA USOOL, AUR ADMIN KISI BHI
        WAQT KOI BHI ACCOUNT BAND KAR SAKE
 Wabwar Software House                     build 2026-11-07
============================================================

TEEN FILES. Koi UI file nahi badli, koi migration nahi,
database nahi badla, POS ki screen nahi badli.


############################################################
 1) ACCOUNT WISE SALE — TAREEKH AB ACCOUNT KE KHULNE PAR
############################################################

AAP NE KAHA:
"Agar main start date 7/11/2026 aur end date 7/11/2026 rakhoon to
mujhe sirf EK account dikhna chahiye jo 7/11/2026 ko shuru hua.
Pichli date wala nahi dikhna chahiye."

PEHLE ULTA THA: account koi bhi ho, bas us ke wo bill ginay jate
thay jo chuni hui tareekhon mein BAND huay. Is liye 7 November ki
report par 1 November aur 5 November ke khule account bhi aa jate
thay.

AB:
    • Fehrist mein sirf wo account jo CHUNI HUI TAREEKHON MEIN KHULE
    • Har account par us ka POORA hisaab — saare bill aur saare
      kharche, chahe wo agle din ke hon

Doosri baat ahem hai: ek till subah 11 baje khul kar agli raat 2
baje band hota hai. Us ki raat 12 ke baad wali sale bhi USI account
ki hai. Chala kar dekha:

    31 October ki report:
        account 1   khula 31 Oct 11:00   band 01 Nov 02:00
                    din 2    4 bill    14,000

    (Sales summary 31 Oct par 2 bill / 8,000 dikhati hai — baqi
     8,000... yani 6,000 agle din band huay.)


>>> EK BAAT SAAF SAMAJH LEIN — YEH JAAN BOOJH KAR HAI <<<

Ab is report ka total `Sales summary` ke total ke BARABAR NAHI hota.
V178 mein aap ne barabri maangi thi; ab aap ne yeh usool chuna hai.
Dono ek sath mumkin nahi, aur wajah seedhi hai:

    • Jo account pehle khula tha magar us ke bill aaj band huay —
      wo bill ab is report mein nahi (us ka account aaj khula hi
      nahi tha)
    • Jo account aaj khula hai aur kal tak chalega — us ke KAL wale
      bill bhi abhi is report mein hain

Do reports ab do alag sawalon ka jawab hain:

    Sales summary   ->  is DIN kitni sale hui
    Account wise    ->  is din KHULE ACCOUNT ne kul kitna kamaya

Report ke upar wala note yehi baat likhta hai, taake koi dono ke
total ka muqabla kar ke pareshan na ho.

JO WAISA KA WAISA HAI:
    • Account ab bhi EK HI LINE — 10 din chalne wala account 10
      lineon mein nahi tootta
    • Account sirf bill par likhe hue account number se tay hota
      hai, waqt ke andaze se nahi — kai till ek sath chalein to
      sale mix nahi hoti
    • Jin billon par account number hai hi nahi (tablet/QR ka
      order) wo "(bina account)" ki apni line mein, bill ki
      tareekh se
    • Account khulte hi report mein aa jata hai, chahe us par ek
      bhi bill na bana ho (V179 wali baat) — ab `HAVING` ki shart
      ki zarurat hi nahi rahi


############################################################
 2) ADMIN KISI BHI WAQT KOI BHI ACCOUNT BAND KAR SAKE
############################################################

AAP NE KAHA: "Mere paas admin mein hona chahiye ke kisi bhi waqt
account band kar sakoon."

MASLA KYA THA: Opening & Closing Shift ki screen par Close ka
button SIRF aap ka apna khula account band karta tha. Doosre
cashier ke khule till par sirf ek peela note aata tha jis ke
neeche likha hota tha "unhein wahi band karenge" — aur code mein
saaf likha tha `Yahan un par koi button nahi.`

Yeh V167 mein jaan boojh kar hua tha (pehle Close ka button
GHALTI se doosre banday ki shift band kar deta tha), magar us ke
sath "admin jaan boojh kar band kare" wala raasta banaya hi nahi
gaya. Qeemat aap ke customer par nazar aayi: ek till 1 November se
chaar din khula para raha.

AB: har khule till par apna "Close this till" ka button.

    • Pehle EXPECTED cash dikhta hai, phir counted maanga jata hai
      (yehi tarteeb cash ka farq pakarti hai — ulta karein to
       ginne wala expected dekh kar wahi likh deta hai)
    • Band hote hi poora hisaab dikhta hai aur closing parchi
      khud khul jati hai
    • Closing parchi par likha jata hai "Closed by <naam>" — taake
      baad mein saaf rahe ke cashier ne khud band nahi kiya

EK AUR KAMI JO ISI KAAM MEIN NIKLI:
Screen sirf DOOSRE banday ke till dikhati thi. Malik ke APNE
purane bhoole huay till kahin nazar hi nahi aate thay — screen us
ka sab se naya account "aap ki shift" dikhati thi aur baqi
khamoshi se khule pare rehte thay.

Aap ke asal data mein bilkul yehi tha: account 8743 jis par ek
bhi bill nahi bana, aur wo khula hi raha.

Ab aap ki MOJOODA shift ke ilawa HAR khula till fehrist mein aata
hai — apna ho ya kisi aur ka — aur har ek par Close ka button.
Apna purana till band karne ke liye admin hona zaroori nahi.

DO HIFAZATEIN:
    1. Jis till par pichle 10 MINUTE mein bill bana ho, us par
       saaf warning: "abhi kaam ho raha hai". Us waqt band karna
       cashier ko beech manjhdhar mein phansa deta hai.
    2. Asal rok SERVER par lagayi gayi hai, screen par nahi.
       Pehle `shiftClose()` mein koi shart thi hi nahi — jis ke
       paas `shift` module tha wo request bana kar KISI KA BHI
       till band kar sakta tha. Button nahi tha is liye nazar nahi
       aaya, magar rok screen par rakhna rok nahi hoti.
       Ab: apna till koi bhi band kare, DOOSRE ka sirf
       Admin/Manager.


############################################################
 CHALA KAR DEKHA — SAB PASS
############################################################

NAYE:
    account wise ka naya usool (4 arse)                     4
      - har line ka account isi arse mein khula
      - pichli tareekh ka account bilkul nahi aaya
      - raat 12 ke paar chalne wale till ka POORA 14,000
    account band karne ke guards                           10
      - admin ko can_close mila, cashier ko nahi
      - doosre ke account ka id bhi aata hai
      - CASHIER doosre ka account band NAHI kar saka
        ("Only an Admin or Manager can close someone else's till")
      - admin ne band kar diya, status CLOSED
      - parchi par "Closed by Akorwal Owner"
      - dobara band karne par saaf error
      - abhi bana bill -> busy = true
      - 30 minute purana bill -> busy = false
    asli screen par (3)
      - teeno khule till par Close ka button
      - apne purane till par "aap ka apna purana till"
      - report ke note par naya usool likha hua

PURANE (dobara chalaye, sab pass):
    V179 ke test                                           43
    V181 ke test                                           32
    V180 (net ka total, column ki tarteeb)                  6
    POS se reports tak ka audit                            16
    saari 34 reports                                   34 / 0 error
    dashboard ki asli screen                               11
    POS par asli bill                                       6
    POS ki screen (bill/account number, service charge)     9

V179 KE 9 TEST JAAN BOOJH KAR BADLE GAYE:
    Wo yeh maang rahe thay ke "account wise ka total = sales
    summary ka total". Aap ke naye faisle se yeh waada khatam ho
    gaya hai, is liye test ko naye usool par le aaya gaya:
    ab wo yeh jaanchte hain ke total = is arse mein khule
    accounts ka poora hisaab, aur pichli tareekh ka koi account
    fehrist mein na aaye.

KUCH TEST PEHLI BAAR FAIL HUAY — WAJAH MERI THI:
    Mere test "doosre ka till" ke liye fehrist ka pehla row
    uthate thay. Ab us fehrist mein apne purane till bhi aate
    hain, aur apna till band karne par "Closed by" ka thappa
    nahi lagta (yehi theek hai) — is liye test ko saaf kaha gaya
    ke doosre ka till chune.

JO MAIN NAHI KAR SAKA:
    Aap ke apne customer ke data par chala kar dekhna.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
TEEN FILES (README aur screenshot ke ilawa):

    VERSION
    src\Services\ReportService.php   (account wise ka naya usool)
    src\Services\OpsService.php      (khule till + server ki rok)
    public\ops_db.js                 (Close this till ka button)

KOI UI FILE NAHI BADLI — APPROVED_UI_SHA256.json bhi nahi badla
(70 files ke hash milaa kar dekhe, sab waise ke waise).
DATABASE NAHI BADLA. KOI MIGRATION NAHI.
POS ka billing raasta, dashboard aur baqi 33 reports nahi badlin.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    Chaar files chadha dein. Koi migration nahi.

PART A — OFFLINE PC
    `src\` aur `public\` ki files sealed package ke andar hoti
    hain. Railway par chadhate hi cloud ka package naya ho jata
    hai, phir us PC par:
        GET_UPDATE.bat   phir   INSTALL_UPDATE.bat

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    src\Services\ReportService.php
    src\Services\OpsService.php
    public\ops_db.js

STEP A2 — EXTRACT
    SaaSVersion_V183.zip extract karein

STEP A3 — VERIFY (Ctrl+F)

      FILE                            SEARCH FOR
      ------------------------------  ------------------------------
      VERSION                         V183
      src\Services\ReportService.php  TAREEKH AB ACCOUNT KE KHULNE PAR
      src\Services\OpsService.php     DOOSRE KA ACCOUNT SIRF ADMIN
      src\Services\OpsService.php     AAP KI MOJOODA SHIFT KE ILAWA
      public\ops_db.js                ADMIN KISI BHI WAQT KOI BHI

STEP A4 — CHALA KAR DEKHEIN
    1. Reports -> Account wise sale, From 07/11 To 07/11
       -> sirf wo account jo 7 November ko khule
       -> pichli tareekh ka koi account nahi
    2. Operations -> Opening & Closing Shift
       -> peele khane mein HAR khula till (apna purana bhi)
       -> har ek par "Close this till"
       -> dabayein: expected dikhega, phir ginti maangi jayegi,
          phir closing parchi khud khul jayegi
    3. Jo till kisi aur ka ho us ki parchi par neeche dekh lein:
       "Closed by <aap ka naam>"

Madad: Wabwar Software House — +92 342 5095104

// build: V183 build 2026-11-07
