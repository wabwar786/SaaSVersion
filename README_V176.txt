============================================================
 V176 — SERVICE CHARGE KHUD LAGNA, AUR KHUD KHULNE WALE DIALOG
 Wabwar Software House                     build 2026-11-01
============================================================

SIRF EK FILE BADLI HAI: approved_ui\restaurant_pos.html
Server ka code nahi badla. Koi migration nahi. Database nahi badla.


############################################################
 1) SERVICE CHARGE KHUD KYUN LAGNE LAGTA THA
############################################################

Do kharabiyan thin, aur dono MIL KAR wo soorat banati thin jo
samajh mein nahi aa rahi thi.

PEHLI — naye bill par service charge saaf hi nahi hota tha.
`newBill()` cart, discount, delivery, mode, payment — sab saaf
karta tha, magar `svc` ko haath hi nahi lagata tha. Yani ek dafa
kisi ne haath se service charge laga diya, to wo HAR AGLE BILL par
chalta rehta tha — jab tak POS ka safha dobara load na ho.

Asli screen par chala kar dekha:

    shuruat               svc = 0%
    Edit se 10% lagaya    svc = 10%,  charge 170
    New Bill dabaya       svc = 10%        <-- saaf nahi hua
    naye bill par item    service charge 340 khud jur gaya

DOOSRI — malik ki tay-shuda service charge kabhi lagti hi nahi thi.
Sirf EK lakeer us ka kaam karti thi, aur wo `BOOT.settings` parhti
thi. POS ab `pos-start` se load hota hai, jahan settings `r.settings`
mein aati hain — `BOOT.settings` banta hi nahi, is liye wo lakeer
KABHI CHALTI HI NAHI THI.

    naya raasta (pos-start)  BOOT.settings=false   svc=0
    purana raasta (pos-boot) BOOT.settings=true    svc=10

MILA KAR ASAR: Settings mein service charge rakhein — kuch na hota.
Koi ek dafa haath se laga de — phir har bill par lagta rahe. POS band
kar ke kholein — phir gayab. Bilkul be-tarteeb.

AB (aap ke faisle ke mutabiq):
    - har naya bill service charge SIFAR se shuru hota hai
    - POS dobara khulne par bhi sifar
    - Tax Settings save karne se bhi khud nahi lagta
    - malik jis bill par chahe, Edit se khud lagata hai

Tax ka rate waise hi chalta hai (16% / 8%) — wo khatam nahi kiya
gaya, warna kisi bill par tax wasool hi na hota aur FBR ka hisaab
toot jata. Khali bill par tax 0 dikhta hai kyunke saamaan hi nahi.


############################################################
 2) POS KHULTE HI NEW BILL KA DIALOG
############################################################

`boot()` ke aakhir mein yeh tha:

    if(!cart.length && !billStarted) newBillFlow();

Yani safha load hote hi cashier seedha dialog mein aa girta tha. Jab
bhi koi cheez safha dobara load karati, dialog "apne aap" khulta
nazar aata.

AB: dialog SIRF New Bill dabane par khulta hai.

JO WAISE KA WAISA HAI: jo cashier bina bill shuru kiye seedha item
dabata hai, us ke liye raasta mojood hai — dialog khulta hai aur
"Choose the bill type first" likha aata hai. Isay nahi badla.

AUR EK BAAT: shift kholne ke foran baad bhi dialog khud khulta hai
(alag lakeer). Aap ne us ka nahi kaha tha, is liye usay haath nahi
lagaya. Kehain to agli build mein wo bhi band kar dunga.


############################################################
 3) DISCOUNT AUR DELIVERY — YEH THEEK THAY
############################################################

Dono ka audit kiya, dono naye bill par saaf hote hain. Magar do
jagah wo "khud" lagte hain — aur yeh JAAN BOOJH KAR hai:

    customer chunne par    us customer ka tay-shuda discount %  (V157)
    Delivery chunne par    Settings wali delivery ki raqam      (V171)

Chala kar dekha: "Discount Wala" chunte hi 15% laga, aur Delivery
chunte hi 150 bhar gaya. Dono dafa screen par toast bhi aata hai.
Yeh kharabi nahi hai, is liye nahi badla.


############################################################
 4) INVENTORY — QTY SIFAR KARNE KA OPTION
############################################################

