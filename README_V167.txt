============================================================
 V167 — SHIFT "KHUD KHUL JANE" KA MASLA + UPDATE KA LOG
 Wabwar Software House                     build 2026-10-25
============================================================

Aap ne do baatein batayi thin. Neeche dono ka jawab hai — ek ka asal
sabab mil gaya aur theek ho gaya, doosre ka saaf jawab aur ek pukhta
raasta.


############################################################
 MASLA 1 — "ACCOUNT BAND KIYA, PHIR BHI KHULA DIKHA RAHA HAI"
                                            *** THEEK HO GAYA ***
############################################################

ASAL SABAB (chala kar pakra gaya)

Shift wale safhe par "khuli shift" ka faisla GHALAT tareeqe se ho raha
tha. Safha branch ki SAB SE PEHLI khuli shift utha leta tha — chahe wo
KISI AUR cashier ki ho.

Aap malik hain, aap ko sab ki shifts nazar aati hain. Is liye:

  - Aap apna account band karte hain
  - Screen phir bhi kehti hai "Shift ... is open"
  - Refresh, logout, login — koi farq nahi parta, kyunke doosre ki
    shift waqai khuli hai

Aap ko laga ke account khud khul gaya. Account khula nahi tha — safha
kisi AUR ka account aap ka bata raha tha.

AUR EK KHATRE KI BAAT: us halat mein jo "Close shift" ka button nazar
aata tha, wo US DOOSRE BANDAY KI SHIFT band karta — aap ki ginti hui
cash ke sath. Yani kisi aur ka account aap ke paison par band ho jata
aur dono ka hisaab ghalat ho jata.

AB KYA HOTA HAI
  - "Khuli shift" ka matlab sirf AAP KI APNI khuli shift
  - Doosre counter alag se, peele khane mein, sirf KHABAR ke tor par:
        "1 other till is still open: Test Cashier (S-CASH, since ...)"
        "Yeh un ke apne account hain — unhein wahi band karenge."
  - Un par koi Close ka button nahi. Ab kisi aur ka account ghalti se
    band nahi ho sakta.
  - Neeche wali list pehle ki tarah SAB shifts dikhati hai (manager ko
    sab nazar aana chahiye) — sirf upar wala faisla theek hua hai.


############################################################
 MASLA 2 — UPDATE KE WAQT ERRORS
############################################################

SACHI BAAT PEHLE: maine update ka har qadam yahan chala kar dekha —
package banaya, sealed node par extract kiya, aur wahi migrations
chalayin jo UPDATE.bat chalati hai. Mere paas koi error NAHI aaya.
Is liye main yeh nahi keh sakta ke aap ne jo dekha wo yehi tha.

MAGAR EK CHEEZ MILI JO YAQEENAN ERROR LAGTI HAI

Database ka schema har update par dobara chalta hai. Doosri dafa chalne
par kuch cheezein pehle se bani hoti hain, aur woh aise chhapti thin:

    SCHEMA_INSTALLED ... statements_ok=95 failed=3 tables=142
      note: SQLSTATE[HY000]: General error: 1826 Duplicate FOREIGN KEY
            constraint name 'fk_ii_default_location'
      note: SQLSTATE[HY000]: ... 'fk_ii_supplier'
      note: SQLSTATE[23000]: ... 1062 Duplicate entry 'V1' for PRIMARY

Teen laal lakeeren, har update par. In ka matlab sirf itna tha ke "yeh
cheez pehle se maujood hai" — koi nuqsan nahi. Magar dekhne wale ko
lagta hai ke update kharab ho gayi.

AB: woh alag ginti mein jati hain aur chhapti nahi —

    SCHEMA_INSTALLED ... statements_ok=96 already=2 failed=0 tables=142

Jo error WAQAI anjaan ho, wo ab bhi poora chhapta hai. Kuch chhupaya
nahi gaya.


AUR — AGLI DAFA KUCH NOTE KARNE KI ZAROORAT NAHI

Aap ne likha tha: "errors aye jo main note nahi kar sakta". Theek baat
hai — window mein sab tezi se guzar jata hai.

AB UPDATE APNA LOG KHUD LIKHTI HAI:

    storage\logs\update-<tareekh>_<waqt>.log

Us mein shuru se aakhir tak sab kuch hota hai. Pata DO dafa screen par
aata hai — update shuru hote hi, aur khatam hone par:

    Log: D:\smart POS\storage\logs\update-20261026_101530.log

