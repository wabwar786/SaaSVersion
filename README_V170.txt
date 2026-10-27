============================================================
 V170 — EK HI ITEM KI ALAG ALAG LINES GUM HO JATI THIN
 Wabwar Software House                     build 2026-10-27
============================================================

V170 MEIN V169 AUR V168 KA SAB KUCH SHAMIL HAI.
Agar wo abhi nahi lagaye to seedha yehi laga lein.

Yeh build aap ki us shikayat ka jawab hai:

    "agar bill generate karain aur KOT na print karain aur hold kar
     dain, bill jab wapis ayen to items hi ghayab hoti hain"


############################################################
 ASAL SABAB
############################################################

Sale Point par cart ki har line TEEN cheezon se banti hai:
item, naam, aur KITCHEN NOTE.

Yani yeh DO alag lines hain — aur honi bhi chahiyen, kyunke kitchen
ko dono alag banani hain:

    Chicken Karahi   x1   [mirch tez]
    Chicken Karahi   x1   [mirch kam]

Magar server par talash sirf DO cheezon par thi — item aur naam.
Note ka koi zikr nahi tha.

Nateeja: dusri line pehli hi row par ja kar baith jati thi, aur qty
JAMA nahi karti thi — BADAL deti thi:

    Screen par  :  3 lines, kul 3 adad
    Database mein:  1 row,  qty 1          <-- do lines mit gayin

Bill hold kar ke wapas kholne par cashier ko sirf wohi ek line milti
thi. Baqi hamesha ke liye ja chuki hotin.

YEH SIRF HOLD KA MASLA NAHI THA. Wohi khana KOT bhejte waqt aur bill
BAND karte waqt bhi chalta hai:

    KOT  : kitchen ko 1 line gayi, 3 ki jagah
           -> teen mein se do customer ka khana kabhi bana hi nahi
    BILL : bill par 1 line likhi gayi, 3 ki jagah


KYA PAISE KAM LIYE GAYE? NAHI.
Yeh maine chala kar dekha hai. Bill ka total Sale Point ki screen se
aata hai, order_items ki ginti se nahi — to customer se poore 960
hi liye gaye. Stock bhi poora kata (3 adad ka), kyunke wo bhi screen
ki list se chalta hai.

TO NUQSAAN KAHAN THA — teen jagah, aur teenon ahem:

  1. HOLD  : items wapas nahi milte (aap ki shikayat)
  2. KITCHEN: adhoori parchi -> galat khana bana
  3. RECORD : bill par 1 line @320, aur total 960 — aapas mein
              mail nahi khate. Item-wise sale report aur FBR dono
              isi list se banti hain.

FBR WALI BAAT ALAG SE SAMAJH LEIN
Agar aap ke paas FBR/KPRA chalu hai to is tarah ka bill FBR ko
BHEJA HI NAHI JATA THA. Software mein pehra lagaya gaya hai: "FBR ka
total bill ke total ke barabar ho, warna bhejna hi nahi." Yahan FBR
ka total 320 banta aur bill ka 960 — farq 640 — to bill PENDING par
ruk jata.

    Yeh pehra theek kaam kar raha tha (galat declaration se PENDING
    behtar hai). Magar agar aap ke paas aise PENDING bill pare hain,
    to un mein se kuch ki wajah YEHI thi.


############################################################
 AB KYA HOTA HAI
############################################################

Talash mein kitchen note bhi shamil kar diya gaya hai. Ab har
alag note apni alag line banata hai — screen par bhi, database mein
bhi, kitchen parchi par bhi, aur bill par bhi.


------------------------------------------------------------
 EK BAAT SAAF KAR DENA ZAROORI HAI
------------------------------------------------------------
JO HOLD BILL PEHLE SE KHARAB HO CHUKE HAIN, WO THEEK NAHI HO SAKTE.

Jo lines mit gayi thin wo kahin mehfooz nahi thin — mita di gayi
thin. Un ko wapas lane ka koi raasta nahi. Jo hold bill aap ke paas
is waqt pare hain aur adhoore lagte hain, cashier ko wo dobara
daalni paren gi.

V170 aage ke bills ko bachata hai.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
DO FILES BADLIN (README ke ilawa):

    VERSION
    src/Services/PosService.php

