============================================================
 V175 — RENEW KA BUTTON, AUR EXPIRY KA NIZAM
 Wabwar Software House                     build 2026-10-29
============================================================

V175 MEIN V174 AUR US SE PEHLE KA SAB KUCH SHAMIL HAI.

Teen cheezein is mein hain:
  1. Renew dabane par New Bill ki screen aana — theek
  2. Wo kharabi dobara na bane — jar se band
  3. Expiry ab demo par test ho sakti hai (bagair SQL ke)


############################################################
 1) RENEW DABATE HI NEW BILL KI SCREEN
############################################################

AAP NE JO DEKHA:
POS ke oopar warning aayi — "Your subscription expires today.
Please renew." — Renew dabaya, aur New Bill (Dine In / Takeaway /
Delivery) ka dialog khul gaya. Renew ka kaam kuch bhi nahi hua.

ASAL MEIN HO KYA RAHA THA:
Renew ka link bilkul theek tha. Safar beech mein mur raha tha:

    Renew  ->  /activate.html
           ->  router ne ijazat na dekh kar WAPAS /restaurant_pos.html
           ->  POS naye sire se khula, cart khali tha
           ->  POS ne khud New Bill ka dialog khol diya

Yani wo dialog Renew ka nahi tha — POS ka apna tha.

IJAZAT KYUN NAHI MILTI THI:
`activate.html` ka apna module hai (`activate`), aur har business ki
apni modules ki list hoti hai (`features_json`). Jin businesses ki
list mein `activate` nahi tha, un par wo page band tha.

Lekin yeh module un do mein se ek hai jo KABHI band nahi hone
chahiyen (`ModuleGroups::ALWAYS_ON = dashboard, activate`) — kyunke
`activate` renewal ki screen hai, aur us ka band hona ka matlab yeh
hai ke customer paisay dena chahe to bhi na de sake.

Do pehre thay, aur dono ka qanoon alag ho gaya tha:

    gateByFeatures()   ALWAYS_ON girne nahi deta  (sidebar, POS ka
                       boot, Users & Access)
    canModule()        girne deta tha             (router ka page gate)

Isi liye sidebar mein "Activate / Renew" NAZAR AATA tha, aur dabane
par page wapas POS par chala jata tha. V149 mein yeh ALWAYS_ON pehle
pehre par laga diya gaya tha, doosre par rah gaya.

SAB SE KHARAB BAAT: yeh sirf expiry se PEHLE hota tha. Expire hone ke
BAAD router `activate.html` ko khud khol deta hai aur yeh pehra chalta
hi nahi. Yani jo customer WAQT PAR renew karna chahta, usay raasta
nahi milta tha — aur jo cut ho chuka hota, usay mil jata tha.

AB:
  - `canModule()` bhi ALWAYS_ON ko girne nahi deti
  - aur `activate.html` par module ka pehra bilkul hi nahi lagta
    (login ki shart phir bhi hai). Yeh doosra taala is liye lagaya
    ke aainda koi list, koi role, koi nayi ghalti is ek screen ko
    band na kar sake.

Yeh ek fix chaaron jagah ka Renew theek karta hai — restaurant POS,
admin screens (shell.js), retail POS, aur retail ki admin screens —
kyunke chaaron ka link wohi tha aur pehra wohi.


############################################################
 2) YEH KHARABI BANI KAHAN SE THI
############################################################

`features_json` TEEN jagah se likhi jati hai, aur sirf EK jagah
ALWAYS_ON jorhti thi:

    Super Admin -> Features screen    ALWAYS_ON jorhta tha
    FBR ka switch                     NAHI
    Owner app ka switch               NAHI

Dono switch ka tareeqa yeh hai: agar business ki list abhi khali
(NULL = sab allowed) ho, to wo `platform_modules` se POORI list KHUD
bana kar likh dete hain, sirf apni ek key jorh/nikaal kar. Us lamhe
jo kuch us list mein na aaye, wo us business par HAMESHA ke liye band
ho jata hai — chahe wo renewal ki screen hi kyun na ho.

Aap ke apne database par yehi hua tha:

    Demo Restaurant        activate list mein NAHI   -> Renew toota
    Demo Supermarket       activate list mein NAHI   -> Renew toota
    AKORWAL FISH POINT     activate list mein HAI    -> theek
    Mubeen Restaurant      activate list mein HAI    -> theek

Akorwal aur Mubeen is liye bach gaye ke un ki list Features screen se
mehfooz hui thi.

AB: dono switch bhi ALWAYS_ON jorhte hain. Aur purani liston ke liye
ek dafa ki safai hai (`migrate_always_on`), jo har business ki list
mein `activate`/`dashboard` daal deti hai — kuch HATATI nahi, aur jin
ki list khali hai un ko haath nahi lagati.

Safai is liye bhi zaroori thi ke Super Admin ki Features screen par
yeh dono khane pehle se "locked" nazar aate hain, jabke mehfooz list
mein wo mojood hi nahi thay — screen aur asliyat alag alag baat kar
rahe thay.


############################################################
 3) EXPIRY AB DEMO PAR TEST HO SAKTI HAI
############################################################

Ab tak Super Admin har guzri hui tareekh rad kar deta tha, is liye
expiry test karne ka ek hi raasta tha: database par seedhi SQL. Wo
khatarnaak hai — ek ghalat WHERE aur har customer band.

AB: guzri hui tareekh us business par qubool hoti hai jis par
`tenants.is_demo = 1` hai. Asli customer par wo ab bhi rad hoti hai,
aur ab wajah saaf likhi aati hai:

    "That expiry date is already in the past.
     (A past date can only be set on a demo business.)"

