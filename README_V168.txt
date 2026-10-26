============================================================
 V168 — SALE POINT BILLING KA AUDIT + CHAR KHARABIYAN THEEK
 Wabwar Software House                     build 2026-10-26
============================================================

Aap ne do baatein batayi thin:
  1. bill band karne ke baad phir Hold par chala jata hai
  2. discount mein phir masla aa gaya hai

Dono ko chala kar dekha. Dono ki jaR EK hi function mein thi. Us ke
saath do aur kharabiyan bhi nikal aayin jo abhi tak nazar nahi aayi
thin — un mein se ek paison ka hisaab kharab kar rahi thi.


############################################################
 ASAL JAR — ek hi function, do ulat ghaltiyan
############################################################

`ensureOpenOrder()` — har bill isi se guzarta hai (hold, KOT, aur
bill band karna, teenon).

  GHALTI 1: HALAT (status) KI KOI SHART NAHI THI.
            Wo BAND ho chuke bill ko bhi utha leta tha.

  GHALTI 2: TAREEKH KI SHART BOHAT SAKHT THI.
            Talash sirf "aaj ki tareekh" par hoti thi, is liye KAL
            ka roka hua bill milta hi nahi tha.

Ek hi function, aur do ulat ghaltiyan. Neeche dono ka asar.


------------------------------------------------------------
 MASLA 1 — "BAND KARNE KE BAAD PHIR HOLD PAR"   *** THEEK ***
------------------------------------------------------------
Jo bill EK DIN roka gaya ho aur AGLE DIN band kiya jaye:

  - Server us ko "aaj ki tareekh" mein dhoondta tha — milta nahi
  - Is liye USI NUMBER PAR AAJ KA NAYA ORDER bana deta
  - Payment us NAYE order par lag jati
  - Aur KAL WALA ORDER KHULA HI REH JATA

Nateeja wahi jo aap ne dekha: bill band kiya, parchi bhi nikli,
magar Hold Bills mein wo bill waise ka waisa baitha hai. Aur kitab
mein ek hi bill number par DO order ban chuke hote the.

AB: POS us ruke hue bill ki apni ID saath bhejta hai. Server wahi EK
order band karta hai — us ki tareekh jo bhi ho. Na duplicate, na
Hold mein baqi.

Chala kar dekha (asli POS screen par, wahi Resume ka button):
  pehle : 2 rows — ek OPEN (Hold mein baqi), ek CLOSED
  ab    : 1 row  — wahi purana order CLOSED, Hold list saaf


------------------------------------------------------------
 MASLA 2 — DISCOUNT       *** DISCOUNT THEEK THA, TOTAL GHALAT ***
------------------------------------------------------------
Discount ki raqam hamesha theek likhi ja rahi thi. Kharabi HOLD ke
total mein thi:

  hold ka total = sub - discount + service + TAX
                                             ^^^
Tax HAMESHA upar se joRa ja raha tha. Pakistan mein tax INCLUSIVE
hota hai — yani keemat ke ANDAR pehle se shamil. Is liye hold par
tax do dafa lag jata tha:

      asli bill 420  ->  Hold Bills mein 477.93
      576 ka bill    ->  Hold Bills mein 655.45

Discount wale bill par farq aur bara dikhta tha, is liye shikayat
discount ki shakal mein aati thi.

AB: hold ka total wahi hisaab istemal karta hai jo bill ka total
karta hai (INCLUSIVE / EXCLUSIVE dono ka khayal). Chaar soorton
mein chala kar dekha — discount ke sath, service charge ke sath,
dono ke sath — har dafa hold ka total aur bill ka total BARABAR.


------------------------------------------------------------
 MASLA 3 — BAND BILL DOBARA LIKHA JA SAKTA THA   *** AHEM ***
------------------------------------------------------------
Yeh aap ne nahi bataya tha — audit mein khud nikla, aur sab se
khatarnak hai.

`pos_bill_guard()` naam ka pehra pehle se maujood hai: wo dekhta
hai ke jis bill number par kaam ho raha hai wo khula bhi hai ya
nahi, aur band ho to naya number de deta hai.

Yeh pehra `pos-kot` aur bill band karne par laga tha — magar HOLD
par lagana reh gaya tha.

Us surakh se yeh ho sakta tha: band ho chuke bill ke number par
hold aa jaye (do POS khule hon, ya parchi na nikle aur cashier
dobara koshish kare) to usi BAND bill par nayi items aur naya
total likh diya jata.

Test mein yehi hua: 640 ka band aur ADA SHUDA bill, hold ke baad
2,740 ka ho gaya. Payment wahi 640 ki — yani us din ka cash kabhi
milta hi nahi.

AB: hold par bhi wahi pehra. Aur `ensureOpenOrder()` khud bhi band
bill ko haath nahi lagata — chahe koi bhi raasta ho.


------------------------------------------------------------
 MASLA 4 — DISCOUNT BILL SE BARA HO TO BILL MANFI
------------------------------------------------------------
840 ke bill par 1,340 ka discount lagayen to bill -500 ka ban jata
tha, aur usi raqam ki payment bhi likh di jati. Aisi entry kisi
report mein theek nahi baithti.

AB: discount bill ko sifar tak le ja sakta hai, us se aage nahi.