KOI MIGRATION NAHI. Database mein koi tabdeeli nahi.
Sale Point ki screen (approved_ui) bhi NAHI badli — wo pehle se
theek thi, masla sirf server ki talash mein tha.


------------------------------------------------------------
 TESTING
------------------------------------------------------------
MASLA PEHLE REPRODUCE KIYA (purane code par):

  Russian Salad @320 ki teen lines — [mirch tez] / [mirch kam] /
  [bina note] — phir:

    HOLD      ->  database mein 1 row (3 ki jagah)
                  resume par cart mein 1 line                 <-- masla
    KOT       ->  kitchen ko 1 line (3 ki jagah)              <-- masla
    BILL BAND ->  bill par 1 line @320, order ka total 960    <-- masla
                  (paise poore liye gaye — 960)

FIX KE BAAD WAHI TEST:

    HOLD      ->  database mein teenon rows, teenon note ke saath
                  resume par cart mein teenon lines           OK
    KOT       ->  teenon lines kitchen gayin (sent=1,1,1)     OK
    BILL BAND ->  bill par teenon lines, total 960            OK

POORI REGRESSION — 91 TEST, SAB PASS, KOI FAIL NAHI:

    billing audit (V168)          21
    paison ka hisaab (V168)       10
    kinare ke halaat (V168)        8
    do din ka bill (V168)          8
    hold / resume                  7
    note wali lines (naya)         4
    KOT + bill band (naya)         5
    bulk delete (V166)            28
    KOT / table / cancel          (purane andaz ke test — sab "theek")

AIK PURANA TEST GHALAT NIKLA — BATA DENA CHAHIYE:
`t_hold_items` naam ka mera purana test teen card par click karta
tha aur maan leta tha ke teen alag item aa gaye. Haqeeqat mein do
card EK HI product ke thay (Fish Tikka x2 + Fried Fish x1 = 2 lines).
Yani test khud ghalat tha, software theek. Test theek kar diya hai
taake aage dhoka na de.

JO MAIN NAHI KAR SAKA:
Asli kitchen printer par parchi nikal kar dekhna — mere paas printer
nahi hai. Test ne jawab se tasdeeq ki ke teenon lines bheji gayin
(sent=1,1,1) aur printer na milne ki wajah se 3 lines "no_printer"
bata di gayin — yani teenon print hone ke liye bheji gayi thin.


============================================================
 LAGANE KA TAREEQA
============================================================

PART A — OFFLINE PC

STEP A1 — BACKUP
    src\Services\PosService.php ki copy bana lein (ya V169 ka zip
    rakha rehne dein).

STEP A2 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    src\Services\PosService.php

    Agar V169 abhi nahi lagaya to us ki bhi:
    tools\update.ps1

    Agar V168 abhi nahi lagaya to us ki bhi:
    APPROVED_UI_SHA256.json
    approved_ui\restaurant_pos.html
    public\api.php

STEP A3 — EXTRACT
    SaaSVersion_V170.zip extract karein, phir UPDATE.bat

STEP A4 — VERIFY (Ctrl+F)

      FILE                          SEARCH FOR
      ----------------------------  ----------------------------
      VERSION                       V170
      src\Services\PosService.php   KITCHEN NOTE BHI PEHCHAN
      src\Services\PosService.php   COALESCE(kitchen_note,'')=?

STEP A5 — CHALA KAR DEKHEIN (5 minute, khud karein)
    1. Sale Point kholein
    2. EK HI item teen dafa daalein, magar har dafa ALAG kitchen
       note likhein (jaise "mirch tez", "mirch kam", aur teesri
       dafa note khali chhor dein)
    3. Screen par teen alag lines nazar aani chahiyen
    4. KOT NA bhejein — seedha HOLD kar dein
    5. Hold Bills se wohi bill wapas kholein
       -> TEENON LINES, apne apne note ke saath, wapas aani chahiyen

    Pehle yahan sirf EK line aati thi.


PART B — SERVER (Railway)

    Yeh fix server par bhi ja raha hai (`src/Services/PosService.php`).
    Deploy karna ZAROORI hai — aur is dafa pehle karein, kyunke jab
    tak server par V170 nahi jata, offline PC ko naya build nazar
    hi nahi aayega (Version mismatch wohi purana masla).

Madad: Wabwar Software House — +92 342 5095104

// build: V170 build 2026-10-27
