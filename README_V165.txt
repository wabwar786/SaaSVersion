============================================================
 V165 — NAYE BUILD KI KHABAR POS KE ANDAR
 Wabwar Software House                     build 2026-10-25
============================================================

Ab jab cloud par naya build chadhega, offline PC par kaam karne wale
ko POS ki screen par hi pata chal jayega — neeche bayen kone mein ek
chhoti si patti. Launcher ki kaali window dekhne ki zaroorat nahi.

Yeh patti KUCH DOWNLOAD YA INSTALL NAHI KARTI. Sirf khabar deti hai
aur bata deti hai ke karna kya hai. Dono faisle pehle ki tarah aap ke
dabane par hi rehte hain — wahi baat jo aap ne V90 mein kahi thi.


------------------------------------------------------------
 YEH KYUN ZAROORI THA
------------------------------------------------------------
Node pehle bhi cloud se poochta tha ke naya build hai ya nahi. Magar
us ka jawab SIRF launcher ki kaali window mein chhapta tha, aur woh
bhi sirf start ke waqt. Teen surakh the:

  1. V164 ke baad woh window tray mein chhup jati hai. Paigham dekhne
     ka imkan pehle se bhi kam ho gaya.
  2. Jo dukan hafton computer band hi nahi karti — aur zyadatar nahi
     karti — us par check KABHI chalta hi nahi tha.
  3. Jo banda saara din POS par kaam karta hai, woh launcher ki window
     kabhi dekhta hi nahi.

Ab khabar wahan aati hai jahan banda pehle se dekh raha hai.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
PANCH FILES BADLIN, DO NAYI:

    VERSION                           (badli)
    public/api.php                    (badli — ek naya action)
    public/router.php                 (badli — patti inject karta hai)
    scripts/self_update.php           (badli — service use karti hai)
    scripts/sync_loop.php             (badli — har 6 ghante check)
    public/update_notice.js           (NAYI — patti khud)
    src/Services/UpdateCheck.php      (NAYI — poochne/parhne ka kaam)

KOI MIGRATION NAHI — database ko kuch nahi hota.
approved_ui KI KISI FILE KO HAATH NAHI LAGAYA — is liye SHA256 wala
taala (APPROVED_UI_SHA256.json) bhi waisa ka waisa hai.


PATTI KAISE CHALTI HAI
  - Neeche BAYEN kone mein, choti si: "New version · V166"
  - Click karne par khul jati hai: dono build ke naam, aur teen qadam
    (tray → Stop SmartPOS → UPDATE.bat → dobara start)
  - "Hide until tomorrow" — ek din ke liye chhup jati hai, agli subah
    phir aa jati hai. Naya build aaye to foran wapas, intezar nahi.
  - Koi DIALOG khula ho (New Bill, payment) to patti us ke PEECHE
    chali jati hai. Bill ya payment ka raasta kabhi nahi rokti.
  - Neeche wali function-key patti (F1, F2, F3...) ko nahi dhakti —
    us se upar rehti hai.
  - Login se pehle kuch nazar nahi aata.
  - Cloud (Railway) par kabhi nazar nahi aati — wahan UPDATE.bat jaisa
    kuch hai hi nahi.

CHECK AB HAR 6 GHANTE BHI HOTA HAI
  Pehle sirf launcher start par (45 second baad). Ab sync loop — jo
  pehle se din raat chal raha hai — har 6 ghante bhi pooch leta hai.
  Is liye jo computer hafton band nahi hota, us par bhi khabar aa
  jati hai. Khabar mil chuki ho to portal ko dobara tang nahi karta.


------------------------------------------------------------
 SAATH MEIN EK PURANA BUG BHI THEEK HUA
------------------------------------------------------------
`upderr()` naam ka function teen jagah bulaya jata tha — download
nakaam ho, disk bhari ho, ya portal ZIP ki jagah error page bhej de.
Magar poore code mein is naam ka koi function define hi nahi tha.

Natija: in teen soorton mein wajah batane wala paigham chhapne ke
bajaye PHP wahin mar jata tha:

    PHP Fatal error: Call to undefined function upderr()

Yani "UPDATE_FAILED ... (check that this node is still linked)" jaisa
paigham — jo khaas is liye likha gaya tha ke user ko wajah pata chale
— kabhi chhapta hi nahi tha.

Ab wajah `storage\logs\update.log` mein likhi jati hai aur paigham
theek se chhapta hai.


------------------------------------------------------------
 TESTING
