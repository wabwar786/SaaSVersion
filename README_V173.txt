============================================================
 V173 — SHIFT CLOSING KA HISAAB
 Wabwar Software House                     build 2026-10-28
============================================================

V173 MEIN V172, V171, V170, V169 AUR V168 KA SAB KUCH SHAMIL HAI.

Aap ki do baatein, lafz ba lafz:
   "shift closing ke time jo calculation hoti hai us mein difference
    nahi aana chahiye"
   "jab close hoga to sirf jo complete bills huay hain wohi count
    honge"

Yehi do baatein is build ka usool hain.


############################################################
 EK MISAAL — YEHI SAB SE ASAAN TAREEQA HAI SAMAJHNE KA
############################################################

Ek din ki shift:

    Subah daraz mein rakhe            3,000
    Bill 1   4,000  cash     poora hua
    Bill 2   2,500  card     poora hua
    Bill 3   1,500  cash     poora hua
    Bill 4   2,000           CANCEL — paisa aaya hi nahi
    Bill 5   1,800           HOLD — abhi tak khula
    Chai-paani                 600   DARAZ se nikle
    Bijli ka bill              900   BANK se gaya

THEEK HISAAB:
    sale = 4,000 + 2,500 + 1,500 = 8,000
    daraz mein = 3,000 + 5,500 cash - 600 = 7,900

PEHLE PARCHI YEH NIKALTI THI:

    Bills                               5
    TOTAL SALES                    11,800
    Opening float                   3,000
    Cash sales                      5,500
    Less cash expenses             -1,500
    Expected in till                8,500
    Counted                         7,900
    CASH SHORT                       -600

    Teen ghaltiyan ek hi parchi par:
      1. 11,800 mein cancel ka 2,000 aur hold ka 1,800 bhi shamil
      2. kharcha 1,500 likha, jabke daraz se sirf 600 nikle
      3. PARCHI KHUD APNE AAP SE MUTAZAAD: 3,000 + 5,500 - 1,500
         = 7,000 banta hai, magar neeche 8,500 likha hai
    Cashier ne bilkul theek 7,900 gine thay, phir bhi 600 ka ilzaam.

AB YEH NIKLEGI:

    Bills                               3
    TOTAL SALES                     8,000
    Opening float                   3,000
    Cash sales                      5,500
    Less cash expenses               -600
    Expected in till                7,900
    Counted                         7,900
    BALANCED                            0


############################################################
 ASAL SABAB — EK HI CHEEZ TEEN JAGAH GINI JA RAHI THI
############################################################

Daraz ka hisaab software mein TEEN alag jagah likha hua tha, aur
teenon ka natija alag aata tha:

    POS ka "Close account"        opening + cash - kharcha   (theek)
    Shift Management ka "Close"   opening + cash             (GALAT)
    Closing parchi                apna teesra hisaab

Yani ek hi shift do darwazon se band karein to expected do alag
aate. Aur jo parchi chhapti wo usi number ko dikhati jis raaste se
band hui thi. Yehi wo "difference" tha.

AB TEENON EK HI JAGAH SE LETE HAIN — `tillPieces()`. Ek hisaab, ek
natija, chahe kisi bhi darwaze se band karein.


------------------------------------------------------------
 CHAAR USOOL JO AB LAGTE HAIN
------------------------------------------------------------

1. SIRF POORE HUAY BILL GINAY JATE HAIN.
   Pehle shart `order_status <> 'VOID'` thi — yani CANCEL aur HOLD
   wale bill bhi "sale" mein gin jate thay. Aap ke apne data par:

       CLOSED      138 bills   123,191   <- asal sale
       CANCELLED    69 bills    91,442   <- yeh bhi gini ja rahi thi
       OPEN         16 bills    20,245   <- aur yeh bhi
       --------------------------------
       report ginti thi:        234,878   (asal se qareeb dugni)

   Ab sirf CLOSED.

2. EXPECTED = OPENING + CASH SALE - CASH KHARCHA.
   Shift khuli ho ya band, hisaab bilkul ek. Pehle khuli shift par
   parchi 7,900 likhti aur band karte hi 8,500 ho jata.

