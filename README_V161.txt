============================================================
 V161 — FBR KO SAHI RAQAM JATI HAI
 Wabwar Software House                     build 2026-10-24
============================================================

Ab FBR ko wohi raqam jati hai jo parchi par chhapti hai. Pehle nahi
jati thi. Baqi kisi cheez ko haath nahi lagaya: POS, bill, KOT, stock,
reports, owner app, sync — sab V160 jaise hain.


------------------------------------------------------------
 MASLA KYA THA (chala kar pakra gaya, parha kar nahi)
------------------------------------------------------------
Maine ek naqli fiscal service localhost:8524 par khari ki, POS ka
service_url us par mora, aur asal bill bhej kar JSON pakra. Jo nikla:

  Bill 0777 — customer ne 1,180 diye, tax 162.76 (inclusive)

      Parchi par        FBR ko ja raha tha
      1,017.24          1,180.00        <- qabil-e-tax raqam
        162.76            188.80        <- sales tax
      1,180.00          1,368.80        <- kul

  Yani FBR ke record mein yeh bill 1,368.80 ka tha. Har 1,180 ke bill
  par 26 rupay zyada tax declare ho raha tha.

DO WAJHEIN THIN:

1. DO ALAG SETTING, DONO KE DEFAULT ULTE
   - Settings -> Tax -> Price mode   (tax_mode)         default INCLUSIVE
   - Settings -> FBR -> Prices include tax              default No
   Bill pehli maanta tha, FBR doosri. Jis dukan ne dono haath se set
   nahi kiye thay — yani har wo dukan jisne kabhi in ko chhua hi nahi —
   us ka HAR bill FBR ko galat raqam bhej raha tha.

   NOTE: jin dukano ka mode EXCLUSIVE hai (tax bill par ooper se
   jurta hai) un ka FBR data pehle bhi theek ja raha tha — kyunke
   purana default bhi wohi matlab rakhta tha. Masla INCLUSIVE wali
   dukano par tha, aur Pakistan mein default wohi hai.

2. DISCOUNT AUR SERVICE CHARGE FBR TAK POHANCHTE HI NAHI THAY
   FBR ko sirf item ki lines jati thin. Bill ka discount header mein
   alag likha jata tha (lines par nahi utarta tha), aur service charge
   kahin bheja hi nahi jata tha — halanke bill us par tax le chuka hota
   hai. Misal: subtotal 1,180, discount 180, service 100, customer ne
   1,100 diye -> FBR ko 1,180 jata tha. 80 rupay ka farq.


------------------------------------------------------------
 AB KYA HOTA HAI
------------------------------------------------------------
1. EK HI PRICE MODE
   FiscalService ab apna alag khana nahi padhta — wahi
   Settings -> Tax -> Price mode padhta hai jo bill padhta hai. FBR ka
   "Prices include tax" wala khana Settings se hata diya gaya; us ki
   jagah likha hai ke mode kahan se aata hai.
   (Purani qeemat DB mein pari rahe to koi farq nahi — ab padhi hi
   nahi jati.)

2. DISCOUNT LINES PAR UTARTA HAI
   Bill ka discount har line par us ke hisse ke mutabiq taqseem hota
   hai, aur bacha hua paisa aakhri line par. Is liye lines ka jama
   hamesha wohi hota hai jo customer se liya gaya.

3. SERVICE CHARGE APNI LINE BANTA HAI
   "Service Charge" naam se, usi tax rate par. Yeh faisla nahi, bill ka
   hisaab hai: POS pehle hi (subtotal - discount + service) par tax
   lagata hai, is liye FBR ko bhi wohi dikhna chahiye.
   Service charge na ho to koi line nahi jurti.

4. ItemCode — menu item ka apna CODE
   Menu item mein Code bhara ho to wohi FBR ko jata hai. Khali ho to
   pehle ki tarah andar wali id. (FBR ko dukan ka apna code chahiye.)

5. NAYA PEHRA — GALAT RAQAM JATI HI NAHI
   Bhejne se pehle FBR ka kul bill ke grand total se milaya jata hai.
   Na milay to bill PHIR BHI CHHAPTA HAI, magar FBR ko bheja nahi
   jata: entry PENDING ho jati hai aur error log mein saaf likha jata
   hai ke kitna farq tha. Galat declaration se PENDING behtar hai —
   PENDING nazar aata hai, galat raqam nahi.
   (Paison ki thori gunjaish rakhi hai, kyunke har line do decimal par
   round hoti hai. Jo masla pakarna hai wo rupon ka hota hai.)


------------------------------------------------------------
 *** UPDATE KE BAAD YEH ZAROOR DEKHEIN ***
------------------------------------------------------------
Ab FBR bill ke peechhe chalta hai. Is liye ek dafa yeh check karein:

      Settings -> Tax -> Price mode

  - Menu par likhi qeemat mein tax SHAMIL hai      -> INCLUSIVE
  - Tax bill par ooper se jurta hai                -> EXCLUSIVE

Yeh pehle bhi bill ko chala raha tha, magar ab FBR bhi isi se chalta
hai. Galat hoga to bill aur FBR DONO galat hon ge (pehle sirf FBR).
Aik bill bana kar dekh lein ke parchi ka total wohi hai jo customer
deta hai — phir sab theek hai.


------------------------------------------------------------
 TESTING (chala kar, guess nahi)