------------------------------------------------------------
  PASS 88 / FAIL 0

  Service (26 test):
    Naya build mila -> nishani bani, build ka naam theek            OK
    Cloud par wahi build jo laga hai -> nishani nahi bani           OK
    Portal band -> "offline" nishani, POS par patti NAHI            OK
    6 ghante ka waqfa: pehli dafa chali, foran dobara nahi          OK
    Khabar mil chuki ho to portal ko dobara nahi poochti            OK
    Purani nishani ka pehra (haath se extract kiya gaya ho)         OK
    role=cloud par kuch nahi                                        OK
    probe() ne kuch DOWNLOAD nahi kiya                              OK

  Browser (62 test) — restaurant POS, dashboard, supermarket POS:
    Patti nazar aati hai, ek hi line mein, sahi jagah               OK
    Function-key patti ko nahi dhakti (dono POS par)                OK
    Dialog khula ho to patti us ke peeche                           OK
    Khulne par UPDATE.bat ka tareeqa aur dono build                 OK
    "Hide until tomorrow" -> hat jati hai, reload par bhi chhupi    OK
    Naya build aane par phir nazar aa jati hai                      OK
    "ready" (utar chuka hai) ka matn alag                           OK
    Update na ho to kuch nahi                                       OK
    Login se pehle kuch nahi, cloud par kuch nahi                   OK
    Koi JS error nahi, kisi screen par                              OK

  Sealed package:
    Package banaya (1.48 MB), `update_notice.js` us mein maujood    OK
    Sealed node par self_update.php chala — nishani package ki
      asal `updates\` folder mein bani (seal ke andar nahi)         OK
    Sealed api.php mein naya action maujood                         OK

  Jo main NAHI kar saka:
    Sealed node par patti ko aankh se dekhna — us ke liye node ka
    apna MariaDB chahiye jo is machine par nahi chal sakta. Patti
    khud bina-sealed tree par poori tarah chala kar dekhi gayi hai,
    aur patti ka code dono jagah bilkul ek hi hai.


============================================================
 LAGANE KA TAREEQA
============================================================

############################################################
 ***  TARTEEB AHEM HAI: PEHLE RAILWAY, PHIR OFFLINE PC  ***
############################################################

Node cloud se poochta hai: "tumhare paas kaun sa build hai?" Agar
cloud par purana build para hai to node ko koi naya build nazar hi
nahi aayega — chahe aap ne offline PC par V165 laga bhi liya ho.

Is liye PEHLE Railway par deploy karein.


PART A — SERVER (Railway)

STEP A1 — Source mein yeh purani files DELETE karein, phir extract:
    VERSION
    public/api.php
    public/router.php
    scripts/self_update.php
    scripts/sync_loop.php

    (Windows "Replace or Skip" poochta hai aur aksar Skip ho jata
     hai — isi liye pehle delete karna zaroori hai.)

    NAYI FILES (inhein delete karne ki zaroorat nahi, yeh pehle se
    hain hi nahi):
    public/update_notice.js
    src/Services/UpdateCheck.php

STEP A2 — Railway par deploy karein, jaise hamesha.

STEP A3 — Deploy ke baad jaanch lein ke cloud V165 bata raha hai:
    Browser mein kholein:
        https://<aap-ka-railway-url>/api.php?action=update-check
    Jawab mein "V165 build 2026-10-25" aana chahiye.


PART B — OFFLINE PC

STEP B1 — BACKUP (ek minute, magar zaroori)
    Purani copy sambhaal lein (ya V164 ka zip rakha rehne dein).

STEP B2 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    public/api.php
    public/router.php
    scripts/self_update.php
    scripts/sync_loop.php

STEP B3 — EXTRACT
    SaaSVersion_V165.zip extract karein, phir UPDATE.bat
    (Koi migration nahi — database ko kuch nahi hota.)

STEP B4 — VERIFY (har file mein Ctrl+F)

      FILE                            SEARCH FOR
      ------------------------------  --------------------------
      VERSION                         V165
      public/api.php                  update-status
      public/router.php               update_notice.js
      public/update_notice.js         smartposUpdateNotice
      src/Services/UpdateCheck.php    probeIfDue
      scripts/self_update.php         UpdateCheck::probe
      scripts/sync_loop.php           probeIfDue

STEP B5 — CHALA KAR DEKHEIN
    1. Software chalayein, POS kholein
    2. Abhi kuch nazar nahi aayega — yehi theek hai, kyunke node aur
       cloud dono V165 par hain
    3. Patti dekhni ho to: software band karein, notepad se yeh file
       banayein aur us mein koi bhi naya naam likh dein:

           updates\available.txt       (matn: V166 build 2026-11-01)

       Phir software chalayein — POS ke neeche bayen kone mein patti
       nazar aani chahiye. Dekh lene ke baad wo file delete kar dein.

    4. Asal mein patti khud us waqt aayegi jab aap Railway par V166
       chadhayenge — launcher start ke 45 second baad, ya chalti
       dukan par 6 ghante ke andar.


------------------------------------------------------------
 EK BAAT JAAN LEIN
------------------------------------------------------------
Patti sirf KHABAR hai. Lagane ka tareeqa wahi purana hai:

    Tray icon par right-click -> Stop SmartPOS
    UPDATE.bat chalayein
    Software dobara chalayein

Agar aap chahein ke yeh bhi khud ho jaye — ya POS ke andar hi
"Download now" ka button ho — to batayein, woh alag se ho sakta hai.

Madad: Wabwar Software House — +92 342 5095104

// build: V165 build 2026-10-25
