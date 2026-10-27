============================================================
 V171 — HOME DELIVERY KA CHARGE
 Wabwar Software House                     build 2026-10-27
============================================================

V171 MEIN V170, V169 AUR V168 KA SAB KUCH SHAMIL HAI.
Agar wo abhi nahi lagaye to seedha yehi laga lein.

Yeh build aap ki farmaish par hai: "i also need delivery charges in
software also".

AAP KE CHAAR FAISLE, JIN PAR YEH BANA HAI:
  - flat raqam (area-wise list nahi)
  - delivery charge par TAX NAHI
  - FBR ko delivery charge NAHI bhejna
  - sirf restaurant POS (retail par nahi)
  - cashier khud badal sakta hai (manager password nahi)


############################################################
 PEHLE EK GHALTI KI TASHEEH — MERI TARAF SE
############################################################

Design ke waqt maine aap ko kaha tha ke service charge wale bill
FBR ko jate hi nahi aur PENDING par ruk jate hain, aur aap ne usi
bina par (i) chuna tha — "service charge bhee FBR ke hisaab se
bahar".

WO BAAT GHALAT THI. Maine test ghalat jagah chalaya tha: bill ki
lines banane wale hisse (`billLines`) ko chhorh kar seedha aage ka
hisaab jaanch liya tha. Asli raasta chala kar dekha to yeh nikla:

    service charge  0    ->  bill 1500   FBR 1500   theek
    service charge 150   ->  bill 1650   FBR 1650   theek   <-- theek hai

Service charge FBR ko apni alag line ("Service Charge") bana kar
PEHLE SE jata hai. Wahan koi kharabi nahi thi.

IS LIYE SERVICE CHARGE KO HAATH NAHI LAGAYA GAYA. Jo theek chal
raha tha wo waise hi chal raha hai. Agar aap phir bhi us ka
tareeqa badalna chahein to batayein — magar meri raaye hai ke
rehne dein.

Delivery charge ka maamla ALAG hai aur wahan masla waqai tha:

    delivery 200, bina kisi intezaam ke
        ->  bill 1700   FBR 1500   MISMATCH -> har delivery bill PENDING

Ab FBR ka muqabla "khane ke total" se hota hai, bill ke poore total
se nahi — is liye delivery bill normally chala jata hai:

    svc   0  delivery   0  ->  theek
    svc   0  delivery 200  ->  theek
    svc 150  delivery   0  ->  theek
    svc 150  delivery 200  ->  theek


############################################################
 KAAM KAISE KARTA HAI
############################################################

SETTINGS (System -> Settings -> Tax & Charges)
    Ek nayi line: "Default Delivery Charge (PKR)".
    Yeh RUPAY hai, percent NAHI. 0 rakhein to feature band.

SALE POINT PAR
    Sirf HOME DELIVERY bill par. Bill type chunte hi charge khud
    bhar jata hai. Total panel mein tax ke NEECHE ek lakeer:

        Subtotal                PKR 1,700
        Discount      [Edit]      - PKR 0
        Service Charge            PKR 0
        Sales Tax                 PKR 234  (16% Cash, incl.)
        Delivery Charge [Edit]    PKR 150
        Grand Total             PKR 1,850

    Cashier [Edit] daba kar koi bhi raqam likh sakta hai. "Free"
    ka button bhi hai — kuch bill par kiraya maaf kar diya jata hai.
    Dine In aur Takeaway par yeh lakeer NAZAR HI NAHI AATI.

HISAAB
    taxable = subtotal - discount + service charge   (delivery is mein NAHI)
    grand   = taxable (+ tax agar exclusive) + delivery charge

    Yani tax sirf khane par banta hai, kiraye par nahi. Yehi
    tarteeb teenon jagah ek jaisi rakhi gayi hai — screen par,
    hold karte waqt, aur bill band karte waqt.

AUR KAHAN NAZAR AATA HAI
    - bill slip par "Delivery Charge" ki apni line (chaaron slip
      designs par chala kar dekha)
    - Sale Summary report mein "Delivery" ka apna column
    - Shift / Daily Closing parchi par "Delivery charges"
    KOT par NAHI — kitchen ka is se koi taalluq nahi.