`storage\` ko update kabhi replace nahi karti, is liye yeh file update
ke baad bhi salamat rehti hai.

Agli dafa koi masla ho to bas wo file bhej dein — poora matn us mein
hoga aur main theek wahi dekh sakoon ga jo aap ne dekha.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
AATH FILES BADLIN (README ke ilawa), KOI NAYI FILE NAHI:

    VERSION
    public/api.php                      (shiftmgr-list ka faisla)
    public/ops_db.js                    (doosre counter ka khana)
    public/router.php                   (ops_db.js ka naya ?b=)
    src/Services/OpsService.php         (shiftOpenState - naya)
    scripts/install_schema.php          ("pehle se hai" ki alag ginti)
    docs/02_local_mysql_schema.sql      (seed row ab INSERT IGNORE)
    tools/update.ps1                    (update ka log)

KOI MIGRATION NAHI — database ko kuch nahi hota.
approved_ui KI KISI FILE KO HAATH NAHI LAGAYA — SHA256 wala taala
waisa ka waisa.


------------------------------------------------------------
 TESTING
------------------------------------------------------------
  PASS 53 / FAIL 0

  Shift ka faisla (7 test) — asli node database par:
    Sirf apni khuli -> apni nazar aati hai                          OK
    Apni BAND, doosre ki khuli -> apni koi nahi (yehi masla tha)    OK
    ...aur doosra counter alag khane mein nazar aata hai            OK
    Dono khuli -> malik ko apni, cashier ko apni                    OK
    Dono band -> koi khuli nahi                                     OK
    List mein phir bhi SAB shifts (manager ka haq)                  OK

  Masla pehle REPRODUCE kiya gaya, phir theek kiya:
    V166 par: malik ne apni band ki -> screen ne kaha "Shift S-CASH
    is open" aur "Close shift" ka button dikha diya (cashier ki
    shift par). V167 par: "You have no open shift" + peela khana.

  install_schema (sealed node par):
    Pehli dafa (naya database)  -> ok=98 already=0 failed=0         OK
    Doosri dafa (har update)    -> ok=96 already=2 failed=0         OK
    Koi SQLSTATE line nahi chhapti                                  OK

  update.ps1:
    Banawat (braces/parens/here-string) — mere hisse barabar        OK
    Start-Transcript / Stop-Transcript — ek ek dafa                 OK

  Purane kaam par asar (regression):
    V166 bulk delete — server 18 test                               OK
    V166 bulk delete — browser 28 test                              OK
    V165 update check — naya build pakrna                           OK

  Jo main NAHI kar saka:
    update.ps1 ko waqai Windows par chala kar dekhna — mere paas
    Windows nahi hai aur PowerShell is machine par install nahi ho
    sakti. Log banne wala hissa aap ke PC par pehli dafa chalega.
    Agar kisi wajah se log na bane to update phir bhi poora chalti
    hai — sirf ek warning aati hai.


============================================================
 LAGANE KA TAREEQA
============================================================

PART A — OFFLINE PC

STEP A1 — BACKUP
    Purani copy sambhaal lein (ya V166 ka zip rakha rehne dein).
    Khaas tor par: tools\update.ps1

STEP A2 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    public\api.php
    public\ops_db.js
    public\router.php
    src\Services\OpsService.php
    scripts\install_schema.php
    docs\02_local_mysql_schema.sql
    tools\update.ps1

    KOI NAYI FILE NAHI (README ke ilawa).

STEP A3 — EXTRACT
    SaaSVersion_V167.zip extract karein, phir UPDATE.bat

STEP A4 — VERIFY (har file mein Ctrl+F)

      FILE                             SEARCH FOR
      -------------------------------  -------------------------
      VERSION                          V167
      public\api.php                   shiftOpenState
      public\ops_db.js                 others_open
      public\router.php                ops_db.js?b=v167
      src\Services\OpsService.php      shiftOpenState
      scripts\install_schema.php       already=
      docs\02_local_mysql_schema.sql   INSERT IGNORE INTO schema_migrations
      tools\update.ps1                 Start-Transcript

STEP A5 — CHALA KAR DEKHEIN
    1. UPDATE.bat chalate waqt shuru mein "Log: ..." wali line aani
       chahiye. Us file ko kholein — poora matn us mein hoga.
    2. Opening & Closing Shift kholein. Agar aap ki koi shift khuli
       nahi hai to likha aana chahiye:
           "You have no open shift."
       aur agar kisi aur ki khuli ho to us ka alag peela khana.
    3. Apni shift kholein -> "Your shift ... is open" aur Close ka
       button. Band karein -> dobara "You have no open shift."

    AHEM: agar aap ki dukan par kisi cashier ki purani shift kai din
    se khuli pari hai, wo ab peele khane mein nazar aayegi. Usay us
    cashier ke login se band karwa dein — tabhi us din ka hisaab
    poora hoga.


PART B — SERVER (Railway)

    Wahi files, wahi tarteeb. Deploy kar dein.
    (`tools\update.ps1` cloud par chalti nahi, magar files dono jagah
     ek jaisi rakhni hain.)

Madad: Wabwar Software House — +92 342 5095104

// build: V167 build 2026-10-25
