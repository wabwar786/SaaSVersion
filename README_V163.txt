============================================================
 V163 — NAYA BILL DESIGN (KHAATA) + MENU SE DELETE
 Wabwar Software House                     build 2026-10-25
============================================================

Do kaam: bill ka naya design (aap ka chuna hua "Khaata"), aur Menu
screen par item/category hatane ka rasta. Baqi kisi cheez ko haath nahi
lagaya: POS ka hisaab, FBR, stock, closing, owner app, sync — sab V162
jaise hain.


------------------------------------------------------------
 1 — BILL KA NAYA DESIGN  "KHAATA"
------------------------------------------------------------
Jo aap ne chuna tha (Option B + QR bayen taraf), wohi bana hai:

  * LOGO sab se ooper — agar business ne lagaya ho. Na lagaya ho to
    bill bilkul waisa hi jaisa namoone mein dikhaya tha.
  * ITEM ka khaata EK FRAME ke andar, ooper ITEM / QTY / AMOUNT.
  * Nuqta-dar lakeeron (- - - -) ki jagah THOS baareek lakeerein.
  * "PAYABLE" ek BHARAY HUE KAALE BAND mein, safed likhai — door se
    aur jaldi mein yehi ek cheez customer dhoondta hai.
  * FBR ka QR raqamon ke BAYEN, us khali jagah mein jo waise bhi bachi
    hoti thi. Is se har bill par taqreeban 30% roll bachta hai.

DONO KAGHAZ AB EK JAISE. Counter se nikalne wali parchi aur baad ka
reprint — dono isi shakl mein. (Pehle yeh do alag code se bante thay
aur dekhne mein alag lagte thay.)

LOGO KAISE CHHAPTA HAI. Logo `tenants.logo_url` mein data URL ki shakl
mein hota hai (Super Admin -> Branding se). Bill ka PDF engine sirf
matn aur rectangles chhapta hai, is liye logo ek dafa 1-bit (kaala /
safed) bitmap mein badal kar RECTANGLES se chhapa jata hai — bilkul
waise jaise QR chhapta hai. Natija `storage/tmp` mein mehfooz hota hai,
is liye har bill par dobara mehnat nahi hoti.

  GD na ho to bill phir bhi chhapta hai — bas logo ke baghair. Bill
  rokna kisi soorat qabool nahi.

  Thermal printer sirf KAALA chhapta hai, is liye 1-bit mein kuch
  "khota" nahi — yehi us ki asli shakl hai. (Neela rang printer ka
  kaam hai, software ka nahi.)

FBR BAND HO TO:
  * Number mil gaya          -> QR aur number dono chhapte hain
  * Bill abhi PENDING hai    -> QR phir bhi, neeche PENDING likha
  * FBR is dukan par hai hi nahi -> na QR, na FBR ki line

YEH CHAUTHA TEMPLATE HAI. Classic, Compact aur Tax jaise ke waise hain.
Chunne ke liye:

      Settings -> Receipt template -> "Khaata — framed + logo (80mm)"

*** AHEM: yeh KHUD SE CHALU NAHI HOTA. ***
Jab tak aap Settings se na chunein, bill purane template par hi
chhapta rahega. Har business apna alag chun sakta hai.


------------------------------------------------------------
 2 — MENU SE ITEM / CATEGORY HATANA  (naya)
------------------------------------------------------------
      Menu & Categories  ->  har item ki row mein "Delete"
      Menu & Categories  ->  "Category order"  ->  har category par  x

Pehle is screen par hatane ka koi rasta tha hi nahi — ghalat naam ya
dohra item banane ke baad usay nikalne ka koi tareeqa nahi tha.

ITEM KA DELETE "SOFT" HAI. Item Sale Point par nazar aana band ho jata
hai, magar purane bill bilkul waise ke waise rehte hain — un mein naam
aur raqam wahi likhi rehti hai jo us waqt thi. Reports ka purana hisaab
nahi badalta.

CATEGORY KE ANDAR ITEM HON TO SERVER INKAR KAR DETA HAI. Saaf wajah ke
sath: pehle un items ko doosri category mein le jayein. Yeh pehra
server par hai, sirf screen par nahi — is liye kisi raaste se bhi
category khali kiye baghair nahi hatti.

Dono jagah wohi confirm dialog aata hai jo baqi software mein hai, aur
"Reason" ka khana bhi (audit log ke liye — optional).


------------------------------------------------------------
 TESTING (chala kar, guess nahi)