YEH PEHLE SE MOJOOD HAI, V162 se. Kuch banane ki zaroorat nahi thi.

    Inventory screen -> "Adjust Stock" (sirf admin ko nazar aata hai)
    -> har item ke saamne nayi ginti likhein
    -> ya ek hi click mein "Set all to zero"

Chala kar dekha: teen item ka stock sifar kiya, teenon sifar ho gaye,
aur stock history mein bakayda ek transaction (3 lines) darj hui —
is liye inventory report bhi theek rehti hai aur agle sync par cloud
par bhi pohanch jata hai.

Agar aap ke kisi customer ke PC par yeh button nazar na aaye to do
mein se ek baat hogi:
    - us ka offline build V162 se purana hai, ya
    - wo login admin ka nahi hai (yeh sirf admin ko milta hai, aur
      server bhi yehi pehra lagata hai — sirf button chhupana nahi)


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
TEEN FILES (README aur screenshot ke ilawa):

    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\restaurant_pos.html

Server ka koi raasta nahi badla. Koi API nahi badli. Database ka
dhaancha nahi badla. Koi migration nahi.


------------------------------------------------------------
 TESTING — 20 TEST, SAB PASS
------------------------------------------------------------
    naye bill par sab sifar                 2
      - subtotal/discount/service/tax/delivery/grand sab 0
      - tax ka rate khatam nahi hua (16% qayam)
    service charge chipakna band            6
      - haath se 10% laga -> naye bill par 0
      - naye bill ke total mein service charge nahi jurta
      - discount aur delivery bhi saaf
    POS khulte hi dialog nahi               3
      - safha khulne par 0 dialog
      - New Bill dabane par dialog khulta hai
      - bina bill item dabane wala raasta waise hi
    POS dobara khulne par svc sifar         2
      - naye raaste par bhi, purane raaste par bhi
    billing ka poora raasta (regression)    7
      - item -> tax laga
      - 10% discount haath se laga
      - 5% service charge haath se laga
      - payment poora hua, bill band
      - screen ka total aur database ka total BILKUL ek (1606.50)
      - discount aur service charge database mein darj
      - bill band hone ke baad agla bill saaf

JO MAIN NAHI KAR SAKA:
    Aap ke apne customer ke PC par chala kar dekhna. Yahan maine
    wohi screen, wohi button aur wohi dialog istemal kiye — magar
    wo aap ka data nahi tha.


============================================================
 LAGANE KA TAREEQA — DHYAN SE PARHEIN
============================================================

PART B — SERVER (Railway)
    Teen files chadha dein, bas. Koi migration nahi, database ko
    haath nahi lagta.

PART A — OFFLINE PC  *** AHEM ***

    `approved_ui\` ki files SEALED PACKAGE KE ANDAR hoti hain
    (`runtime\app.sealed`). Is liye sirf yeh zip extract karne se
    offline PC par KUCH NAHI BADLEGA — wahan chalne wali screen
    seal ke andar se aati hai.

    Offline customer ke liye raasta yeh hai:
        1. Super Admin se is build ka POORA package banwayein
        2. portal par rakhein
        3. us PC par GET_UPDATE.bat  phir  INSTALL_UPDATE.bat

    Jo customer yeh update NA le, us par koi asar nahi parega —
    kyunke server ka koi raasta nahi badla aur database waisa hi hai.

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\restaurant_pos.html

STEP A2 — EXTRACT
    SaaSVersion_V176.zip extract karein

STEP A3 — VERIFY (Ctrl+F)

      FILE                               SEARCH FOR
      ---------------------------------  -------------------------------
      VERSION                            V176
      approved_ui\restaurant_pos.html    SERVICE CHARGE BHI NAYE BILL PAR
      approved_ui\restaurant_pos.html    SERVICE CHARGE HAMESHA SIFAR
      approved_ui\restaurant_pos.html    NEW BILL KA DIALOG NAHI KHULEGA

STEP A4 — CHALA KAR DEKHEIN
    1. POS kholein -> koi dialog KHUD nahi khulna chahiye
    2. New Bill dabayein -> dialog khulna chahiye
    3. Ek bill par Edit se service charge lagayein
    4. New Bill dabayein -> service charge 0 hona chahiye
    5. POS band kar ke dobara kholein -> service charge 0
    6. Inventory -> Adjust Stock -> "Set all to zero" chal raha hai

Madad: Wabwar Software House — +92 342 5095104

// build: V176 build 2026-11-01