------------------------------------------------------------
Naqli fiscal service par asal FiscalService::submit() chala kar, har
payload pakar kar bill ke total se milaya gaya:

  A  Saada bill (inclusive)         1,180.00 = 1,180.00            PASS
  B  Discount 180                   1,000.00 = 1,000.00            PASS
  C  Discount 180 + service 100     1,100.00 = 1,100.00            PASS
  D  EXCLUSIVE mode                 1,368.80 = 1,368.80            PASS
  E  Toote hue number               1,268.05 = 1,268.05            PASS
     (3 x 333.33, 7 x 37.50, discount 55.55, service 61.11)

  Har bill par teen cheezein jaanchi gayin:
     TotalBillAmount == bill ka grand_total                        PASS
     TotalTaxCharged == bill ka tax                                PASS
     lines ka jama   == TotalBillAmount                            PASS
  C aur E par: "Service Charge" apni line par gaya                 PASS
  Menu item ka Code bhara -> ItemCode BIR-001 gaya                 PASS
  Purana "Prices include tax = No" jaan boojh kar laga rehne diya
    -> ab padha hi nahi jata, natija inclusive hi raha             PASS

  PEHRA: bill ka total jaan boojh kar bigaar kar
     FBR ko bheja NAHI gaya                                        PASS
     status PENDING, bill par PENDING likha gaya                   PASS
     error log mein farq ki raqam samet darj hua                   PASS

  Total: PASS 20   FAIL 0

  run_migrations --stage=all -> ok=42 skipped=0 failed=0
  Restaurant 56 screens -> 0 nayi kharabi, 0 JS error
  Retail      41 screens -> 0 kharabi, 0 JS error
  Settings -> FBR safha khulta hai, purana khana gayab, baqi
    sab khane (Provider, POS ID, URL, NTN, PCT, POS fee) qayam


------------------------------------------------------------
 JO IS UPDATE MEIN NAHI HAI (ta ke aap ko pata ho)
------------------------------------------------------------
  - BuyerNTN / BuyerCNIC abhi bhi khali jate hain. Inhen bharne ke
    liye customers mein do nayi column aur POS mein do khane chahiyen.
  - PaymentMode mein WALLET aur BANK abhi 1 (cash) jate hain. Code
    mein sirf 1=cash, 2=card likha hai. FBR ki asal code list mil
    jaye to theek kar doon ga — ghalat code bhejna khali se bura hai.
  - Counter ki parchi par FBR number LIKHA nahi jata (sirf QR). Reprint
    par likha hota hai.
  - PENDING bills khud retry nahi hotay — Settings ka "Retry pending"
    button dabana parta hai.
  - Retail POS (supermarket wala) FBR ko kuch nahi bhejta. Yeh raasta
    sirf restaurant ke bill par hai.


============================================================
 PART A — SERVER (Railway)
============================================================

FBR SIRF OFFLINE PC PAR CHALTA HAI. Cloud par is tabdeeli ka koi asar
nahi — magar files dono jagah ek jesi rakhni hain, is liye deploy
karein. Asal kaam PART B hai.

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    Windows ka "Replace or Skip" extraction ko toot-phoot deta hai,
    is liye pehle delete, phir extract.

    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\settings.html
    public\router.php
    src\Services\FiscalService.php

    KOI NAYI FILE NAHI HAI.

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V161.zip extract karein, phir Railway par deploy.
    (Koi nayi column nahi — migration ka naya kaam nahi.)

STEP A3 — DEPLOY LOG MEIN YEH DEKHEIN
        MIGRATIONS_OK
        MENU_SORT_OK
        DISCOUNTS_OK
        MODULE_GROUPS_OK
        COLLATION_OK
        RETAIL_WIRING_OK

STEP A4 — VERIFY (har file mein Ctrl+F)

      FILE                            SEARCH FOR
      ------------------------------  ------------------------------
      VERSION                         V161
      src\Services\FiscalService.php  private static function billLines
      src\Services\FiscalService.php  TaxMode::current() === TaxMode
      src\Services\FiscalService.php  totalsMismatch
      src\Services\FiscalService.php  Service Charge
      approved_ui\settings.html       Set in <b>Tax
      public\router.php               ?b=v24


============================================================
 PART B — OFFLINE PACKAGE (customer ka PC)   *** ASAL KAAM ***
============================================================

FBR isi PC par chalta hai, is liye tabdeeli yahan asar karti hai.

STEP B1 — UPDATE.bat chalayein (dukan ke PC par)
    Koi migration nahi, is liye seedha hai.

STEP B2 — Settings -> Tax -> Price mode DEKHEIN
    (ooper "UPDATE KE BAAD YEH ZAROOR DEKHEIN" wala hissa)

STEP B3 — EK TEST BILL
    Ek chhota bill banayein jis par discount aur service charge dono
    hon. Parchi par jo GRAND TOTAL chhapta hai, Reports -> Tax mein
    usi bill ke saamne wohi raqam honi chahiye.


------------------------------------------------------------
 BROWSER MEIN CHECK
------------------------------------------------------------
 1. Settings -> FBR / Digital Invoice -> "Prices include tax" ki jagah
    ab likha hai: Set in Tax -> Price mode
 2. Settings -> Tax -> Price mode sahi hai
 3. Ek bill (discount + service charge ke sath) band karein
 4. Parchi ka GRAND TOTAL yaad rakhein
 5. Reports -> Tax -> FBR / fiscal sales — usi bill ke saamne wohi
    raqam honi chahiye, kam ya zyada nahi
 6. Fiscal service band kar ke ek bill banayein -> parchi par PENDING

Madad: Wabwar Software House — +92 342 5095104

// build: V161 build 2026-10-24