PURANE BILL
    Un par koi asar nahi. Column default 0 ke saath banta hai, to
    142 purane bill jaanch kar dekhe — kisi ka total nahi badla.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
NAU FILES (README ke ilawa):

    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\restaurant_pos.html
    public\api.php
    src\Services\PosService.php
    src\Services\OpsService.php
    src\Services\SettingsService.php
    src\Services\FiscalService.php
    src\Services\BillTemplate.php
    src\Services\ReportService.php
    docs\02_local_mysql_schema.sql
    scripts\migration_order.php

EK NAYI FILE:
    scripts\migrate_delivery_charge.php

MIGRATION HAI — `orders.delivery_charge`. Dono taraf chalni hai.


------------------------------------------------------------
 TESTING
------------------------------------------------------------
DELIVERY CHARGE — 19 TEST, SAB PASS:
    Settings mein 150 mehfooz hua                             OK
    Dine In: charge 0, lakeer nazar hi nahi aati              OK
    Takeaway: wohi                                            OK
    Home Delivery: 150 khud lag gaya                          OK
    *** tax sirf khane par (234.48 = 1700 ka 16% incl.) ***   OK
    grand = khana + 150                                       OK
    cashier ne 250 kiya                                       OK
    cashier ne "Free" se maaf kiya                            OK
    hold ke baad database: delivery 250, total 1,950          OK
    *** resume par kiraya bacha raha ***                      OK
    bill band: delivery 250, total 1,950, payment 1,950       OK

CHALTE HUE DO ASLI BUG PAKRE GAYE AUR THEEK KIYE:

  1. Default kiraya sirf us waqt lagta tha jab cashier customer bhi
     chun leta. Customer wale qadam par Esc daba dein to bill ka
     type HOME DELIVERY ho chuka hota magar kiraya sifar reh jata —
     screen par lakeer, raqam khali. Ab kiraya BILL KA TYPE chunte
     hi lag jata hai.

  2. Zyada sangeen: database mein bill ka type "DELIVERY" likha
     jata hai aur hold kholne par server "Delivery" wapas bhejta
     hai — jabke POS "Home Delivery" samajhta hai. Nateeja: ruka
     hua delivery bill wapas kholte hi kiraya ginti se BAHAR ho
     jata. Screen par 250 likha nazar aata magar Grand Total mein
     shamil na hota, aur bill band karte waqt server ko 0 chala
     jata — yani CUSTOMER SE KIRAYA LIYA HI NA JATA.
     Test ne yeh pakra: resume ke baad total 1,700 aaya jabke
     1,950 hona chahiye tha. Ab dono naam ek hi cheez samjhe jate
     hain.

FBR KA PEHRA — 4 SOORTEIN, SAB THEEK (upar table mein).

REPORTS:
    Sale Summary ka column: Date | Bills | Subtotal | Discount |
    Service | Delivery | Tax | Total  -> Delivery = 250       OK
    Daily Closing parchi par "Delivery charges"                OK
    Chaaron bill slip designs par "Delivery Charge 250"        OK

OFFLINE NODE:
    Bilkul naye database par   -> schema se column bana, 41 ok, 0 fail
    Purane node par (column na ho) -> "+ orders.delivery_charge",
                                       41 ok, 0 fail
    Migration dobara chalao     -> added=0 skipped=1 (nuqsan nahi)
    Sealed package khol kar dekha — V171 ki saari files seal ke
    andar mojood hain (272 files)

PURANI REGRESSION — 100 TEST, SAB PASS:
    billing audit (V168) 21 · paison ka hisaab 10 · kinare 8 ·
    do din ka bill 8 · hold/resume 7 · note wali lines 4 ·
    KOT+bill band 5 · KOT ke baad aur items 14 · duplicate naam 3 ·
    bulk delete (V166) 28