Yani:  Super Admin -> demo business -> Licence -> guzri tareekh ->
       Save. Demo foran expire. Phir mahine barha kar wapas chalu.

NOT CHANGED: expiry ka baqi poora nizam waisa hi hai — 30 din ki
mohlat, us arse mein data mehfooz, activation key ka raasta, aur
offline node ka licence cache. In mein kuch nahi badla.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
PAANCH FILES + EK NAYI (README ke ilawa):

    VERSION
    src\Auth.php
    public\router.php
    public\api.php
    scripts\migration_order.php
    scripts\migrate_always_on.php        <-- NAYI

POS ki screen nahi badli. Koi UI file nahi badli. Database ka dhaancha
nahi badla (migration sirf purani liston ki safai karti hai).


------------------------------------------------------------
 EK TASHEEH — V174 KE README MEIN
------------------------------------------------------------
V174 ke README mein maine likha tha ke sale report "71 cancelled bill,
93,741" gin rahi thi. WO GHALAT THA.

Wo CANCELLED rows software ne nahi likhi thin — meri apni test cleanup
ki SQL ne likhi thin. Software kabhi `CANCELLED` nahi likhta (hold
cancel karne par bhi `VOID` likha jata hai), is liye aap ki asli
report mein us 93,741 ka koi wujood nahi tha.

Asal kharabi 31 rokay hue (OPEN) bill thay — 40,685. V174 ka fix usi
ke liye tha aur wo durust hai. README_V174.txt mein yeh tasheeh kar
di gayi hai.


------------------------------------------------------------
 TESTING — 59 TEST, SAB PASS
------------------------------------------------------------
NAYE (V175):
    Renew ka raasta                        3
      - activate list mein ho              -> khulta hai
      - activate list se GAYAB ho          -> phir bhi khulta hai
      - dashboard list se gayab ho         -> phir bhi khulta hai
    baqi pehra waisa ka waisa              3
      - 'reports' band ho                  -> ab bhi rukta hai
      - retail ka page restaurant par      -> ab bhi band
      - list khali (sab allowed)           -> sab khulta hai
    migration                              4
      - --status sirf batata hai
      - activate+dashboard daal deta hai, kuch hatata nahi
      - dobara chalane se kuch nahi badalta
      - khali list ko haath nahi lagata
    FBR ka switch                          2
    Owner app ka switch                    2
    demo expiry                            4
      - asli business par guzri tareekh RAD
      - demo par QUBOOL, aur licence waqai expired
      - demo wapas chalu bhi ho jata hai
    aap ka asal scenario (expires today +
      activate list mein nahi)             1  -> ab khulta hai
    do business mix to nahi hote           2

PURANE (dobara chalaye, sab pass):
    bill ka poora safar (lifecycle)       23
    cashier ki ijazat                      5
    doosre business par hamla             12

EK PURANA TEST THEEK KIYA (software nahi):
    "naya duplicate order nahi bana" fail ho raha tha. Wajah test ki
    apni thi — wo ginti HAR DIN ke bill par chala raha tha, halanke
    bill number roz dobara shuru hote hain. Database mein dekha to
    bill #0005 chaar dafa tha, magar CHAARON ALAG DIN ka. Aaj ka sirf
    ek. Ginti aaj ke din tak mehdood ki, test pass.

JO MAIN NAHI KAR SAKA:
    Aap ke apne Railway database par chala kar dekhna. Maine wahi code
    aur wahi soorat apne yahan banayi (do demo jaisi lists), magar wo
    aap ka data nahi tha.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    PEHLE YAHI. Dono demo cloud par hain aur fix bhi cloud ka hai.

    Deploy ke baad migration khud chal jati hai. Chahen to haath se
    bhi chala sakte hain (Railway -> service -> Shell):

        php scripts/migrate_always_on.php --status    # sirf batayegi
        php scripts/migrate_always_on.php             # theek karegi

    Pehla hukm kuch badalta nahi — sirf naam bata deta hai.

PART A — OFFLINE PC

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    src\Auth.php
    public\router.php
    public\api.php
    scripts\migration_order.php

    (`scripts\migrate_always_on.php` nayi hai — delete karne ki
     zaroorat nahi.)

STEP A2 — EXTRACT
    SaaSVersion_V175.zip extract karein, phir UPDATE.bat

STEP A3 — VERIFY (Ctrl+F)

      FILE                            SEARCH FOR
      ------------------------------  ----------------------------
      VERSION                         V175
      src\Auth.php                    ALWAYS_ON YAHAN BHI
      public\router.php               RENEW KA RAASTA KABHI BAND
      public\api.php                  ALWAYS_ON YAHAN SE KABHI NA
      public\api.php                  GUZRI HUI TAREEKH SIRF DEMO
      scripts\migration_order.php     migrate_always_on
      scripts\migrate_always_on.php   PURANI FEATURES LISTON KI

STEP A4 — CHALA KAR DEKHEIN

    1. Demo Restaurant mein login karein
    2. POS kholein — oopar licence ki patti par "Renew" dabayein
       -> Activate / Renew ki screen khulni chahiye
       -> New Bill ka dialog NAHI aana chahiye
    3. Super Admin -> Demo Restaurant -> Licence
       -> guzri hui tareekh daal kar Save
       -> qubool honi chahiye, aur demo foran expire
    4. Demo mein koi bhi page kholein
       -> seedha Activate ki screen par le jana chahiye
    5. Super Admin se mahine barha dein
       -> demo wapas normal chal jaye
    6. Ab AKORWAL par wohi guzri tareekh daal kar dekhein
       -> RAD honi chahiye, aur wajah likhi aani chahiye

Madad: Wabwar Software House — +92 342 5095104

// build: V175 build 2026-10-29
