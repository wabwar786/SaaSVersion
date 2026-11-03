============================================================
 V177 — DUPLICATE BILL, HOLD BILL AUR ACCOUNT WISE REPORT
 Wabwar Software House                     build 2026-11-03
============================================================

Sirf wohi paanch cheezein jo aap ne kahin. Koi aur screen, koi aur
report, koi aur raasta haath nahi lagaya gaya.


############################################################
 1) DUPLICATE BILL — AB TARTEEB SE
############################################################

Pehle fehrist waqt ke hisaab se ulti thi (aakhri bill sab se oopar).
Ab bill number ke hisaab se barhte hue: 0009, 0010, 0011, 0012.


############################################################
 2) DUPLICATE BILL — TABLE NUMBER
############################################################

Table ka data server PEHLE SE bhej raha tha (`shiftBills.table`),
sirf screen par dikhaya nahi ja raha tha. Ab Dine In wale bill par
table ka badge aata hai. Takeaway / Delivery par kuch nahi.

Search mein bhi table shamil — ab "Table 7" likh kar us table ke
bill dhoondhe ja sakte hain.


############################################################
 3) BILL NUMBER ASAL SHAKL MEIN
############################################################

Yeh aap ne alag se kehne par theek kiya.

Duplicate ki fehrist mein bill number ginti mein badal diya jata tha:

    `L41-0012`  ->  screen par `410012`

Jis customer ke node par prefix lagta hai, us ka cashier apna bill
number pehchan hi nahi pata tha. Ab wohi number dikhta hai jo bill
par chhapa hai.

Reprint par koi asar nahi — wo pehle bhi asal bill number (`id`) se
chalta tha aur ab bhi usi se chalta hai. Ginti wali shakl ab `seq`
mein alag rakhi hai, sirf TARTEEB ke liye.


############################################################
 4) HOLD BILLS — MODE AUR TABLE DONO
############################################################

Pehle yahan ek hi cheez likhi aati thi: table mil jaye to table,
warna mode. Is liye "Dine In" aur "Table 5" kabhi ek saath nazar
nahi aate the.

AB:
    Bill #0005 · Dine In  [Table 1]
    Bill #0006 · Dine In  [table nahi chuna]
    Bill #0007 · Takeaway

Jis Dine In bill par table chuna hi na gaya ho, wo ab SAAF batata
hai — chup nahi rehta. Wahi bill baad mein "yeh kis ka hai?" wala
masla banta hai.

EK BAAT IMANDARI SE: maine chala kar dekha, table MEHFOOZ theek ho
raha hai — table chunne par hold list `Table 1` lauta rahi thi. Yani
data ki kharabi nahi thi, dikhane ki thi. Agar aap ke yahan table
chunne ke BAWAJOOD na aaye to wo alag maamla hoga, batayein.


############################################################
 5) NAYI REPORT — ACCOUNT WISE SALE
############################################################

AAP KA MASLA:
Till subah 11 baje khula, agli raat 2 baje band hua. Raat 12 bajte
hi TAREEKH badal gayi, to us ek till ki sale DO tareekhon mein bat
gayi. Date-wise report par us till ka apna total kahin nazar hi nahi
aata tha. Isi database par chala kar dekha:

    31 Oct ki date-wise report :  2 bill,   8,000
    01 Nov ki date-wise report :  4 bill,  14,607  (doosre tillon ke
                                                    saath mila hua)
    us till ka ASAL total      :  4 bill,  14,000  <- kahin nahi tha

AB: "Account wise sale" — har account apni EK hi line, chahe wo 10
din chale. Us line par POORE account ka total aata hai.

    Account    Counter   Cashier  Khula         Band          Din Bills   Sale
    S-RAAT-01  Counter 1 Ahmed    31 Oct 11:00  01 Nov 02:00   2     4  14,000

CHALA KAR DEKHA — sirf 31 October ki report:
    S-RAAT-01  ->  4 bill, 14,000     (poora till, dono din samet)

KAI TILL EK SAATH KHULE HON — SALE MIX NAHI HOGI:
Account sirf `orders.shift_id` se tay hota hai — wahi number jo bill
par likha gaya tha. Waqt ke hisaab se koi ANDAZA NAHI lagaya jata.
Yeh jaan boojh kar hai: jab do cashier ek saath apne apne till par
bech rahe hon, to waqt ka andaza ek ki sale doosre ke account mein
daal sakta hai.