3. KHARCHE MEIN SIRF WO JO DARAZ SE NIKLE, AUR SIRF ISI SHIFT KE.
   `expenses` par `payment_method` aur `shift_id` dono columns
   pehle se mojood thay — magar koi unhen parhta hi nahi tha, sirf
   waqt dekha jata tha. Nateeja: bank se gaya bijli ka bill bhi
   daraz se ghata diya jata, aur doosri shift ka kharcha bhi is
   shift par char ho jata.

4. BINA SHIFT WALE BILL SIRF EK SHIFT MEIN.
   Purane bills par `shift_id` NULL hota hai, un ke liye waqt dekha
   jata hai. Agar ek LAMBI shift (jaise 3 din wali) ke ANDAR koi
   chhoti shift bhi chali ho, to wohi ek bill DONO ki report mein
   gin jata tha — ek hi sale do dafa. Ab wo bill us shift ko milta
   hai jo sab se BAAD mein khuli aur us waqt chal rahi thi.


------------------------------------------------------------
 COD — RIDER KA NAQD PAISA
------------------------------------------------------------
"Cash on Delivery" ab daraz mein gina jata hai. Rider wapas aa kar
naqd paisa daraz mein hi daalta hai, is liye wo cash hai. Card,
Raast, Easypaisa, JazzCash aur bank ka paisa daraz mein aata hi
nahi — wo parchi par "HOW IT WAS PAID" mein alag nazar aata hai.

Aap ke asli data mein abhi COD ka ek bhi bill nahi (sirf Cash aur
Bank Transfer), to is se aaj kuch nahi badlega. Agar aap chahte hain
ke COD daraz se BAHAR rahe, to batayein — ek lakeer ka farq hai.


------------------------------------------------------------
 CLOSING KI SCREEN BHI BADLI
------------------------------------------------------------
DO KHARABIYAN THIN:

  1. Ginti ka khana PEHLE SE BHARA hua aata tha — usi raqam se jo
     hona chahiye thi. Cashier sirf "Close" dabata aur farq hamesha
     sifar aata. Yani ginti hoti hi nahi thi, aur asal kami kabhi
     pakri hi na jati.
  2. "Expected Cash" ginti se PEHLE nazar aa jata tha — to cashier
     ko gin-ne ki zaroorat hi na rehti.

AB:
  - khana KHALI aata hai, aur jawab chhupa rehta hai
  - cashier raqam likhta hai, phir NEECHE poora hisaab khud khul
    jata hai:

        Opening float              PKR 3,000
        Cash sales                 PKR 5,500
        Less cash expenses        - PKR  600
        Daraz mein hone chahiyen   PKR 7,900
        Aap ne gine                PKR 7,900
        BARABAR                    PKR 0

  - khali chhorh kar Close dabayen to rok deta hai
  - Shift Management se band karein to bhi wahi teen tukre nazar
    aate hain, sirf "CASH SHORT 600" nahi


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
AATH FILES (README ke ilawa):

    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\restaurant_pos.html
    public\api.php
    public\ops_db.js
    public\router.php
    src\Services\OpsService.php
    src\Services\BillTemplate.php

KOI MIGRATION NAHI — database mein koi tabdeeli nahi.
PURANI BAND SHIFTS NAHI BADLENGI: un ka snapshot us waqt mehfooz ho
chuka hota hai, aur accounts ke liye purani report ka badal jana
theek nahi. Naya hisaab AGLI shift se chalega.


------------------------------------------------------------
 TESTING
------------------------------------------------------------
MISAAL WALI SHIFT (upar wali) — software se chala kar:
    pehle : bills 5, sale 11,800, kharcha 1,500, expected 8,500,
            variance -600
    ab    : bills 3, sale  8,000, kharcha   600, expected 7,900,
            variance 0                                        OK
    asli parchi bhi nikal kar parhi — teen lakeerein jama karein to
    neeche wala number wahi banta hai                          OK