EK BAAT TEST KE BARE MEIN:
    Regression ke doran `t_kot` ek dafa fail hua. Jaanchne par
    nikla ke bulk-delete wale test ne do menu item BAND kar diye
    thay (`is_active=0`) — wo order se juray hain is liye mitay
    nahi, band huay. POS band item nahi dikhata, is liye agla test
    unhein daal hi na saka. Item chalu karte hi test dobara pass.
    Software ki kharabi nahi thi, test ke data ki thi.

JO MAIN NAHI KAR SAKA:
    Asli thermal printer par delivery wali parchi nikal kar dekhna —
    mere paas printer nahi hai. Slip ka matn chaaron designs par
    bana kar parha hai, chhapa nahi.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B PEHLE — SERVER (Railway)

    YEH PEHLE KARNA HAI, OFFLINE PC BAAD MEIN.

    Do wajuhat:
    1. Jab tak server par V171 nahi jata, offline PC ko naya build
       nazar hi nahi aayega (wohi "Version mismatch" wala maamla).
    2. Sync nayi column ko tabhi le jata hai jab wo DONO taraf
       mojood ho. Cloud par column na ho to node ki row chup chaap
       chhoot jayegi.

    Deploy ke baad log mein yeh dikhna chahiye:
        + orders.delivery_charge
        DELIVERY_CHARGE_OK added=1 skipped=0


PART A — OFFLINE PC

STEP A1 — BACKUP
    V170 ka zip rakha rehne dein.

STEP A2 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\restaurant_pos.html
    public\api.php
    src\Services\PosService.php
    src\Services\OpsService.php
    src\Services\SettingsService.php
    src\Services\FiscalService.php
    src\Services\BillTemplate.php
    src\Services\ReportService.php
    docs\02_local_mysql_schema.sql
    scripts\migration_order.php

    (scripts\migrate_delivery_charge.php NAYI hai — delete karne ki
     zaroorat nahi, wo pehle se hai hi nahi.)

STEP A3 — EXTRACT
    SaaSVersion_V171.zip extract karein, phir UPDATE.bat

    V169 se UPDATE.bat khud database chala leti hai, is liye
    migration bhi apne aap chal jayegi. Step 5 par yeh dikhna
    chahiye:
        + orders.delivery_charge
        DELIVERY_CHARGE_OK added=1 skipped=0

STEP A4 — VERIFY (Ctrl+F)

      FILE                               SEARCH FOR
      ---------------------------------  --------------------------
      VERSION                            V171
      approved_ui\restaurant_pos.html    edDlv
      approved_ui\restaurant_pos.html    isDelivery
      src\Services\PosService.php        deliveryOf
      src\Services\FiscalService.php     DELIVERY CHARGE FBR KO NAHI
      src\Services\SettingsService.php   delivery_charge
      src\Services\BillTemplate.php      Delivery Charge
      src\Services\ReportService.php     delivery
      scripts\migration_order.php        migrate_delivery_charge
      scripts\migrate_delivery_charge.php  DELIVERY_CHARGE_OK

STEP A5 — CHALA KAR DEKHEIN (5 minute, khud karein)
    1. System -> Settings -> "Default Delivery Charge (PKR)" mein
       150 likh kar Save Tax Settings
    2. New Bill -> HOME DELIVERY -> customer chunein (ya Walk-in)
    3. Do char item daalein
       -> Sales Tax ke neeche "Delivery Charge  PKR 150" aana chahiye
       -> Grand Total mein 150 shamil hona chahiye
       -> Sales Tax ki raqam NAHI badalni chahiye (tax sirf khane par)
    4. Hold Bill karein, phir Hold Bills se wapas kholein
       -> kiraya 150 wapas aana chahiye aur total wohi
    5. Bill band karein aur parchi dekhein
       -> "Delivery Charge" ki line honi chahiye
    6. Ab New Bill -> DINE IN ya TAKEAWAY
       -> "Delivery Charge" ki lakeer nazar hi nahi aani chahiye

Madad: Wabwar Software House — +92 342 5095104

// build: V171 build 2026-10-27