------------------------------------------------------------
 SAATH MEIN — "Held" ka khana
------------------------------------------------------------
Running Orders screen par "Held" ki ginti `hold_status` parhti thi,
magar us khane mein likhta koi nahi tha — wo ginti hamesha 0 rehti
thi. Ab hold par 1 aur bill band hone par 0 likha jata hai.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
PANCH FILES BADLIN (README ke ilawa), KOI NAYI FILE NAHI:

    VERSION
    APPROVED_UI_SHA256.json
    approved_ui/restaurant_pos.html   (bill ke saath us ki ID bhi)
    public/api.php                    (hold par pehra, asli bill number)
    src/Services/PosService.php       (asal jaR — ensureOpenOrder,
                                       hold ke totals, discount ki hadd)

KOI MIGRATION NAHI — database ko kuch nahi hota.


------------------------------------------------------------
 TESTING
------------------------------------------------------------
  PASS 100 / FAIL 0

  Sale Point (47 test — asli POS screen par, asli clicks):
    Seedha bill: band hua, PAID, cart khali, naya number           OK
    Hold -> Resume -> band: hold list se nikal gaya                OK
    KAL ka hold aaj band: sirf EK order, Hold list saaf            OK
    Band bill ke number par hold -> naya number mila, band
      bill ko haath tak nahi lagaya                                OK
    Band bill ke number par KOT -> wahi                            OK
    Discount flat 100: screen, database aur bill teenon barabar    OK
    Discount 10%: raqam bhi, percent bhi mehfooz                   OK
    Discount hold ke aar paar: 10% hi wapas aaya (flat nahi)       OK
    Hold ka total == bill ka total (4 soorton mein)                OK
    Service charge, discount+service: hisaab theek                 OK
    Discount bill se bara: bill sifar, manfi nahi                  OK
    Ek hi bill do dafa pay: ek hi payment, stock ek hi dafa        OK
    Koi JS error nahi                                              OK

  Purane kaam par asar (53 test):
    V166 bulk delete — server 18, browser 28                       OK
    V167 shift ka faisla — 7                                       OK

  Masla pehle REPRODUCE kiya gaya, phir theek:
    Hold ka total 477.93 (chahiye 420) -> ab 420
    Kal ka bill: 2 rows, ek Hold mein baqi -> ab 1 row CLOSED
    Band bill 640 -> hold ne 2,740 kar diya -> ab 640 hi rehta hai


------------------------------------------------------------
 EK BAAT JO ABHI BAQI HAI (theek NAHI ki)
------------------------------------------------------------
Jo bill sirf KITCHEN bheja gaya ho (KOT) magar Hold na kiya gaya
ho, wo Hold Bills mein "PKR 0" dikhata hai — kyunke totals sirf
hold ke waqt likhe jate hain. Items theek hain, sirf raqam 0 nazar
aati hai aur Resume karne par sab theek aa jata hai.

Yeh aap ki batayi hui shikayat mein nahi tha is liye haath nahi
lagaya. Kahein to agli dafa theek kar doon — KOT ke waqt bhi totals
likhna hai, bas.


============================================================
 LAGANE KA TAREEQA
============================================================

PART A — OFFLINE PC

STEP A1 — BACKUP
    Purani copy sambhaal lein (ya V167 ka zip rakha rehne dein).

STEP A2 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\restaurant_pos.html
    public\api.php
    src\Services\PosService.php

    KOI NAYI FILE NAHI (README ke ilawa).

STEP A3 — EXTRACT
    SaaSVersion_V168.zip extract karein, phir UPDATE.bat
    (Update ab apna log khud likhti hai — V167 wala kaam. Agar koi
     paigham aaye to storage\logs\update-*.log bhej dein.)

STEP A4 — VERIFY (har file mein Ctrl+F)

      FILE                             SEARCH FOR
      -------------------------------  -------------------------
      VERSION                          V168
      public\api.php                   V168 — HOLD PAR BHI WAHI PEHRA
      src\Services\PosService.php      touchOrder
      src\Services\PosService.php      hold_status=1
      approved_ui\restaurant_pos.html  order_id:curHoldId

STEP A5 — CHALA KAR DEKHEIN
    1. POS kholein, do item daalein, Hold karein.
       Hold Bills mein jo raqam likhi hai wo bill ki asli raqam honi
       chahiye (pehle tax dobara lag kar zyada aati thi).
    2. Usay Resume karein aur bill band karein.
       Hold Bills khol kar dekhein — wo bill ab us mein NAHI hona
       chahiye.
    3. Agar aap ke paas koi purana bill kai din se Hold mein para
       hai, usay Resume kar ke band karein — ab wo list se nikal
       jayega (pehle nahi nikalta tha).

    PURANE PHANSAY HUE BILL: jo bill pehle se Hold mein phansay
    paray hain, wo apne aap nahi nikleinge. Unhein ek ek kar ke
    Resume kar ke band karein (ya Cancel karein). Us ke baad masla
    dobara nahi hoga.


PART B — SERVER (Railway)

    Wahi files, wahi tarteeb. Deploy kar dein.

Madad: Wabwar Software House — +92 342 5095104

// build: V168 build 2026-10-26