3 DIN CHALNE WALI SHIFT — 5 test, sab pass:
    opening 5,000 + cash 3,800 (teen din) - kharcha 700 = 8,100
    cashier ne 8,100 gine  ->  variance 0                      OK
    hold wala bill sale mein nahi gina                         OK
    bills ki ginti sirf band bills ki                          OK

LAMBI SHIFT KE ANDAR CHHOTI SHIFT — 2 test, sab pass:
    card se kiya kharcha daraz ke hisaab se bahar              OK
    bina shift wala bill sirf EK shift mein gina               OK

COD — 1 test:
    cash 2,000 + COD 1,500 daraz mein; card 800 aur Easypaisa
    700 bahar  ->  expected 4,500                              OK

CLOSING SCREEN — 5 test:
    ginti ka khana khali aata hai                              OK
    ginti se pehle "Expected Cash" nazar nahi aata             OK
    raqam likhte hi poora hisaab khul jata hai                 OK
    theek ginti par BARABAR, kam par CASH SHORT                OK

POORI PURANI REGRESSION — 138 TEST, SAB PASS, KOI FAIL NAHI:
    delivery charge 19 · billing audit 21 · paison ka hisaab 10 ·
    kinare 8 · do din ka bill 8 · hold/resume 7 · note wali lines 4 ·
    KOT+bill band 5 · duplicate naam 3 · stock 4+1 · hold wapsi 3 ·
    closing screen 5 · bulk delete 28 · shift ke 8

EK BAAT TEST KE BARE MEIN:
    Regression ke doran `t_stock_ok` ek dafa fail hua. Jaanchne par
    nikla ke mere apne purane test scripts ne teen adhoori recipe
    (bina ingredient) database mein chhorh di thin, aur nayi recipe
    ban hi nahi pa rahi thi. Software ki kharabi nahi thi — test
    ka choora tha. Test theek kar diye (ab har dafa nayi recipe
    banate hain), phir sab pass.

JO MAIN NAHI KAR SAKA:
    Aap ke asli Akorwal data par chala kar dekhna — wo mere paas
    nahi hai. Jo bhi dawa upar likhi hai, wo apne yahan asli shift
    bana kar sabit ki hai.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    Deploy kar dein. Koi migration nahi, is liye log mein kuch khaas
    dekhne ki zaroorat nahi.

PART A — OFFLINE PC

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\restaurant_pos.html
    public\api.php
    public\ops_db.js
    public\router.php
    src\Services\OpsService.php
    src\Services\BillTemplate.php

STEP A2 — EXTRACT
    SaaSVersion_V173.zip extract karein, phir UPDATE.bat

STEP A3 — VERIFY (Ctrl+F)

      FILE                             SEARCH FOR
      -------------------------------  -------------------------
      VERSION                          V173
      src\Services\OpsService.php      tillPieces
      src\Services\OpsService.php      SIRF POORE HUAY BILL
      src\Services\BillTemplate.php    parchi ab apna hisaab nahi
      public\api.php                   DARAZ KA HISAAB AB SIRF EK
      public\ops_db.js                 Cash expenses
      approved_ui\restaurant_pos.html  tillCalcHtml
      approved_ui\restaurant_pos.html  PEHLE GINO, PHIR HISAAB
      public\router.php                ops_db.js?b=v173

STEP A4 — CHALA KAR DEKHEIN (5 minute, khud karein)
    1. Account kholein, opening cash likhein (jaise 3,000)
    2. Do teen bill poore karein — kuch cash, kuch card
    3. Ek bill hold par chhorh dein, ek cancel kar dein
    4. Ek kharcha daalein jo DARAZ se nikla ho (payment method CASH)
    5. F11 "Close account" dabayein
       -> ginti ka khana KHALI hona chahiye
       -> "Expected Cash" nazar NAHI aana chahiye
    6. Daraz ka cash gin kar likhein
       -> neeche poora hisaab khulna chahiye
       -> theek gina ho to BARABAR, PKR 0
    7. Parchi par dekhen:
       -> Bills mein sirf poore huay bill
       -> teen lakeerein jama karein to "Expected in till" wahi banay

Madad: Wabwar Software House — +92 342 5095104

// build: V173 build 2026-10-28
