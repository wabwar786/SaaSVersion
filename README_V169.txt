============================================================
 V169 — UPDATE.bat PEHLE DATABASE CHALATI HAI
 Wabwar Software House                     build 2026-10-26
============================================================

V169 MEIN V168 KA SAB KUCH SHAMIL HAI.
Agar aap ne V168 abhi nahi lagaya to seedha yehi laga lein — Sale
Point billing ke chaar fixes bhi isi mein hain (tafseel README_V168
mein).

Is build mein SIRF EK cheez nayi hai, aur wo aap ke us purane sawal
ka jawab hai: "update ke waqt errors aate hain jo main note nahi kar
sakta."


############################################################
 ASAL SABAB MIL GAYA
############################################################

UPDATE.bat ne khud screen par likha hota hai:

    "The software must be closed. Close its window if it is open."

Aur jab software band hota hai to MariaDB (database) bhi band ho jati
hai — usay launcher chalata hai (START_OFFLINE.bat), UPDATE.bat nahi.

Yani jo customer hidayat par AMAL karta hai, us ke saath yeh hota tha:

    software band  ->  UPDATE.bat  ->  database band pari hai
                                   ->  har migration ka yehi jawab:

      FAIL install_schema    SQLSTATE[HY000] [2002] Connection refused
      FAIL migrate_platform  SQLSTATE[HY000] [2002] Connection refused
      FAIL migrate_sync      SQLSTATE[HY000] [2002] Connection refused
      ... (25 lakeeren)
      25 step(s) failed - the software may not work correctly.

Yehi wo laal lakeeren thin jo tezi se guzar jati thin.

AUR YEH SIRF DEKHNE MEIN BURI NAHI THI. Nayi files to lag jati thin,
magar DATABASE ka hissa BILKUL NAHI chalta tha. Us build mein koi
naya column aaya hota to software baad mein toot bhi sakta tha.

Jab aap ne UPDATE.bat software CHALTE HUE chalayi hogi, us dafa sab
theek chala hoga (database us waqt chal rahi thi) — isi liye kabhi
errors aate thay aur kabhi nahi.


AB KYA HOTA HAI
  1. UPDATE.bat pehle dekhti hai ke database chal rahi hai ya nahi
  2. Na chal rahi ho to KHUD chala leti hai, phir migrations chalati hai
  3. Jo database us ne chalayi thi, kaam ke baad wapas band kar deti hai
     (agar wo pehle se chal rahi thi to usay haath nahi lagati —
      warna chalta hua software toot jata)

Screen par ab yeh nazar aayega:

    [5/5] Updating the database...
          Starting the local database for this step...
          Database is up.
          38 steps applied, 4 skipped.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
DO FILES BADLIN (README ke ilawa):

    VERSION
    tools/update.ps1

KOI MIGRATION NAHI. V168 ki baqi saari files is zip mein pehle se
maujood hain.


------------------------------------------------------------
 TESTING
------------------------------------------------------------
  MASLA REPRODUCE HUA:
    Sealed offline package banaya, database band kar ke migrations
    chalayin  ->  ok=3 skipped=14 FAILED=25, har ek par
                  "SQLSTATE[HY000] [2002] Connection refused"      <-- yehi
    Database chalu kar ke wahi migrations  ->  ok=38 skipped=4 failed=0

  V168 KA FIX SEALED NODE PAR BHI CHAL KAR DEKHA:
    Offline package se node banaya, us ka apna database,
    kal ka hold bill resume kar ke band kiya:
      sirf EK order, Hold list se nikal gaya                       OK
      (8 test, sab pass — wahi jo cloud par pass huay thay)

  JO MAIN NAHI KAR SAKA:
    `update.ps1` ko Windows par chala kar dekhna — mere paas Windows
    nahi hai aur PowerShell is machine par install nahi ho sakti.
    Jo hissa maine jorha hai us ki banawat jaanchi hai (braces,
    parens, here-strings — V168 ke barabar), magar chala kar nahi
    dekha.

    Agar kisi wajah se database shuru na ho sake to update RUKTI
    NAHI — ek warning aati hai aur baqi kaam pehle ki tarah chalta
    hai.


============================================================
 LAGANE KA TAREEQA
============================================================

PART A — OFFLINE PC

STEP A1 — BACKUP
    tools\update.ps1 ki copy bana lein (ya V168 ka zip rakha rehne dein).

STEP A2 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    tools\update.ps1

    Agar V168 abhi nahi lagaya to us ki files bhi delete karein:
    APPROVED_UI_SHA256.json
    approved_ui\restaurant_pos.html
    public\api.php
    src\Services\PosService.php

STEP A3 — EXTRACT
    SaaSVersion_V169.zip extract karein, phir UPDATE.bat

    NOTE: yeh naya tareeqa AGLI dafa se chalega — abhi wali UPDATE.bat
    purani hai. Is dafa agar laal lakeeren aayen to ghabrayen nahi;
    software chalu kar ke UPDATE.bat dobara chala lein, ya agli dafa
    ka intezar karein — ab wo khud database chala legi.

STEP A4 — VERIFY (Ctrl+F)

      FILE                 SEARCH FOR
      -------------------  ----------------------------
      VERSION              V169
      tools\update.ps1     PEHLE DATABASE CHALAO
      tools\update.ps1     dbStartedByUs

STEP A5 — CHALA KAR DEKHEIN
    Software BAND karein, phir UPDATE.bat chalayein. Step 5 par ab
    yeh aana chahiye:
        Starting the local database for this step...
        Database is up.
        38 steps applied, 4 skipped.
    Koi "Connection refused" NAHI aani chahiye.

    Aur update ka poora log (V167 se) yahan milta hai:
        storage\logs\update-<tareekh>.log


PART B — SERVER (Railway)

    `tools\update.ps1` cloud par chalti nahi, magar files dono jagah
    ek jaisi rakhni hain — deploy kar dein.

Madad: Wabwar Software House — +92 342 5095104

// build: V169 build 2026-10-26