CHALA KAR DEKHA — do till, bilkul ek hi waqt par, bills guth-muth:
    asal sach   S-CNT-A = 3 bill / 9,000   S-CNT-B = 2 bill / 6,000
    report      S-CNT-A = 3 bill / 9,000   S-CNT-B = 2 bill / 6,000

BINA ACCOUNT WALE BILL CHHUPAYE NAHI JATE: aakhir mein "(bina
account)" ki apni line, taake total kabhi gum na ho.

TAREEKH KA RANGE sirf yeh chunta hai ke KAUN SE account dikhane hain.
Account chun liya jaye to us ke SAARE bill ginay jate hain — chahe
kisi bhi tareekh ke hon.

DATE WISE REPORT JAISI THI WAISI HI HAI — usay haath nahi lagaya.
Chala kar tasdeeq ki: 31 Oct = 8,000, 01 Nov = 14,607, bilkul pehle
jaise.

PDF aur CSV khud ba khud mil jate hain — wo har report par lagta hai.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
PAANCH FILES (README aur screenshot ke ilawa):

    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\restaurant_pos.html      (duplicate popup + hold list)
    src\Services\PageData.php            (bill number + tarteeb)
    src\Services\ReportService.php       (nayi report)

DATABASE NAHI BADLA. KOI MIGRATION NAHI. Reports ki screen bhi nahi
badli — wo apni fehrist khud catalog se banati hai, is liye nayi
report khud aa jati hai.

KISI PURANI REPORT KO HAATH NAHI LAGAYA GAYA.


------------------------------------------------------------
 TESTING — SAB PASS
------------------------------------------------------------
NAYE (V177):
    duplicate bill                          3
      - bill number asal shakl mein (L41-0012, 410012 nahi)
      - fehrist bill number ki tarteeb se
      - search mein table bhi
    hold bills                              1
      - Dine In ke saath table ka badge; table na ho to saaf likha
    account wise report                     4
      - raat wala till EK line mein, poora 14,000
      - sirf 31 Oct ki report par bhi poora till
      - do till ek saath -> sale bilkul mix nahi hui
      - bina account wale bill alag line mein, gum nahi huay
    date wise report waisi ki waisi         2

PURANE (dobara chalaye, sab pass):
    V176 ke faisle                          7
      - naye bill par sab sifar, tax ka rate qayam
      - POS khulte hi dialog nahi, New Bill par khulta hai
      - service charge POS dobara khulne par bhi sifar
    billing ka poora raasta                 7
      - item -> tax -> 10% discount -> 5% service charge -> payment
      - screen ka total aur database ka total BILKUL ek (1606.50)

DO TEST PEHLI BAAR FAIL HUAY — WAJAH MERI THI, SOFTWARE KI NAHI:
    Maine jo test bills banaye un ka waqt 08:15-09:41 tha, jabke is
    machine ki ghari 05:43 par thi — yani wo bill MUSTAQBIL ke thay.
    Test "aakhri band bill" uthata hai, to wo mere hi seeded bill
    utha laya. Wo bill hata kar dobara chalaya: 7/7 pass.

JO MAIN NAHI KAR SAKA:
    Aap ke apne customer ke data par chala kar dekhna. Yahan wohi
    screen aur wohi button istemal kiye, magar wo aap ka data nahi
    tha.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    Paanch files chadha dein. Koi migration nahi, database ko haath
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
    approved_ui\restaurant_pos.html
    src\Services\PageData.php
    src\Services\ReportService.php

STEP A2 — EXTRACT
    SaaSVersion_V177.zip extract karein

STEP A3 — VERIFY (Ctrl+F)

      FILE                               SEARCH FOR
      ---------------------------------  ------------------------------
      VERSION                            V177
      approved_ui\restaurant_pos.html    DUPLICATE KI FEHRIST: EK HI
      approved_ui\restaurant_pos.html    MODE AUR TABLE DONO
      src\Services\PageData.php          BILL NUMBER ASAL SHAKL MEIN
      src\Services\ReportService.php     ACCOUNT (TILL) WISE SALE

STEP A4 — CHALA KAR DEKHEIN
    1. Duplicate Bill kholein
       -> bill number tarteeb se, prefix samet poora number
       -> Dine In bill par table ka badge
    2. Hold Bills kholein
       -> "Dine In" ke saath table, ya "table nahi chuna"
    3. Reports -> Account wise sale
       -> har account ki ek line, poora total
       -> do till ek saath chale hon to dono alag alag

Madad: Wabwar Software House — +92 342 5095104

// build: V177 build 2026-11-03