------------------------------------------------------------
  BILL
    khaata template bina logo ke chhapta hai                     PASS
    khaata template logo ke sath (1-bit bitmap)                  PASS
    Counter parchi aur reprint — shakl ek jaisi                  PASS
    QR raqamon ke bayen, koi line toot-phoot nahi                PASS
    Purane teen template (classic/compact/tax) waise ke waise    PASS

  MENU DELETE (browser mein)
    Har item ki row par Delete ka button (12 items)              PASS
    Delete ka confirm dialog + Reason ka khana                   PASS
    Item delete hua, list 13 -> 12                               PASS
    "Category order" mein har category par x (15 categories)     PASS
    Khali category delete hui, 16 -> 15                          PASS
    Items wali category -> server ne BLOCKED kaha, saaf wajah    PASS
    Koi JS error nahi                                            PASS

  REGRESSION
    Daily closing parchi (V162 ka kaam) — har line hadd ke andar PASS
    run_migrations --stage=all -> ok=42 skipped=0 failed=0
    Restaurant 56 screens -> 0 nayi kharabi, 0 JS error
    Retail      41 screens -> 0 kharabi, 0 JS error


------------------------------------------------------------
 JO IS UPDATE MEIN NAHI HAI
------------------------------------------------------------
  - Logo sirf bill par. Owner app aur reports par abhi nahi.
  - Retail (supermarket) ka bill alag code se banta hai — us par yeh
    naya design nahi laga.
  - Pichhle audit ke do point abhi bhi baqi: counter ki parchi par FBR
    number likha hua (sirf QR hai), aur PENDING FBR bills ka khud retry.


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    Windows ka "Replace or Skip" extraction ko toot-phoot deta hai,
    is liye pehle delete, phir extract.

    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\menu_management.html
    approved_ui\restaurant_pos.html
    public\api.php
    public\router.php
    src\Services\BillTemplate.php
    src\Services\Pdf.php

    YEH NAYI HAI — delete karne ki zaroorat nahi:
    src\Services\ReceiptLogo.php

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V163.zip extract karein, phir Railway par deploy.
    (Koi nayi column nahi — migration ka naya kaam nahi.)

STEP A3 — DEPLOY LOG MEIN YEH DEKHEIN
        MIGRATIONS_OK
        MENU_SORT_OK
        DISCOUNTS_OK
        MODULE_GROUPS_OK
        COLLATION_OK
        RETAIL_WIRING_OK

STEP A4 — VERIFY (har file mein Ctrl+F)

      FILE                                  SEARCH FOR
      ------------------------------------  --------------------------
      VERSION                               V163
      src\Services\BillTemplate.php         function khaata
      src\Services\BillTemplate.php         'khaata'
      src\Services\Pdf.php                  '@img'
      src\Services\ReceiptLogo.php          class ReceiptLogo
      public\api.php                        ReceiptLogo::tenantLogo
      public\router.php                     ?b=v26
      approved_ui\restaurant_pos.html       bqr
      approved_ui\menu_management.html      data-delcat
      approved_ui\menu_management.html      data-delitem


============================================================
 PART B — OFFLINE PACKAGE (customer ka PC)
============================================================

STEP B1 — UPDATE.bat chalayein (dukan ke PC par)
    Koi migration nahi, is liye seedha hai.

STEP B2 — TEMPLATE CHUNEIN
    Settings -> Receipt template -> "Khaata — framed + logo (80mm)"
    (Jab tak na chunein, purana bill hi chhapta rahega.)

STEP B3 — LOGO (agar lagana hai)
    Super Admin -> us business ki Branding -> logo upload.
    Logo saaf chhapne ke liye: saada, mota, kaala-safed wala logo
    behtar aata hai. Halke rang aur baareek lakeerein thermal par
    waise bhi theek nahi chhaptin.

STEP B4 — EK TEST BILL
    Ek bill banayein aur dekhein: logo ooper, item frame ke andar,
    QR bayen, PAYABLE ka kaala band. Phir Duplicate Bill dabayein —
    dono kaghaz ek jaise hone chahiyen.


------------------------------------------------------------
 BROWSER MEIN CHECK
------------------------------------------------------------
 1. Settings -> Receipt template -> Khaata chunein -> Save
 2. Ek bill band karein -> naya design nikle
 3. Duplicate Bill -> wohi shakl
 4. Menu & Categories -> kisi item par Delete -> confirm -> list se gaya,
    aur POS par bhi nazar na aaye
 5. Menu & Categories -> Category order -> jis category mein item hain
    us par x -> server inkar kare, saaf wajah ke sath
 6. Khali category par x -> hat jaye

Madad: Wabwar Software House — +92 342 5095104

// build: V163 build 2026-10-25
